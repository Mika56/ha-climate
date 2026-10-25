<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use App\Domain\Climate\Season;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The global settings (`Settings` of the contract); the read-only `auditEntryCount` is ignored.
 */
final readonly class SettingsRequest
{
    /**
     * @param list<string> $trackedPersons
     */
    public function __construct(
        public Season $season,
        #[Assert\All([new Assert\Regex(pattern: EntityIdPattern::ENTITY_ID)])]
        public array $trackedPersons,
        #[Assert\Range(min: 0, max: 86400)]
        public int $houseEmptyDelaySeconds,
        #[Assert\Range(min: 1, max: 1440)]
        public int $manualOverrideMinutes,
        #[Assert\Valid]
        public VacationRequest $vacation,
        #[Assert\Range(min: 1, max: 3650)]
        public int $auditRetentionDays,
        #[Assert\Regex(pattern: EntityIdPattern::ENTITY_ID)]
        public ?string $weatherEntity = null,
        #[Assert\Choice(choices: ['en', 'fr'])]
        public ?string $uiLocale = null,
        #[Assert\Choice(choices: ['en', 'fr'])]
        public ?string $notificationLocale = null,
    ) {
    }
}
