<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Api\ConfigurationAudit;
use App\Application\Api\EngineNotifier;
use App\Application\Api\Exception\NotFoundException;
use App\Application\Api\Exception\ValidationException;
use App\Application\Api\Exception\Violations;
use App\Application\Api\Input\DuplicateScheduleRequest;
use App\Application\Api\Input\ScheduleRequest;
use App\Application\Api\Input\SlotRequest;
use App\Application\Api\Input\SlotStartRequest;
use App\Application\Api\Schedule\ScheduleBuilder;
use App\Application\Api\Schedule\ScheduleNormalizer;
use App\Application\Api\Schedule\ScheduleService;
use App\UI\Http\Api\ApiExceptionListener;
use App\UI\Http\Api\ApiJsonResponse;
use App\UI\Http\Api\ScheduleController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(ScheduleController::class)]
#[CoversClass(ScheduleService::class)]
#[CoversClass(ScheduleNormalizer::class)]
#[CoversClass(ScheduleBuilder::class)]
#[CoversClass(ScheduleRequest::class)]
#[CoversClass(SlotRequest::class)]
#[CoversClass(SlotStartRequest::class)]
#[CoversClass(DuplicateScheduleRequest::class)]
#[CoversClass(Violations::class)]
#[CoversClass(ValidationException::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(ApiExceptionListener::class)]
#[CoversClass(ApiJsonResponse::class)]
#[CoversClass(ConfigurationAudit::class)]
#[CoversClass(EngineNotifier::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\UI')]
#[UsesNamespace('App\Infrastructure')]
#[UsesNamespace('App\Domain')]
final class ScheduleApiTest extends ApiTestCase
{
    public function testAScheduleIsCreatedReadUpdatedAndDeleted(): void
    {
        $created = $this->call('createSchedule', 'POST', '/api/schedules', self::scheduleBody(), self::asUser())->assertStatus(201)->json();

        self::assertSame('Weekdays', $created['name']);
        self::assertSame('heat', $created['mode']);
        self::assertSame([], $created['usedBy']);
        self::assertSame(
            [
                ['weekday' => 1, 'start' => ['type' => 'fixed', 'minuteOfDay' => 390], 'preset' => 'comfort'],
                ['weekday' => 1, 'start' => ['type' => 'anchored', 'entityId' => 'input_datetime.bedtime', 'offsetMinutes' => -30, 'fallbackMinuteOfDay' => 1350], 'preset' => 'night'],
            ],
            $created['slots'],
        );
        $id = $created['id'];
        self::assertIsString($id);

        self::assertSame($created, $this->call('getSchedule', 'GET', '/api/schedules/'.$id)->assertStatus(200)->json());
        self::assertCount(1, $this->call('listSchedules', 'GET', '/api/schedules')->jsonList());

        $body = ['name' => 'Renamed', 'mode' => 'heat', 'slots' => [['weekday' => 7, 'start' => ['type' => 'fixed', 'minuteOfDay' => 0], 'preset' => 'eco']]];
        $updated = $this->call('updateSchedule', 'PUT', '/api/schedules/'.$id, $body, self::asUser())->assertStatus(200)->json();
        self::assertSame('Renamed', $updated['name']);
        self::assertSame([['weekday' => 7, 'start' => ['type' => 'fixed', 'minuteOfDay' => 0], 'preset' => 'eco']], $updated['slots']);

        $this->call('deleteSchedule', 'DELETE', '/api/schedules/'.$id, null, self::asUser())->assertStatus(204);
        $this->call('getSchedule', 'GET', '/api/schedules/'.$id)->assertError(404, 'not_found');

        $keys = array_map(static fn ($entry): string => $entry->getMessageKey(), $this->auditEntries());
        self::assertContains('audit.configuration.schedule_created', $keys);
        self::assertContains('audit.configuration.schedule_updated', $keys);
        self::assertContains('audit.configuration.schedule_deleted', $keys);
    }

    public function testASchedulesModeCannotBeChangedByAnUpdate(): void
    {
        $id = $this->createSchedule();

        $updated = $this->call('updateSchedule', 'PUT', '/api/schedules/'.$id, self::scheduleBody('Weekdays', 'cool'))->assertStatus(200)->json();

        self::assertSame('heat', $updated['mode']);
    }

