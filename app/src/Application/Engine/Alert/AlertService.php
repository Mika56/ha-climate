<?php

declare(strict_types=1);

namespace App\Application\Engine\Alert;

use App\Application\Engine\Audit\AuditLogger;
use App\Application\Engine\Notification\NotificationService;
use App\Application\Engine\Notification\PersistentNotifier;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\Alert;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\AlertRepository;
use App\Infrastructure\Persistence\Repository\NotificationTypeSettingRepository;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Opens and resolves the alerts (one row per key), and keeps their notifications in step.
 *
 * Raising is idempotent: an alert that is already open is not opened again, and one whose notification was
 * delivered is not notified again, even after a restart. A persistent notification is created when the type's
 * setting asks for it, and dismissed when the alert is resolved. Alerts never change how rooms are driven.
 *
 * While HA Climate is paused alerts still open and resolve (they are displayed) but nothing is sent: the alert
 * remembers what it should notify with, and {@see deliverPending()} catches up when HA Climate resumes.
 */
final readonly class AlertService
{
    public function __construct(
        private AlertRepository $alerts,
        private RoomRepository $rooms,
        private NotificationTypeSettingRepository $types,
        private NotificationService $notifications,
        private PersistentNotifier $persistent,
        private AuditLogger $audit,
        private ClockInterface $clock,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @param array<string, scalar|null> $params the values of the notification message
     */
    public function raise(string $key, string $alertType, NotificationType $notification, array $params, ?string $entityId = null, ?RoomId $room = null): RaisedAlert
    {
        $now = $this->clock->now();
        $alert = $this->alerts->findOneByKey($key);
        $opened = false;

        if (null === $alert) {
            $alert = new Alert($key, $alertType, $now, $entityId, null === $room ? null : $this->rooms->find(Uuid::fromString((string) $room)));
            $opened = true;
        } elseif (!$alert->isActive()) {
            $alert->reopen($now);
            $opened = true;
        }

        if ($opened) {
            $this->audit->log(ActorType::System, 'alert', 'audit.alert.opened', ['key' => $key, 'type' => $alertType], $room, ['entityId' => $entityId]);
        }

        $alert->rememberNotification($notification->value, $params);
        $this->deliver($alert, $notification, $params, $room);
        $this->alerts->save($alert);

        if ($opened && null !== $room) {
            $this->dispatcher->dispatch(new AlertsChanged($room));
        }

        return new RaisedAlert($alert, $opened);
    }

    /**
     * Resolves the alert if it is open, dismissing its persistent notification; does nothing otherwise.
     */
    public function resolve(string $key): void
    {
        $alert = $this->alerts->findOneByKey($key);
        if (null === $alert || !$alert->isActive()) {
            return;
        }

        $alert->resolve($this->clock->now());
        $persistentId = $alert->getPersistentNotificationId();
        if (null !== $persistentId) {
            $this->persistent->dismiss($persistentId);
        }
        $this->alerts->save($alert);

        $room = $alert->getRoom();
        $roomId = null === $room ? null : new RoomId($room->getId()->toRfc4122());
        $this->audit->log(ActorType::System, 'alert', 'audit.alert.resolved', ['key' => $key, 'type' => $alert->getType()], $roomId);
        if (null !== $roomId) {
            $this->dispatcher->dispatch(new AlertsChanged($roomId));
        }
    }

    /**
     * HA Climate was paused: dismisses the persistent notifications it created for the active alerts (the alerts
     * stay open and visible in the interface) and forgets them, so that resuming creates them again.
     */
    public function dismissPersistentNotifications(): void
    {
        foreach ($this->alerts->findActive() as $alert) {
            $id = $alert->getPersistentNotificationId();
            if (null === $id) {
                continue;
            }

            $this->persistent->dismiss($id);
            $alert->attachPersistentNotification(null);
            $this->alerts->save($alert);
        }
    }

    /**
     * HA Climate resumed: sends what was held back for the active alerts, the notification that never went out and
     * the persistent notification that was dismissed or never created (each respecting the settings of its type).
     */
    public function deliverPending(): void
    {
        foreach ($this->alerts->findActive() as $alert) {
            $type = NotificationType::tryFrom($alert->getNotificationType() ?? '');
            if (null === $type) {
                continue; // Opened before alerts remembered their notification: nothing to rebuild it from.
            }

            $room = $alert->getRoom();
            $this->deliver($alert, $type, $alert->getNotificationParams(), null === $room ? null : new RoomId($room->getId()->toRfc4122()));
            $this->alerts->save($alert);
        }
    }

    public function find(string $key): ?Alert
    {
        return $this->alerts->findOneByKey($key);
    }

    /**
     * @param array<string, scalar|null> $params
     */
    private function deliver(Alert $alert, NotificationType $notification, array $params, ?RoomId $room): void
    {
        if (null === $alert->getNotifiedAt() && $this->notifications->notify($notification, $params, $room)) {
            $alert->markNotified($this->clock->now());
        }

        if (null === $alert->getPersistentNotificationId() && $this->wantsPersistentNotification($notification)) {
            $id = PersistentNotifier::idFor($alert->getKey());
            if ($this->persistent->create($id, $notification, $params, $room)) {
                $alert->attachPersistentNotification($id);
            }
        }
    }

    private function wantsPersistentNotification(NotificationType $type): bool
    {
        return $this->types->findOneByType($type)?->isPersistentNotification() ?? false;
    }
}
