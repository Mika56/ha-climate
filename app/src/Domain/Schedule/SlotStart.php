<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

interface SlotStart
{
    /**
     * @return int<0, 1439>
     */
    public function minuteOfDay(TimeAnchorResolverInterface $anchors): int;
}
