<?php

declare(strict_types=1);

namespace App\Application\Engine\Notification;

use App\Application\Engine\Audit\AuditLogger;
use App\Application\Port\HomeAssistant\HomeAssistantException;
use App\Application\Port\HomeAssistant\HomeAssistantGatewayInterface;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\NotificationTarget;
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
    public const string TEST_TYPE = 'test';

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

        [$delivered] = $this->deliver($targets, $type->value, $this->texts->title($type, $params), $this->texts->message($type, $params), $room);

        return $delivered > 0;
    }

    /**
     * Sends a test notification to every enabled destination, whatever the enabled notification types.
     *
     * @return array{sent: int, failed: int}|null null when no destination is enabled
     */
    public function sendTest(): ?array
    {
        $targets = $this->targets->findEnabled();
        if ([] === $targets) {
            return null;
        }

        [$delivered, $failed] = $this->deliver($targets, self::TEST_TYPE, $this->texts->testTitle(), $this->texts->testMessage(), null);

        return ['sent' => $delivered, 'failed' => $failed];
    }

    /**
     * @param list<NotificationTarget> $targets
     *
     * @return array{int, int} how many destinations received the notification, and how many failed
     */
    private function deliver(array $targets, string $type, string $title, string $message, ?RoomId $room): array
    {
        $delivered = 0;
        $failed = 0;
        foreach ($targets as $target) {
            $service = $target->getService();
            try {
                $this->gateway->callService('notify', substr($service, \strlen('notify.')), ['title' => $title, 'message' => $message]);
            } catch (HomeAssistantException $e) {
                $this->logger->error('Notification {type} to {service} failed: {message}', ['type' => $type, 'service' => $service, 'message' => $e->getMessage()]);
                $this->audit->log(ActorType::System, 'notification', 'audit.notification.failed', [
                    'type' => $type,
                    'target' => $service,
                    'error' => $e->getMessage(),
                ], $room);
                ++$failed;

                continue;
            }

            ++$delivered;
            $this->audit->log(ActorType::System, 'notification', 'audit.notification.sent', [
                'type' => $type,
                'target' => $service,
            ], $room, ['title' => $title, 'message' => $message]);
        }

        return [$delivered, $failed];
    }
}
