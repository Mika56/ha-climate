<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\Entity;

use App\Domain\Climate\Season;
use App\Infrastructure\Persistence\Entity\GlobalSettings;
use App\Infrastructure\Persistence\Entity\NotificationTarget;
use App\Infrastructure\Persistence\Entity\NotificationTypeSetting;
use App\Infrastructure\Persistence\Entity\UserPreference;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Enum\ThemePreference;
use App\Infrastructure\Persistence\Support\Guard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlobalSettings::class)]
#[CoversClass(NotificationTarget::class)]
#[CoversClass(NotificationTypeSetting::class)]
#[CoversClass(UserPreference::class)]
#[UsesClass(Guard::class)]
final class SettingsEntitiesTest extends TestCase
{
    public function testGlobalSettingsDefaults(): void
    {
        $settings = new GlobalSettings();

        self::assertSame(1, $settings->getId());
        self::assertSame(Season::Off, $settings->getSeason());
        self::assertFalse($settings->isVacationEnabled());
        self::assertNull($settings->getVacationStartAt());
        self::assertNull($settings->getUiLocale());
        self::assertSame(GlobalSettings::CURRENT_SCHEMA_VERSION, $settings->getSchemaVersion());
    }

    public function testTrackedPersonsAreDeduplicated(): void
    {
        $settings = new GlobalSettings();
        $settings->configurePresence(60, ['person.a', 'person.a', 'person.b']);

        self::assertSame(60, $settings->getHouseEmptyDelaySeconds());
        self::assertSame(['person.a', 'person.b'], $settings->getTrackedPersons());
    }

    public function testEnabledVacationRequiresAReturnDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new GlobalSettings())->configureVacation(true, null, null, true);
    }

    public function testVacationReturnMustFollowStart(): void
    {
        $at = new \DateTimeImmutable('2026-01-01 00:00', new \DateTimeZone('UTC'));

        $this->expectException(\InvalidArgumentException::class);
        (new GlobalSettings())->configureVacation(true, $at, $at, true);
    }

    public function testDisabledVacationMayBeIncomplete(): void
    {
        $settings = new GlobalSettings();
        $settings->configureVacation(false, null, null, true);

        self::assertFalse($settings->isVacationEnabled());
        self::assertTrue($settings->isOffDuringVacation());
    }

    public function testSchemaVersionCannotGoBackwards(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new GlobalSettings())->upgradeSchemaVersion(0);
    }

    public function testRejectsInvalidSettings(): void
    {
        $settings = new GlobalSettings();

        foreach ([
            static fn () => $settings->changeManualOverrideMinutes(0),
            static fn () => $settings->changeAuditRetentionDays(0),
            static fn () => $settings->configurePresence(-1, []),
            static fn () => $settings->changeWeatherEntity(' '),
            static fn () => $settings->configureLocales(' ', null),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNotificationTargetMustBeANotifyService(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NotificationTarget('light.kitchen');
    }

    public function testNotificationTargetToggle(): void
    {
        $target = new NotificationTarget('notify.phone', false);
        self::assertFalse($target->isEnabled());
        self::assertNotEmpty($target->getId()->toRfc4122());

        $target->enable();
        self::assertTrue($target->isEnabled());
        $target->disable();
        self::assertFalse($target->isEnabled());
    }

    public function testNotificationTypeSettingRejectsNegativeDelay(): void
    {
        $setting = new NotificationTypeSetting(NotificationType::WindowOpen);
        self::assertTrue($setting->isEnabled());
        self::assertSame(NotificationType::WindowOpen, $setting->getType());
        self::assertNotEmpty($setting->getId()->toRfc4122());

        $this->expectException(\InvalidArgumentException::class);
        $setting->configure(true, -5, false);
    }

    public function testNotificationTypeSettingRejectsNegativeInitialDelay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NotificationTypeSetting(NotificationType::WindowOpen, true, -1);
    }

    public function testUserPreferenceDefaultsAndChoice(): void
    {
        $preference = new UserPreference('user-1');
        self::assertSame(ThemePreference::Auto, $preference->getTheme());
        self::assertNull($preference->getLocale());
        self::assertNotEmpty($preference->getId()->toRfc4122());

        $preference->choose('en', ThemePreference::Light);
        self::assertSame('en', $preference->getLocale());
        self::assertSame('user-1', $preference->getHaUserId());
    }

    public function testUserPreferenceRequiresAUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UserPreference('');
    }
}
