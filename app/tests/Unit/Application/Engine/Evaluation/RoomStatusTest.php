<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Evaluation;

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
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoomStatus::class)]
#[CoversClass(DeviceStatus::class)]
#[CoversClass(RoomStatusFactory::class)]
#[CoversClass(LiveStatusPublisher::class)]
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
            'devices' => [new DeviceStatus('climate.living', 'reversible', 'heat', 21.0, 19.5)],
            'updatedAt' => '2026-10-26T08:00:00+01:00',
        ], [...$status->jsonSerialize(), 'devices' => [...$status->devices]]);
        self::assertJson(json_encode($status, \JSON_THROW_ON_ERROR));
    }

    public function testADeviceWithoutStateIsDescribedWithNulls(): void
    {
        $snapshot = new EngineSnapshot(
            Fixtures::at('2026-10-26 08:00'),
            new EngineConfiguration([], [], new GlobalPolicy(1800, Fixtures::preset('eco'), Fixtures::preset('away')), Season::Winter, [], null, null, 90, 120),
            new StateStore(),
        );

        $status = (new RoomStatusFactory())->create(Fixtures::room(), Decision::off(new Reason('reason.season_off')), RoomFacts::none(), $snapshot);

        self::assertEquals([new DeviceStatus('climate.living', 'reversible', null, null, null)], $status->devices);
        self::assertSame(['entityId' => 'climate.living', 'role' => 'reversible', 'state' => null, 'targetTemperature' => null, 'currentTemperature' => null], $status->devices[0]->jsonSerialize());
        self::assertNull($status->jsonSerialize()['override']);
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
