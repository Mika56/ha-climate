<?php

declare(strict_types=1);

namespace App\Application\Engine\State;

use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\HomeAssistantGatewayInterface;

/**
 * Brings the state store in line with Home Assistant using `get_states`, keeping only the entities the
 * engine watches.
 */
final readonly class StateSynchronizer
{
    public function __construct(
        private HomeAssistantGatewayInterface $gateway,
        private StateStore $states,
        private EntityRoomIndex $index,
        private ChangeProcessor $processor,
    ) {
    }

    /**
     * Start of the engine: fills the store without triggering anything (the startup evaluation follows).
     *
     * @return int the number of entities stored
     */
    public function loadInitial(): int
    {
        $this->states->load($this->gateway->getStates(), $this->index->tracks(...));

        return \count($this->states->entityIds());
    }

    /**
     * After a configuration reload: learns the state of entities that were not watched before, silently.
     */
    public function loadMissing(): void
    {
        foreach ($this->trackedStates() as $state) {
            if (null === $this->states->get($state->entityId)) {
                $this->states->apply(new EntityStateChange($state->entityId, null, $state));
            }
        }
    }

    /**
     * After a reconnection: replays what changed while the connection was down, so that manual changes
     * made in the meantime are noticed and the affected rooms re-evaluated.
     */
    public function catchUp(): void
    {
        $fresh = [];
        foreach ($this->trackedStates() as $state) {
            $fresh[$state->entityId] = $state;
            $this->processor->process(new EntityStateChange($state->entityId, $this->states->get($state->entityId), $state));
        }

        foreach ($this->states->entityIds() as $entityId) {
            if (!isset($fresh[$entityId])) {
                $this->processor->process(new EntityStateChange($entityId, $this->states->get($entityId), null));
            }
        }
    }

    /**
     * @return list<EntityState>
     */
    private function trackedStates(): array
    {
        return array_values(array_filter(
            $this->gateway->getStates(),
            fn (EntityState $state): bool => $this->index->tracks($state->entityId),
        ));
    }
}
