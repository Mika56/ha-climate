<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Port\HomeAssistant;

use App\Application\Port\HomeAssistant\Area;
use App\Application\Port\HomeAssistant\AuthenticationFailedException;
use App\Application\Port\HomeAssistant\CommandFailedException;
use App\Application\Port\HomeAssistant\CommandTimeoutException;
use App\Application\Port\HomeAssistant\ConnectionLostException;
use App\Application\Port\HomeAssistant\DeviceEntry;
use App\Application\Port\HomeAssistant\EntityRegistryEntry;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use App\Application\Port\HomeAssistant\HomeAssistantException;
use App\Application\Port\HomeAssistant\InvalidPayloadException;
use App\Application\Port\HomeAssistant\ServiceCallResult;
use App\Application\Port\HomeAssistant\ServiceDescription;
use App\Application\Port\HomeAssistant\StateContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Area::class)]
#[CoversClass(CommandFailedException::class)]
#[CoversClass(DeviceEntry::class)]
#[CoversClass(EntityRegistryEntry::class)]
#[CoversClass(EntityState::class)]
#[CoversClass(EntityStateChange::class)]
#[CoversClass(HomeAssistantConfig::class)]
#[CoversClass(ServiceCallResult::class)]
#[CoversClass(ServiceDescription::class)]
#[CoversClass(StateContext::class)]
#[CoversClass(HomeAssistantException::class)]
#[CoversClass(AuthenticationFailedException::class)]
#[CoversClass(CommandTimeoutException::class)]
#[CoversClass(ConnectionLostException::class)]
#[CoversClass(InvalidPayloadException::class)]
final class PortValueObjectsTest extends TestCase
{
    public function testCommandFailedExceptionExposesTheHaErrorCode(): void
    {
        $exception = new CommandFailedException('not_found', 'Service not found.');

        self::assertSame('not_found', $exception->errorCode);
        self::assertSame('not_found: Service not found.', $exception->getMessage());
    }

    /**
     * @param class-string<HomeAssistantException> $class
     */
    #[DataProvider('provideExceptions')]
    public function testEveryExceptionIsAHomeAssistantException(string $class): void
    {
        self::assertSame('boom', (new $class('boom'))->getMessage());
        self::assertTrue(is_subclass_of($class, HomeAssistantException::class));
    }

    /**
     * @return iterable<string, array{class-string<HomeAssistantException>}>
     */
    public static function provideExceptions(): iterable
    {
        yield 'authentication' => [AuthenticationFailedException::class];
        yield 'timeout' => [CommandTimeoutException::class];
        yield 'connection lost' => [ConnectionLostException::class];
        yield 'invalid payload' => [InvalidPayloadException::class];
    }

    public function testStateChangeKnowsWhetherItIsARemoval(): void
    {
        $state = new EntityState('a.b', 'on', [], new \DateTimeImmutable(), new \DateTimeImmutable(), new StateContext('c'));

        self::assertFalse((new EntityStateChange('a.b', null, $state))->isRemoval());
        self::assertTrue((new EntityStateChange('a.b', $state, null))->isRemoval());
    }

    public function testDeviceDisplayNamePrefersTheUserName(): void
    {
        self::assertSame('Mine', (new DeviceEntry('d', 'Original', 'Mine'))->displayName());
        self::assertSame('Original', (new DeviceEntry('d', 'Original'))->displayName());
        self::assertNull((new DeviceEntry('d'))->displayName());
    }

    public function testPlainValueObjectsKeepTheirValues(): void
    {
        $context = new StateContext('id', 'parent', 'user');
        self::assertSame($context, (new ServiceCallResult($context))->context);
        self::assertSame('°C', (new HomeAssistantConfig('en', 'UTC', '°C', '1'))->temperatureUnit);
        self::assertSame('floor', (new Area('a', 'A', 'floor'))->floorId);
        self::assertSame('tado', (new EntityRegistryEntry('x.y', null, null, 'tado'))->platform);
        self::assertSame(['f' => 1], (new ServiceDescription('d', 's', null, null, ['f' => 1]))->fields);
    }
}
