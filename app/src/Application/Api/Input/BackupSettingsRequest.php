<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use App\Domain\Climate\Season;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The settings of a backup (`BackupSettings` of the contract): the settings of the API, except that the season
 * may also be `off`, the way a version 1 backup spells "HA Climate does nothing". `off` is read as winter and
 * paused; a backup without `paused` (version 1) is not paused.
 */
final readonly class BackupSettingsRequest
{
    public const string LEGACY_OFF = 'off';

    /**
     * @param list<string> $trackedPersons
     */
    public function __construct(
        #[Assert\Choice(choices: ['winter', 'summer', self::LEGACY_OFF])]
        public string $season,
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
        public ?bool $paused = null,
        #[Assert\Regex(pattern: EntityIdPattern::ENTITY_ID)]
        public ?string $weatherEntity = null,
        #[Assert\Choice(choices: ['en', 'fr'])]
        public ?string $uiLocale = null,
        #[Assert\Choice(choices: ['en', 'fr'])]
        public ?string $notificationLocale = null,
    ) {
    }

    public function toSettingsRequest(): SettingsRequest
    {
        $legacyOff = self::LEGACY_OFF === $this->season;

        return new SettingsRequest(
            $legacyOff ? Season::Winter : Season::from($this->season),
            $this->trackedPersons,
            $this->houseEmptyDelaySeconds,
            $this->manualOverrideMinutes,
            $this->vacation,
            $this->auditRetentionDays,
            $legacyOff || ($this->paused ?? false),
            $this->weatherEntity,
            $this->uiLocale,
            $this->notificationLocale,
        );
    }
}
