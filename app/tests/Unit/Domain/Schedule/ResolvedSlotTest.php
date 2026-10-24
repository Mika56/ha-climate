<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Schedule;

use App\Domain\Schedule\ResolvedSlot;
use App\Domain\Schedule\ScheduleSlot;
use App\Domain\Schedule\Weekday;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedSlot::class)]
#[CoversClass(ScheduleSlot::class)]
#[UsesClass(\App\Domain\Preset\PresetKey::class)]
#[UsesClass(\App\Domain\Schedule\FixedSlotStart::class)]
#[UsesClass(\App\Domain\Schedule\MinuteOfDay::class)]
final class ResolvedSlotTest extends TestCase
{
    public function testExposesSlotStartAndPreset(): void
    {
        $slot = Fixtures::slot(Weekday::Monday, Fixtures::fixed(6, 30), 'comfort');
        $start = Fixtures::at('2026-10-26 06:30');
        $resolved = new ResolvedSlot($slot, $start);

        self::assertSame($slot, $resolved->slot);
        self::assertSame($start, $resolved->start);
        self::assertSame('comfort', (string) $resolved->presetKey());
        self::assertSame(Weekday::Monday, $slot->weekday);
    }
}
