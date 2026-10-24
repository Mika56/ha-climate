<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use Amp\ByteStream\ReadableStream;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\TimeoutCancellation;
use App\Application\Engine\Evaluation\LiveStatusPublisher;
use App\Domain\Climate\Season;
use App\Infrastructure\Engine\EngineTokenStore;
use App\Infrastructure\Engine\Http\BoostRoute;
use App\Infrastructure\Engine\Http\CancelOverrideRoute;
use App\Infrastructure\Engine\Http\ContextRoute;
use App\Infrastructure\Engine\Http\DiscoveryRoute;
use App\Infrastructure\Engine\Http\InternalHttpServer;
use App\Infrastructure\Engine\Http\InternalRouteInterface;
use App\Infrastructure\Engine\Http\InternalRouter;
use App\Infrastructure\Engine\Http\JsonResponse;
use App\Infrastructure\Engine\Http\LiveRoute;
use App\Infrastructure\Engine\Http\NotificationTestRoute;
use App\Infrastructure\Engine\Http\OverviewRoute;
use App\Infrastructure\Engine\Http\ReloadRoute;
use App\Infrastructure\Engine\Http\StatusRoute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Psr\Log\NullLogger;

/**
 * The internal HTTP server for real, on a random loopback port, with a real HTTP client.
 */
#[CoversClass(InternalHttpServer::class)]
#[CoversClass(InternalRouter::class)]
#[CoversClass(ReloadRoute::class)]
#[CoversClass(StatusRoute::class)]
#[CoversClass(LiveRoute::class)]
#[CoversClass(JsonResponse::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure\Persistence')]
#[UsesClass(EngineTokenStore::class)]
#[UsesClass(BoostRoute::class)]
#[UsesClass(CancelOverrideRoute::class)]
#[UsesClass(ContextRoute::class)]
#[UsesClass(DiscoveryRoute::class)]
#[UsesClass(OverviewRoute::class)]
#[UsesClass(NotificationTestRoute::class)]
final class InternalHttpServerTest extends EngineTestCase
{
    /** @var list<InternalHttpServer> */
    private array $servers = [];

