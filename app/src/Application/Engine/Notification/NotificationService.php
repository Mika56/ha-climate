<?php

declare(strict_types=1);

namespace App\Application\Engine\Notification;

use App\Application\Engine\Audit\AuditLogger;
use App\Application\Port\HomeAssistant\HomeAssistantException;
use App\Application\Port\HomeAssistant\HomeAssistantGatewayInterface;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\NotificationTargetRepository;
use App\Infrastructure\Persistence\Repository\NotificationTypeSettingRepository;
use Psr\Log\LoggerInterface;

/**
 * Sends a notification to every enabled destination (a Home Assistant `notify.*` service), provided its type
 * is enabled. A type with no setting row counts as enabled. A destination that fails is logged and audited,
 * and never prevents the others from being served nor the engine from carrying on.
 */
final readonly class NotificationService
{
    public function __construct(
        private HomeAssistantGatewayInterface $gateway,
        private NotificationTypeSettingRepository $types,
        private NotificationTargetRepository $targets,
        private NotificationTexts $texts,
        private AuditLogger $audit,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, scalar|null> $params the values of the type's message (see translations/notifications+intl-icu.*.yaml)
     *
     * @return bool whether at least one destination received the notification
     */
    public function notify(NotificationType $type, array $params, ?RoomId $room = null): bool
    {
        $setting = $this->types->findOneByType($type);
        if (null !== $setting && !$setting->isEnabled()) {
            return false;
        }

        $targets = $this->targets->findEnabled();
        if ([] === $targets) {
            return false;
        }

        $title = $this->texts->title($type, $params);
        $message = $this->texts->message($type, $params);

        $delivered = false;
        foreach ($targets as $target) {
            $service = $target->getService();
            try {
                $this->gateway->callService('notify', substr($service, \strlen('notify.')), ['title' => $title, 'message' => $message]);
            } catch (HomeAssistantException $e) {
                $this->logger->error('Notification {type} to {service} failed: {message}', ['type' => $type->value, 'service' => $service, 'message' => $e->getMessage()]);
                $this->audit->log(ActorType::System, 'notification', 'audit.notification.failed', [
                    'type' => $type->value,
                    'target' => $service,
                    'error' => $e->getMessage(),
                ], $room);

                continue;
            }

            $delivered = true;
            $this->audit->log(ActorType::System, 'notification', 'audit.notification.sent', [
                'type' => $type->value,
                'target' => $service,
            ], $room, ['title' => $title, 'message' => $message]);
        }

        return $delivered;
    }
}
