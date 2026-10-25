<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Api\ApiTime;
use App\Application\Api\ConfigurationAudit;
use App\Application\Api\EngineNotifier;
use App\Application\Api\Exception\NotFoundException;
use App\Application\Api\Exception\ValidationException;
use App\Application\Api\Exception\Violations;
use App\Application\Api\HaTimeZone;
use App\Application\Api\Input\BoostRequest;
use App\Application\Api\Input\DeviceRequest;
use App\Application\Api\Input\PresetTemperatureRequest;
use App\Application\Api\Input\ReorderRoomsRequest;
use App\Application\Api\Input\RoomRequest;
use App\Application\Api\RequestLocale;
use App\Application\Api\Room\RoomApplier;
use App\Application\Api\Room\RoomChecker;
use App\Application\Api\Room\RoomNormalizer;
use App\Application\Api\Room\RoomService;
use App\Application\Api\Status\StatusService;
use App\Infrastructure\Persistence\Entity\HeatingRate;
use App\Infrastructure\Persistence\Entity\Room;
use App\UI\Http\Api\ApiExceptionListener;
use App\UI\Http\Api\ApiJsonResponse;
use App\UI\Http\Api\RequestLocaleListener;
use App\UI\Http\Api\RoomController;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(RoomController::class)]
#[CoversClass(RoomService::class)]
#[CoversClass(RoomNormalizer::class)]
#[CoversClass(RoomChecker::class)]
#[CoversClass(RoomApplier::class)]
#[CoversClass(RoomRequest::class)]
#[CoversClass(DeviceRequest::class)]
#[CoversClass(PresetTemperatureRequest::class)]
#[CoversClass(ReorderRoomsRequest::class)]
#[CoversClass(ConfigurationAudit::class)]
#[CoversClass(EngineNotifier::class)]
#[CoversClass(StatusService::class)]
#[CoversClass(BoostRequest::class)]
#[CoversClass(ApiExceptionListener::class)]
#[CoversClass(ApiJsonResponse::class)]
#[CoversClass(ValidationException::class)]
#[CoversClass(Violations::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(ApiTime::class)]
#[CoversClass(HaTimeZone::class)]
#[CoversClass(RequestLocale::class)]
#[CoversClass(RequestLocaleListener::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\UI')]
#[UsesNamespace('App\Infrastructure')]
#[UsesNamespace('App\Domain')]
final class RoomApiTest extends ApiTestCase
{
    public function testThereIsNoRoomAtFirst(): void
    {
        $this->call('listRooms', 'GET', '/api/rooms')->assertStatus(200);
        self::assertSame([], $this->call('listRooms', 'GET', '/api/rooms')->jsonList());
    }

    public function testARoomIsCreatedReadUpdatedAndDeleted(): void
    {
        $created = $this->call('createRoom', 'POST', '/api/rooms', self::roomBody(), self::asUser())->assertStatus(201)->json();

        self::assertSame('Living room', $created['name']);
        self::assertSame(0, $created['position']);
        self::assertSame(['heat' => null, 'cool' => null], $created['learnedRates']);
        self::assertSame(
            [['preset' => 'comfort', 'heat' => 21.0, 'cool' => 24.5], ['preset' => 'eco', 'heat' => 18.0, 'cool' => null]],
            $created['presetTemperatures'],
        );
        self::assertSame([['entityId' => 'climate.living', 'role' => 'reversible', 'minOnSeconds' => 300, 'minOffSeconds' => 120, 'hysteresis' => 0.5]], $created['devices']);
        $id = $created['id'];
        self::assertIsString($id);

        self::assertSame($created, $this->call('getRoom', 'GET', '/api/rooms/'.$id)->assertStatus(200)->json());
        self::assertCount(1, $this->call('listRooms', 'GET', '/api/rooms')->jsonList());

        $update = self::roomBody('Lounge', 'climate.lounge', [
            'presetTemperatures' => [['preset' => 'comfort', 'heat' => 20.5, 'cool' => null], ['preset' => 'night', 'heat' => 17.0, 'cool' => 26.0]],
            'openings' => [],
            'presenceSensor' => null,
        ]);
        $updated = $this->call('updateRoom', 'PUT', '/api/rooms/'.$id, $update, self::asUser())->assertStatus(200)->json();
        self::assertSame($id, $updated['id']);
        self::assertSame('Lounge', $updated['name']);
        self::assertSame([['entityId' => 'climate.lounge', 'role' => 'reversible', 'minOnSeconds' => 300, 'minOffSeconds' => 120, 'hysteresis' => 0.5]], $updated['devices']);
        self::assertSame(
            [['preset' => 'comfort', 'heat' => 20.5, 'cool' => null], ['preset' => 'night', 'heat' => 17.0, 'cool' => 26.0]],
            $updated['presetTemperatures'],
        );
        self::assertNull($updated['presenceSensor']);

        $this->call('deleteRoom', 'DELETE', '/api/rooms/'.$id, null, self::asUser())->assertStatus(204);
        $this->call('getRoom', 'GET', '/api/rooms/'.$id)->assertError(404, 'not_found');
        self::assertNull($this->em()->find(Room::class, $id));
    }

