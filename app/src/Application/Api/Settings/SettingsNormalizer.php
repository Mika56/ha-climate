<?php

declare(strict_types=1);

namespace App\Application\Api\Settings;

use App\Application\Api\ApiTime;
use App\Infrastructure\Persistence\Entity\GlobalSettings;
use App\Infrastructure\Persistence\Repository\AuditEntryRepository;

/**
 * The global settings as the API shows them.
 */
final readonly class SettingsNormalizer
{
    public function __construct(
        private ApiTime $time,
        private AuditEntryRepository $audit,
    ) {
    }

    /**
     * @param bool $withAuditCount whether to add the read-only `auditEntryCount` (a backup does not keep it)
     *
     * @return array<string, mixed>
     */
    public function normalize(GlobalSettings $settings, bool $withAuditCount = true): array
    {
        $normalized = [
            'season' => $settings->getSeason()->value,
            'paused' => $settings->isPaused(),
            'trackedPersons' => $settings->getTrackedPersons(),
            'houseEmptyDelaySeconds' => $settings->getHouseEmptyDelaySeconds(),
            'manualOverrideMinutes' => $settings->getManualOverrideMinutes(),
            'vacation' => $this->vacation($settings),
            'weatherEntity' => $settings->getWeatherEntity(),
            'uiLocale' => $settings->getUiLocale(),
            'notificationLocale' => $settings->getNotificationLocale(),
            'auditRetentionDays' => $settings->getAuditRetentionDays(),
        ];

        return $withAuditCount ? $normalized + ['auditEntryCount' => $this->audit->count([])] : $normalized;
    }

    /**
     * @return array{enabled: bool, startAt: string|null, returnAt: string|null, offDuringVacation: bool}
     */
    public function vacation(GlobalSettings $settings): array
    {
        return [
            'enabled' => $settings->isVacationEnabled(),
            'startAt' => $this->time->format($settings->getVacationStartAt()),
            'returnAt' => $this->time->format($settings->getVacationReturnAt()),
            'offDuringVacation' => $settings->isOffDuringVacation(),
        ];
    }
}
