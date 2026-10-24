<?php

declare(strict_types=1);

namespace App\Application\Engine\Alert;

use App\Application\Engine\Command\DeviceCommandSent;
use App\Application\Engine\Command\OwnCommandRegistry;
use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Engine\State\EntityStateChanged;
use App\Application\Engine\Timer\TimerScheduler;
use App\Domain\Exception\DomainExceptionInterface;
use App\Domain\HomeAssistant\EntityId;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\NotificationTypeSettingRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Checks that devices do what they are told: every command arms a persisted timer (the `device_unresponsive`
 * delay, 5 minutes by default) at which {@see DeviceUnresponsiveHandler} looks at the device; a device that
 * reaches the commanded state first disarms it and clears the alert it may have raised.
 */
#[AsEventListener(event: DeviceCommandSent::class, method: 'onCommandSent')]
#[AsEventListener(event: EntityStateChanged::class, method: 'onStateChanged')]
final readonly class DeviceResponseWatcher
{
    public const string TIMER_TYPE = 'device_unresponsive';
    public const string TIMER_KEY_PREFIX = 'device.unresponsive.';
    public const string ALERT_KEY_PREFIX = 'device.';
    public const int DEFAULT_DELAY_SECONDS = 300;

    public function __construct(
        private TimerScheduler $timers,
        private AlertService $alerts,
        private NotificationTypeSettingRepository $types,
        private OwnCommandRegistry $ownCommands,
        private ConfigurationRepository $configuration,
        private CommandedStateMatcher $matcher,
    ) {
    }

    public function onCommandSent(DeviceCommandSent $event): void
    {
        $delay = $this->types->findOneByType(NotificationType::DeviceUnresponsive)?->getDelaySeconds() ?? self::DEFAULT_DELAY_SECONDS;
        $device = (string) $event->device;

        $this->timers->schedule(
            self::TIMER_KEY_PREFIX.$device,
            $event->sentAt->modify(\sprintf('+%d seconds', $delay)),
            self::TIMER_TYPE,
            $event->roomId,
            ['device' => $device, 'mode' => $event->mode->value, 'temperature' => $event->temperature, 'delay' => $delay],
        );
    }

    public function onStateChanged(EntityStateChanged $event): void
    {
        $new = $event->change->new;
        $entityId = $event->change->entityId;
        if (null === $new || !$this->isDevice($entityId)) {
            return;
        }

        $commanded = $this->ownCommands->lastCommanded($entityId);
        if (null === $commanded || !$this->matcher->matches($new, $commanded->mode, $commanded->temperature)) {
            return;
        }

        $this->timers->cancel(self::TIMER_KEY_PREFIX.$entityId);
        $this->alerts->resolve(self::ALERT_KEY_PREFIX.$entityId);
    }

    private function isDevice(string $entityId): bool
    {
        try {
            return null !== $this->configuration->snapshot()->roomOfDevice(new EntityId($entityId));
        } catch (DomainExceptionInterface) {
            return false;
        }
    }
}