    public function testDeletingAScheduleDetachesItFromTheRooms(): void
    {
        $heating = $this->createSchedule('Heat', 'heat');
        $cooling = $this->createSchedule('Cool', 'cool');
        $room = $this->createRoom(overrides: ['heatingScheduleId' => $heating, 'coolingScheduleId' => $cooling]);
        $other = $this->createRoom('Other', 'climate.other', ['heatingScheduleId' => $heating]);

        $used = $this->call('getSchedule', 'GET', '/api/schedules/'.$heating)->json()['usedBy'];
        self::assertSame([['roomId' => $room, 'roomName' => 'Living room'], ['roomId' => $other, 'roomName' => 'Other']], $used);

        $this->call('deleteSchedule', 'DELETE', '/api/schedules/'.$heating)->assertStatus(204);

        $rooms = $this->call('listRooms', 'GET', '/api/rooms')->jsonList();
        self::assertNull($rooms[0]['heatingScheduleId']);
        self::assertSame($cooling, $rooms[0]['coolingScheduleId']);
        self::assertNull($rooms[1]['heatingScheduleId']);

        $this->call('deleteSchedule', 'DELETE', '/api/schedules/'.$cooling)->assertStatus(204);
        self::assertNull($this->call('getRoom', 'GET', '/api/rooms/'.$room)->json()['coolingScheduleId']);
    }

    public function testAScheduleIsDuplicated(): void
    {
        $id = $this->createSchedule();

        $copy = $this->call('duplicateSchedule', 'POST', '/api/schedules/'.$id.'/duplicate', ['name' => 'Copy'], self::asUser())->assertStatus(201)->json();

        self::assertNotSame($id, $copy['id']);
        self::assertSame('Copy', $copy['name']);
        self::assertSame($this->call('getSchedule', 'GET', '/api/schedules/'.$id)->json()['slots'], $copy['slots']);
        self::assertCount(2, $this->call('listSchedules', 'GET', '/api/schedules')->jsonList());
        self::assertCount(1, $this->auditEntries('audit.configuration.schedule_duplicated'));

        $this->call('duplicateSchedule', 'POST', '/api/schedules/'.$id.'/duplicate', ['name' => ''])->assertViolation('name');
        $this->call('duplicateSchedule', 'POST', '/api/schedules/nope/duplicate', ['name' => 'X'])->assertError(404, 'not_found');
    }

    public function testAnInvalidScheduleIsRefused(): void
    {
        $body = [
            'name' => '',
            'mode' => 'heat',
            'slots' => [
                ['weekday' => 9, 'start' => ['type' => 'fixed', 'minuteOfDay' => 2000], 'preset' => 'Bad'],
                ['weekday' => 1, 'start' => ['type' => 'fixed'], 'preset' => 'comfort'],
                ['weekday' => 1, 'start' => ['type' => 'anchored', 'entityId' => 'nope', 'offsetMinutes' => 900], 'preset' => 'comfort'],
                ['weekday' => 1, 'start' => ['type' => 'weird'], 'preset' => 'comfort'],
            ],
        ];

        $this->call('createSchedule', 'POST', '/api/schedules', $body)
            ->assertViolation('name')->assertViolation('slots[0].weekday')->assertViolation('slots[0].start.minuteOfDay')->assertViolation('slots[0].preset')
            ->assertViolation('slots[1].start.minuteOfDay')->assertViolation('slots[2].start.entityId')->assertViolation('slots[2].start.offsetMinutes')
            ->assertViolation('slots[2].start.fallbackMinuteOfDay')->assertViolation('slots[3].start.type');
        $this->call('createSchedule', 'POST', '/api/schedules', ['name' => 'Empty', 'mode' => 'heat', 'slots' => []])->assertViolation('slots');
        $this->call('createSchedule', 'POST', '/api/schedules', ['name' => 'Off', 'mode' => 'off', 'slots' => [['weekday' => 1, 'start' => ['type' => 'fixed', 'minuteOfDay' => 1], 'preset' => 'comfort']]])->assertViolation('mode');
    }

    public function testASlotMustUseAKnownPreset(): void
    {
        $body = ['name' => 'Ghost', 'mode' => 'cool', 'slots' => [['weekday' => 1, 'start' => ['type' => 'fixed', 'minuteOfDay' => 60], 'preset' => 'ghost']]];

        $this->call('createSchedule', 'POST', '/api/schedules', $body)->assertViolation('slots[0].preset');
        $id = $this->createSchedule();
        $this->call('updateSchedule', 'PUT', '/api/schedules/'.$id, $body)->assertViolation('slots[0].preset');
    }

    public function testUnknownSchedulesAreNotFound(): void
    {
        $unknown = '0199e3a0-0000-7000-8000-000000000000';

        $this->call('getSchedule', 'GET', '/api/schedules/'.$unknown)->assertError(404, 'not_found');
        $this->call('updateSchedule', 'PUT', '/api/schedules/'.$unknown, self::scheduleBody())->assertError(404, 'not_found');
        $this->call('deleteSchedule', 'DELETE', '/api/schedules/'.$unknown)->assertError(404, 'not_found');
    }
}
