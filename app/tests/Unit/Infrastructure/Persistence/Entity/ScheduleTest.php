<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\Entity;

use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Entity\ScheduleSlot;
use App\Infrastructure\Persistence\Enum\SlotStartType;
use App\Infrastructure\Persistence\Support\Guard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Schedule::class)]
#[CoversClass(ScheduleSlot::class)]
#[UsesClass(Preset::class)]
#[UsesClass(Guard::class)]
final class ScheduleTest extends TestCase
{
    private function preset(): Preset
    {
        return new Preset('comfort', 'Comfort', '#ff9800');
    }

    public function testScheduleBasics(): void
    {
        $schedule = new Schedule('Week', HvacMode::Heat);
        $schedule->rename('Winter');

        self::assertSame('Winter', $schedule->getName());
        self::assertSame(HvacMode::Heat, $schedule->getMode());
        self::assertNotEmpty($schedule->getId()->toRfc4122());
        self::assertCount(0, $schedule->getSlots());
    }

    public function testScheduleModeCannotBeOff(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Schedule('Week', HvacMode::Off);
    }

    public function testFixedSlotIgnoresAnchorData(): void
    {
        $schedule = new Schedule('Week', HvacMode::Heat);
        $slot = $schedule->addSlot(3, SlotStartType::Fixed, 390, $this->preset(), 'input_datetime.x', 15);

        self::assertNull($slot->getAnchorEntityId());
        self::assertSame(0, $slot->getOffsetMinutes());
        self::assertSame(390, $slot->getFallbackMinuteOfDay());
        self::assertSame($schedule, $slot->getSchedule());
        self::assertNotEmpty($slot->getId()->toRfc4122());
    }

    public function testAnchoredSlotKeepsAnchorAndFallback(): void
    {
        $schedule = new Schedule('Week', HvacMode::Heat);
        $slot = $schedule->addSlot(7, SlotStartType::Anchored, 1320, $this->preset(), 'input_datetime.bedtime', -15, 1300);

        self::assertSame('input_datetime.bedtime', $slot->getAnchorEntityId());
        self::assertSame(-15, $slot->getOffsetMinutes());
        self::assertSame(1300, $slot->getFallbackMinuteOfDay());
        self::assertSame(SlotStartType::Anchored, $slot->getStartType());
        self::assertSame(7, $slot->getWeekday());
        self::assertSame(1320, $slot->getMinuteOfDay());
    }

    public function testSlotPresetCanChange(): void
    {
        $schedule = new Schedule('Week', HvacMode::Heat);
        $slot = $schedule->addSlot(1, SlotStartType::Fixed, 0, $this->preset());
        $eco = new Preset('eco', 'Eco', '#4caf50');
        $slot->changePreset($eco);

        self::assertSame($eco, $slot->getPreset());
    }

    public function testSlotsCanBeRemoved(): void
    {
        $schedule = new Schedule('Week', HvacMode::Heat);
        $slot = $schedule->addSlot(1, SlotStartType::Fixed, 0, $this->preset());
        $schedule->addSlot(2, SlotStartType::Fixed, 0, $this->preset());

        $schedule->removeSlot($slot);
        self::assertCount(1, $schedule->getSlots());
        $schedule->clearSlots();
        self::assertCount(0, $schedule->getSlots());
    }

    /**
     * @return iterable<string, array{int, SlotStartType, int, ?string, int, ?int}>
     */
    public static function invalidSlots(): iterable
    {
        yield 'weekday 0' => [0, SlotStartType::Fixed, 0, null, 0, null];
        yield 'weekday 8' => [8, SlotStartType::Fixed, 0, null, 0, null];
        yield 'negative minute' => [1, SlotStartType::Fixed, -1, null, 0, null];
        yield 'minute 1440' => [1, SlotStartType::Fixed, 1440, null, 0, null];
        yield 'anchored without entity' => [1, SlotStartType::Anchored, 60, null, 0, null];
        yield 'anchored with blank entity' => [1, SlotStartType::Anchored, 60, ' ', 0, null];
        yield 'offset too large' => [1, SlotStartType::Anchored, 60, 'input_datetime.x', 1441, null];
        yield 'fallback out of range' => [1, SlotStartType::Anchored, 60, 'input_datetime.x', 0, 1440];
    }

    #[DataProvider('invalidSlots')]
    public function testRejectsInvalidSlots(int $weekday, SlotStartType $type, int $minute, ?string $anchor, int $offset, ?int $fallback): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Schedule('Week', HvacMode::Heat))->addSlot($weekday, $type, $minute, $this->preset(), $anchor, $offset, $fallback);
    }
}
