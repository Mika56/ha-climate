<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Api\Backup\BackupExporter;
use App\Application\Api\Backup\BackupRestorer;
use App\Application\Api\ConfigurationAudit;
use App\Application\Api\EngineNotifier;
use App\Application\Api\Exception\ValidationException;
use App\Application\Api\Exception\Violations;
use App\Application\Api\Input\BackupRequest;
use App\Application\Api\Input\RoomRequest;
use App\Application\Api\Input\ScheduleRequest;
use App\Application\Api\Input\SettingsRequest;
use App\Application\Api\Preset\PresetService;
use App\Application\Api\Room\RoomApplier;
use App\Application\Api\Room\RoomNormalizer;
use App\Application\Api\Schedule\ScheduleBuilder;
use App\Application\Api\Schedule\ScheduleNormalizer;
use App\Application\Api\Settings\NotificationSettingsApplier;
use App\Application\Api\Settings\NotificationSettingsService;
use App\Application\Api\Settings\SettingsApplier;
use App\Application\Api\Settings\SettingsNormalizer;
use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Entity\HeatingRate;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\Timer;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\UI\Http\Api\ApiExceptionListener;
use App\UI\Http\Api\ApiJsonResponse;
use App\UI\Http\Api\BackupController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(BackupController::class)]
#[CoversClass(BackupExporter::class)]
#[CoversClass(BackupRestorer::class)]
#[CoversClass(BackupRequest::class)]
#[CoversClass(ValidationException::class)]
#[CoversClass(Violations::class)]
#[CoversClass(ApiExceptionListener::class)]
#[CoversClass(ApiJsonResponse::class)]
#[CoversClass(ConfigurationAudit::class)]
#[CoversClass(EngineNotifier::class)]
#[CoversClass(RoomApplier::class)]
#[CoversClass(RoomNormalizer::class)]
#[CoversClass(ScheduleBuilder::class)]
#[CoversClass(ScheduleNormalizer::class)]
#[CoversClass(SettingsApplier::class)]
#[CoversClass(SettingsNormalizer::class)]
#[CoversClass(NotificationSettingsApplier::class)]
#[CoversClass(NotificationSettingsService::class)]
#[CoversClass(PresetService::class)]
#[CoversClass(ScheduleRequest::class)]
#[CoversClass(RoomRequest::class)]
#[CoversClass(SettingsRequest::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\UI')]
#[UsesNamespace('App\Infrastructure')]
#[UsesNamespace('App\Domain')]
final class BackupApiTest extends ApiTestCase
{
    /**
     * A house with two schedules, two rooms, vacation, tracked persons and notification targets.
     */
    private function buildHouse(): void
    {
        $this->call('updateSettings', 'PUT', '/api/settings', [
            'season' => 'winter',
            'trackedPersons' => ['person.alice'],
            'houseEmptyDelaySeconds' => 1800,
            'manualOverrideMinutes' => 90,
            'vacation' => ['enabled' => true, 'startAt' => '2026-12-20T08:00:00+01:00', 'returnAt' => '2027-01-03T18:30:00+01:00', 'offDuringVacation' => false],
            'weatherEntity' => 'weather.home',
            'uiLocale' => 'fr',
            'notificationLocale' => 'en',
            'auditRetentionDays' => 30,
        ]);
        $this->call('createPreset', 'POST', '/api/presets', ['key' => 'party', 'label' => 'Party', 'color' => '#abcdef', 'position' => 9]);
        $heating = $this->createSchedule('Heating', 'heat');
        $cooling = $this->createSchedule('Cooling', 'cool');
        $this->createRoom('Living room', 'climate.living', ['heatingScheduleId' => $heating, 'coolingScheduleId' => $cooling]);
        $this->createRoom('Bedroom', 'climate.bedroom', [
            'heatingScheduleId' => $heating,
            'devices' => [['entityId' => 'climate.bed_a', 'role' => 'heating', 'minOnSeconds' => 60, 'minOffSeconds' => 30, 'hysteresis' => 1.0], ['entityId' => 'climate.bed_b', 'role' => 'cooling', 'minOnSeconds' => 0, 'minOffSeconds' => 0, 'hysteresis' => 0.5]],
            'presetTemperatures' => [['preset' => 'party', 'heat' => 22.5, 'cool' => 23.0]],
        ]);
        $this->call('updateNotificationSettings', 'PUT', '/api/notifications', [
            'targets' => [['service' => 'notify.mobile_app_pixel', 'enabled' => true], ['service' => 'notify.living_tv', 'enabled' => false]],
            'types' => [['type' => 'window_open', 'enabled' => false, 'delaySeconds' => 900, 'persistentNotification' => false]],
        ]);
    }

