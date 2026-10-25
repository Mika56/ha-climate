<?php

declare(strict_types=1);

namespace App\Application\Engine\Override;

/**
 * A boost or an override cannot be created or cancelled; `reason` is a stable machine-readable code
 * (`room_not_found`, `no_active_mode`, `no_schedule`, `no_end`, `no_active_override`, `paused`).
 */
final class OverrideRejectedException extends \RuntimeException
{
    public const string ROOM_NOT_FOUND = 'room_not_found';
    public const string NO_ACTIVE_MODE = 'no_active_mode';
    public const string NO_SCHEDULE = 'no_schedule';
    public const string NO_END = 'no_end';
    public const string NO_ACTIVE_OVERRIDE = 'no_active_override';
    public const string PAUSED = 'paused';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
