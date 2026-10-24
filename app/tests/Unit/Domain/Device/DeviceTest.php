<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Device;

use App\Domain\Device\AntiCyclingPolicy;
use App\Domain\Device\Device;
use App\Domain\Device\DeviceRole;
use App\Domain\HomeAssistant\EntityId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Device::class)]
#[UsesClass(AntiCyclingPolicy::class)]
#[UsesClass(EntityId::class)]
final class DeviceTest extends TestCase
{
    public function testHoldsItsParts(): void
    {
        $policy = new AntiCyclingPolicy(300, 300, 0.5);
        $device = new Device(new EntityId('climate.heat_pump'), DeviceRole::Reversible, $policy);

        self::assertSame('climate.heat_pump', (string) $device->entityId);
        self::assertSame(DeviceRole::Reversible, $device->role);
        self::assertSame($policy, $device->antiCycling);
    }
}
