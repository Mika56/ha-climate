<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

/**
 * Home Assistant answered a command with `success: false`.
 */
final class CommandFailedException extends HomeAssistantException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct(\sprintf('%s: %s', $errorCode, $message));
    }
}
