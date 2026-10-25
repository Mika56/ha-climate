<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Api\ApiTime;
use App\Application\Api\Audit\AuditEntryNormalizer;
use App\Application\Api\Audit\AuditMessageRenderer;
use App\Application\Api\Exception\NotFoundException;
use App\Application\Api\Overview\OverviewService;
use App\Application\Api\Settings\SettingsNormalizer;
use App\Application\Api\Status\StatusService;
use App\Application\Engine\Alert\AlertView;
use App\Infrastructure\Engine\EngineClient;
use App\Infrastructure\Persistence\Entity\Alert;
use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Entity\GlobalSettings;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\UI\Http\Api\ApiExceptionListener;
use App\UI\Http\Api\ApiJsonResponse;
use App\UI\Http\Api\OverviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(OverviewController::class)]
#[CoversClass(OverviewService::class)]
#[CoversClass(StatusService::class)]
#[CoversClass(AlertView::class)]
#[CoversClass(ApiTime::class)]
#[CoversClass(ApiExceptionListener::class)]
#[CoversClass(ApiJsonResponse::class)]
#[CoversClass(AuditEntryNormalizer::class)]
#[CoversClass(AuditMessageRenderer::class)]
#[CoversClass(SettingsNormalizer::class)]
#[CoversClass(EngineClient::class)]
#[CoversClass(NotFoundException::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\UI')]
#[UsesNamespace('App\Infrastructure')]
#[UsesNamespace('App\Domain')]
final class OverviewApiTest extends ApiTestCase
{
    public function testTheOverviewGathersTheLiveFactsOfTheEngine(): void
    {
        $settings = $this->em()->find(GlobalSettings::class, GlobalSettings::SINGLETON_ID);
        self::assertNotNull($settings);
        $settings->changeSeason(\App\Domain\Climate\Season::Winter);
        $settings->configurePresence(1200, ['person.alice', 'person.ghost']);
        $this->em()->flush();
        $this->engine()->answer('GET', '/internal/overview', [
            'persons' => [
                ['entityId' => 'person.alice', 'name' => 'Alice', 'state' => 'not_home'],
                ['entityId' => 'person.bob', 'name' => 'Bob', 'state' => 'home'],
                ['entityId' => 'person.broken'],
            ],
            'weather' => ['entityId' => 'weather.home', 'condition' => 'rainy', 'temperature' => 9.5, 'humidity' => 80, 'temperatureLow' => 7, 'temperatureHigh' => 12],
            'houseEmptySince' => '2026-10-26T06:00:00+01:00',
        ]);

        $overview = $this->call('getOverview', 'GET', '/api/overview')->assertStatus(200)->data();

        self::assertSame('winter', $overview->at('season')->value());
        self::assertFalse($overview->at('paused')->value());
        self::assertSame([
            ['entityId' => 'person.alice', 'name' => 'Alice', 'state' => 'not_home', 'tracked' => true],
            ['entityId' => 'person.bob', 'name' => 'Bob', 'state' => 'home', 'tracked' => false],
            ['entityId' => 'person.ghost', 'name' => 'person.ghost', 'state' => 'unknown', 'tracked' => true],
        ], $overview->at('presence.persons')->value());
        self::assertSame('2026-10-26T06:00:00+01:00', $overview->at('presence.houseEmptySince')->value());
        self::assertSame(1200, $overview->at('presence.houseEmptyDelaySeconds')->value());
        self::assertSame('rainy', $overview->at('weather.condition')->value());
        self::assertFalse($overview->at('vacation.enabled')->value());
        self::assertSame([], $overview->at('alerts')->value());
        self::assertSame([], $overview->at('recentDecisions')->value());
    }

    public function testTheOverviewSaysWhenHaClimateIsPaused(): void
    {
        $this->call('updatePause', 'PUT', '/api/pause', ['paused' => true])->assertStatus(200);
        $roomId = $this->createRoom();
        $this->engine()->answer('GET', '/internal/status', ['rooms' => [self::roomStatus($roomId, overrides: ['paused' => true])]]);

        self::assertTrue($this->call('getOverview', 'GET', '/api/overview')->assertStatus(200)->data()->at('paused')->value());
        self::assertTrue($this->call('listRoomStatuses', 'GET', '/api/status')->assertStatus(200)->data()->at('0.paused')->value());
    }

