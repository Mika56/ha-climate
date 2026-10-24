<?php

declare(strict_types=1);

namespace App\Application\Engine\Opening;

use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\Facts\RoomFactsBuilder;
use App\Application\Engine\State\TimeAnchorResolver;
use App\Domain\Climate\Temperature;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\TargetResolver;

/**
 * The setpoint a room would be given if its windows were closed: what the rule chain decides once the
 * opening is left out. That is the target the open window prevents from being applied.
 */
final readonly class PendingSetpointResolver
{
    public function __construct(
        private TargetResolver $resolver,
        private RoomFactsBuilder $factsBuilder,
        private TimeAnchorResolver $anchors,
    ) {
    }

    /**
     * @return Temperature|null null when the room would be off anyway (season off, no device for the mode...)
     */
    public function resolve(RoomConfiguration $room, EngineSnapshot $snapshot): ?Temperature
    {
        $season = $snapshot->configuration->season;
        $decision = $this->resolver->resolve(new RoomEvaluationContext(
            $snapshot->now,
            $season,
            $room,
            $this->factsBuilder->build($room, $snapshot)->withOpeningOpenSince(null),
            $snapshot->configuration->scheduleFor($room, $season->activeHvacMode()),
            $this->anchors,
            $snapshot->configuration->policy,
        ));

        return $decision->target;
    }
}
