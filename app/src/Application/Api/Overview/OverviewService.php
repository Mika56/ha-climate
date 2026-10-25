<?php

declare(strict_types=1);

namespace App\Application\Api\Overview;

use App\Application\Api\ApiTime;
use App\Application\Api\Audit\AuditEntryNormalizer;
use App\Application\Api\Settings\SettingsNormalizer;
use App\Application\Engine\Alert\AlertView;
use App\Infrastructure\Engine\EngineClient;
use App\Infrastructure\Engine\EngineUnavailableException;
use App\Infrastructure\Persistence\Entity\Alert;
use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Repository\AlertRepository;
use App\Infrastructure\Persistence\Repository\AuditEntryRepository;
use App\Infrastructure\Persistence\Repository\GlobalSettingsRepository;

/**
 * The overview page besides the live room cards: season, whether HA Climate is paused, who is home, weather, vacation, active alerts and the
 * latest decisions.
 *
 * Persons: every person Home Assistant has (read live by the engine), each flagged `tracked` when the settings
 * count it for the "house empty" rule. When the engine cannot be asked, only the tracked persons are listed, with
 * the state `unknown`, and there is no weather nor "empty since" instant.
 */
final readonly class OverviewService
{
    private const int RECENT_DECISIONS = 5;

    /** @var list<string> */
    private const array DECISION_CATEGORIES = ['decision', 'override', 'opening'];

    public function __construct(
        private GlobalSettingsRepository $settings,
        private SettingsNormalizer $settingsNormalizer,
        private AlertRepository $alerts,
        private AuditEntryRepository $audit,
        private AuditEntryNormalizer $auditNormalizer,
        private EngineClient $engine,
        private ApiTime $time,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $locale): array
    {
        $settings = $this->settings->get();

        try {
            $live = $this->engine->overview();
        } catch (EngineUnavailableException) {
            $live = [];
        }

        $weather = $live['weather'] ?? null;
        $since = $live['houseEmptySince'] ?? null;

        return [
            'season' => $settings->getSeason()->value,
            'paused' => $settings->isPaused(),
            'presence' => [
                'persons' => $this->persons($settings->getTrackedPersons(), $live['persons'] ?? []),
                'houseEmptySince' => \is_string($since) ? $since : null,
                'houseEmptyDelaySeconds' => $settings->getHouseEmptyDelaySeconds(),
            ],
            'weather' => \is_array($weather) ? $weather : null,
            'vacation' => $this->settingsNormalizer->vacation($settings),
            'alerts' => $this->activeAlerts(),
            'recentDecisions' => array_map(
                fn (AuditEntry $entry): array => $this->auditNormalizer->normalize($entry, $locale),
                $this->audit->latestInCategories(self::DECISION_CATEGORIES, self::RECENT_DECISIONS),
            ),
        ];
    }

    /**
     * Active alerts, newest first.
     *
     * @return list<AlertView>
     */
    public function activeAlerts(): array
    {
        $alerts = $this->alerts->findActive();
        usort($alerts, static fn (Alert $a, Alert $b): int => $b->getOpenedAt() <=> $a->getOpenedAt());
        $zone = $this->time->zone();

        return array_map(static fn (Alert $alert): AlertView => AlertView::fromEntity($alert, $zone), $alerts);
    }

    /**
     * @param list<string> $tracked
     *
     * @return list<array{entityId: string, name: string, state: string, tracked: bool}>
     */
    private function persons(array $tracked, mixed $live): array
    {
        $persons = [];
        foreach (\is_array($live) ? $live : [] as $person) {
            if (\is_array($person) && \is_string($person['entityId'] ?? null) && \is_string($person['state'] ?? null)) {
                $persons[$person['entityId']] = [
                    'entityId' => $person['entityId'],
                    'name' => \is_string($person['name'] ?? null) ? $person['name'] : $person['entityId'],
                    'state' => $person['state'],
                    'tracked' => \in_array($person['entityId'], $tracked, true),
                ];
            }
        }

        foreach ($tracked as $entityId) {
            $persons[$entityId] ??= ['entityId' => $entityId, 'name' => $entityId, 'state' => 'unknown', 'tracked' => true];
        }

        return array_values($persons);
    }
}
