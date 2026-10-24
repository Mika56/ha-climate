<?php

declare(strict_types=1);

namespace App\Domain\Climate;

enum HvacMode: string
{
    case Heat = 'heat';
    case Cool = 'cool';
    case Off = 'off';
}
