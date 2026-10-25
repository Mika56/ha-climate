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
    private bool $known = false;
    private bool $connected = false;
    private ?string $version = null;

    public function __construct()
    {
        $this->timeZone = new \DateTimeZone('UTC');
    }

    public function update(HomeAssistantConfig $config): void
    {
        $this->known = true;
        $this->connected = true;
        $this->version = '' === $config->version ? null : $config->version;
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

    /**
     * Whether the configuration of Home Assistant was read at least once (language, time zone and version are
     * only meaningful from then on).
     */
    public function isKnown(): bool
    {
        return $this->known;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function connectionLost(): void
    {
        $this->connected = false;
    }

    public function connectionRestored(): void
    {
        $this->connected = true;
    }
}
