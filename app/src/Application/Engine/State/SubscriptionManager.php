<?php

declare(strict_types=1);

namespace App\Application\Engine\State;

use App\Application\Port\HomeAssistant\HomeAssistantGatewayInterface;
use App\Application\Port\HomeAssistant\SubscriptionInterface;

/**
 * Owns the single `subscribe_entities` subscription of the engine, limited to the entities of the index
 * (never to everything: with no entity there is no subscription at all).
 */
final class SubscriptionManager
{
    private ?SubscriptionInterface $current = null;
    private bool $stale = false;

    public function __construct(
        private readonly HomeAssistantGatewayInterface $gateway,
        private readonly EntityRoomIndex $index,
        private readonly ChangeProcessor $processor,
    ) {
    }

    /**
     * Subscribes to the entities of the current index, then drops the previous subscription, so no change
     * is missed in between (duplicates are filtered by the state store).
     */
    public function refresh(): void
    {
        $this->stale = true;

        $entityIds = $this->index->entityIds();
        $next = [] === $entityIds ? null : $this->gateway->subscribeEntities($entityIds, $this->processor->process(...));

        $previous = $this->current;
        $this->current = $next;
        $previous?->unsubscribe();

        $this->stale = false;
    }

    /**
     * Redoes a {@see refresh()} that failed (typically because the connection was down at the time).
     */
    public function repairIfStale(): void
    {
        if ($this->stale) {
            $this->refresh();
        }
    }

    public function close(): void
    {
        $this->current?->unsubscribe();
        $this->current = null;
    }
}
