<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Domain\HomeAssistant\EntityId;

interface TimeAnchorResolverInterface
{
    /**
     * Current time of the anchor entity (an input_datetime) as a minute of the day,
     * or null when it is unknown or unavailable.
     */
    public function minuteOfDay(EntityId $anchor): ?int;
}
