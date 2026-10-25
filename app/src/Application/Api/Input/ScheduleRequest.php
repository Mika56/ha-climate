<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use App\Domain\Climate\HvacMode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A schedule (`ScheduleInput` of the contract). `ref` is the local id rooms use to point at the schedule in a
 * backup; it is ignored elsewhere.
 */
final readonly class ScheduleRequest
{
    /**
     * @param list<SlotRequest> $slots
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        public string $name,
        #[Assert\Choice(choices: [HvacMode::Heat, HvacMode::Cool])]
        public HvacMode $mode,
        #[Assert\Count(min: 1)]
        #[Assert\Valid]
        public array $slots,
        public ?string $ref = null,
    ) {
    }
}
