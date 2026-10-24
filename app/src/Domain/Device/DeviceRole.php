<?php

declare(strict_types=1);

namespace App\Domain\Device;

use App\Domain\Climate\HvacMode;

enum DeviceRole: string
{
    case Heating = 'heating';
    case Cooling = 'cooling';
    case Reversible = 'reversible';

    public function canOperateIn(HvacMode $mode): bool
    {
        return match ($this) {
            self::Heating => HvacMode::Heat === $mode,
            self::Cooling => HvacMode::Cool === $mode,
            self::Reversible => HvacMode::Heat === $mode || HvacMode::Cool === $mode,
        };
    }
}
