<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision\Rule;

use App\Domain\Climate\Season;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\Rule\NoDeviceForModeRule;
use App\Domain\Decision\TargetResolver;
use App\Domain\Device\DeviceRole;
use App\Tests\Unit\Domain\Support\Fixtures;
use App\Tests\Unit\Domain\Support\SpyRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoDeviceForModeRule::class)]
#[UsesClass(Season::class)]
#[UsesClass(\App\Domain\Climate\Temperature::class)]
#[UsesClass(Decision::class)]
#[UsesClass(\App\Domain\Decision\GlobalPolicy::class)]
#[UsesClass(Reason::class)]
#[UsesClass(\App\Domain\Decision\RoomConfiguration::class)]
#[UsesClass(\App\Domain\Decision\RoomEvaluationContext::class)]
#[UsesClass(\App\Domain\Decision\RoomFacts::class)]
#[UsesClass(TargetResolver::class)]
#[UsesClass(\App\Domain\Device\AntiCyclingPolicy::class)]
#[UsesClass(\App\Domain\Device\Device::class)]
#[UsesClass(DeviceRole::class)]
#[UsesClass(\App\Domain\HomeAssistant\EntityId::class)]
#[UsesClass(\App\Domain\Preset\PresetKey::class)]
#[UsesClass(\App\Domain\Room\PresetTemperatures::class)]
#[UsesClass(\App\Domain\Room\RoomId::class)]
#[UsesClass(\App\Domain\Schedule\ScheduleId::class)]
final class NoDeviceForModeRuleTest extends TestCase
{
    /**
     * @param list<DeviceRole> $roles
     */
    #[DataProvider('provideBlockedCases')]
    public function testTurnsOffWhenNoDeviceCanOperate(Season $season, array $roles, string $mode): void
    {
        $devices = array_map(static fn (DeviceRole $role) => Fixtures::device($role), $roles);
        $next = new SpyRule();

        $decision = (new TargetResolver([new NoDeviceForModeRule(), $next]))
            ->resolve(Fixtures::context($season, Fixtures::room($devices)));

        self::assertTrue($decision->isOff());
        self::assertSame('reason.no_device', $decision->reason->code);
        self::assertSame(['mode' => $mode], $decision->reason->params);
        self::assertSame(0, $next->calls);
    }

    /**
     * @return iterable<string, array{Season, list<DeviceRole>, string}>
     */
    public static function provideBlockedCases(): iterable
    {
        yield 'winter without devices' => [Season::Winter, [], 'heat'];
        yield 'winter with air conditioner only' => [Season::Winter, [DeviceRole::Cooling], 'heat'];
        yield 'summer with heater only' => [Season::Summer, [DeviceRole::Heating], 'cool'];
    }

    /**
     * @param list<DeviceRole> $roles
     */
    #[DataProvider('provideAllowedCases')]
    public function testDelegatesWhenADeviceCanOperate(Season $season, array $roles): void
    {
        $devices = array_map(static fn (DeviceRole $role) => Fixtures::device($role), $roles);
        $next = new SpyRule(Decision::off(new Reason('reason.next')));

        $decision = (new TargetResolver([new NoDeviceForModeRule(), $next]))
            ->resolve(Fixtures::context($season, Fixtures::room($devices)));

        self::assertSame('reason.next', $decision->reason->code);
    }

    /**
     * @return iterable<string, array{Season, list<DeviceRole>}>
     */
    public static function provideAllowedCases(): iterable
    {
        yield 'winter heater' => [Season::Winter, [DeviceRole::Heating]];
        yield 'summer air conditioner' => [Season::Summer, [DeviceRole::Heating, DeviceRole::Cooling]];
        yield 'reversible both seasons' => [Season::Summer, [DeviceRole::Reversible]];
        yield 'season off is left to its own rule' => [Season::Off, []];
    }

    public function testPriority(): void
    {
        self::assertSame(900, NoDeviceForModeRule::priority());
    }
}
