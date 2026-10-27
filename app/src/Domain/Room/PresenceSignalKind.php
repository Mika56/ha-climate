<?php

declare(strict_types=1);

namespace App\Domain\Room;

/**
 * How an entity tells that somebody is in a room.
 */
enum PresenceSignalKind: string
{
    /** Active while the entity is on (playing or paused for a media player). */
    case Active = 'active';

    /** Active while the numeric state is strictly above a threshold (a power sensor). */
    case Above = 'above';

    /** Active while the state equals a given value (a room-presence sensor such as Bermuda or ESPresense). */
    case Equals = 'equals';
}
