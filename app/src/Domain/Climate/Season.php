<?php

declare(strict_types=1);

namespace App\Domain\Climate;

/**
 * Global season selector: it determines the single active HVAC mode,
 * so heating and cooling can never run at the same time.
 */
enum Season: string
{
    case Winter = 'winter';
    case Summer = 'summer';
    case Off = 'off';

    public function activeHvacMode(): HvacMode
    {
        return match ($this) {
            self::Winter => HvacMode::Heat,
            self::Summer => HvacMode::Cool,
            self::Off => HvacMode::Off,
        };
    }
}
