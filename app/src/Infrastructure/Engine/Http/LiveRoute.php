<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Pipeline\Queue;
use App\Application\Engine\Evaluation\LiveStatusPublisher;
use App\Application\Engine\Evaluation\RoomStatus;
use App\Domain\Room\RoomId;
use Revolt\EventLoop;

/**
 * `GET /api/live`: Server-Sent Events stream of the room statuses.
 *
 * On connection the client receives one `room` event per room (full snapshot), then one event per change.
 * A comment line is sent every `heartbeatSeconds` so the stream is never idle: the AMPHP HTTP/1 driver closes
 * a response that writes nothing for HttpDriver::DEFAULT_STREAM_TIMEOUT seconds (15 s), and proxies drop idle
 * connections too. No token: nginx only exposes this route behind the Home Assistant ingress.
 */
final class LiveRoute implements InternalRouteInterface, ShutdownAwareRouteInterface
{
    /** Well below the server's stream timeout, so a quiet stream is never closed. */
    public const float DEFAULT_HEARTBEAT_SECONDS = 10.0;

    /** @var array<int, \Closure(): void> cleanup of every open stream */
    private array $openStreams = [];

    public function __construct(
        private readonly LiveStatusPublisher $publisher,
        private readonly float $heartbeatSeconds = self::DEFAULT_HEARTBEAT_SECONDS,
    ) {
    }

    public function method(): string
    {
        return 'GET';
    }

    public function path(): string
    {
        return '/api/live';
    }

    public function requiresToken(): bool
    {
        return false;
    }

    public function handle(Request $request): Response
    {
        /** @var Queue<string> $queue */
        $queue = new Queue();
        $send = static function (string $chunk) use ($queue): void {
            if (!$queue->isComplete() && !$queue->isDisposed()) {
                $queue->pushAsync($chunk)->ignore();
            }
        };

        foreach ($this->publisher->snapshot() as $status) {
            $send(self::roomEvent($status));
        }

        $unsubscribe = $this->publisher->subscribe(
            static fn (RoomStatus $status) => $send(self::roomEvent($status)),
            static fn (RoomId $room) => $send(self::event('room_removed', ['roomId' => (string) $room])),
        );
        $heartbeat = EventLoop::repeat($this->heartbeatSeconds, static fn () => $send(": heartbeat\n\n"));

        $closed = false;
        $streamId = spl_object_id($queue);
        $cleanup = function () use (&$closed, $unsubscribe, $heartbeat, $queue, $streamId): void {
            if ($closed) {
                return;
            }
            $closed = true;
            unset($this->openStreams[$streamId]);
            $unsubscribe();
            EventLoop::cancel($heartbeat);
            if (!$queue->isComplete()) {
                $queue->complete();
            }
        };
        $this->openStreams[$streamId] = $cleanup;
        $request->getClient()->onClose(static fn () => $cleanup());

        $response = new Response(HttpStatus::OK, [
            'content-type' => 'text/event-stream; charset=utf-8',
            'cache-control' => 'no-cache',
            'x-accel-buffering' => 'no',
        ], new ReadableIterableStream($queue->iterate()));
        $response->onDispose($cleanup);

        return $response;
    }

    /**
     * Ends every open stream: the server cannot stop while a response is still being streamed.
     */
    public function shutdown(): void
    {
        foreach ($this->openStreams as $cleanup) {
            $cleanup();
        }
    }

    private static function roomEvent(RoomStatus $status): string
    {
        return self::event('room', $status);
    }

    private static function event(string $name, mixed $data): string
    {
        return \sprintf("event: %s\ndata: %s\n\n", $name, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION));
    }
}