    public function testTheConfigurationIsExportedAsAVersionedDocument(): void
    {
        $this->buildHouse();

        $response = $this->call('exportConfiguration', 'GET', '/api/backup')->assertStatus(200);
        $backup = $response->data();

        self::assertStringContainsString('attachment', (string) $response->header('Content-Disposition'));
        self::assertSame('ha-climate-backup', $backup->at('format')->value());
        self::assertSame(1, $backup->at('version')->value());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+0[12]:00$/', $backup->at('exportedAt')->text());
        self::assertArrayNotHasKey('auditEntryCount', $backup->at('settings')->map());
        self::assertSame(['schedule-1', 'schedule-2'], $backup->at('schedules')->column('ref'));
        self::assertSame(['Cooling', 'Heating'], $backup->at('schedules')->column('name'));
        self::assertSame(['Living room', 'Bedroom'], $backup->at('rooms')->column('name'));
        self::assertSame('schedule-2', $backup->at('rooms.0.heatingScheduleRef')->value());
        self::assertSame('schedule-1', $backup->at('rooms.0.coolingScheduleRef')->value());
        self::assertNull($backup->at('rooms.1.coolingScheduleRef')->value());
        self::assertArrayNotHasKey('heatingScheduleId', $backup->at('rooms.0')->map());
        self::assertSame([['service' => 'notify.living_tv', 'enabled' => false], ['service' => 'notify.mobile_app_pixel', 'enabled' => true]], $backup->at('notifications.targets')->value());
        self::assertContains('party', $backup->at('presets')->column('key'));
    }

    public function testExportingRestoringAndExportingAgainGivesTheSameDocument(): void
    {
        $this->buildHouse();
        $first = $this->call('exportConfiguration', 'GET', '/api/backup')->json();

        $restored = $this->call('restoreConfiguration', 'POST', '/api/backup/restore', $first, self::asUser())->assertStatus(200)->json();
        $second = $this->call('exportConfiguration', 'GET', '/api/backup')->json();

        self::assertSame(['rooms' => 2, 'schedules' => 2, 'presets' => 5], $restored);
        unset($first['exportedAt'], $second['exportedAt']);
        self::assertSame($first, $second);
    }

    public function testRestoringReplacesEverythingAndKeepsWhatTheEngineProduced(): void
    {
        $this->buildHouse();
        $backup = $this->call('exportConfiguration', 'GET', '/api/backup')->json();
        $oldRooms = $this->call('listRooms', 'GET', '/api/rooms')->data()->texts('id');
        $room = $this->em()->find(Room::class, $oldRooms[0]);
        self::assertNotNull($room);
        $this->em()->persist(new HeatingRate($room, HvacMode::Heat, 2.0, new \DateTimeImmutable()));
        $this->em()->persist(new Timer('maintenance', 'daily_maintenance', new \DateTimeImmutable('+1 day'), null, []));
        $this->em()->persist(new AuditEntry(new \DateTimeImmutable(), ActorType::System, 'engine', 'audit.connection.lost'));
        $this->em()->flush();
        $this->em()->clear();

        // A different configuration is in place when the backup is restored.
        $this->call('updateSettings', 'PUT', '/api/settings', [...$this->call('getSettings', 'GET', '/api/settings')->json(), 'season' => 'summer', 'trackedPersons' => []]);
        $this->call('deleteRoom', 'DELETE', '/api/rooms/'.$oldRooms[1]);
        $this->createRoom('Garage', 'climate.garage');

        $this->call('restoreConfiguration', 'POST', '/api/backup/restore', $backup, self::asUser())->assertStatus(200);

        $rooms = $this->call('listRooms', 'GET', '/api/rooms')->data();
        self::assertSame(['Living room', 'Bedroom'], $rooms->column('name'));
        self::assertNotContains($oldRooms[0], $rooms->column('id'), 'Rooms are recreated.');
        self::assertNull($rooms->at('0.learnedRates.heat')->value(), 'The rate of a deleted room goes with it.');
        self::assertSame('winter', $this->call('getSettings', 'GET', '/api/settings')->data()->at('season')->value());
        self::assertCount(1, $this->em()->getRepository(Timer::class)->findAll(), 'Timers are kept.');
        $keys = array_map(static fn (AuditEntry $entry): string => $entry->getMessageKey(), $this->auditEntries());
        self::assertContains('audit.connection.lost', $keys, 'The audit trail is kept.');
        $restored = $this->auditEntries('audit.configuration.restored');
        self::assertCount(1, $restored);
        self::assertSame(['rooms' => 2, 'schedules' => 2, 'presets' => 5], $restored[0]->getParams());
        self::assertSame('Alice', $restored[0]->getActorName());
        self::assertNotEmpty($this->engine()->requestsTo('/internal/reload'));
    }