    public function testEveryWriteIsAuditedWithTheUserAndTellsTheEngine(): void
    {
        $id = $this->createRoomAs();
        $this->call('updateRoom', 'PUT', '/api/rooms/'.$id, self::roomBody('Renamed'), self::asUser());
        $this->call('deleteRoom', 'DELETE', '/api/rooms/'.$id, null, self::asUser());

        $entries = $this->auditEntries();
        $keys = array_map(static fn ($entry): string => $entry->getMessageKey(), $entries);
        self::assertSame(['audit.configuration.room_created', 'audit.configuration.room_updated', 'audit.configuration.room_deleted'], array_values(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'audit.configuration.room'))));

        $updated = $this->auditEntries('audit.configuration.room_updated')[0];
        self::assertSame('configuration', $updated->getCategory());
        self::assertSame('user', $updated->getActorType()->value);
        self::assertSame('u1', $updated->getActorId());
        self::assertSame('Alice', $updated->getActorName());
        self::assertSame(['room' => 'Renamed'], $updated->getParams());
        $payload = $updated->getPayload();
        self::assertIsArray($payload['before']);
        self::assertSame('Living room', $payload['before']['name']);
        self::assertIsArray($payload['after']);
        self::assertSame('Renamed', $payload['after']['name']);

        $deleted = $this->auditEntries('audit.configuration.room_deleted')[0];
        self::assertSame('Renamed', $deleted->getRoomName(), 'The entry keeps the name of the deleted room.');
        self::assertNull($deleted->getRoom());

        self::assertCount(3, $this->engine()->requestsTo('/internal/reload'));
    }

    public function testAWriteSucceedsWhenTheEngineIsDown(): void
    {
        $this->engine()->goDown();

        $this->call('createRoom', 'POST', '/api/rooms', self::roomBody())->assertStatus(201);

        self::assertCount(1, $this->em()->getRepository(Room::class)->findAll());
        $handler = self::untyped(self::getContainer()->get('monolog.handler.main'));
        self::assertInstanceOf(TestHandler::class, $handler);
        self::assertTrue($handler->hasWarningThatContains('could not be told to reload'));
    }

    public function testTheRoomsKeepTheirDisplayOrder(): void
    {
        $first = $this->createRoom('Kitchen', 'climate.kitchen');
        $second = $this->createRoom('Bedroom', 'climate.bedroom');
        $third = $this->createRoom('Office', 'climate.office');
        self::assertSame(['Kitchen', 'Bedroom', 'Office'], array_column($this->call('listRooms', 'GET', '/api/rooms')->jsonList(), 'name'));

        $this->call('reorderRooms', 'PUT', '/api/rooms/order', ['roomIds' => [$third, $first]], self::asUser())->assertStatus(204);

        $rooms = $this->call('listRooms', 'GET', '/api/rooms')->jsonList();
        self::assertSame([$third, $first, $second], array_column($rooms, 'id'));
        self::assertSame([0, 1, 2], array_column($rooms, 'position'));
        self::assertCount(1, $this->auditEntries('audit.configuration.rooms_reordered'));
    }

    public function testReorderingRefusesUnknownAndRepeatedRooms(): void
    {
        $room = $this->createRoom();

        $response = $this->call('reorderRooms', 'PUT', '/api/rooms/order', ['roomIds' => [$room, $room, '0199e3a0-0000-7000-8000-000000000000']]);

        $response->assertViolation('roomIds[1]')->assertViolation('roomIds[2]');
        $this->call('reorderRooms', 'PUT', '/api/rooms/order', ['roomIds' => ['nope']])->assertViolation('roomIds[0]');
    }

    public function testTheLearnedRatesAreShown(): void
    {
        $id = $this->createRoom();
        $room = $this->em()->find(Room::class, $id);
        self::assertNotNull($room);
        $this->em()->persist(new HeatingRate($room, \App\Domain\Climate\HvacMode::Heat, 1.5, new \DateTimeImmutable(), 4));
        $this->em()->flush();

        $learned = $this->call('getRoom', 'GET', '/api/rooms/'.$id)->json()['learnedRates'];

        self::assertSame(['heat' => ['degreesPerHour' => 1.5, 'samples' => 4], 'cool' => null], $learned);
    }

    public function testUnknownRoomsAreNotFound(): void
    {
        $unknown = '0199e3a0-0000-7000-8000-000000000000';

        $this->call('getRoom', 'GET', '/api/rooms/'.$unknown)->assertError(404, 'not_found');
        $this->call('getRoom', 'GET', '/api/rooms/not-a-uuid')->assertError(404, 'not_found');
        $this->call('updateRoom', 'PUT', '/api/rooms/'.$unknown, self::roomBody())->assertError(404, 'not_found');
        $this->call('deleteRoom', 'DELETE', '/api/rooms/'.$unknown)->assertError(404, 'not_found');
    }

    public function testAnInvalidRoomIsRefusedWithThePathOfEveryProblem(): void
    {
        $body = self::roomBody('', 'Not an entity', [
            'vacancyDelaySeconds' => -1,
            'devices' => [['entityId' => 'climate.a', 'role' => 'reversible', 'minOnSeconds' => 9000], ['entityId' => 'no', 'role' => 'heating']],
            'presetTemperatures' => [['preset' => 'comfort', 'heat' => 99.0]],
            'openings' => ['bad'],
        ]);

        $response = $this->call('createRoom', 'POST', '/api/rooms', $body);

        $response->assertViolation('name')->assertViolation('vacancyDelaySeconds')->assertViolation('devices[0].minOnSeconds')
            ->assertViolation('devices[1].entityId')->assertViolation('presetTemperatures[0].heat')->assertViolation('openings[0]');
        self::assertNotSame('', $response->data()->at('violations.0.message')->text());
        self::assertSame([], $this->em()->getRepository(Room::class)->findAll());

        $wrongTypes = $this->call('createRoom', 'POST', '/api/rooms', self::roomBody('A', 'climate.a', ['devices' => [['entityId' => 'climate.a', 'role' => 'toaster']], 'vacancyDelaySeconds' => 'soon']));
        $wrongTypes->assertViolation('devices[0].role')->assertViolation('vacancyDelaySeconds');
    }

    public function testViolationMessagesAreInTheLanguageOfTheRequest(): void
    {
        $english = $this->call('createRoom', 'POST', '/api/rooms', self::roomBody(''), ['Accept-Language' => 'en'])->data()->at('violations.0.message')->text();
        $french = $this->call('createRoom', 'POST', '/api/rooms', self::roomBody(''), ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.5'])->data()->at('violations.0.message')->text();
        $unsupported = $this->call('createRoom', 'POST', '/api/rooms', self::roomBody(''), ['Accept-Language' => 'de'])->data()->at('violations.0.message')->text();

        self::assertSame('This value should not be blank.', $english);
        self::assertSame('Cette valeur ne doit pas être vide.', $french);
        self::assertSame($english, $unsupported);
    }

    public function testAMissingRequiredFieldAndAMalformedBodyAreViolationsToo(): void
    {
        $this->call('createRoom', 'POST', '/api/rooms', ['devices' => [], 'presetTemperatures' => []])->assertViolation('name');
        $this->call('createRoom', 'POST', '/api/rooms', '{not json')->assertStatus(422);
        $this->call('createRoom', 'POST', '/api/rooms', '')->assertStatus(422);
    }

    public function testReferencesToMissingThingsAreRefused(): void
    {
        $heating = $this->createSchedule('Heat', 'heat');
        $cooling = $this->createSchedule('Cool', 'cool');

        $response = $this->call('createRoom', 'POST', '/api/rooms', self::roomBody('A', 'climate.a', [
            'heatingScheduleId' => $cooling,
            'coolingScheduleId' => '0199e3a0-0000-7000-8000-000000000000',
            'presetTemperatures' => [['preset' => 'ghost', 'heat' => 20.0], ['preset' => 'comfort', 'heat' => 20.0], ['preset' => 'comfort', 'cool' => 25.0]],
            'devices' => [['entityId' => 'climate.a', 'role' => 'heating'], ['entityId' => 'climate.a', 'role' => 'cooling']],
        ]));

        $response->assertViolation('heatingScheduleId')->assertViolation('coolingScheduleId')
            ->assertViolation('presetTemperatures[0].preset')->assertViolation('presetTemperatures[2].preset')->assertViolation('devices[1].entityId');

        $ok = $this->call('createRoom', 'POST', '/api/rooms', self::roomBody('B', 'climate.b', ['heatingScheduleId' => $heating, 'coolingScheduleId' => $cooling]))->assertStatus(201)->json();
        self::assertSame($heating, $ok['heatingScheduleId']);
        self::assertSame($cooling, $ok['coolingScheduleId']);
    }

    public function testADeviceCannotBelongToTwoRooms(): void
    {
        $first = $this->createRoom('First', 'climate.shared');

        $this->call('createRoom', 'POST', '/api/rooms', self::roomBody('Second', 'climate.shared'))->assertViolation('devices[0].entityId');
        $this->call('updateRoom', 'PUT', '/api/rooms/'.$first, self::roomBody('First', 'climate.shared'))->assertStatus(200);
    }

    public function testBoostingARoomGoesThroughTheEngineWithTheUserAsActor(): void
    {
        $id = $this->createRoom();
        $status = self::roomStatus($id, overrides: ['decision' => ['mode' => 'heat', 'target' => 22.0, 'reason' => ['code' => 'reason.boost', 'params' => []]]]);
        $this->engine()->answer('POST', '/internal/rooms/'.$id.'/boost', $status);

        $response = $this->call('startBoost', 'POST', '/api/rooms/'.$id.'/boost', ['delta' => 1.5, 'durationMinutes' => 60], self::asUser())->assertStatus(200);

        self::assertSame(22.0, $response->data()->at('decision.target')->value());
        self::assertSame(['delta' => 1.5, 'untilNextSlot' => false, 'actor' => 'Alice', 'durationMinutes' => 60], $this->engine()->bodyOf('/internal/rooms/'.$id.'/boost'));
        self::assertStringContainsString('"params":{}', $response->body(), 'Empty parameters stay a JSON object.');

        $this->call('startBoost', 'POST', '/api/rooms/'.$id.'/boost', ['delta' => 2, 'untilNextSlot' => true])->assertStatus(200);
        self::assertSame('api', $this->engine()->bodyOf('/internal/rooms/'.$id.'/boost', 1)['actor']);
    }

    public function testABoostIsValidatedAndMapsTheRefusalsOfTheEngine(): void
    {
        $id = $this->createRoom();

        $this->call('startBoost', 'POST', '/api/rooms/'.$id.'/boost', ['delta' => 9, 'durationMinutes' => 0])->assertViolation('delta')->assertViolation('durationMinutes');
        $this->call('startBoost', 'POST', '/api/rooms/'.$id.'/boost', ['delta' => 1])->assertViolation('durationMinutes');
        $this->call('startBoost', 'POST', '/api/rooms/'.$id.'/boost', ['delta' => 1.2, 'durationMinutes' => 5])->assertViolation('delta');
        $this->call('startBoost', 'POST', '/api/rooms/0199e3a0-0000-7000-8000-000000000000/boost', ['delta' => 1, 'durationMinutes' => 5])->assertError(404, 'not_found');

        $this->engine()->answer('POST', '/internal/rooms/'.$id.'/boost', ['error' => 'no_active_mode', 'message' => 'The season is off.'], 409);
        $this->call('startBoost', 'POST', '/api/rooms/'.$id.'/boost', ['delta' => 1, 'durationMinutes' => 5])->assertError(409, 'no_active_mode');

        $this->engine()->answer('POST', '/internal/rooms/'.$id.'/boost', ['error' => 'room_not_found', 'message' => 'gone'], 404);
        $this->call('startBoost', 'POST', '/api/rooms/'.$id.'/boost', ['delta' => 1, 'durationMinutes' => 5])->assertError(404, 'not_found');

        $this->engine()->answer('POST', '/internal/rooms/'.$id.'/boost', ['error' => 'invalid_request', 'message' => 'bad'], 400);
        $this->call('startBoost', 'POST', '/api/rooms/'.$id.'/boost', ['delta' => 1, 'durationMinutes' => 5])->assertViolation('');

        $this->engine()->goDown();
        $this->call('startBoost', 'POST', '/api/rooms/'.$id.'/boost', ['delta' => 1, 'durationMinutes' => 5])->assertError(503, 'engine_unavailable');
    }

    public function testBoostAndCancelAreRefusedWhileHaClimateIsPaused(): void
    {
        $id = $this->createRoom();
        $this->engine()->answer('POST', '/internal/rooms/'.$id.'/boost', ['error' => 'paused', 'message' => 'HA Climate is paused.'], 409);
        $this->engine()->answer('DELETE', '/internal/rooms/'.$id.'/override', ['error' => 'paused', 'message' => 'HA Climate is paused.'], 409);

        $this->call('startBoost', 'POST', '/api/rooms/'.$id.'/boost', ['delta' => 1, 'durationMinutes' => 5])->assertError(409, 'paused');
        $this->call('cancelOverride', 'DELETE', '/api/rooms/'.$id.'/override')->assertError(409, 'paused');
    }

    public function testAnOverrideIsCancelledThroughTheEngine(): void
    {
        $id = $this->createRoom();
        $this->engine()->answer('DELETE', '/internal/rooms/'.$id.'/override', self::roomStatus($id));

        $this->call('cancelOverride', 'DELETE', '/api/rooms/'.$id.'/override', null, self::asUser())->assertStatus(200);
        self::assertSame('/internal/rooms/'.$id.'/override?actor=Alice', $this->engine()->lastPath());

        $this->engine()->answer('DELETE', '/internal/rooms/'.$id.'/override', ['error' => 'no_active_override', 'message' => 'Nothing to cancel.'], 404);
        $this->call('cancelOverride', 'DELETE', '/api/rooms/'.$id.'/override')->assertError(404, 'no_active_override');
        $this->call('cancelOverride', 'DELETE', '/api/rooms/0199e3a0-0000-7000-8000-000000000000/override')->assertError(404, 'not_found');

        $this->engine()->goDown();
        $this->call('cancelOverride', 'DELETE', '/api/rooms/'.$id.'/override')->assertError(503, 'engine_unavailable');
    }

    private function createRoomAs(): string
    {
        $id = $this->call('createRoom', 'POST', '/api/rooms', self::roomBody(), self::asUser())->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }
}
