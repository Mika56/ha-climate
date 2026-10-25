<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Alert\AlertsChanged;
use App\Application\Engine\Alert\AlertView;
use App\Application\Engine\Alert\RoomAlertsProvider;
use App\Application\Engine\Alert\RoomStatusRefresher;
use App\Application\Engine\Evaluation\LiveStatusPublisher;
use App\Application\Engine\Evaluation\RoomEvaluator;
use App\Application\Engine\Evaluation\RoomStatus;
use App\Application\Engine\Evaluation\RoomStatusFactory;
use App\Domain\Climate\Season;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

/**
 * What the published room status carries besides the decision: the facts behind it, the device activity and the
 * active alerts, kept up to date when an alert opens or resolves.
 */
#[CoversClass(RoomStatus::class)]
#[CoversClass(RoomStatusFactory::class)]
#[CoversClass(RoomEvaluator::class)]
#[CoversClass(RoomAlertsProvider::class)]
#[CoversClass(RoomStatusRefresher::class)]
#[CoversClass(AlertsChanged::class)]
#[CoversClass(AlertView::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class RoomStatusFeedTest extends NotificationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setSeason(Season::Winter);
    }

    public function testTheStatusShowsTheAlertsOfTheRoomWhenTheyOpenAndWhenTheyResolve(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();
        self::assertSame([], $this->roomStatus()->alerts);

        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');

        $alerts = $this->roomStatus()->alerts;
        self::assertCount(1, $alerts);
        self::assertSame('entity.'.self::SENSOR, $alerts[0]->key);
        self::assertSame('entity_unavailable', $alerts[0]->type);
        self::assertSame('Living room', $alerts[0]->roomName);
        self::assertSame('+01:00', substr($alerts[0]->openedAt, -6), 'In the Home Assistant time zone.');
        self::assertNotNull($alerts[0]->notifiedAt);

        $this->homeAssistant()->pushStateChange(self::SENSOR, '19.0');

        self::assertSame([], $this->roomStatus()->alerts);
    }

    public function testTheStatusReportsTheFactsAndWhetherTheDeviceIsRunning(): void
    {
        $this->createRoom();
        $this->homeAssistant()->seedState(self::DEVICE, 'heat', ['hvac_modes' => ['off', 'heat'], 'temperature' => 21.0, 'current_temperature' => 19.5, 'hvac_action' => 'heating']);
        $this->seedSensor();
        $this->homeAssistant()->seedState('binary_sensor.living_room_window', 'on');
        $this->startEngine();

        $json = $this->roomStatus()->jsonSerialize();

        self::assertIsArray($json['devices']);
        self::assertIsArray($json['devices'][0]);
        self::assertTrue($json['devices'][0]['running']);
        self::assertIsArray($json['facts']);
        self::assertSame($this->clock()->now()->setTimezone(new \DateTimeZone('Europe/Paris'))->format(\DATE_ATOM), $json['facts']['openingOpenSince']);
        self::assertNull($json['facts']['houseEmptySince']);
    }

    /**
     * @phpstan-impure
     */
    private function roomStatus(): RoomStatus
    {
        $statuses = $this->service(LiveStatusPublisher::class)->snapshot();
        self::assertCount(1, $statuses);

        return $statuses[0];
    }
}
