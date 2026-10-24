<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Enum;

enum DeviceRole: string
{
    case Heating = 'heating';
    case Cooling = 'cooling';
    case Reversible = 'reversible';
}
