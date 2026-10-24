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

/**
 * Opens and resolves the alerts (one row per key), and keeps their notifications in step.
 *
 * Raising is idempotent: an alert that is already open is not opened again, and one whose notification was
 * delivered is not notified again, even after a restart. A persistent notification is created when the type's
 * setting asks for it, and dismissed when the alert is resolved. Alerts never change how rooms are driven.
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

        if (null === $alert->getNotifiedAt() && $this->notifications->notify($notification, $params, $room)) {
            $alert->markNotified($now);
        }

        if (null === $alert->getPersistentNotificationId() && $this->wantsPersistentNotification($notification)) {
            $id = PersistentNotifier::idFor($key);
            if ($this->persistent->create($id, $notification, $params, $room)) {
                $alert->attachPersistentNotification($id);
            }
        }

        $this->alerts->save($alert);

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
        $this->audit->log(ActorType::System, 'alert', 'audit.alert.resolved', ['key' => $key, 'type' => $alert->getType()], null === $room ? null : new RoomId($room->getId()->toRfc4122()));
    }

    public function find(string $key): ?Alert
    {
        return $this->alerts->findOneByKey($key);
    }

    private function wantsPersistentNotification(NotificationType $type): bool
    {
        return $this->types->findOneByType($type)?->isPersistentNotification() ?? false;
    }
}
