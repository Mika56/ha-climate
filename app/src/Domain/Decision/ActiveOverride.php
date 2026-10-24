<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Climate\Temperature;
use App\Domain\Climate\TemperatureDelta;
use App\Domain\Exception\InvalidValueException;

/**
 * A running boost (scheduled target plus a delta) or manual override (absolute target, or "off" when the
 * target is null). It ends at a given instant, at the next schedule slot, or both (whichever comes first).
 *
 * Overrides are created with a concrete `endsAt` (an "until next slot" override is resolved to the start of
 * that slot when it is created, so that a restart cannot move it); `untilNextSlot` only remembers how it was
 * asked for. The rule reads `endsAt` alone.
 */
final readonly class ActiveOverride
{
    public function __construct(
        public OverrideType $type,
        public ?TemperatureDelta $delta,
        public ?Temperature $target,
        public ?\DateTimeImmutable $endsAt,
        public bool $untilNextSlot,
    ) {
        if (OverrideType::Boost === $type && (null === $delta || null !== $target)) {
            throw new InvalidValueException('A boost requires a delta and no absolute target.');
        }

        if (OverrideType::Manual === $type && null !== $delta) {
            throw new InvalidValueException('A manual override has no delta.');
        }
    }

    public static function boost(TemperatureDelta $delta, ?\DateTimeImmutable $endsAt = null, bool $untilNextSlot = false): self
    {
        return new self(OverrideType::Boost, $delta, null, $endsAt, $untilNextSlot);
    }

    public static function manual(Temperature $target, ?\DateTimeImmutable $endsAt = null, bool $untilNextSlot = false): self
    {
        return new self(OverrideType::Manual, null, $target, $endsAt, $untilNextSlot);
    }

    /**
     * A manual override that switches the room off.
     */
    public static function manualOff(?\DateTimeImmutable $endsAt = null, bool $untilNextSlot = false): self
    {
        return new self(OverrideType::Manual, null, null, $endsAt, $untilNextSlot);
    }

    public function isManualOff(): bool
    {
        return OverrideType::Manual === $this->type && null === $this->target;
    }

    /**
     * Whether the override is over at $now (it ends exactly at `endsAt`).
     */
    public function hasEndedAt(\DateTimeImmutable $now): bool
    {
        return null !== $this->endsAt && $this->endsAt <= $now;
    }
}
