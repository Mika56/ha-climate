<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Enum;

enum SlotStartType: string
{
    case Fixed = 'fixed';
    case Anchored = 'anchored';
}
