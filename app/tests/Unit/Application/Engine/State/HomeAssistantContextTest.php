<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\State;

use App\Application\Engine\State\HomeAssistantContext;
use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HomeAssistantContext::class)]
#[UsesClass(HomeAssistantConfig::class)]
final class HomeAssistantContextTest extends TestCase
{
    public function testDefaultsApplyUntilConfigured(): void
    {
        $context = new HomeAssistantContext();

        self::assertSame('UTC', $context->timeZone()->getName());
        self::assertSame('en', $context->language());
    }

    public function testTakesTheTimeZoneAndLanguageOfHomeAssistant(): void
    {
        $context = new HomeAssistantContext();

        $context->update(new HomeAssistantConfig('fr', 'Europe/Paris', '°C', '2026.10.0'));

        self::assertSame('Europe/Paris', $context->timeZone()->getName());
        self::assertSame('fr', $context->language());
    }

    public function testFallsBackOnUtcAndEnglishWhenTheConfigIsUnusable(): void
    {
        $context = new HomeAssistantContext();
        $context->update(new HomeAssistantConfig('fr', 'Europe/Paris', '°C', '2026.10.0'));

        $context->update(new HomeAssistantConfig('', 'Mars/Olympus', '°C', '2026.10.0'));

        self::assertSame('UTC', $context->timeZone()->getName());
        self::assertSame('en', $context->language());
    }

    public function testNothingIsKnownBeforeTheConfigurationIsRead(): void
    {
        $context = new HomeAssistantContext();

        self::assertFalse($context->isKnown());
        self::assertFalse($context->isConnected());
        self::assertNull($context->version());
    }

    public function testTracksTheVersionAndTheConnection(): void
    {
        $context = new HomeAssistantContext();
        $context->update(new HomeAssistantConfig('fr', 'Europe/Paris', '°C', '2026.10.0'));

        self::assertTrue($context->isKnown());
        self::assertTrue($context->isConnected());
        self::assertSame('2026.10.0', $context->version());

        $context->connectionLost();
        self::assertFalse($context->isConnected());
        self::assertTrue($context->isKnown(), 'What was learnt stays valid while the connection is down.');

        $context->connectionRestored();
        self::assertTrue($context->isConnected());

        $context->update(new HomeAssistantConfig('fr', 'Europe/Paris', '°C', ''));
        self::assertNull($context->version());
    }
}
