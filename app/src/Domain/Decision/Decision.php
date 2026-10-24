<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;
use App\Domain\Exception\InvalidValueException;

/**
 * Outcome of the resolution for a room: mode Off carries no target, any other mode requires one.
 */
final readonly class Decision
{
    public function __construct(
        public HvacMode $mode,
        public ?Temperature $target,
        public Reason $reason,
    ) {
        if (HvacMode::Off === $mode && null !== $target) {
            throw new InvalidValueException('An "off" decision must not have a target temperature.');
        }

        if (HvacMode::Off !== $mode && null === $target) {
            throw new InvalidValueException('A heat or cool decision requires a target temperature.');
        }
    }

    public static function off(Reason $reason): self
    {
        return new self(HvacMode::Off, null, $reason);
    }

    public static function heat(Temperature $target, Reason $reason): self
    {
        return new self(HvacMode::Heat, $target, $reason);
    }

    public static function cool(Temperature $target, Reason $reason): self
    {
        return new self(HvacMode::Cool, $target, $reason);
    }

    public function isOff(): bool
    {
        return HvacMode::Off === $this->mode;
    }
}
