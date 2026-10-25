<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Infrastructure\Persistence\Repository\GlobalSettingsRepository;
use App\Infrastructure\Persistence\Repository\UserPreferenceRepository;

/**
 * The language of the texts the server writes for a request (validation messages, audit messages): the
 * preference of the Home Assistant user, else the default of the settings, else the browser's, else English.
 */
final readonly class RequestLocale
{
    public const string DEFAULT = 'en';

    /** @var list<string> */
    public const array SUPPORTED = ['en', 'fr'];

    public function __construct(
        private UserPreferenceRepository $preferences,
        private GlobalSettingsRepository $settings,
    ) {
    }

    /**
     * @param string|null $browserLocale the best supported language of the Accept-Language header, if any
     */
    public function resolve(?IngressUser $user, ?string $browserLocale): string
    {
        $candidates = [
            null === $user ? null : $this->preferences->findOneByHaUserId($user->id)?->getLocale(),
            $this->settings->get()->getUiLocale(),
            $browserLocale,
        ];

        foreach ($candidates as $candidate) {
            if (null !== $candidate && \in_array($candidate, self::SUPPORTED, true)) {
                return $candidate;
            }
        }

        return self::DEFAULT;
    }
}