    public function testTheOverviewStillWorksWithoutTheEngine(): void
    {
        $settings = $this->em()->find(GlobalSettings::class, GlobalSettings::SINGLETON_ID);
        self::assertNotNull($settings);
        $settings->configurePresence(600, ['person.alice']);
        $this->em()->flush();
        $this->engine()->goDown();

        $overview = $this->call('getOverview', 'GET', '/api/overview')->assertStatus(200)->data();

        self::assertSame([['entityId' => 'person.alice', 'name' => 'person.alice', 'state' => 'unknown', 'tracked' => true]], $overview->at('presence.persons')->value());
        self::assertNull($overview->at('presence.houseEmptySince')->value());
        self::assertNull($overview->at('weather')->value());
    }

    public function testTheOverviewShowsTheLastFiveDecisionsAndTheActiveAlertsNewestFirst(): void
    {
        $roomId = $this->createRoom();
        $room = $this->em()->find(Room::class, $roomId);
        self::assertNotNull($room);
        $base = new \DateTimeImmutable('2026-10-26 06:00:00', new \DateTimeZone('UTC'));
        foreach (['decision', 'override', 'opening', 'engine', 'decision', 'override', 'decision'] as $index => $category) {
            $this->em()->persist(new AuditEntry($base->modify(\sprintf('+%d minutes', $index)), ActorType::Automation, $category, 'audit.decision.changed', ['mode' => 'heat', 'target' => 21.0], [], null, null, $room));
        }
        $old = new Alert('entity.sensor.old', 'entity_unavailable', $base, 'sensor.old', $room);
        $new = new Alert('entity.sensor.new', 'entity_unavailable', $base->modify('+1 hour'), 'sensor.new');
        $new->markNotified($base->modify('+2 hours'));
        $resolved = new Alert('entity.sensor.fixed', 'entity_unavailable', $base);
        $resolved->resolve($base->modify('+5 minutes'));
        foreach ([$old, $new, $resolved] as $alert) {
            $this->em()->persist($alert);
        }
        $this->em()->flush();

        $overview = $this->call('getOverview', 'GET', '/api/overview', null, ['Accept-Language' => 'en'])->assertStatus(200)->data();

        self::assertCount(5, $overview->at('recentDecisions')->list());
        self::assertSame(['audit.decision.changed'], array_values(array_unique($overview->at('recentDecisions')->texts('messageKey'))));
        self::assertSame('Heating set to 21 °C', $overview->at('recentDecisions.0.message')->value());
        self::assertSame('2026-10-26T07:06:00+01:00', $overview->at('recentDecisions.0.occurredAt')->value());
        self::assertSame(['entity.sensor.new', 'entity.sensor.old'], $overview->at('alerts')->column('key'));
        self::assertSame($overview->at('alerts')->value(), $this->call('listActiveAlerts', 'GET', '/api/alerts')->assertStatus(200)->jsonList());
        self::assertSame(['key' => 'entity.sensor.new', 'type' => 'entity_unavailable', 'entityId' => 'sensor.new', 'roomId' => null, 'roomName' => null, 'openedAt' => '2026-10-26T08:00:00+01:00', 'notifiedAt' => '2026-10-26T09:00:00+01:00'], $overview->at('alerts.0')->value());
        self::assertSame($roomId, $overview->at('alerts.1.roomId')->value());
    }

    public function testTheStatusOfTheRoomsComesFromTheEngineInRoomOrder(): void
    {
        $first = $this->createRoom('Kitchen', 'climate.kitchen');
        $second = $this->createRoom('Bedroom', 'climate.bedroom');
        $this->engine()->answer('GET', '/internal/status', ['rooms' => [self::roomStatus($second, 'Bedroom'), self::roomStatus($first, 'Kitchen'), self::roomStatus('0199e3a0-0000-7000-8000-000000000000', 'Gone')]]);

        $statuses = $this->call('listRoomStatuses', 'GET', '/api/status')->assertStatus(200)->data();

        self::assertSame([$first, $second, '0199e3a0-0000-7000-8000-000000000000'], $statuses->column('roomId'));
        self::assertSame('reason.no_rule', $statuses->at('0.decision.reason.code')->value());

        $this->engine()->goDown();
        $this->call('listRoomStatuses', 'GET', '/api/status')->assertError(503, 'engine_unavailable');
    }

    public function testNoAlertNoRoomNoStatus(): void
    {
        $this->engine()->answer('GET', '/internal/status', ['rooms' => []]);

        self::assertSame([], $this->call('listRoomStatuses', 'GET', '/api/status')->jsonList());
        self::assertSame([], $this->call('listActiveAlerts', 'GET', '/api/alerts')->jsonList());
    }

    public function testAnUnexpectedStatusPayloadIsAnUnavailableEngine(): void
    {
        $this->engine()->answer('GET', '/internal/status', ['rooms' => 'nope']);

        $this->call('listRoomStatuses', 'GET', '/api/status')->assertError(503, 'engine_unavailable');
    }
}
