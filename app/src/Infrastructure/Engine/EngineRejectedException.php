<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

/**
 * The engine was reached and refused the request (unknown room, invalid input, nothing to cancel...).
 * `error` is the stable code of the engine's JSON answer (`room_not_found`, `invalid_request`...).
 */
final class EngineRejectedException extends \RuntimeException
{
    public function __construct(public readonly int $statusCode, public readonly string $error, string $message)
    {
        parent::__construct($message);
    }
}
