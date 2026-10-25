<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Domain\Climate\Season;
use App\Infrastructure\Persistence\Repository\GlobalSettingsRepository;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton row (id is always {@see self::SINGLETON_ID}).
 */
#[ORM\Entity(repositoryClass: GlobalSettingsRepository::class)]
#[ORM\Table(name: 'global_settings')]
final class GlobalSettings
{
    public const int SINGLETON_ID = 1;
    public const int CURRENT_SCHEMA_VERSION = 1;

    // smallint: an "integer" primary key is a SQLite rowid alias that DBAL introspects as auto-increment.
    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(type: 'string', length: 8, enumType: Season::class)]
    private Season $season = Season::Winter;

    /**
     * While paused HA Climate observes and decides but sends nothing to Home Assistant.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $paused = false;

    #[ORM\Column(type: 'integer')]
    private int $houseEmptyDelaySeconds = 3600;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $trackedPersons = [];

    #[ORM\Column(type: 'integer')]
    private int $manualOverrideMinutes = 120;

    #[ORM\Column(type: 'boolean')]
    private bool $vacationEnabled = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $vacationStartAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $vacationReturnAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $offDuringVacation = true;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $weatherEntity = null;

    #[ORM\Column(type: 'string', length: 8, nullable: true)]
    private ?string $uiLocale = null;

    #[ORM\Column(type: 'string', length: 8, nullable: true)]
    private ?string $notificationLocale = null;

    #[ORM\Column(type: 'integer')]
    private int $auditRetentionDays = 90;

    #[ORM\Column(type: 'integer')]
    private int $schemaVersion = self::CURRENT_SCHEMA_VERSION;

    public function getId(): int
    {
        return $this->id;
    }

    public function getSeason(): Season
    {
        return $this->season;
    }

    public function changeSeason(Season $season): void
    {
        $this->season = $season;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function changePaused(bool $paused): void
    {
        $this->paused = $paused;
    }

    public function getHouseEmptyDelaySeconds(): int
    {
        return $this->houseEmptyDelaySeconds;
    }

    /**
     * @return list<string>
     */
    public function getTrackedPersons(): array
    {
        return $this->trackedPersons;
    }

    /**
     * @param list<string> $trackedPersons
     */
    public function configurePresence(int $houseEmptyDelaySeconds, array $trackedPersons): void
    {
        $this->houseEmptyDelaySeconds = Guard::nonNegativeInt($houseEmptyDelaySeconds, 'houseEmptyDelaySeconds');
        $this->trackedPersons = Guard::stringList($trackedPersons, 'person');
    }

    public function getManualOverrideMinutes(): int
    {
        return $this->manualOverrideMinutes;
    }

    public function changeManualOverrideMinutes(int $minutes): void
    {
        $this->manualOverrideMinutes = Guard::positiveInt($minutes, 'manualOverrideMinutes');
    }

    public function isVacationEnabled(): bool
    {
        return $this->vacationEnabled;
    }

    public function getVacationStartAt(): ?\DateTimeImmutable
    {
        return $this->vacationStartAt;
    }

    public function getVacationReturnAt(): ?\DateTimeImmutable
    {
        return $this->vacationReturnAt;
    }

    public function isOffDuringVacation(): bool
    {
        return $this->offDuringVacation;
    }

    public function configureVacation(bool $enabled, ?\DateTimeImmutable $startAt, ?\DateTimeImmutable $returnAt, bool $offDuringVacation): void
    {
        if ($enabled && null === $returnAt) {
            throw new \InvalidArgumentException('An enabled vacation requires a return date.');
        }
        if (null !== $startAt && null !== $returnAt && $returnAt <= $startAt) {
            throw new \InvalidArgumentException('The vacation return must be after its start.');
        }

        $this->vacationEnabled = $enabled;
        $this->vacationStartAt = $startAt;
        $this->vacationReturnAt = $returnAt;
        $this->offDuringVacation = $offDuringVacation;
    }

    public function getWeatherEntity(): ?string
    {
        return $this->weatherEntity;
    }

    public function changeWeatherEntity(?string $weatherEntity): void
    {
        $this->weatherEntity = Guard::nullableNotBlank($weatherEntity, 'weatherEntity');
    }

    public function getUiLocale(): ?string
    {
        return $this->uiLocale;
    }

    public function getNotificationLocale(): ?string
    {
        return $this->notificationLocale;
    }

    /**
     * Null means "automatic" (follow Home Assistant).
     */
    public function configureLocales(?string $uiLocale, ?string $notificationLocale): void
    {
        $this->uiLocale = Guard::nullableNotBlank($uiLocale, 'uiLocale');
        $this->notificationLocale = Guard::nullableNotBlank($notificationLocale, 'notificationLocale');
    }

    public function getAuditRetentionDays(): int
    {
        return $this->auditRetentionDays;
    }

    public function changeAuditRetentionDays(int $days): void
    {
        $this->auditRetentionDays = Guard::positiveInt($days, 'auditRetentionDays');
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function upgradeSchemaVersion(int $version): void
    {
        if ($version < $this->schemaVersion) {
            throw new \InvalidArgumentException('The schema version cannot go backwards.');
        }
        $this->schemaVersion = $version;
    }
}