    public function testNothingChangesWhenTheBackupIsInconsistent(): void
    {
        $this->buildHouse();
        $backup = $this->call('exportConfiguration', 'GET', '/api/backup')->json();
        $before = $this->call('listRooms', 'GET', '/api/rooms')->jsonList();

        $document = new Json($backup);
        $broken = self::with($backup, 'presets', [$document->at('presets.0')->value(), $document->at('presets.0')->value()]);
        $broken = self::with($broken, 'schedules.0.ref', null);
        $broken = self::with($broken, 'schedules.1.ref', 'dup');
        $broken = self::with($broken, 'schedules.1.slots.0.preset', 'ghost');
        $broken = self::with($broken, 'schedules.2', [...$document->at('schedules.1')->map(), 'ref' => 'dup']);
        $broken = self::with($broken, 'rooms.0.heatingScheduleRef', 'nope');
        $broken = self::with($broken, 'rooms.0.coolingScheduleRef', 'dup');
        $broken = self::with($broken, 'rooms.0.presetTemperatures', [['preset' => 'ghost', 'heat' => 20.0, 'cool' => null], ['preset' => 'comfort', 'heat' => 20.0, 'cool' => null], ['preset' => 'comfort', 'heat' => 21.0, 'cool' => null]]);
        $broken = self::with($broken, 'rooms.1.devices', [
            ...$document->at('rooms.1.devices')->list(),
            $document->at('rooms.0.devices.0')->value(),
            $document->at('rooms.1.devices.0')->value(),
        ]);

        $this->call('restoreConfiguration', 'POST', '/api/backup/restore', $broken)
            ->assertViolation('presets[1].key')->assertViolation('presets')
            ->assertViolation('schedules[0].ref')->assertViolation('schedules[1].slots[0].preset')->assertViolation('schedules[2].ref')
            ->assertViolation('rooms[0].heatingScheduleRef')->assertViolation('rooms[0].coolingScheduleRef')
            ->assertViolation('rooms[0].presetTemperatures[0].preset')->assertViolation('rooms[0].presetTemperatures[2].preset')
            ->assertViolation('rooms[1].devices[2].entityId')->assertViolation('rooms[1].devices[3].entityId');
        self::assertSame($before, $this->call('listRooms', 'GET', '/api/rooms')->jsonList());
        self::assertSame([], $this->auditEntries('audit.configuration.restored'));
    }

    public function testTheFormatAndVersionMustBeSupported(): void
    {
        $backup = $this->call('exportConfiguration', 'GET', '/api/backup')->json();

        $this->call('restoreConfiguration', 'POST', '/api/backup/restore', [...$backup, 'format' => 'something-else'])->assertViolation('format');
        $this->call('restoreConfiguration', 'POST', '/api/backup/restore', [...$backup, 'version' => 2])->assertViolation('version');
        $this->call('restoreConfiguration', 'POST', '/api/backup/restore', ['format' => 'ha-climate-backup', 'version' => 1])->assertStatus(422);
    }

    public function testTheEssentialPresetsCannotBeDroppedByARestore(): void
    {
        $backup = $this->call('exportConfiguration', 'GET', '/api/backup')->json();
        $presets = array_values(array_filter(
            (new Json($backup))->at('presets')->rows(),
            static fn (array $preset): bool => 'away' !== $preset['key'],
        ));

        $this->call('restoreConfiguration', 'POST', '/api/backup/restore', self::with($backup, 'presets', $presets))->assertViolation('presets');
    }

    public function testAnEmptyBackupCanBeRestoredOnceTheEssentialPresetsAreThere(): void
    {
        $this->buildHouse();
        $backup = $this->call('exportConfiguration', 'GET', '/api/backup')->json();
        $presets = array_values(array_filter(
            (new Json($backup))->at('presets')->rows(),
            static fn (array $preset): bool => \in_array($preset['key'], ['eco', 'away'], true),
        ));
        $empty = self::with(self::with(self::with(self::with($backup, 'rooms', []), 'schedules', []), 'presets', $presets), 'notifications', ['targets' => [], 'types' => []]);

        $this->call('restoreConfiguration', 'POST', '/api/backup/restore', $empty)->assertStatus(200);

        self::assertSame([], $this->call('listRooms', 'GET', '/api/rooms')->jsonList());
        self::assertSame([], $this->call('listSchedules', 'GET', '/api/schedules')->jsonList());
        self::assertSame(['eco', 'away'], array_column($this->call('listPresets', 'GET', '/api/presets')->jsonList(), 'key'));
    }
}
