<?php

declare(strict_types=1);

namespace App\Application\Engine\Alert;

use App\Application\Engine\Lifecycle\EnginePauseChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Pausing dismisses the persistent notifications HA Climate created (the alerts stay open); resuming sends the
 * notifications that were held back while paused and recreates the persistent ones.
 */
#[AsEventListener]
final readonly class AlertPauseSubscriber
{
    public function __construct(private AlertService $alerts)
    {
    }

    public function __invoke(EnginePauseChanged $event): void
    {
        if ($event->paused) {
            $this->alerts->dismissPersistentNotifications();
        } else {
            $this->alerts->deliverPending();
        }
    }
}
