<?php

declare(strict_types=1);

namespace App\Application\Engine\Alert;

use App\Domain\Room\RoomId;

/**
 * An alert concerning a room was opened or resolved: the room status shows its active alerts, so it must be
 * published again.
 */
final readonly class AlertsChanged
{
    public function __construct(public RoomId $room)
    {
    }
}
