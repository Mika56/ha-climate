<?php

declare(strict_types=1);

namespace App\Application\Engine\State;

use App\Application\Engine\Command\ManualChangeDetector;
use App\Application\Engine\Evaluation\RoomEvaluator;
use App\Application\Port\HomeAssistant\EntityStateChange;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Entry point of every entity change: updates the state store, spots manual device changes, and
 * re-evaluates exactly the rooms the entity affects, then announces the change with an {@see EntityStateChanged}
 * event. Changes that bring nothing new are dropped.
 */
final readonly class ChangeProcessor
{
    public function __construct(
        private StateStore $states,
        private EntityRoomIndex $index,
        private ManualChangeDetector $manualChanges,
        private RoomEvaluator $evaluator,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function process(EntityStateChange $change): void
    {
        if (!$this->index->tracks($change->entityId)) {
            return;
        }

        $previous = $this->states->get($change->entityId);
        if (!$this->states->apply($change)) {
            return;
        }

        $this->manualChanges->inspect($change->entityId, $previous, $change->new);
        $this->evaluator->evaluate($this->index->roomsFor($change->entityId), 'state:'.$change->entityId);
        $this->dispatcher->dispatch(new EntityStateChanged($change));
    }
}
