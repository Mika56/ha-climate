<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

/**
 * The connection is not available (or dropped while a command was pending).
 */
final class ConnectionLostException extends HomeAssistantException
{
}
