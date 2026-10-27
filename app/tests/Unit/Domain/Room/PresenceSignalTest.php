<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Room;

use App\Domain\Exception\InvalidValueException;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\PresenceSignal;
use App\Domain\Room\PresenceSignalKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresenceSignal::class)]
#[UsesClass(EntityId::class)]
final class PresenceSignalTest extends TestCase
{
    #[DataProvider('provideValid')]
    public function testAValidSignalIsBuilt(string $entity, PresenceSignalKind $kind, ?float $threshold, ?string $value): void
    {
        $signal = new PresenceSignal(new EntityId($entity), $kind, $threshold, $value);

        self::assertSame($entity, (string) $signal->entityId);
        self::assertSame($kind, $signal->kind);
        self::assertSame($threshold, $signal->threshold);
        self::assertSame($value, $signal->value);
    }

    /**
     * @return iterable<string, array{string, PresenceSignalKind, float|null, string|null}>
     */
    public static function provideValid(): iterable
    {
        foreach (PresenceSignal::ACTIVE_DOMAINS as $domain) {
            yield 'active '.$domain => [$domain.'.x', PresenceSignalKind::Active, null, null];
        }
        yield 'above, any entity' => ['sensor.tv_power', PresenceSignalKind::Above, 12.5, null];
        yield 'above a negative threshold' => ['sensor.tv_power', PresenceSignalKind::Above, -1.0, null];
        yield 'equals, any entity' => ['sensor.phone_area', PresenceSignalKind::Equals, null, 'Living room'];
        yield 'equals the longest value' => ['sensor.phone_area', PresenceSignalKind::Equals, null, str_repeat('é', 255)];
    }

    #[DataProvider('provideInvalid')]
    public function testAnInconsistentSignalIsRefused(string $entity, PresenceSignalKind $kind, ?float $threshold, ?string $value): void
    {
        $this->expectException(InvalidValueException::class);

        new PresenceSignal(new EntityId($entity), $kind, $threshold, $value);
    }

    /**
     * @return iterable<string, array{string, PresenceSignalKind, float|null, string|null}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'active on a sensor' => ['sensor.x', PresenceSignalKind::Active, null, null];
        yield 'active on a climate' => ['climate.x', PresenceSignalKind::Active, null, null];
        yield 'active with a threshold' => ['light.x', PresenceSignalKind::Active, 1.0, null];
        yield 'active with a value' => ['light.x', PresenceSignalKind::Active, null, 'on'];
        yield 'above without threshold' => ['sensor.x', PresenceSignalKind::Above, null, null];
        yield 'above an infinite threshold' => ['sensor.x', PresenceSignalKind::Above, INF, null];
        yield 'above with a value' => ['sensor.x', PresenceSignalKind::Above, 1.0, 'a'];
        yield 'equals without value' => ['sensor.x', PresenceSignalKind::Equals, null, null];
        yield 'equals a blank value' => ['sensor.x', PresenceSignalKind::Equals, null, '  '];
        yield 'equals a too long value' => ['sensor.x', PresenceSignalKind::Equals, null, str_repeat('a', 256)];
        yield 'equals with a threshold' => ['sensor.x', PresenceSignalKind::Equals, 1.0, 'a'];
    }

    #[DataProvider('provideActivity')]
    public function testActivity(string $entity, PresenceSignalKind $kind, ?float $threshold, ?string $value, string $state, bool $expected): void
    {
        $signal = new PresenceSignal(new EntityId($entity), $kind, $threshold, $value);

        self::assertSame($expected, $signal->isActive($state));
    }

    /**
     * @return iterable<string, array{string, PresenceSignalKind, float|null, string|null, string, bool}>
     */
    public static function provideActivity(): iterable
    {
        yield 'binary sensor on' => ['binary_sensor.x', PresenceSignalKind::Active, null, null, 'on', true];
        yield 'binary sensor off' => ['binary_sensor.x', PresenceSignalKind::Active, null, null, 'off', false];
        yield 'light on' => ['light.x', PresenceSignalKind::Active, null, null, 'on', true];
        yield 'light playing is nothing' => ['light.x', PresenceSignalKind::Active, null, null, 'playing', false];
        yield 'media player playing' => ['media_player.x', PresenceSignalKind::Active, null, null, 'playing', true];
        yield 'media player paused' => ['media_player.x', PresenceSignalKind::Active, null, null, 'paused', true];
        yield 'media player on' => ['media_player.x', PresenceSignalKind::Active, null, null, 'on', true];
        yield 'media player idle' => ['media_player.x', PresenceSignalKind::Active, null, null, 'idle', false];
        yield 'media player off' => ['media_player.x', PresenceSignalKind::Active, null, null, 'off', false];
        yield 'above: over' => ['sensor.x', PresenceSignalKind::Above, 10.0, null, '10.5', true];
        yield 'above: equal is not above' => ['sensor.x', PresenceSignalKind::Above, 10.0, null, '10', false];
        yield 'above: under' => ['sensor.x', PresenceSignalKind::Above, 10.0, null, '3', false];
        yield 'above: not a number' => ['sensor.x', PresenceSignalKind::Above, 10.0, null, 'on', false];
        yield 'equals: same' => ['sensor.x', PresenceSignalKind::Equals, null, 'Living room', 'Living room', true];
        yield 'equals: other room' => ['sensor.x', PresenceSignalKind::Equals, null, 'Living room', 'Kitchen', false];
        yield 'equals: case matters' => ['sensor.x', PresenceSignalKind::Equals, null, 'Living room', 'living room', false];
    }
}
