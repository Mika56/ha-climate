<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\SubscriptionInterface;

/**
 * Subscription handle of {@see FakeHomeAssistantGateway}; remembers what its listener last saw.
 */
final class FakeSubscription implements SubscriptionInterface
{
    /** @var array<string, EntityState> */
    private array $delivered = [];

    private bool $active = true;

    /**
     * @param list<string>                      $entityIds
     * @param \Closure(EntityStateChange): void $listener
     * @param \Closure(): void                  $onUnsubscribe
     */
    public function __construct(
        private readonly array $entityIds,
        public readonly \Closure $listener,
        private readonly \Closure $onUnsubscribe,
    ) {
    }

    public function accepts(string $entityId): bool
    {
        return $this->active && \in_array($entityId, $this->entityIds, true);
    }

    public function remember(EntityStateChange $change): void
    {
        if (null === $change->new) {
            unset($this->delivered[$change->entityId]);
        } else {
            $this->delivered[$change->entityId] = $change->new;
        }
    }

    /**
     * Computes what the listener must be told so that it matches the given states, and remembers it.
     *
     * @param array<string, EntityState> $states
     *
     * @return list<EntityStateChange>
     */
    public function resync(array $states): array
    {
        $changes = [];
        foreach ($this->entityIds as $entityId) {
            $old = $this->delivered[$entityId] ?? null;
            $new = $states[$entityId] ?? null;
            if ($old === $new) {
                continue;
            }
            $change = new EntityStateChange($entityId, $old, $new);
            $this->remember($change);
            $changes[] = $change;
        }

        return $changes;
    }

    public function unsubscribe(): void
    {
        if ($this->active) {
            $this->active = false;
            ($this->onUnsubscribe)();
        }
    }
}
