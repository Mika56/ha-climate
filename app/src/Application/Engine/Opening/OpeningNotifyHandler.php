<?php

declare(strict_types=1);

namespace App\Application\Engine\Opening;

use App\Application\Engine\Alert\AlertService;
use App\Application\Engine\EngineSnapshotFactory;
use App\Application\Engine\Notification\NotificationService;
use App\Application\Engine\State\StateStore;
use App\Application\Engine\Timer\TimerHandlerInterface;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\Timer;
use App\Infrastructure\Persistence\Enum\NotificationType;

/**
 * A window has been open for the room's notify delay: opens the room's opening alert and sends the
 * `window_open` notification, then `setpoint_not_applicable` with the target the schedule would have applied.
 * The alert row remembers the notification, so a restart never repeats it.
 */
final readonly class OpeningNotifyHandler implements TimerHandlerInterface
{
    public function __construct(
        private EngineSnapshotFactory $snapshots,
        private OpeningObserver $observer,
        private PendingSetpointResolver $setpoints,
        private AlertService $alerts,
        private NotificationService $notifications,
        private StateStore $states,
    ) {
    }

    public function type(): string
    {
        return OpeningNotificationScheduler::TIMER_TYPE;
    }

    public function handle(Timer $timer): void
    {
        $roomId = $timer->getRoomId();
        if (null === $roomId) {
            return;
        }

        $id = new RoomId($roomId->toRfc4122());
        $snapshot = $this->snapshots->create();
        $room = $snapshot->configuration->room($id);
        $since = null === $room ? null : $this->observer->openSince($room, $this->states);
        if (null === $room || null === $since) {
            return; // Closed or removed in the meantime.
        }

        $dueAt = $since->modify(\sprintf('+%d seconds', $room->windowNotifyDelay));
        if ($snapshot->now < $dueAt) {
            return; // The window closed and opened again: the scheduler has armed the right instant.
        }

        $raised = $this->alerts->raise(
            OpeningNotificationScheduler::ALERT_KEY_PREFIX.$room->id,
            'opening_open',
            NotificationType::WindowOpen,
            ['room' => $room->name, 'minutes' => intdiv(max(0, $snapshot->now->getTimestamp() - $since->getTimestamp()), 60)],
            null,
            $room->id,
        );
        if (!$raised->opened) {
            return;
        }

        $setpoint = $this->setpoints->resolve($room, $snapshot);
        if (null !== $setpoint) {
            $this->notifications->notify(NotificationType::SetpointNotApplicable, ['room' => $room->name, 'target' => $setpoint->toFloat()], $room->id);
        }
    }
}
