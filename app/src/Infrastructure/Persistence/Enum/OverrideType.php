<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Enum;

enum OverrideType: string
{
    case Boost = 'boost';
    case Manual = 'manual';
}
