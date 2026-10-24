<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomFacts;
use App\Domain\Exception\InvalidValueException;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlobalPolicy::class)]
#[CoversClass(RoomFacts::class)]
#[CoversClass(RoomEvaluationContext::class)]
#[UsesClass(Season::class)]
#[UsesClass(\App\Domain\Decision\ActiveOverride::class)]
#[UsesClass(\App\Domain\Decision\VacationPeriod::class)]
#[UsesClass(\App\Domain\Decision\OverrideType::class)]
#[UsesClass(\App\Domain\Climate\Temperature::class)]
#[UsesClass(\App\Domain\Decision\RoomConfiguration::class)]
#[UsesClass(\App\Domain\Device\AntiCyclingPolicy::class)]
#[UsesClass(\App\Domain\Device\Device::class)]
#[UsesClass(\App\Domain\HomeAssistant\EntityId::class)]
#[UsesClass(\App\Domain\Preset\PresetKey::class)]
#[UsesClass(\App\Domain\Room\PresetTemperatures::class)]
#[UsesClass(\App\Domain\Room\RoomId::class)]
#[UsesClass(\App\Domain\Schedule\ScheduleId::class)]
final class GlobalPolicyAndFactsTest extends TestCase
{
    public function testPolicyHoldsItsValues(): void
    {
        $policy = new GlobalPolicy(0, Fixtures::preset('eco'), Fixtures::preset('away'));

        self::assertSame(0, $policy->houseEmptyDelaySeconds);
        self::assertSame('eco', (string) $policy->vacancyPreset);
        self::assertSame('away', (string) $policy->absentPreset);
    }

    public function testNegativeHouseEmptyDelayIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new GlobalPolicy(-1, Fixtures::preset('eco'), Fixtures::preset('away'));
    }

    public function testFactsAreAllOptional(): void
    {
        $facts = RoomFacts::none();

        self::assertNull($facts->houseEmptySince);
        self::assertNull($facts->roomVacantSince);
        self::assertNull($facts->openingOpenSince);
        self::assertNull($facts->override);
        self::assertNull($facts->vacation);
        self::assertNull($facts->currentTemperature);
    }

    public function testFactsKeepProvidedValues(): void
    {
        $since = Fixtures::at('2026-10-26 07:00');
        $facts = new RoomFacts(houseEmptySince: $since, currentTemperature: Fixtures::temperature(19.5));

        self::assertSame($since, $facts->houseEmptySince);
        self::assertSame(19.5, $facts->currentTemperature?->toFloat());
        self::assertNull($facts->override);
    }

    public function testContextDerivesModeFromSeason(): void
    {
        self::assertSame(HvacMode::Heat, Fixtures::context(Season::Winter)->mode());
        self::assertSame(HvacMode::Cool, Fixtures::context(Season::Summer)->mode());
        self::assertSame(HvacMode::Off, Fixtures::context(Season::Off)->mode());
    }

    public function testWithersReplaceOneFactAndKeepTheOthers(): void
    {
        $at = Fixtures::at('2026-10-26 07:00');
        $vacation = new \App\Domain\Decision\VacationPeriod($at, $at->modify('+1 day'), true);
        $override = \App\Domain\Decision\ActiveOverride::manual(Fixtures::temperature(22.0));

        $facts = RoomFacts::none()
            ->withHouseEmptySince($at)
            ->withRoomVacantSince($at->modify('+1 hour'))
            ->withOpeningOpenSince($at->modify('+2 hours'))
            ->withOverride($override)
            ->withVacation($vacation)
            ->withCurrentTemperature(Fixtures::temperature(19.5));

        self::assertSame($at, $facts->houseEmptySince);
        self::assertEquals($at->modify('+1 hour'), $facts->roomVacantSince);
        self::assertEquals($at->modify('+2 hours'), $facts->openingOpenSince);
        self::assertSame($override, $facts->override);
        self::assertSame($vacation, $facts->vacation);
        self::assertSame(19.5, $facts->currentTemperature?->toFloat());

        $cleared = $facts->withOverride(null)->withCurrentTemperature(null);
        self::assertNull($cleared->override);
        self::assertNull($cleared->currentTemperature);
        self::assertSame($vacation, $cleared->vacation);
    }
}
