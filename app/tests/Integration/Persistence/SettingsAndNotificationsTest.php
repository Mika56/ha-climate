<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Climate\Season;
use App\Infrastructure\Persistence\Entity\GlobalSettings;
use App\Infrastructure\Persistence\Entity\NotificationTarget;
use App\Infrastructure\Persistence\Entity\NotificationTypeSetting;
use App\Infrastructure\Persistence\Entity\UserPreference;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Enum\ThemePreference;
use App\Infrastructure\Persistence\Repository\GlobalSettingsRepository;
use App\Infrastructure\Persistence\Repository\NotificationTargetRepository;
use App\Infrastructure\Persistence\Repository\NotificationTypeSettingRepository;
use App\Infrastructure\Persistence\Repository\UserPreferenceRepository;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaDriver;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaMiddleware;
use App\Infrastructure\Persistence\Support\Guard;
use App\Infrastructure\Persistence\Type\UtcDateTimeImmutableType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(GlobalSettingsRepository::class)]
#[CoversClass(NotificationTargetRepository::class)]
#[CoversClass(NotificationTypeSettingRepository::class)]
#[CoversClass(UserPreferenceRepository::class)]
#[CoversClass(GlobalSettings::class)]
#[CoversClass(NotificationTarget::class)]
#[CoversClass(NotificationTypeSetting::class)]
#[CoversClass(UserPreference::class)]
#[CoversClass(Guard::class)]
#[CoversClass(UtcDateTimeImmutableType::class)]
#[CoversClass(SqlitePragmaMiddleware::class)]
#[CoversClass(SqlitePragmaDriver::class)]
final class SettingsAndNotificationsTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testGlobalSettingsAreCreatedWithDefaultsOnFirstAccess(): void
    {
        $repository = $this->service(GlobalSettingsRepository::class);

        $settings = $repository->get();
        $this->em()->clear();

        self::assertSame(GlobalSettings::SINGLETON_ID, $settings->getId());
        self::assertSame(Season::Winter, $settings->getSeason());
        self::assertFalse($settings->isPaused());
        self::assertSame(1, $this->em()->getRepository(GlobalSettings::class)->count([]));
        self::assertSame(1, $repository->get()->getId());
        self::assertSame(1, $this->em()->getRepository(GlobalSettings::class)->count([]));
    }

    public function testGlobalSettingsRoundTrip(): void
    {
        $repository = $this->service(GlobalSettingsRepository::class);
        $settings = $repository->get();
        $settings->changeSeason(Season::Winter);
        $settings->configurePresence(900, ['person.alice', 'person.bob']);
        $settings->changeManualOverrideMinutes(60);
        $settings->configureVacation(true, self::at('2026-12-20 08:00'), self::at('2027-01-03 18:00'), false);
        $settings->changeWeatherEntity('weather.home');
        $settings->configureLocales('fr', null);
        $settings->changeAuditRetentionDays(30);
        $settings->upgradeSchemaVersion(2);
        $repository->save($settings);
        $this->em()->clear();

        $loaded = $repository->get();

        self::assertSame(Season::Winter, $loaded->getSeason());
        self::assertSame(900, $loaded->getHouseEmptyDelaySeconds());
        self::assertSame(['person.alice', 'person.bob'], $loaded->getTrackedPersons());
        self::assertSame(60, $loaded->getManualOverrideMinutes());
        self::assertTrue($loaded->isVacationEnabled());
        self::assertEquals(self::at('2027-01-03 18:00'), $loaded->getVacationReturnAt());
        self::assertSame('UTC', $loaded->getVacationReturnAt()?->getTimezone()->getName());
        self::assertEquals(self::at('2026-12-20 08:00'), $loaded->getVacationStartAt());
        self::assertFalse($loaded->isOffDuringVacation());
        self::assertSame('weather.home', $loaded->getWeatherEntity());
        self::assertSame('fr', $loaded->getUiLocale());
        self::assertNull($loaded->getNotificationLocale());
        self::assertSame(30, $loaded->getAuditRetentionDays());
        self::assertSame(2, $loaded->getSchemaVersion());
    }

    public function testNotificationTargets(): void
    {
        $repository = $this->service(NotificationTargetRepository::class);
        $repository->save(new NotificationTarget('notify.mobile_app_phone'));
        $repository->save(new NotificationTarget('notify.tablet', false));
        $this->em()->clear();

        $enabled = $repository->findEnabled();
        self::assertCount(1, $enabled);
        self::assertSame('notify.mobile_app_phone', $enabled[0]->getService());

        $tablet = $repository->findOneByService('notify.tablet');
        self::assertInstanceOf(NotificationTarget::class, $tablet);
        $tablet->enable();
        $repository->save($tablet);
        self::assertCount(2, $repository->findEnabled());
        self::assertNull($repository->findOneByService('notify.unknown'));

        $repository->remove($tablet);
        $this->em()->clear();
        self::assertNull($repository->findOneByService('notify.tablet'));

        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save(new NotificationTarget('notify.mobile_app_phone'));
    }

    public function testNotificationTypeSettingsAreUniquePerType(): void
    {
        $repository = $this->service(NotificationTypeSettingRepository::class);
        $repository->save(new NotificationTypeSetting(NotificationType::WindowOpen, true, 1800, false));
        $this->em()->clear();

        $loaded = $repository->findOneByType(NotificationType::WindowOpen);
        self::assertInstanceOf(NotificationTypeSetting::class, $loaded);
        self::assertSame(1800, $loaded->getDelaySeconds());
        self::assertNull($repository->findOneByType(NotificationType::SensorUnavailable));

        $loaded->configure(false, null, true);
        $repository->save($loaded);
        $this->em()->clear();
        $reloaded = $repository->findOneByType(NotificationType::WindowOpen);
        self::assertInstanceOf(NotificationTypeSetting::class, $reloaded);
        self::assertFalse($reloaded->isEnabled());
        self::assertNull($reloaded->getDelaySeconds());
        self::assertTrue($reloaded->isPersistentNotification());

        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save(new NotificationTypeSetting(NotificationType::WindowOpen));
    }

    public function testUserPreferencesAreUniquePerHaUser(): void
    {
        $repository = $this->service(UserPreferenceRepository::class);
        $preference = new UserPreference('abc123');
        $preference->choose('fr', ThemePreference::Dark);
        $repository->save($preference);
        $this->em()->clear();

        $loaded = $repository->findOneByHaUserId('abc123');
        self::assertInstanceOf(UserPreference::class, $loaded);
        self::assertSame('fr', $loaded->getLocale());
        self::assertSame(ThemePreference::Dark, $loaded->getTheme());
        self::assertNull($repository->findOneByHaUserId('nobody'));

        $repository->remove($loaded);
        $this->em()->clear();
        self::assertNull($repository->findOneByHaUserId('abc123'));

        $repository->save(new UserPreference('dup'));
        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save(new UserPreference('dup'));
    }
}
