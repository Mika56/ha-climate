<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Device;

use App\Domain\Climate\HvacMode;
use App\Domain\Device\DeviceRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceRole::class)]
final class DeviceRoleTest extends TestCase
{
    #[DataProvider('provideCapabilities')]
    public function testCanOperateIn(DeviceRole $role, HvacMode $mode, bool $expected): void
    {
        self::assertSame($expected, $role->canOperateIn($mode));
    }

    /**
     * @return iterable<string, array{DeviceRole, HvacMode, bool}>
     */
    public static function provideCapabilities(): iterable
    {
        yield 'heating heat' => [DeviceRole::Heating, HvacMode::Heat, true];
        yield 'heating cool' => [DeviceRole::Heating, HvacMode::Cool, false];
        yield 'heating off' => [DeviceRole::Heating, HvacMode::Off, false];
        yield 'cooling heat' => [DeviceRole::Cooling, HvacMode::Heat, false];
        yield 'cooling cool' => [DeviceRole::Cooling, HvacMode::Cool, true];
        yield 'cooling off' => [DeviceRole::Cooling, HvacMode::Off, false];
        yield 'reversible heat' => [DeviceRole::Reversible, HvacMode::Heat, true];
        yield 'reversible cool' => [DeviceRole::Reversible, HvacMode::Cool, true];
        yield 'reversible off' => [DeviceRole::Reversible, HvacMode::Off, false];
    }
}
