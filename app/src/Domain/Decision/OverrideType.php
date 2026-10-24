<?php

declare(strict_types=1);

namespace App\Domain\Decision;

enum OverrideType: string
{
    case Boost = 'boost';
    case Manual = 'manual';
}
