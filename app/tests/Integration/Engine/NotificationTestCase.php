<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use App\Infrastructure\Persistence\Entity\Alert;
use App\Infrastructure\Persistence\Entity\NotificationTarget;
use App\Infrastructure\Persistence\Entity\NotificationTypeSetting;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\AlertRepository;
use App\Infrastructure\Persistence\Repository\NotificationTargetRepository;
use App\Infrastructure\Persistence\Repository\NotificationTypeSettingRepository;
use App\Tests\Double\RecordedServiceCall;

/**
 * Base of the openings, alerts and notifications scenarios: the fake Home Assistant dates its states with the
 * mock clock, and a phone is registered as notification destination.
 */
abstract class NotificationTestCase extends EngineTestCase
{
    protected const string WINDOW = 'binary_sensor.living_room_window';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindHomeAssistantToTheClock();
        $this->addTarget('notify.mobile_app_phone');
    }

    protected function bindHomeAssistantToTheClock(): void
    {
        $clock = $this->clock();
        $this->homeAssistant()->followingClock($clock->now(...));
    }

    protected function addTarget(string $service, bool $enabled = true): void
    {
        $this->service(NotificationTargetRepository::class)->save(new NotificationTarget($service, $enabled));
    }

    protected function configureType(NotificationType $type, bool $enabled, ?int $delaySeconds, bool $persistent): void
    {
        $repository = $this->service(NotificationTypeSettingRepository::class);
        $setting = $repository->findOneByType($type) ?? new NotificationTypeSetting($type);
        $setting->configure($enabled, $delaySeconds, $persistent);
        $repository->save($setting);
    }

    /**
     * Stops the engine and starts it again in a fresh kernel, 'later' seconds on the mock clock, against a
     * Home Assistant that still holds the given states (callback), the way an add-on restart goes.
     *
     * @param \Closure(): void $homeAssistantState seeds what Home Assistant holds when the engine is back
     */
    protected function restartEngine(int $later, \Closure $homeAssistantState): void
    {
        $this->engine()->stop();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->homeAssistant()->withConfig(new HomeAssistantConfig('fr', 'Europe/Paris', '°C', '2026.10.0'));
        $this->bindHomeAssistantToTheClock();
        $homeAssistantState();
        $this->clock()->sleep($later);
        $this->startEngine();
    }

    /**
     * @return list<RecordedServiceCall> the calls to the given notify service, in order
     *
     * @phpstan-impure
     */
    protected function notifications(string $service = 'mobile_app_phone'): array
    {
        return $this->homeAssistant()->serviceCallsTo('notify', $service);
    }

    /**
     * @phpstan-impure
     */
    protected function alert(string $key): ?Alert
    {
        $this->em()->clear();

        return $this->service(AlertRepository::class)->findOneByKey($key);
    }

    protected static function stringData(RecordedServiceCall $call, string $field): string
    {
        $value = $call->serviceData[$field] ?? null;
        self::assertIsString($value);

        return $value;
    }
}
