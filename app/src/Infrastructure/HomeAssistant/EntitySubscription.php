<?php

declare(strict_types=1);

namespace App\Infrastructure\HomeAssistant;

use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\SubscriptionInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * State of one `subscribe_entities` subscription: its entities, listener, cache of full states and the
 * WebSocket id it currently uses (a new one after each reconnection).
 *
 * Changes are handed to the listener sequentially, from a fiber of their own, so a slow listener (or one
 * that calls the gateway) never blocks the connection reader.
 *
 * @internal
 */
final class EntitySubscription implements SubscriptionInterface
{
    /** WebSocket message id of the live subscription, null while not subscribed. */
    public ?int $wsId = null;

    /** True until the first event after (re)subscribing has been decoded. */
    public bool $awaitingInitial = true;

    /** @var array<string, EntityState> */
    public array $cache = [];

    private bool $active = true;
    private bool $draining = false;

    /** @var list<EntityStateChange> */
    private array $queue = [];

    /**
     * @param list<string>                      $entityIds
     * @param \Closure(EntityStateChange): void $listener
     * @param \Closure(self): void              $onUnsubscribe
     */
    public function __construct(
        public readonly array $entityIds,
        private readonly \Closure $listener,
        private readonly \Closure $onUnsubscribe,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Stops delivering changes without telling Home Assistant (used when the connection is gone for good).
     */
    public function deactivate(): void
    {
        $this->active = false;
        $this->queue = [];
    }

    public function unsubscribe(): void
    {
        if (!$this->active) {
            return;
        }
        $this->deactivate();
        ($this->onUnsubscribe)($this);
    }

    /**
     * @param list<EntityStateChange> $changes
     */
    public function dispatch(array $changes): void
    {
        if (!$this->active || [] === $changes) {
            return;
        }
        array_push($this->queue, ...$changes);
        if (!$this->draining) {
            $this->draining = true;
            EventLoop::queue($this->drain(...));
        }
    }

    private function drain(): void
    {
        while ($this->active && null !== ($change = array_shift($this->queue))) {
            try {
                ($this->listener)($change);
            } catch (\Throwable $e) {
                $this->logger->error('Entity subscription listener failed for {entity}: {message}', [
                    'entity' => $change->entityId,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
        $this->draining = false;
    }
}