    private HttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = HttpClientBuilder::buildDefault();
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        parent::tearDown();
    }

    public function testTheInternalRoutesRequireTheToken(): void
    {
        $port = $this->startContainerServer();

        self::assertSame(401, $this->request($port, 'GET', '/internal/status')->getStatus());
        self::assertSame(401, $this->request($port, 'GET', '/internal/status', 'wrong-token')->getStatus());
        self::assertSame(401, $this->request($port, 'POST', '/internal/reload')->getStatus());
        self::assertSame(401, $this->request($port, 'POST', '/internal/reload', 'wrong-token')->getStatus());
    }

    public function testTheTokenFileIsCreatedPrivate(): void
    {
        $this->startContainerServer();

        $path = \dirname(__DIR__, 3).'/var/engine_test.token';
        self::assertFileExists($path);
        self::assertSame('0600', substr(\sprintf('%o', fileperms($path)), -4));
    }

    public function testUnknownPathsAndMethodsAreRejected(): void
    {
        $port = $this->startContainerServer();

        $missing = $this->request($port, 'GET', '/internal/nothing', $this->token());
        self::assertSame(404, $missing->getStatus());
        self::assertSame(['error' => 'not_found'], json_decode($missing->getBody()->buffer(), true));

        $wrongMethod = $this->request($port, 'GET', '/internal/reload', $this->token());
        self::assertSame(405, $wrongMethod->getStatus());
        self::assertSame('POST', $wrongMethod->getHeader('allow'));
    }

    public function testStatusListsTheRoomsAsJson(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();
        $port = $this->startContainerServer();

        $response = $this->request($port, 'GET', '/internal/status', $this->token());

        self::assertSame(200, $response->getStatus());
        self::assertStringStartsWith('application/json', (string) $response->getHeader('content-type'));
        /** @var array{rooms: list<array<string, mixed>>} $payload */
        $payload = json_decode($response->getBody()->buffer(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['rooms']);
        self::assertSame('Living room', $payload['rooms'][0]['name']);
        self::assertSame(19.5, $payload['rooms'][0]['currentTemperature']);
        self::assertEquals(['mode' => 'heat', 'target' => 21.0, 'reason' => ['code' => 'reason.schedule', 'params' => ['preset' => 'comfort', 'until' => '22:30']]], $payload['rooms'][0]['decision']);
    }

    public function testReloadAppliesTheConfigurationChange(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->startEngine();
        $port = $this->startContainerServer();
        $this->setSeason(Season::Off);
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        $response = $this->request($port, 'POST', '/internal/reload', $this->token());

        self::assertSame(200, $response->getStatus());
        self::assertSame(['status' => 'reloaded'], json_decode($response->getBody()->buffer(), true));
        self::assertSame(['hvac_mode' => 'off'], $this->homeAssistant()->serviceCalls()[0]->serviceData);
    }

    public function testALiveStreamStartsWithTheSnapshotThenFollowsTheChanges(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->createRoom('Bedroom', 'climate.bedroom', sensor: 'sensor.bedroom_temperature');
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedDevice('climate.bedroom', 'heat', 21.0);
        $this->seedSensor();
        $this->startEngine();
        $port = $this->startContainerServer();

        // No token needed: nginx exposes this route behind the ingress.
        $response = $this->request($port, 'GET', '/api/live');
        self::assertSame(200, $response->getStatus());
        self::assertSame('text/event-stream; charset=utf-8', $response->getHeader('content-type'));
        self::assertSame('no', $response->getHeader('x-accel-buffering'));

        $stream = $response->getBody();
        $snapshot = $this->readUntil($stream, 2);
        self::assertSame(2, substr_count($snapshot, "event: room\n"));
        self::assertStringContainsString('"name":"Living room"', $snapshot);
        self::assertStringContainsString('"name":"Bedroom"', $snapshot);

        $this->homeAssistant()->pushStateChange(self::SENSOR, '18.0');
        $update = $this->readUntil($stream, 1);
        self::assertStringContainsString('event: room', $update);
        self::assertStringContainsString('"currentTemperature":18', $update);

        $stream->close();
    }

    public function testALiveStreamAnnouncesRemovedRooms(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->startEngine();
        $port = $this->startContainerServer();
        $stream = $this->request($port, 'GET', '/api/live')->getBody();
        $this->readUntil($stream, 1);

        $this->deleteRoom($room);
        $this->service(\App\Application\Engine\Lifecycle\ConfigurationReloader::class)->reload();

        $removed = $this->readUntil($stream, 1);
        self::assertStringContainsString('event: room_removed', $removed);
        self::assertStringContainsString($room->getId()->toRfc4122(), $removed);
        $stream->close();
    }

    public function testALiveStreamSendsHeartbeatsAndStopsListeningWhenTheClientLeaves(): void
    {
        $publisher = $this->service(LiveStatusPublisher::class);
        $tokens = $this->service(EngineTokenStore::class);
        $server = new InternalHttpServer(new InternalRouter([new LiveRoute($publisher, 0.05)], $tokens, new NullLogger()), $tokens, new NullLogger(), 0);
        $this->servers[] = $server;
        $server->start();

        $response = $this->request($server->port(), 'GET', '/api/live');
        $stream = $response->getBody();
        $chunk = $this->readUntil($stream, 1);
        self::assertStringContainsString(': heartbeat', $chunk);

        $stream->close();
        $this->request($server->port(), 'GET', '/api/live')->getBody()->close();
        // The closed stream's listener is gone: publishing does not fail and the server still answers.
        self::assertSame(404, $this->request($server->port(), 'GET', '/internal/status')->getStatus());
    }

    public function testARouteThatFailsAnswers500WithoutLeakingDetails(): void
    {
        $tokens = $this->service(EngineTokenStore::class);
        $failing = new class implements InternalRouteInterface {
            public function method(): string
            {
                return 'GET';
            }

            public function path(): string
            {
                return '/internal/boom';
            }

            public function requiresToken(): bool
            {
                return false;
            }

            public function handle(\Amp\Http\Server\Request $request): \Amp\Http\Server\Response
            {
                throw new \RuntimeException('secret detail');
            }
        };
        $server = new InternalHttpServer(new InternalRouter([$failing], $tokens, new NullLogger()), $tokens, new NullLogger(), 0);
        $this->servers[] = $server;
        $server->start();

        $response = $this->request($server->port(), 'GET', '/internal/boom');

        self::assertSame(500, $response->getStatus());
        self::assertSame('{"error":"internal_error"}', $response->getBody()->buffer());
    }

    public function testTheServerCanBeStartedTwiceAndStoppedAndReportsItsPort(): void
    {
        $tokens = $this->service(EngineTokenStore::class);
        $server = new InternalHttpServer(new InternalRouter([], $tokens, new NullLogger()), $tokens, new NullLogger(), 0);
        $this->servers[] = $server;

        $server->start();
        $port = $server->port();
        $server->start();

        self::assertGreaterThan(0, $port);
        self::assertSame($port, $server->port());
        self::assertSame(404, $this->request($port, 'GET', '/')->getStatus());
        $server->stop();
        $server->stop();
    }

    private function startContainerServer(): int
    {
        $server = $this->service(InternalHttpServer::class);
        $this->servers[] = $server;
        $server->start();

        return $server->port();
    }

    private function token(): string
    {
        return $this->service(EngineTokenStore::class)->read() ?? throw new \LogicException('The server did not create the token.');
    }

    /**
     * @param non-empty-string $method
     */
    private function request(int $port, string $method, string $path, ?string $token = null): Response
    {
        $request = new Request(\sprintf('http://127.0.0.1:%d%s', $port, $path), $method);
        if (null !== $token) {
            $request->setHeader('X-Internal-Token', $token);
        }

        return $this->http->request($request, new TimeoutCancellation(5));
    }

    /**
     * Reads the stream until the given number of blank-line-terminated SSE blocks was received.
     */
    private function readUntil(ReadableStream $stream, int $blocks): string
    {
        $received = '';
        while (substr_count($received, "\n\n") < $blocks) {
            $chunk = $stream->read(new TimeoutCancellation(5));
            self::assertNotNull($chunk, 'The stream ended too soon.');
            $received .= $chunk;
        }

        return $received;
    }
}
