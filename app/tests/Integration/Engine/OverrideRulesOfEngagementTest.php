<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Command\ManualDeviceChangeDetected;
use App\Application\Engine\Override\ManualOverrideSubscriber;
use App\Application\Engine\Override\OverrideCommands;
use App\Application\Engine\Override\OverrideManager;
use App\Application\Engine\Override\OverrideRejectedException;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Climate\TemperatureDelta;
use App\Domain\Device\DeviceRole;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Repository\RoomOverrideRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * What a boost or an override refuses to do, and the manual changes that create no override.
 */
#[CoversClass(OverrideManager::class)]
#[CoversClass(OverrideCommands::class)]
#[CoversClass(OverrideRejectedException::class)]
#[CoversClass(ManualOverrideSubscriber::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class OverrideRulesOfEngagementTest extends EngineTestCase
{
    public function testAnUnknownRoomIsRejected(): void
    {
        $this->startEngine();

        foreach (['ghost', '00000000-0000-7000-8000-000000000000'] as $id) {
            $this->assertRejected(OverrideRejectedException::ROOM_NOT_FOUND, fn () => $this->manager()->startBoost(new RoomId($id), new TemperatureDelta(1.0), 30, false, 'alice'));
            $this->assertRejected(OverrideRejectedException::ROOM_NOT_FOUND, fn () => $this->manager()->cancel(new RoomId($id), 'alice'));
        }
    }

    public function testNothingCanBeBoostedWhenTheSeasonIsOffOrTheRoomHasNoDeviceForIt(): void
    {
        $this->setSeason(Season::Off);
        $room = new RoomId($this->createRoom()->getId()->toRfc4122());
        $this->seedDevice();
        $this->startEngine();
        $this->assertRejected(OverrideRejectedException::NO_ACTIVE_MODE, fn () => $this->manager()->startBoost($room, new TemperatureDelta(1.0), 30, false, 'alice'));

        $this->setSeason(Season::Summer);
        $this->service(\App\Application\Engine\Lifecycle\ConfigurationReloader::class)->reload();
        $this->assertRejected(OverrideRejectedException::NO_ACTIVE_MODE, fn () => $this->manager()->startBoost($room, new TemperatureDelta(1.0), 30, false, 'alice'));
    }

    public function testABoostNeedsAnEnd(): void
    {
        $this->setSeason(Season::Winter);
        $room = new RoomId($this->createRoom()->getId()->toRfc4122());
        $this->seedDevice();
        $this->startEngine();

        $this->assertRejected(OverrideRejectedException::NO_END, fn () => $this->manager()->startBoost($room, new TemperatureDelta(1.0), null, false, 'alice'));
    }

    public function testUntilTheNextSlotNeedsAScheduleUnlessADurationIsGiven(): void
    {
        $this->setSeason(Season::Summer);
        $room = new RoomId($this->createRoom(role: DeviceRole::Reversible)->getId()->toRfc4122());
        $this->seedDevice();
        $this->startEngine();

        $this->assertRejected(OverrideRejectedException::NO_SCHEDULE, fn () => $this->manager()->startBoost($room, new TemperatureDelta(1.0), null, true, 'alice'));

        $this->manager()->startBoost($room, new TemperatureDelta(1.0), 45, true, 'alice');
        $overrides = $this->service(RoomOverrideRepository::class)->findAll();
        self::assertCount(1, $overrides);
        self::assertSame(HvacMode::Cool, $overrides[0]->getMode());
        self::assertTrue($overrides[0]->isUntilNextSlot());
    }

    public function testCancellingNeedsAnOverrideInForce(): void
    {
        $this->setSeason(Season::Winter);
        $room = new RoomId($this->createRoom()->getId()->toRfc4122());
        $this->seedDevice();
        $this->startEngine();

        $this->assertRejected(OverrideRejectedException::NO_ACTIVE_OVERRIDE, fn () => $this->manager()->cancel($room, 'alice'));

        $this->manager()->startBoost($room, new TemperatureDelta(1.0), 30, false, 'alice');
        $this->clock()->sleep(31 * 60);
        $this->assertRejected(OverrideRejectedException::NO_ACTIVE_OVERRIDE, fn () => $this->manager()->cancel($room, 'alice'), 'An ended boost is not in force any more.');
        self::assertSame([], $this->service(RoomOverrideRepository::class)->findAll(), 'But cancelling still sweeps it away.');
    }

    public function testAnEvaluationThatLeavesNoStatusIsAnError(): void
    {
        $this->setSeason(Season::Winter);
        $broken = $this->createRoom();
        foreach ($broken->getPresetTemperatures() as $temperature) {
            if (HvacMode::Heat === $temperature->getMode() && 'comfort' === $temperature->getPreset()->getKey()) {
                $broken->removePresetTemperature($temperature);
            }
        }
        $this->em()->flush();
        $this->seedDevice();
        $this->startEngine();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be evaluated');

        $this->service(OverrideCommands::class)->boost(new RoomId($broken->getId()->toRfc4122()), new TemperatureDelta(1.0), 30, false, 'alice');
    }

    /**
     * @param array{newMode: string, newTemperature: ?float} $change
     */
    #[DataProvider('changesWithoutOverride')]
    public function testSomeManualChangesCreateNoOverride(array $change, Season $season): void
    {
        $this->setSeason($season);
        $room = $this->createRoom();
        $this->seedDevice();
        $this->startEngine();

        $this->dispatch(new RoomId($room->getId()->toRfc4122()), $change['newMode'], $change['newTemperature']);

        self::assertSame([], $this->service(RoomOverrideRepository::class)->findAll());
    }

    /**
     * @return iterable<string, array{array{newMode: string, newTemperature: ?float}, Season}>
     */
    public static function changesWithoutOverride(): iterable
    {
        yield 'a mode the engine does not drive' => [['newMode' => 'heat_cool', 'newTemperature' => 21.0], Season::Winter];
        yield 'no target reported' => [['newMode' => 'heat', 'newTemperature' => null], Season::Winter];
        yield 'an impossible target' => [['newMode' => 'heat', 'newTemperature' => 80.0], Season::Winter];
        yield 'season off' => [['newMode' => 'heat', 'newTemperature' => 22.0], Season::Off];
    }

    public function testAChangeOnARoomThatVanishedIsIgnored(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice();
        $this->startEngine();

        $this->dispatch(new RoomId('00000000-0000-7000-8000-000000000000'), 'heat', 22.0);

        self::assertSame([], $this->service(RoomOverrideRepository::class)->findAll());
    }

    private function dispatch(RoomId $room, string $mode, ?float $temperature): void
    {
        $this->service(EventDispatcherInterface::class)->dispatch(new ManualDeviceChangeDetected(
            $room,
            new EntityId(self::DEVICE),
            'off',
            null,
            $mode,
            $temperature,
            'user-1',
            $this->clock()->now(),
        ));
    }

    private function manager(): OverrideManager
    {
        return $this->service(OverrideManager::class);
    }

    private function assertRejected(string $reason, \Closure $action, string $message = ''): void
    {
        try {
            $action();
        } catch (OverrideRejectedException $e) {
            self::assertSame($reason, $e->reason, $message);

            return;
        }

        self::fail(\sprintf('"%s" was expected. %s', $reason, $message));
    }
}
