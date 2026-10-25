<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Evaluation;

use App\Application\Engine\Alert\AlertView;
use App\Application\Engine\Configuration\EngineConfiguration;
use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\Evaluation\DeviceStatus;
use App\Application\Engine\Evaluation\LiveStatusPublisher;
use App\Application\Engine\Evaluation\RoomStatus;
use App\Application\Engine\Evaluation\RoomStatusFactory;
use App\Application\Engine\State\StateStore;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\Season;
use App\Domain\Climate\TemperatureDelta;
use App\Domain\Decision\ActiveOverride;
use App\Domain\Decision\Decision;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomFacts;
use App\Domain\Room\RoomId;
use App\Tests\Double\CallRecorder;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoomStatus::class)]
#[CoversClass(DeviceStatus::class)]
#[CoversClass(RoomStatusFactory::class)]
#[CoversClass(LiveStatusPublisher::class)]
#[CoversClass(AlertView::class)]
#[UsesClass(EngineSnapshot::class)]
#[UsesClass(EngineConfiguration::class)]
#[UsesClass(StateStore::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(StateContext::class)]
#[UsesNamespace('App\Domain')]
final class RoomStatusTest extends TestCase
{
    public function testTheFactoryDescribesTheRoomAndItsDevices(): void
    {
        $store = new StateStore();
        $store->load([new EntityState('climate.living', 'heat', ['temperature' => 21.0, 'current_temperature' => '19.5'], new \DateTimeImmutable(), new \DateTimeImmutable(), new StateContext('c'))]);
        $snapshot = new EngineSnapshot(
            Fixtures::at('2026-10-26 08:00'),
            new EngineConfiguration([], [], new GlobalPolicy(1800, Fixtures::preset('eco'), Fixtures::preset('away')), Season::Winter, [], null, null, 90, 120),
            $store,
        );
        $facts = RoomFacts::none()
            ->withCurrentTemperature(Fixtures::temperature(19.5))
            ->withOverride(ActiveOverride::boost(new TemperatureDelta(2.0), Fixtures::at('2026-10-26 10:00'), true));

        $status = (new RoomStatusFactory())->create(Fixtures::room(), Decision::heat(Fixtures::temperature(21.0), new Reason('reason.schedule', ['preset' => 'comfort'])), $facts, $snapshot);

        self::assertEquals([
            'roomId' => 'living',
            'name' => 'Living room',
            'currentTemperature' => 19.5,
            'decision' => ['mode' => 'heat', 'target' => 21.0, 'reason' => ['code' => 'reason.schedule', 'params' => ['preset' => 'comfort']]],
            'override' => ['type' => 'boost', 'delta' => 2.0, 'target' => null, 'endsAt' => '2026-10-26T10:00:00+01:00', 'untilNextSlot' => true],
            'devices' => [['entityId' => 'climate.living', 'role' => 'reversible', 'state' => 'heat', 'targetTemperature' => 21.0, 'currentTemperature' => 19.5, 'running' => null]],
            'facts' => ['openingOpenSince' => null, 'roomVacantSince' => null, 'houseEmptySince' => null, 'heatingRatePerHour' => null],
            'alerts' => [],
            'updatedAt' => '2026-10-26T08:00:00+01:00',
            'paused' => false,
        ], $status->jsonSerialize());
        self::assertJson(json_encode($status, \JSON_THROW_ON_ERROR));
    }

    public function testADeviceWithoutStateIsDescribedWithNulls(): void
    {
        $snapshot = new EngineSnapshot(
            Fixtures::at('2026-10-26 08:00'),
            new EngineConfiguration([], [], new GlobalPolicy(1800, Fixtures::preset('eco'), Fixtures::preset('away')), Season::Winter, [], null, null, 90, 120),
            new StateStore(),
        );

        $status = (new RoomStatusFactory())->create(Fixtures::room(), Decision::off(new Reason('reason.no_rule')), RoomFacts::none(), $snapshot);

        self::assertEquals([new DeviceStatus('climate.living', 'reversible', null, null, null)], $status->devices);
        self::assertSame(['entityId' => 'climate.living', 'role' => 'reversible', 'state' => null, 'targetTemperature' => null, 'currentTemperature' => null, 'running' => null], $status->devices[0]->jsonSerialize());
        self::assertNull($status->jsonSerialize()['override']);
        self::assertStringContainsString('"reason":{"code":"reason.no_rule","params":{}}', json_encode($status, \JSON_THROW_ON_ERROR), 'No parameter is an empty object, not an empty list.');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('hvacActions')]
    public function testADeviceIsRunningWhenItReportsHeatingOrCooling(array $attributes, ?bool $running): void
    {
        $store = new StateStore();
        $store->load([new EntityState('climate.living', 'heat', $attributes, new \DateTimeImmutable(), new \DateTimeImmutable(), new StateContext('c'))]);
        $snapshot = new EngineSnapshot(
            Fixtures::at('2026-10-26 08:00'),
            new EngineConfiguration([], [], new GlobalPolicy(1800, Fixtures::preset('eco'), Fixtures::preset('away')), Season::Winter, [], null, null, 90, 120),
            $store,
        );

        $status = (new RoomStatusFactory())->create(Fixtures::room(), Decision::off(new Reason('reason.no_rule')), RoomFacts::none(), $snapshot);

        self::assertSame($running, $status->devices[0]->running);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool|null}>
     */
    public static function hvacActions(): iterable
    {
        yield 'heating' => [['hvac_action' => 'heating'], true];
        yield 'cooling' => [['hvac_action' => 'cooling'], true];
        yield 'idle' => [['hvac_action' => 'idle'], false];
        yield 'off' => [['hvac_action' => 'off'], false];
        yield 'not reported' => [[], null];
        yield 'not a text' => [['hvac_action' => 3], null];
    }

