<?php

declare(strict_types=1);

namespace App\Application\Engine\Timer;

use App\Infrastructure\Persistence\Entity\Timer;

/**
 * Extension point (service tag `app.timer_handler`, applied automatically to implementations): reacts to
 * the persisted timers of one type when they come due.
 *
 * A handler may reschedule its own timer (same key) from {@see handle()}; otherwise the timer is deleted
 * once handled. A handler that throws is logged and its timer deleted, so a faulty timer cannot fire in a
 * loop at every restart.
 */
interface TimerHandlerInterface
{
    /**
     * The `type` of the timers this handler takes care of. Exactly one handler per type.
     */
    public function type(): string;

    public function handle(Timer $timer): void;
}
