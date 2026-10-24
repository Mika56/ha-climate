<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Climate\Season;
use App\Infrastructure\Persistence\Entity\GlobalSettings;
use App\Infrastructure\Persistence\Entity\NotificationTypeSetting;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\GlobalSettingsRepository;
use App\Infrastructure\Persistence\Repository\NotificationTypeSettingRepository;
use App\Infrastructure\Persistence\Repository\PresetRepository;
use App\Infrastructure\Persistence\Seed\DefaultDataSeeder;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaDriver;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaMiddleware;
use App\Infrastructure\Persistence\Support\Guard;
use App\Infrastructure\Persistence\Type\UtcDateTimeImmutableType;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DefaultDataSeeder::class)]
#[CoversClass(GlobalSettingsRepository::class)]
#[CoversClass(PresetRepository::class)]
#[CoversClass(NotificationTypeSettingRepository::class)]
#[CoversClass(GlobalSettings::class)]
#[CoversClass(NotificationTypeSetting::class)]
#[CoversClass(Preset::class)]
#[CoversClass(Guard::class)]
#[CoversClass(UtcDateTimeImmutableType::class)]
#[CoversClass(SqlitePragmaMiddleware::class)]
#[CoversClass(SqlitePragmaDriver::class)]
final class DefaultDataSeederTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testSeedsPresetsNotificationTypesAndSettings(): void
    {
        $created = $this->service(DefaultDataSeeder::class)->seed();
        $this->em()->clear();

        self::assertSame(9, $created);
        self::assertSame(
            ['comfort', 'eco', 'night', 'away'],
            array_map(static fn (Preset $p): string => $p->getKey(), $this->service(PresetRepository::class)->findAllOrdered()),
        );
        self::assertSame('Comfort', $this->service(PresetRepository::class)->findOneByKey('comfort')?->getLabel());
        self::assertSame(Season::Off, $this->service(GlobalSettingsRepository::class)->get()->getSeason());
        self::assertSame(4, $this->em()->getRepository(NotificationTypeSetting::class)->count([]));
        foreach (NotificationType::cases() as $type) {
            self::assertNotNull($this->service(NotificationTypeSettingRepository::class)->findOneByType($type), $type->value);
        }
    }

    public function testSeedingIsIdempotentAndPreservesUserChanges(): void
    {
        $seeder = $this->service(DefaultDataSeeder::class);
        $seeder->seed();

        $settings = $this->service(GlobalSettingsRepository::class)->get();
        $settings->changeSeason(Season::Summer);
        $eco = $this->service(PresetRepository::class)->findOneByKey('eco');
        self::assertInstanceOf(Preset::class, $eco);
        $eco->describe('Economy', '#00ff00');
        $this->em()->flush();
        $this->em()->clear();

        self::assertSame(0, $seeder->seed());
        $this->em()->clear();

        self::assertSame(4, $this->em()->getRepository(Preset::class)->count([]));
        self::assertSame(4, $this->em()->getRepository(NotificationTypeSetting::class)->count([]));
        self::assertSame(1, $this->em()->getRepository(GlobalSettings::class)->count([]));
        self::assertSame(Season::Summer, $this->service(GlobalSettingsRepository::class)->get()->getSeason());
        self::assertSame('Economy', $this->service(PresetRepository::class)->findOneByKey('eco')?->getLabel());
    }

    public function testSeedingRestoresOnlyMissingRows(): void
    {
        $seeder = $this->service(DefaultDataSeeder::class);
        $seeder->seed();
        $away = $this->service(PresetRepository::class)->findOneByKey('away');
        self::assertInstanceOf(Preset::class, $away);
        $this->service(PresetRepository::class)->remove($away);
        $this->em()->clear();

        self::assertSame(1, $seeder->seed());
    }
}
