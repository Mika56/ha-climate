<?php

declare(strict_types=1);

namespace App\Application\Engine\Override;

use App\Application\Engine\Audit\AuditLogger;
use App\Application\Engine\EngineSnapshotFactory;
use App\Application\Engine\State\TimeAnchorResolver;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;
use App\Domain\Climate\TemperatureDelta;
use App\Domain\Decision\OverrideType;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\RoomOverride;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Repository\RoomOverrideRepository;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Creates and cancels the boost / manual override of a room, and audits it. A room has at most one override:
 * a new one replaces the previous one.
 *
 * The end of an override is always resolved to a concrete instant here. "Until the next slot" becomes the
 * start of the next schedule slot as it is known now, so that restarting the engine, or the schedule
 * changing later, cannot move it. The caller re-evaluates the room afterwards (the evaluation also arms the
 * timer of the override's end).
 */
final readonly class OverrideManager
{
    public function __construct(
        private EngineSnapshotFactory $snapshots,
        private TimeAnchorResolver $anchors,
        private RoomRepository $rooms,
        private RoomOverrideRepository $overrides,
        private AuditLogger $audit,
    ) {
    }

    /**
     * Boosts the room by $delta in the active season's mode, for $durationMinutes and/or until the next
     * schedule slot (whichever comes first when both are given).
     *
     * @throws OverrideRejectedException
     */
    public function startBoost(RoomId $roomId, TemperatureDelta $delta, ?int $durationMinutes, bool $untilNextSlot, string $actor): void
    {
        $snapshot = $this->snapshots->create();
        $configuration = $snapshot->configuration;
        $room = $configuration->room($roomId);
        $entity = $this->entity($roomId);
        if (null === $room || null === $entity) {
            throw new OverrideRejectedException(OverrideRejectedException::ROOM_NOT_FOUND, 'The room does not exist.');
        }

        $mode = $configuration->season->activeHvacMode();
        if (HvacMode::Off === $mode || !$room->hasDeviceFor($mode)) {
            throw new OverrideRejectedException(OverrideRejectedException::NO_ACTIVE_MODE, 'The room has nothing to boost in the current season.');
        }

        $endsAt = null;
        if (null !== $durationMinutes) {
            $endsAt = $snapshot->now->modify(\sprintf('+%d minutes', $durationMinutes));
        }
        if ($untilNextSlot) {
            $schedule = $configuration->scheduleFor($room, $mode);
            if (null === $schedule && null === $endsAt) {
                throw new OverrideRejectedException(OverrideRejectedException::NO_SCHEDULE, 'The room has no schedule to follow until its next slot.');
            }
            if (null !== $schedule) {
                $slotStart = $schedule->nextSlotAfter($snapshot->now, $this->anchors)->start;
                $endsAt = null === $endsAt || $slotStart < $endsAt ? $slotStart : $endsAt;
            }
        }
        if (null === $endsAt) {
            throw new OverrideRejectedException(OverrideRejectedException::NO_END, 'A boost needs a duration or the until-next-slot flag.');
        }

        $this->overrides->replaceForRoom(RoomOverride::boost($entity, $mode, $delta->toFloat(), $snapshot->now, $endsAt, $untilNextSlot, $actor));
        $this->audit->log(ActorType::User, 'override', 'audit.override.boost_started', [
            'mode' => $mode->value,
            'delta' => $delta->toFloat(),
            'until' => $endsAt->format(\DATE_ATOM),
        ], $roomId, [
            'durationMinutes' => $durationMinutes,
            'untilNextSlot' => $untilNextSlot,
        ], $actor);
    }

    /**
     * Pins the room on $target (or off when null) in $mode for $minutes from $startedAt. Used when somebody
     * changed a device by hand.
     *
     * @param positive-int $minutes
     *
     * @throws OverrideRejectedException
     */
    public function startManual(RoomId $roomId, HvacMode $mode, ?Temperature $target, \DateTimeImmutable $startedAt, int $minutes, ActorType $actorType, string $actor): void
    {
        $entity = $this->entity($roomId);
        if (null === $entity) {
            throw new OverrideRejectedException(OverrideRejectedException::ROOM_NOT_FOUND, 'The room does not exist.');
        }

        $startedAt = $startedAt->setTimezone($this->snapshots->create()->now->getTimezone());
        $endsAt = $startedAt->modify(\sprintf('+%d minutes', $minutes));
        $this->overrides->replaceForRoom(RoomOverride::manual($entity, $mode, $target?->toFloat(), $startedAt, $endsAt, $actor));

        $params = ['mode' => null === $target ? 'off' : $mode->value, 'until' => $endsAt->format(\DATE_ATOM)];
        if (null !== $target) {
            $params['target'] = $target->toFloat();
        }
        $this->audit->log($actorType, 'override', 'audit.override.manual_started', $params, $roomId, ['seasonMode' => $mode->value], $actor);
    }

    /**
     * Removes the boost or manual override of the room.
     *
     * @throws OverrideRejectedException when the room does not exist or has no override in force
     */
    public function cancel(RoomId $roomId, string $actor): OverrideType
    {
        $entity = $this->entity($roomId);
        if (null === $entity) {
            throw new OverrideRejectedException(OverrideRejectedException::ROOM_NOT_FOUND, 'The room does not exist.');
        }

        $now = $this->snapshots->create()->now;
        $removed = $this->overrides->clearRoom($entity);
        $active = null;
        foreach ($removed as $override) {
            if ($override->isActiveAt($now)) {
                $active = $override;
            }
        }
        if (null === $active) {
            throw new OverrideRejectedException(OverrideRejectedException::NO_ACTIVE_OVERRIDE, 'The room has no boost or manual override in force.');
        }

        $this->audit->log(ActorType::User, 'override', 'audit.override.cancelled', ['type' => $active->getType()->value], $roomId, [], $actor);

        return $active->getType();
    }

    private function entity(RoomId $roomId): ?Room
    {
        try {
            return $this->rooms->find(Uuid::fromString((string) $roomId));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
