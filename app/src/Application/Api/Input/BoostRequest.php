<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class BoostRequest
{
    public function __construct(
        #[Assert\Range(min: 0.5, max: 5)]
        public float $delta,
        #[Assert\Range(min: 1, max: 1440)]
        public ?int $durationMinutes = null,
        public bool $untilNextSlot = false,
    ) {
    }

    #[Assert\Callback]
    public function validateHasAnEnd(ExecutionContextInterface $context): void
    {
        if (null === $this->durationMinutes && !$this->untilNextSlot) {
            $context->buildViolation('This value should not be blank.')->atPath('durationMinutes')->addViolation();
        }
        if (0.0 !== fmod($this->delta * 2, 1.0)) {
            $context->buildViolation('This value is not valid.')->atPath('delta')->addViolation();
        }
    }
}
