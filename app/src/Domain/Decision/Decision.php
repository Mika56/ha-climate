<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;
use App\Domain\Exception\InvalidValueException;

/**
 * Outcome of the resolution for a room: mode Off carries no target, any other mode requires one.
 *
 * A rule may ask for the room to be evaluated again at a given instant (a delay elapsing, an override
 * ending) with withReevaluateAt(); the engine arms a timer at the earliest requested instant.
 */
final readonly class Decision
{
    public function __construct(
        public HvacMode $mode,
        public ?Temperature $target,
        public Reason $reason,
        public ?\DateTimeImmutable $reevaluateAt = null,
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

    /**
     * Returns a copy that also requests a re-evaluation at $at; the earliest requested instant is kept.
     */
    public function withReevaluateAt(\DateTimeImmutable $at): self
    {
        $earliest = null === $this->reevaluateAt || $at < $this->reevaluateAt ? $at : $this->reevaluateAt;

        return new self($this->mode, $this->target, $this->reason, $earliest);
    }
}
