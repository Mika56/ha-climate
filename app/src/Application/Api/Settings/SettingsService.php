<?php

declare(strict_types=1);

namespace App\Application\Api\Settings;

use App\Application\Api\ConfigurationAudit;
use App\Application\Api\EngineNotifier;
use App\Application\Api\IngressUser;
use App\Application\Api\Input\PauseRequest;
use App\Application\Api\Input\SeasonRequest;
use App\Application\Api\Input\SettingsRequest;
use App\Infrastructure\Persistence\Repository\GlobalSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The global settings (season, pause, presence, vacation, weather, languages, audit retention).
 */
final readonly class SettingsService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GlobalSettingsRepository $settings,
        private SettingsNormalizer $normalizer,
        private SettingsApplier $applier,
        private ConfigurationAudit $audit,
        private EngineNotifier $engine,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->normalizer->normalize($this->settings->get());
    }

    /**
     * @return array<string, mixed>
     */
    public function update(SettingsRequest $request, ?IngressUser $user): array
    {
        $settings = $this->settings->get();
        $before = $this->normalizer->normalize($settings, false);

        $this->applier->apply($settings, $request);

        $this->audit->record($user, 'settings_updated', [], $before, $this->normalizer->normalize($settings, false));
        if ($before['paused'] !== $settings->isPaused()) {
            $this->audit->recordPause($user, $settings->isPaused());
        }
        $this->entityManager->flush();
        $this->engine->configurationChanged();

        return $this->normalizer->normalize($settings);
    }

    /**
     * @return array{season: string}
     */
    public function changeSeason(SeasonRequest $request, ?IngressUser $user): array
    {
        $settings = $this->settings->get();
        $before = $settings->getSeason();

        $settings->changeSeason($request->season);
        $this->audit->record($user, 'season_changed', ['season' => $request->season->value], ['season' => $before->value], ['season' => $request->season->value]);
        $this->entityManager->flush();
        $this->engine->configurationChanged();

        return ['season' => $settings->getSeason()->value];
    }

    /**
     * Pauses or resumes HA Climate. Asking for the state it already is in changes nothing and is not audited.
     *
     * @return array{paused: bool}
     */
    public function changePause(PauseRequest $request, ?IngressUser $user): array
    {
        $settings = $this->settings->get();
        if ($settings->isPaused() === $request->paused) {
            return ['paused' => $request->paused];
        }

        $settings->changePaused($request->paused);
        $this->audit->recordPause($user, $request->paused);
        $this->entityManager->flush();
        $this->engine->configurationChanged();

        return ['paused' => $settings->isPaused()];
    }
}
