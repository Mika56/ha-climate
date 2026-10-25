<?php

declare(strict_types=1);

namespace App\Application\Engine\Notification;

use App\Application\Engine\Audit\AuditLogger;
use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Port\HomeAssistant\HomeAssistantException;
use App\Application\Port\HomeAssistant\HomeAssistantGatewayInterface;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Enum\NotificationType;
use Psr\Log\LoggerInterface;

/**
 * Creates and dismisses Home Assistant persistent notifications (the ones shown in the sidebar). The
 * notification id is stable for an alert, so creating twice replaces and dismissing twice is harmless.
 *
 * Nothing is created while HA Climate is paused. Dismissing stays possible: pausing dismisses the notifications
 * HA Climate created.
 */
final readonly class PersistentNotifier
{
    public function __construct(
        private HomeAssistantGatewayInterface $gateway,
        private NotificationTexts $texts,
        private AuditLogger $audit,
        private LoggerInterface $logger,
        private ConfigurationRepository $configuration,
    ) {
    }

    /**
     * The persistent notification id of an alert key: `ha_climate_` followed by the key reduced to `[a-z0-9_]`.
     */
    public static function idFor(string $alertKey): string
    {
        return 'ha_climate_'.trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($alertKey)), '_');
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return bool whether Home Assistant accepted the notification
     */
    public function create(string $notificationId, NotificationType $type, array $params, ?RoomId $room = null): bool
    {
        if ($this->configuration->isPaused()) {
            return false;
        }

        try {
            $this->gateway->callService('persistent_notification', 'create', [
                'notification_id' => $notificationId,
                'title' => $this->texts->title($type, $params),
                'message' => $this->texts->message($type, $params),
            ]);
        } catch (HomeAssistantException $e) {
            $this->logger->error('Persistent notification {id} could not be created: {message}', ['id' => $notificationId, 'message' => $e->getMessage()]);
            $this->audit->log(ActorType::System, 'notification', 'audit.notification.failed', [
                'type' => $type->value,
                'target' => 'persistent_notification',
                'error' => $e->getMessage(),
            ], $room);

            return false;
        }

        $this->audit->log(ActorType::System, 'notification', 'audit.notification.sent', [
            'type' => $type->value,
            'target' => 'persistent_notification',
        ], $room, ['notificationId' => $notificationId]);

        return true;
    }

    public function dismiss(string $notificationId): void
    {
        try {
            $this->gateway->callService('persistent_notification', 'dismiss', ['notification_id' => $notificationId]);
        } catch (HomeAssistantException $e) {
            $this->logger->error('Persistent notification {id} could not be dismissed: {message}', ['id' => $notificationId, 'message' => $e->getMessage()]);
        }
    }
}
