<?php

declare(strict_types=1);

namespace App\Infrastructure\HomeAssistant;

use Amp\DeferredFuture;
use Amp\Future;

/**
 * Correlates WebSocket commands with their answers by message id.
 *
 * Ids are strictly increasing for the lifetime of the object (Home Assistant requires it within a
 * connection, and never reusing ids across reconnections is harmless).
 */
final class PendingRequests
{
    private int $lastId = 0;

    /** @var array<int, DeferredFuture<mixed>> */
    private array $pending = [];

    public function nextId(): int
    {
        return ++$this->lastId;
    }

    /**
     * @return Future<mixed>
     */
    public function register(int $id): Future
    {
        /** @var DeferredFuture<mixed> $deferred */
        $deferred = new DeferredFuture();
        $this->pending[$id] = $deferred;

        return $deferred->getFuture()->ignore();
    }

    /**
     * @return bool whether a request was waiting for this id
     */
    public function resolve(int $id, mixed $value): bool
    {
        $deferred = $this->take($id);
        $deferred?->complete($value);

        return null !== $deferred;
    }

    /**
     * @return bool whether a request was waiting for this id
     */
    public function reject(int $id, \Throwable $error): bool
    {
        $deferred = $this->take($id);
        $deferred?->error($error);

        return null !== $deferred;
    }

    /**
     * Drops a request without answering it (e.g. after a timeout).
     */
    public function forget(int $id): void
    {
        unset($this->pending[$id]);
    }

    public function failAll(\Throwable $error): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $deferred) {
            $deferred->error($error);
        }
    }

    public function count(): int
    {
        return \count($this->pending);
    }

    /**
     * @return DeferredFuture<mixed>|null
     */
    private function take(int $id): ?DeferredFuture
    {
        $deferred = $this->pending[$id] ?? null;
        unset($this->pending[$id]);

        return $deferred;
    }
}
