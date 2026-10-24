<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Entity\ScheduleSlot;
use App\Infrastructure\Persistence\Enum\SlotStartType;
use App\Infrastructure\Persistence\Repository\PresetRepository;
use App\Infrastructure\Persistence\Repository\ScheduleRepository;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaDriver;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaMiddleware;
use App\Infrastructure\Persistence\Support\Guard;
use App\Infrastructure\Persistence\Type\UtcDateTimeImmutableType;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ScheduleRepository::class)]
#[CoversClass(PresetRepository::class)]
#[CoversClass(Schedule::class)]
#[CoversClass(ScheduleSlot::class)]
#[CoversClass(Preset::class)]
#[CoversClass(Guard::class)]
#[CoversClass(UtcDateTimeImmutableType::class)]
#[CoversClass(SqlitePragmaMiddleware::class)]
#[CoversClass(SqlitePragmaDriver::class)]
final class ScheduleAggregateTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testSlotsAreCascadedAndOrderedByWeekdayThenMinute(): void
    {
        $comfort = new Preset('comfort', 'Comfort', '#ff9800');
        $night = new Preset('night', 'Night', '#3f51b5', 1);
        $schedule = new Schedule('Week', HvacMode::Heat);
        $schedule->addSlot(2, SlotStartType::Fixed, 1320, $night);
        $schedule->addSlot(1, SlotStartType::Fixed, 1320, $night);
        $schedule->addSlot(1, SlotStartType::Fixed, 420, $comfort);
        $schedule->addSlot(1, SlotStartType::Anchored, 1260, $night, 'input_datetime.bedtime', -30, 1290);
        $this->persistAndClear($comfort, $night, $schedule);

        $loaded = $this->service(ScheduleRepository::class)->find($schedule->getId());
        self::assertInstanceOf(Schedule::class, $loaded);

        $summary = [];
        foreach ($loaded->getSlots() as $slot) {
            $summary[] = sprintf('%d@%d:%s', $slot->getWeekday(), $slot->getMinuteOfDay(), $slot->getPreset()->getKey());
        }
        self::assertSame(['1@420:comfort', '1@1260:night', '1@1320:night', '2@1320:night'], $summary);

        $anchored = $loaded->getSlots()->get(1);
        self::assertInstanceOf(ScheduleSlot::class, $anchored);
        self::assertSame(SlotStartType::Anchored, $anchored->getStartType());
        self::assertSame('input_datetime.bedtime', $anchored->getAnchorEntityId());
        self::assertSame(-30, $anchored->getOffsetMinutes());
        self::assertSame(1290, $anchored->getFallbackMinuteOfDay());
        self::assertSame($loaded, $anchored->getSchedule());
    }

    public function testRemovedSlotsAreDeletedAsOrphans(): void
    {
        $comfort = new Preset('comfort', 'Comfort', '#ff9800');
        $schedule = new Schedule('Week', HvacMode::Cool);
        $keep = $schedule->addSlot(1, SlotStartType::Fixed, 60, $comfort);
        $drop = $schedule->addSlot(2, SlotStartType::Fixed, 60, $comfort);
        $this->persistAndClear($comfort, $schedule);

        $loaded = $this->service(ScheduleRepository::class)->find($schedule->getId());
        self::assertInstanceOf(Schedule::class, $loaded);
        $dropped = $loaded->getSlots()->filter(static fn (ScheduleSlot $slot): bool => $slot->getId()->equals($drop->getId()))->first();
        self::assertInstanceOf(ScheduleSlot::class, $dropped);
        $loaded->removeSlot($dropped);
        $this->em()->flush();
        self::assertSame(1, $this->em()->getRepository(ScheduleSlot::class)->count([]));

        $loaded->clearSlots();
        $this->em()->flush();
        self::assertSame(0, $this->em()->getRepository(ScheduleSlot::class)->count([]));
        self::assertNotSame($keep->getId()->toRfc4122(), $drop->getId()->toRfc4122());
    }

    public function testDeletingAScheduleDeletesItsSlots(): void
    {
        $comfort = new Preset('comfort', 'Comfort', '#ff9800');
        $schedule = new Schedule('Week', HvacMode::Heat);
        $schedule->addSlot(1, SlotStartType::Fixed, 60, $comfort);
        $this->persistAndClear($comfort, $schedule);

        $repository = $this->service(ScheduleRepository::class);
        $loaded = $repository->find($schedule->getId());
        self::assertInstanceOf(Schedule::class, $loaded);
        $repository->remove($loaded);

        self::assertSame(0, $this->em()->getRepository(ScheduleSlot::class)->count([]));
    }

    public function testAPresetUsedByASlotCannotBeDeleted(): void
    {
        $comfort = new Preset('comfort', 'Comfort', '#ff9800');
        $schedule = new Schedule('Week', HvacMode::Heat);
        $schedule->addSlot(1, SlotStartType::Fixed, 60, $comfort);
        $this->persistAndClear($comfort, $schedule);

        $presets = $this->service(PresetRepository::class);
        $loaded = $presets->findOneByKey('comfort');
        self::assertInstanceOf(Preset::class, $loaded);

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $presets->remove($loaded);
    }

    public function testPresetsAreOrderedAndUniqueByKey(): void
    {
        $presets = $this->service(PresetRepository::class);
        $presets->save(new Preset('night', 'Night', '#3f51b5', 2));
        $presets->save(new Preset('comfort', 'Comfort', '#ff9800', 0));
        $presets->save(new Preset('eco', 'Eco', '#4caf50', 1));
        $this->em()->clear();

        self::assertSame(['comfort', 'eco', 'night'], array_map(
            static fn (Preset $preset): string => $preset->getKey(),
            $presets->findAllOrdered(),
        ));
        self::assertNull($presets->findOneByKey('unknown'));

        $this->expectException(UniqueConstraintViolationException::class);
        $presets->save(new Preset('eco', 'Duplicate', '#000000'));
    }
}
