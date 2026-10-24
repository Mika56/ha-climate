<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Domain\Exception\InvalidValueException;
use App\Domain\HomeAssistant\EntityId;

/**
 * Slot start tied to an HA time entity (e.g. bedtime), shifted by an offset.
 * When the entity has no usable time, the fallback minute is used as is.
 */
final readonly class AnchoredSlotStart implements SlotStart
{
    public const int MAX_OFFSET_MINUTES = 720;

    /** @var int<0, 1439> */
    private int $fallbackMinuteOfDay;

    public function __construct(
        public EntityId $anchor,
        public int $offsetMinutes,
        int $fallbackMinuteOfDay,
    ) {
        if (abs($offsetMinutes) > self::MAX_OFFSET_MINUTES) {
            throw new InvalidValueException(\sprintf('Anchor offset must be between -%1$d and %1$d minutes, got %2$d.', self::MAX_OFFSET_MINUTES, $offsetMinutes));
        }

        $this->fallbackMinuteOfDay = MinuteOfDay::assert($fallbackMinuteOfDay);
    }

    public function minuteOfDay(TimeAnchorResolverInterface $anchors): int
    {
        $anchored = $anchors->minuteOfDay($this->anchor);

        if (null === $anchored) {
            return $this->fallbackMinuteOfDay;
        }

        return MinuteOfDay::clamp($anchored + $this->offsetMinutes);
    }
}
