<?php

declare(strict_types=1);

namespace App\Infrastructure\HomeAssistant;

/**
 * Where and how to talk to Home Assistant.
 */
final readonly class ConnectionSettings
{
    public function __construct(
        public string $url,
        #[\SensitiveParameter]
        public string $token,
        public float $commandTimeout = 10.0,
        public float $pingInterval = 30.0,
        public float $pongTimeout = 10.0,
    ) {
    }
}
