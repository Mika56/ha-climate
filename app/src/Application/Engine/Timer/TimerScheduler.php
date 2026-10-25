<?php

declare(strict_types=1);

namespace App\Application\Engine\Timer;

use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\Timer;
use App\Infrastructure\Persistence\Repository\TimerRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Persisted one-shot timers, identified by a key.
 *
 * Each timer lives in the database (so it survives a restart) and, while the engine runs, in the
 * {@see TimerDriverInterface}. When one comes due it is dispatched to the tagged {@see TimerHandlerInterface}
 * of its type, then deleted unless the handler rescheduled it.
 */
final class TimerScheduler
{
    /** @var array<string, ArmedTimer> */
    private array $armed = [];

    /** @var array<string, TimerHandlerInterface>|null */
    private ?array $handlers = null;

    /**
     * @param iterable<TimerHandlerInterface> $handlerServices
     */
    public function __construct(
        private readonly TimerRepository $timers,
        private readonly TimerDriverInterface $driver,
        private readonly ClockInterface $clock,
        private readonly iterable $handlerServices,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Creates the timer, or moves/replaces the existing one with the same key.
     *
     * @param array<string, mixed> $payload
     */
    public function schedule(string $key, \DateTimeImmutable $dueAt, string $type, ?RoomId $room = null, array $payload = []): void
    {
        $roomId = null === $room ? null : (string) $room;
        if (($this->armed[$key] ?? null)?->isSameAs($dueAt, $type, $roomId, $payload) ?? false) {
            return;
        }

        $roomUuid = null === $roomId ? null : Uuid::fromString($roomId);
        $timer = $this->timers->findOneByKey($key);
        if (null !== $timer && ($timer->getType() !== $type || $timer->getRoomId()?->toRfc4122() !== $roomUuid?->toRfc4122())) {
            $this->timers->remove($timer);
            $timer = null;
        }

        if (null === $timer) {
            $timer = new Timer($key, $type, $dueAt, $roomUuid, $payload);
        } else {
            $timer->reschedule($dueAt, $payload);
        }
        $this->timers->save($timer);

        $this->arm($key, $dueAt, $type, $roomId, $payload);
    }

    public function cancel(string $key): void
    {
        if (isset($this->armed[$key])) {
            $this->driver->cancel($this->armed[$key]->handle);
            unset($this->armed[$key]);
        }
        $this->timers->deleteByKey($key);
    }

    /**
     * Cancels every pending timer of a type.
     *
     * @return int the number of timers cancelled
     */
    public function cancelOfType(string $type): int
    {
        $cancelled = 0;
        foreach ($this->timers->findAllPending() as $timer) {
            if ($timer->getType() === $type) {
                $this->cancel($timer->getKey());
                ++$cancelled;
            }
        }

        return $cancelled;
    }

    /**
     * Whether a timer with this key is waiting.
     */
    public function isScheduled(string $key): bool
    {
        return isset($this->armed[$key]);
    }

    /**
     * Rearms every persisted timer after a start; those already past due fire right away, oldest first.
     *
     * @return int the number of persisted timers found
     */
    public function restore(): int
    {
        $pending = $this->timers->findAllPending();
        $now = $this->clock->now();

        $due = [];
        foreach ($pending as $timer) {
            if ($timer->isDueAt($now)) {
                $due[] = $timer->getKey();
                continue;
            }
            $this->arm($timer->getKey(), $timer->getDueAt(), $timer->getType(), $timer->getRoomId()?->toRfc4122(), $timer->getPayload());
        }
        foreach ($due as $key) {
            $this->fire($key);
        }

        return \count($pending);
    }

    /**
     * Stops every pending delay without touching the database (graceful shutdown): the timers come back
     * with {@see restore()} at the next start.
     */
    public function disarmAll(): void
    {
        foreach ($this->armed as $armed) {
            $this->driver->cancel($armed->handle);
        }
        $this->armed = [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function arm(string $key, \DateTimeImmutable $dueAt, string $type, ?string $roomId, array $payload): void
    {
        if (isset($this->armed[$key])) {
            $this->driver->cancel($this->armed[$key]->handle);
        }

        $seconds = max(0.0, (float) $dueAt->format('U.u') - (float) $this->clock->now()->format('U.u'));
        $handle = $this->driver->delay($seconds, fn () => $this->fire($key));
        $this->armed[$key] = new ArmedTimer($handle, $dueAt, $type, $roomId, $payload);
    }

    private function fire(string $key): void
    {
        unset($this->armed[$key]);

        $timer = $this->timers->findOneByKey($key);
        if (null === $timer) {
            return;
        }

        if (!$timer->isDueAt($this->clock->now())) {
            // The driver woke up slightly early: wait for the remainder.
            $this->arm($key, $timer->getDueAt(), $timer->getType(), $timer->getRoomId()?->toRfc4122(), $timer->getPayload());

            return;
        }

        $dueAt = $timer->getDueAt();
        try {
            $this->handlerFor($timer->getType())->handle($timer);
        } catch (\Throwable $e) {
            $this->logger->error('Timer "{key}" ({type}) failed: {message}', ['key' => $key, 'type' => $timer->getType(), 'message' => $e->getMessage(), 'exception' => $e]);
        } finally {
            $this->removeUnlessRescheduled($key, $dueAt);
        }
    }

    private function removeUnlessRescheduled(string $key, \DateTimeImmutable $handledDueAt): void
    {
        $current = $this->timers->findOneByKey($key);
        if (null !== $current && $current->getDueAt()->format('U.u') === $handledDueAt->format('U.u')) {
            $this->timers->remove($current);
        }
    }

    private function handlerFor(string $type): TimerHandlerInterface
    {
        if (null === $this->handlers) {
            $this->handlers = [];
            foreach ($this->handlerServices as $handler) {
                if (isset($this->handlers[$handler->type()])) {
                    throw new \LogicException(\sprintf('Several timer handlers declare the type "%s".', $handler->type()));
                }
                $this->handlers[$handler->type()] = $handler;
            }
        }

        return $this->handlers[$type] ?? throw new \RuntimeException(\sprintf('No handler for timers of type "%s".', $type));
    }
}
