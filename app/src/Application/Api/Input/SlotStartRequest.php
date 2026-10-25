<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Start of a slot: `fixed` (a minute of the day) or `anchored` (an `input_datetime` plus an offset, with a
 * fallback minute used while the entity gives no time).
 */
final readonly class SlotStartRequest
{
    public const string FIXED = 'fixed';
    public const string ANCHORED = 'anchored';

    public function __construct(
        #[Assert\Choice(choices: [self::FIXED, self::ANCHORED])]
        public string $type,
        #[Assert\Range(min: 0, max: 1439)]
        public ?int $minuteOfDay = null,
        #[Assert\Regex(pattern: EntityIdPattern::ENTITY_ID)]
        public ?string $entityId = null,
        #[Assert\Range(min: -720, max: 720)]
        public int $offsetMinutes = 0,
        #[Assert\Range(min: 0, max: 1439)]
        public ?int $fallbackMinuteOfDay = null,
    ) {
    }

    #[Assert\Callback]
    public function validateFieldsOfTheType(ExecutionContextInterface $context): void
    {
        $required = self::FIXED === $this->type
            ? ['minuteOfDay' => $this->minuteOfDay]
            : ['entityId' => $this->entityId, 'fallbackMinuteOfDay' => $this->fallbackMinuteOfDay];

        foreach ($required as $field => $value) {
            if (null === $value && \in_array($this->type, [self::FIXED, self::ANCHORED], true)) {
                $context->buildViolation('This value should not be blank.')->atPath($field)->addViolation();
            }
        }
    }
}
