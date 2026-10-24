<?php

declare(strict_types=1);

namespace App\Application\Engine\Opening;

use App\Application\Engine\Alert\AlertService;
use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Engine\Lifecycle\EngineStateSynchronized;
use App\Application\Engine\State\EntityStateChanged;
use App\Application\Engine\State\StateStore;
use App\Application\Engine\Timer\TimerScheduler;
use App\Domain\Decision\RoomConfiguration;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps one persisted timer per room with an open window or door, due when the window has been open for the
 * room's notify delay; closing cancels it and resolves the room's opening alert. A window found open at start
 * (or after a reload) is picked up like any other, and one already notified before a restart is not armed
 * again, so it is never notified twice.
 */
#[AsEventListener(event: EntityStateChanged::class, method: 'onStateChanged')]
#[AsEventListener(event: EngineStateSynchronized::class, method: 'onSynchronized')]
final readonly class OpeningNotificationScheduler
{
    public const string TIMER_TYPE = 'opening_notify';
    public const string TIMER_KEY_PREFIX = 'opening.notify.';
    public const string ALERT_KEY_PREFIX = 'opening.';

    public function __construct(
        private ConfigurationRepository $configuration,
        private StateStore $states,
        private OpeningObserver $observer,
        private TimerScheduler $timers,
        private AlertService $alerts,
    ) {
    }

    public function onStateChanged(EntityStateChanged $event): void
    {
        $entityId = $event->change->entityId;
        foreach ($this->configuration->rooms() as $room) {
            foreach ($room->openings as $opening) {
                if ((string) $opening === $entityId) {
                    $this->refresh($room);

                    break;
                }
            }
        }
    }

    public function onSynchronized(EngineStateSynchronized $event): void
    {
        foreach ($this->configuration->rooms() as $room) {
            $this->refresh($room);
        }
    }

    private function refresh(RoomConfiguration $room): void
    {
        if ([] === $room->openings) {
            return;
        }

        $key = self::TIMER_KEY_PREFIX.$room->id;
        $alertKey = self::ALERT_KEY_PREFIX.$room->id;
        $since = $this->observer->openSince($room, $this->states);
        if (null === $since) {
            $this->timers->cancel($key);
            $this->alerts->resolve($alertKey);

            return;
        }

        $alert = $this->alerts->find($alertKey);
        if (null !== $alert && $alert->isActive() && null !== $alert->getNotifiedAt()) {
            return; // Already notified for this opening, possibly before a restart.
        }

        $this->timers->schedule($key, $since->modify(\sprintf('+%d seconds', $room->windowNotifyDelay)), self::TIMER_TYPE, $room->id);
    }
}
