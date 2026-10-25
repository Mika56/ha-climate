<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

/**
 * Patterns shared by the request validation, mirroring the contract.
 */
interface EntityIdPattern
{
    public const string ENTITY_ID = '/^[a-z0-9_]+\.[a-z0-9_]+$/D';
    public const string PRESET_KEY = '/^[a-z0-9_]{1,32}$/D';
    public const string NOTIFY_SERVICE = '/^notify\.[a-z0-9_]+$/D';
}
