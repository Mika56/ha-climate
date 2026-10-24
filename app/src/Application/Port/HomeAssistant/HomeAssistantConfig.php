<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

/**
 * Subset of the Home Assistant core configuration the add-on cares about.
 */
final readonly class HomeAssistantConfig
{
    public function __construct(
        public string $language,
        public string $timeZone,
        public string $temperatureUnit,
        public string $version,
    ) {
    }
}
