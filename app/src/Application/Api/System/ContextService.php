<?php

declare(strict_types=1);

namespace App\Application\Api\System;

use App\Application\Api\HaTimeZone;
use App\Application\Api\IngressUser;
use App\Application\Api\RequestLocale;
use App\Infrastructure\Engine\EngineClient;
use App\Infrastructure\Engine\EngineUnavailableException;
use App\Infrastructure\Persistence\Enum\ThemePreference;
use App\Infrastructure\Persistence\Repository\UserPreferenceRepository;

/**
 * Everything the front end needs at boot: who the user is, the languages, their preferences and what the engine
 * knows of Home Assistant. An engine that does not answer is reported (`running: false`), not an error.
 */
final readonly class ContextService
{
    public function __construct(
        private EngineClient $engine,
        private HaTimeZone $timeZone,
        private UserPreferenceRepository $preferences,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(?IngressUser $user): array
    {
        try {
            $context = $this->engine->context();
        } catch (EngineUnavailableException) {
            $context = null;
        }

        $timeZone = self::text($context['haTimeZone'] ?? null);
        $this->timeZone->remember($timeZone);

        return [
            'user' => null === $user ? null : ['id' => $user->id, 'name' => $user->name, 'displayName' => $user->displayName],
            'supportedLocales' => RequestLocale::SUPPORTED,
            'haLanguage' => self::text($context['haLanguage'] ?? null),
            'haTimeZone' => $timeZone,
            'haVersion' => self::text($context['haVersion'] ?? null),
            'preferences' => $this->userPreferences($user),
            'engine' => ['running' => null !== $context, 'connected' => true === ($context['connected'] ?? false)],
        ];
    }

    /**
     * @return array{locale: string|null, theme: string}
     */
    public function userPreferences(?IngressUser $user): array
    {
        $stored = null === $user ? null : $this->preferences->findOneByHaUserId($user->id);

        return ['locale' => $stored?->getLocale(), 'theme' => ($stored?->getTheme() ?? ThemePreference::Auto)->value];
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
