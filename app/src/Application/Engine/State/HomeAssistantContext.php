<?php

declare(strict_types=1);

namespace App\Application\Engine\State;

use App\Application\Port\HomeAssistant\HomeAssistantConfig;

/**
 * Facts about the Home Assistant instance learnt at connection time: the time zone in which schedules are
 * expressed and the language used for server-side texts. Defaults apply until {@see update()} is called.
 */
final class HomeAssistantContext
{
    private \DateTimeZone $timeZone;
    private string $language = 'en';

    public function __construct()
    {
        $this->timeZone = new \DateTimeZone('UTC');
    }

    public function update(HomeAssistantConfig $config): void
    {
        $this->language = '' === $config->language ? 'en' : $config->language;

        try {
            $this->timeZone = new \DateTimeZone($config->timeZone);
        } catch (\Exception) {
            $this->timeZone = new \DateTimeZone('UTC');
        }
    }

    public function timeZone(): \DateTimeZone
    {
        return $this->timeZone;
    }

    public function language(): string
    {
        return $this->language;
    }
}
