<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\HomeAssistant;

use App\Application\Port\HomeAssistant\HomeAssistantException;
use App\Infrastructure\HomeAssistant\ConnectionSettings;
use App\Infrastructure\HomeAssistant\ConnectionSettingsFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionSettingsFactory::class)]
#[CoversClass(ConnectionSettings::class)]
final class ConnectionSettingsFactoryTest extends TestCase
{
    public function testSupervisorTokenWinsOverStandaloneSettings(): void
    {
        $settings = ConnectionSettingsFactory::fromEnvironment('sup-token', 'http://ignored:8123', 'ignored');

        self::assertSame('ws://supervisor/core/websocket', $settings->url);
        self::assertSame('sup-token', $settings->token);
        self::assertSame(10.0, $settings->commandTimeout);
        self::assertSame(30.0, $settings->pingInterval);
        self::assertSame(10.0, $settings->pongTimeout);
    }

    public function testStandaloneSettings(): void
    {
        $settings = ConnectionSettingsFactory::fromEnvironment('', 'http://homeassistant.local:8123', 'long-lived');

        self::assertSame('ws://homeassistant.local:8123/api/websocket', $settings->url);
        self::assertSame('long-lived', $settings->token);
    }

    #[DataProvider('provideUrls')]
    public function testUrlConversion(string $url, string $expected): void
    {
        self::assertSame($expected, ConnectionSettingsFactory::toWebsocketUrl($url));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUrls(): iterable
    {
        yield 'http with port' => ['http://ha:8123', 'ws://ha:8123/api/websocket'];
        yield 'https without port' => ['https://ha.example.org', 'wss://ha.example.org/api/websocket'];
        yield 'trailing slash' => ['http://ha:8123/', 'ws://ha:8123/api/websocket'];
        yield 'path prefix' => ['http://ha/prefix/', 'ws://ha/prefix/api/websocket'];
        yield 'already websocket' => ['ws://ha:8123/api/websocket', 'ws://ha:8123/api/websocket'];
        yield 'wss' => ['wss://ha', 'wss://ha/api/websocket'];
        yield 'surrounding spaces' => [' http://ha:8123 ', 'ws://ha:8123/api/websocket'];
    }

    #[DataProvider('provideInvalidConfigurations')]
    public function testInvalidConfiguration(string $url, string $token): void
    {
        $this->expectException(HomeAssistantException::class);

        ConnectionSettingsFactory::fromEnvironment('', $url, $token);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidConfigurations(): iterable
    {
        yield 'nothing configured' => ['', ''];
        yield 'missing token' => ['http://ha:8123', ''];
        yield 'missing url' => ['', 'token'];
        yield 'no scheme' => ['ha:8123', 'token'];
        yield 'unsupported scheme' => ['ftp://ha', 'token'];
        yield 'not a url' => ['http:///', 'token'];
    }
}
