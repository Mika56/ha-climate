<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

/**
 * Home Assistant sent a payload that does not have the expected shape.
 */
final class InvalidPayloadException extends HomeAssistantException
{
}
