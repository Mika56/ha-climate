<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Enum;

enum ActorType: string
{
    case User = 'user';
    case System = 'system';
    case Device = 'device';
    case Automation = 'automation';
}