    public function testTheStatusCarriesTheFactsAndTheAlertsOfTheRoom(): void
    {
        $facts = RoomFacts::none()
            ->withOpeningOpenSince(new \DateTimeImmutable('2026-10-26 06:55:00', new \DateTimeZone('UTC')))
            ->withRoomVacantSince(new \DateTimeImmutable('2026-10-26 06:00:00', new \DateTimeZone('UTC')))
            ->withHouseEmptySince(new \DateTimeImmutable('2026-10-25 20:00:00', new \DateTimeZone('UTC')))
            ->withHeatingRatePerHour(1.23456);
        $alert = new AlertView('entity.sensor.x', 'entity_unavailable', 'sensor.x', 'living', 'Living room', '2026-10-26T07:30:00+01:00', null);
        $status = new RoomStatus(new RoomId('living'), 'Living room', null, Decision::heat(Fixtures::temperature(21.0), new Reason('reason.schedule')), null, [], Fixtures::at('2026-10-26 08:00'), $facts, [$alert]);

        $json = $status->jsonSerialize();

        self::assertSame(['openingOpenSince' => '2026-10-26T07:55:00+01:00', 'roomVacantSince' => '2026-10-26T07:00:00+01:00', 'houseEmptySince' => '2026-10-25T21:00:00+01:00', 'heatingRatePerHour' => 1.23], $json['facts']);
        self::assertSame([$alert->jsonSerialize()], $json['alerts']);
        self::assertFalse($status->sameContentAs(self::roomStatus('2026-10-26 08:00', 21.0)), 'Facts and alerts are part of the content.');
        self::assertTrue((new RoomStatus(new RoomId('living'), 'Living room', null, Decision::heat(Fixtures::temperature(21.0), new Reason('reason.schedule')), null, [], Fixtures::at('2026-10-26 09:00'), $facts, [clone $alert]))->sameContentAs($status));
    }

    public function testStatusesWithEqualDevicesAreEqual(): void
    {
        $device = static fn (): DeviceStatus => new DeviceStatus('climate.living', 'heating', 'heat', 21.0, 19.5, true);
        $make = static fn (string $at): RoomStatus => new RoomStatus(new RoomId('living'), 'Living room', null, Decision::heat(Fixtures::temperature(21.0), new Reason('reason.schedule')), null, [$device()], Fixtures::at($at));

        self::assertTrue($make('2026-10-26 08:00')->sameContentAs($make('2026-10-26 08:30')), 'Two evaluations building distinct device objects still have the same content.');
    }

    public function testStatusesWithTheSameContentAreEqualWhateverTheirTime(): void
    {
        $a = self::roomStatus('2026-10-26 08:00', 21.0);

        self::assertTrue($a->sameContentAs(self::roomStatus('2026-10-26 09:00', 21.0)));
        self::assertFalse($a->sameContentAs(self::roomStatus('2026-10-26 08:00', 19.0)));
    }

    public function testThePublisherOnlyNotifiesRealChanges(): void
    {
        $publisher = new LiveStatusPublisher();
        $seen = new CallRecorder();
        $removed = new CallRecorder();
        $stop = $publisher->subscribe(
            static function (RoomStatus $status) use ($seen): void {
                $seen->record($status->decision->target?->toFloat());
            },
            static function (RoomId $room) use ($removed): void {
                $removed->record((string) $room);
            },
        );

        self::assertTrue($publisher->publish(self::roomStatus('2026-10-26 08:00', 21.0)));
        self::assertFalse($publisher->publish(self::roomStatus('2026-10-26 08:05', 21.0)));
        self::assertTrue($publisher->publish(self::roomStatus('2026-10-26 08:10', 19.0)));

        self::assertSame([21.0, 19.0], $seen->calls);
        self::assertCount(1, $publisher->snapshot());
        self::assertSame(19.0, $publisher->snapshot()[0]->decision->target?->toFloat());

        $publisher->remove(new RoomId('unknown'));
        $publisher->remove(new RoomId('living'));
        self::assertSame(['living'], $removed->calls);
        self::assertSame([], $publisher->snapshot());

        $stop();
        $publisher->publish(self::roomStatus('2026-10-26 08:20', 17.0));
        self::assertSame([21.0, 19.0], $seen->calls, 'A stopped listener hears nothing.');
    }

    private static function roomStatus(string $at, float $target): RoomStatus
    {
        return new RoomStatus(new RoomId('living'), 'Living room', null, Decision::heat(Fixtures::temperature($target), new Reason('reason.schedule')), null, [], Fixtures::at($at));
    }
}
