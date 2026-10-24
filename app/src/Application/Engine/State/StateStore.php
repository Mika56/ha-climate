<?php

declare(strict_types=1);

namespace App\Application\Engine\State;

use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Domain\HomeAssistant\EntityId;

/**
 * In-memory cache of the last known state of every entity the engine watches.
 */
final class StateStore
{
    /** @var array<string, EntityState> */
    private array $states = [];

    /**
     * Applies a change reported by Home Assistant and tells whether it brought anything new: the same
     * state, attributes and context as already known (typically the initial burst of a subscription made
     * after the states were loaded) is not a change and must not trigger an evaluation.
     */
    public function apply(EntityStateChange $change): bool
    {
        $known = $this->states[$change->entityId] ?? null;
        $new = $change->new;

        if (null === $new) {
            unset($this->states[$change->entityId]);

            return null !== $known;
        }

        $this->states[$change->entityId] = $new;

        return null === $known
            || $known->state !== $new->state
            || $known->attributes !== $new->attributes
            || $known->context->id !== $new->context->id;
    }

    /**
     * Replaces the whole content, keeping only the entities for which $keep answers true.
     *
     * @param iterable<EntityState>         $states
     * @param (\Closure(string): bool)|null $keep
     */
    public function load(iterable $states, ?\Closure $keep = null): void
    {
        $this->states = [];
        foreach ($states as $state) {
            if (null === $keep || $keep($state->entityId)) {
                $this->states[$state->entityId] = $state;
            }
        }
    }

    /**
     * Forgets every entity that is not in the given list.
     *
     * @param list<string> $entityIds
     */
    public function retain(array $entityIds): void
    {
        $this->states = array_intersect_key($this->states, array_flip($entityIds));
    }

    public function get(string|EntityId $entityId): ?EntityState
    {
        return $this->states[(string) $entityId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function entityIds(): array
    {
        return array_keys($this->states);
    }
}
