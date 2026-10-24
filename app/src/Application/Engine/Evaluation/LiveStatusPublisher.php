<?php

declare(strict_types=1);

namespace App\Application\Engine\Evaluation;

use App\Domain\Room\RoomId;

/**
 * Keeps the latest {@see RoomStatus} of every room and tells the live listeners (the SSE streams) when one
 * really changed. A re-evaluation that finds the same content is silent.
 */
final class LiveStatusPublisher
{
    /** @var array<string, RoomStatus> */
    private array $statuses = [];

    /** @var array<int, array{\Closure(RoomStatus): void, \Closure(RoomId): void}> */
    private array $listeners = [];

    private int $nextListenerId = 0;

    /**
     * @return bool whether listeners were notified
     */
    public function publish(RoomStatus $status): bool
    {
        $key = (string) $status->roomId;
        $known = $this->statuses[$key] ?? null;
        if (null !== $known && $known->sameContentAs($status)) {
            return false;
        }

        $this->statuses[$key] = $status;
        foreach ($this->listeners as [$onStatus]) {
            $onStatus($status);
        }

        return true;
    }

    /**
     * Forgets a room that was deleted from the configuration.
     */
    public function remove(RoomId $room): void
    {
        if (!isset($this->statuses[(string) $room])) {
            return;
        }

        unset($this->statuses[(string) $room]);
        foreach ($this->listeners as [, $onRemoved]) {
            $onRemoved($room);
        }
    }

    /**
     * @return list<RoomStatus>
     */
    public function snapshot(): array
    {
        return array_values($this->statuses);
    }

    /**
     * @param \Closure(RoomStatus): void $onStatus
     * @param \Closure(RoomId): void     $onRemoved
     *
     * @return \Closure(): void call it to stop listening
     */
    public function subscribe(\Closure $onStatus, \Closure $onRemoved): \Closure
    {
        $id = $this->nextListenerId++;
        $this->listeners[$id] = [$onStatus, $onRemoved];

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }
}
