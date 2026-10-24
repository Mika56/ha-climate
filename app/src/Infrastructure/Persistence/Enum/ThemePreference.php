<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Enum;

enum ThemePreference: string
{
    case Auto = 'auto';
    case Light = 'light';
    case Dark = 'dark';
}
