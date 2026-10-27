<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Room;

use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\PresenceReading;
use App\Domain\Room\PresenceSignal;
use App\Domain\Room\PresenceSignalKind;
use App\Domain\Room\PresenceVacancy;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresenceVacancy::class)]
#[CoversClass(PresenceReading::class)]
#[UsesClass(PresenceSignal::class)]
#[UsesClass(EntityId::class)]
final class PresenceVacancyTest extends TestCase
{
    /**
     * @param array<string, array{string, string}> $states state and last change by entity
     */
    #[DataProvider('provideCases')]
    public function testVacantSince(array $states, ?string $expected): void
    {
        $signals = [
            new PresenceSignal(new EntityId('binary_sensor.motion'), PresenceSignalKind::Active),
            new PresenceSignal(new EntityId('sensor.tv_power'), PresenceSignalKind::Above, 10.0),
            new PresenceSignal(new EntityId('sensor.phone_area'), PresenceSignalKind::Equals, null, 'Living room'),
        ];
        $readings = [];
        foreach ($states as $entity => [$state, $changed]) {
            $readings[$entity] = new PresenceReading($state, Fixtures::at($changed));
        }

        self::assertEquals(null === $expected ? null : Fixtures::at($expected), PresenceVacancy::since($signals, $readings));
    }

    /**
     * @return iterable<string, array{array<string, array{string, string}>, string|null}>
     */
    public static function provideCases(): iterable
    {
        yield 'every signal at rest: the latest change' => [
            ['binary_sensor.motion' => ['off', '2026-10-26 06:00:00'], 'sensor.tv_power' => ['2', '2026-10-26 07:30:00'], 'sensor.phone_area' => ['Kitchen', '2026-10-26 07:00:00']],
            '2026-10-26 07:30:00',
        ];
        yield 'the latest change is not the first listed' => [
            ['binary_sensor.motion' => ['off', '2026-10-26 08:00:00'], 'sensor.tv_power' => ['2', '2026-10-26 07:30:00'], 'sensor.phone_area' => ['Kitchen', '2026-10-26 07:00:00']],
            '2026-10-26 08:00:00',
        ];
        yield 'one active signal: occupied' => [
            ['binary_sensor.motion' => ['off', '2026-10-26 06:00:00'], 'sensor.tv_power' => ['85.2', '2026-10-26 07:30:00'], 'sensor.phone_area' => ['Kitchen', '2026-10-26 07:00:00']],
            null,
        ];
        yield 'an active signal after a vacant one: occupied' => [
            ['binary_sensor.motion' => ['off', '2026-10-26 06:00:00'], 'sensor.phone_area' => ['Living room', '2026-10-26 07:00:00']],
            null,
        ];
        yield 'unavailable and unknown signals are ignored' => [
            ['binary_sensor.motion' => ['off', '2026-10-26 06:00:00'], 'sensor.tv_power' => ['unavailable', '2026-10-26 09:00:00'], 'sensor.phone_area' => ['unknown', '2026-10-26 09:30:00']],
            '2026-10-26 06:00:00',
        ];
        yield 'a missing signal is ignored' => [['sensor.tv_power' => ['0', '2026-10-26 07:30:00']], '2026-10-26 07:30:00'];
        yield 'an unavailable signal does not hide an active one' => [
            ['binary_sensor.motion' => ['on', '2026-10-26 06:00:00'], 'sensor.tv_power' => ['unavailable', '2026-10-26 09:00:00']],
            null,
        ];
        yield 'all signals unavailable or unknown: not known vacant' => [
            ['binary_sensor.motion' => ['unavailable', '2026-10-26 06:00:00'], 'sensor.tv_power' => ['unknown', '2026-10-26 09:00:00']],
            null,
        ];
        yield 'no state at all: not known vacant' => [[], null];
    }

    public function testWithoutSignalTheRoomIsNotKnownVacant(): void
    {
        self::assertNull(PresenceVacancy::since([], []));
    }
}
