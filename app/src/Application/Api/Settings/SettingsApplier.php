<?php

declare(strict_types=1);

namespace App\Application\Api\Settings;

use App\Application\Api\Input\SettingsRequest;
use App\Infrastructure\Persistence\Entity\GlobalSettings;

/**
 * Makes the settings entity match a validated {@see SettingsRequest}.
 */
final readonly class SettingsApplier
{
    public function apply(GlobalSettings $settings, SettingsRequest $request): void
    {
        $settings->changeSeason($request->season);
        if (null !== $request->paused) {
            $settings->changePaused($request->paused);
        }
        $settings->configurePresence($request->houseEmptyDelaySeconds, $request->trackedPersons);
        $settings->changeManualOverrideMinutes($request->manualOverrideMinutes);
        $settings->configureVacation($request->vacation->enabled, $request->vacation->startAt, $request->vacation->returnAt, $request->vacation->offDuringVacation);
        $settings->changeWeatherEntity($request->weatherEntity);
        $settings->configureLocales($request->uiLocale, $request->notificationLocale);
        $settings->changeAuditRetentionDays($request->auditRetentionDays);
    }
}
