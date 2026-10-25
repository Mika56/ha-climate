<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class VacationRequest
{
    public function __construct(
        public bool $enabled,
        public ?\DateTimeImmutable $startAt = null,
        public ?\DateTimeImmutable $returnAt = null,
        public bool $offDuringVacation = true,
    ) {
    }

    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        if ($this->enabled && null === $this->returnAt) {
            $context->buildViolation('This value should not be blank.')->atPath('returnAt')->addViolation();
        }
        if (null !== $this->startAt && null !== $this->returnAt && $this->returnAt <= $this->startAt) {
            $context->buildViolation('This value should be greater than {{ compared_value }}.')
                ->setParameter('{{ compared_value }}', 'startAt')
                ->atPath('returnAt')
                ->addViolation();
        }
    }
}
