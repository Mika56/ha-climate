<?php

declare(strict_types=1);

namespace App\Domain\Climate;

/**
 * Global season selector: it determines the single active HVAC mode,
 * so heating and cooling can never run at the same time.
 *
 * There is no "off" season: stopping HA Climate altogether is the separate "paused" switch of the settings.
 */
enum Season: string
{
    case Winter = 'winter';
    case Summer = 'summer';

    public function activeHvacMode(): HvacMode
    {
        return match ($this) {
            self::Winter => HvacMode::Heat,
            self::Summer => HvacMode::Cool,
        };
    }
}
