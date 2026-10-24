<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Climate\Temperature;
use App\Domain\Climate\TemperatureDelta;
use App\Domain\Exception\InvalidValueException;

/**
 * A running boost (scheduled target plus a delta) or manual override (absolute target).
 * It ends at a given instant, at the next schedule slot, or both (whichever comes first).
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

        if (OverrideType::Manual === $type && (null === $target || null !== $delta)) {
            throw new InvalidValueException('A manual override requires an absolute target and no delta.');
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
}
