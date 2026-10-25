<?php

declare(strict_types=1);

namespace App\Application\Api\Exception;

/**
 * The request conflicts with the current state (`preset_in_use`, `no_user`, `no_active_mode`...).
 */
final class ConflictException extends ApiException
{
    public function __construct(string $errorCode, string $message)
    {
        parent::__construct(409, $errorCode, $message);
    }
}
