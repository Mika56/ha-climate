<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Command\AntiCyclingGuard;
use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Engine\Evaluation\RoomEvaluator;
use App\Application\Engine\Override\OverrideCommands;
use App\Application\Engine\State\StateStore;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\Season;
use App\Domain\Climate\TemperatureDelta;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Repository\TimerRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

/**
 * The device protection against short cycles, through the real executor, timers and clock. Home Assistant's
 * fake stamps `last_changed` with the real time, so the scenarios place the device in the state store with a
 * `last_changed` taken from the mock clock.
 */
#[CoversClass(AntiCyclingGuard::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class AntiCyclingScenarioTest extends EngineTestCase
{
    private RoomId $roomId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        foreach ($room->getDevices() as $device) {
            $device->configureAntiCycling(300, 600, 1.0);
        }
        $this->em()->flush();
        $this->roomId = new RoomId($room->getId()->toRfc4122());
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();
    }

    public function testARestartWantedTooSoonIsDeferredThenSentAtTheRightTime(): void
    {
        $this->deviceSeen('off');
        $this->evaluate();

        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'The device has been off for 0 s out of the 600 s required.');
        $deferred = $this->auditEntries('audit.command.deferred');
        self::assertCount(1, $deferred);
        self::assertSame(self::DEVICE, $deferred[0]->getParams()['device']);
        self::assertSame('2026-10-26T07:10:00+00:00', $deferred[0]->getParams()['until']);
        self::assertSame(AntiCyclingGuard::REASON_MIN_OFF, $deferred[0]->getParams()['reason']);
        $timer = $this->service(TimerRepository::class)->findOneByKey('command.deferred.'.self::DEVICE);
        self::assertSame('2026-10-26 07:10:00', $timer?->getDueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

        $this->timers()->advance(599);
        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'One second early.');

        $this->timers()->advance(1);
        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $calls[0]->serviceData);
    }

    public function testASwitchOffWantedTooSoonIsDeferredEvenWhenTheSeasonRequiresIt(): void
    {
        // Summer: the room has no device able to cool, so the heater has to go off.
        $this->setSeason(Season::Summer);
        $this->service(ConfigurationRepository::class)->reload();
        $this->deviceSeen('heat');
        $this->evaluate();

        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'The device has run for 0 s out of the 300 s required.');

        $this->timers()->advance(299);
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        $this->timers()->advance(1);
        self::assertSame(['hvac_mode' => 'off'], $this->homeAssistant()->serviceCalls()[0]->serviceData);
    }

    public function testASetpointChangeOfARunningDeviceIsNotDeferred(): void
    {
        $this->deviceSeen('heat');

        $this->service(OverrideCommands::class)->boost($this->roomId, new TemperatureDelta(1.5), 60, false, 'alice');

        self::assertSame(22.5, $this->homeAssistant()->serviceCalls()[0]->serviceData['temperature'], 'The device has run for 0 s, yet its setpoint moves at once.');
    }

    public function testASetpointWithinTheHysteresisIsNotSent(): void
    {
        $status = $this->service(OverrideCommands::class)->boost($this->roomId, new TemperatureDelta(0.5), 60, false, 'alice');

        self::assertSame(21.5, $status->decision->target?->toFloat());
        self::assertSame([], $this->homeAssistant()->serviceCalls(), '0.5 °C away is below the 1 °C hysteresis.');

        $this->service(OverrideCommands::class)->boost($this->roomId, new TemperatureDelta(1.0), 60, false, 'alice');

        self::assertSame(22.0, $this->homeAssistant()->serviceCalls()[0]->serviceData['temperature']);
    }

    private function deviceSeen(string $state): void
    {
        $now = $this->clock()->now();
        $this->service(StateStore::class)->apply(new EntityStateChange(self::DEVICE, null, new EntityState(self::DEVICE, $state, ['temperature' => 21.0, 'current_temperature' => 19.5], $now, $now, new StateContext('elsewhere'))));
    }

    private function evaluate(): void
    {
        $this->service(RoomEvaluator::class)->evaluate([$this->roomId], 'test');
    }
}
