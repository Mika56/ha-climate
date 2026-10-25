<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Alert\AlertPauseSubscriber;
use App\Application\Engine\Alert\AlertService;
use App\Application\Engine\Alert\DeviceResponseWatcher;
use App\Application\Engine\Alert\DeviceUnresponsiveHandler;
use App\Application\Engine\Audit\AuditLogger;
use App\Application\Engine\Command\CommandExecutor;
use App\Application\Engine\Evaluation\LiveStatusPublisher;
use App\Application\Engine\Evaluation\RoomStatus;
use App\Application\Engine\Evaluation\RoomStatusFactory;
use App\Application\Engine\Lifecycle\ConfigurationReloader;
use App\Application\Engine\Lifecycle\Engine;
use App\Application\Engine\Lifecycle\EnginePauseChanged;
use App\Application\Engine\Notification\NotificationService;
use App\Application\Engine\Notification\PersistentNotifier;
use App\Application\Engine\Override\ManualOverrideSubscriber;
use App\Application\Engine\Override\OverrideManager;
use App\Application\Engine\Timer\TimerScheduler;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\Season;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\AlertRepository;
use App\Infrastructure\Persistence\Repository\CommandLogRepository;
use App\Infrastructure\Persistence\Repository\RoomOverrideRepository;
use App\Infrastructure\Persistence\Repository\TimerRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * While HA Climate is paused it observes and decides, audits what it would have done, and sends nothing: no
 * climate command, no notification, no persistent notification, no override from a manual change.
 */
#[CoversClass(CommandExecutor::class)]
#[CoversClass(AuditLogger::class)]
#[CoversClass(NotificationService::class)]
#[CoversClass(PersistentNotifier::class)]
#[CoversClass(AlertService::class)]
#[CoversClass(AlertPauseSubscriber::class)]
#[CoversClass(DeviceResponseWatcher::class)]
#[CoversClass(DeviceUnresponsiveHandler::class)]
#[CoversClass(ManualOverrideSubscriber::class)]
#[CoversClass(OverrideManager::class)]
#[CoversClass(ConfigurationReloader::class)]
#[CoversClass(EnginePauseChanged::class)]
#[CoversClass(Engine::class)]
#[CoversClass(RoomStatus::class)]
#[CoversClass(RoomStatusFactory::class)]
#[CoversClass(TimerScheduler::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class PausedScenarioTest extends NotificationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setSeason(Season::Winter);
    }

    public function testPausedAScheduleBoundaryLogsASimulatedDecisionAndSendsNothing(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->setPaused(true);
        $this->startEngine();
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        $this->timers()->advance(14 * 3600 + 1800); // 22:30 in Paris: the night slot starts.

        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'Nothing reaches Home Assistant.');
        self::assertSame([], $this->service(CommandLogRepository::class)->findAll());
        self::assertSame([], $this->auditEntries('audit.command.sent'));
        self::assertSame([], $this->auditEntries('audit.decision.changed'));
        $simulated = $this->auditEntries('audit.decision.simulated');
        self::assertCount(1, $simulated);
        self::assertSame('Night', $simulated[0]->getParams()['preset']);
        self::assertSame('heat', $simulated[0]->getParams()['mode']);
        self::assertSame(17.0, $simulated[0]->getParams()['target']);
        self::assertSame('winter', $simulated[0]->getParams()['season']);
        self::assertSame('schedule', $simulated[0]->getParams()['reason']);
        self::assertSame('decision', $simulated[0]->getCategory());

        $translator = $this->service(TranslatorInterface::class);
        $message = $translator->trans('audit.decision.simulated', $simulated[0]->getParams(), 'audit', 'fr');
        self::assertSame("En pause\u{00A0}: HA Climate aurait réglé le chauffage sur 17\u{00A0}°C (planning Night). Rien n’a été envoyé.", $message);

        $statuses = $this->service(LiveStatusPublisher::class)->snapshot();
        self::assertCount(1, $statuses);
        self::assertTrue($statuses[0]->paused);
        self::assertSame(17.0, $statuses[0]->decision->target?->toFloat(), 'The decision is still computed.');
    }

    public function testPausedAWindowOpeningSendsNoNotificationAndLogsASimulatedOff(): void
    {
        $this->configureType(NotificationType::WindowOpen, true, 1800, true);
        $room = $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->homeAssistant()->seedState(self::WINDOW, 'off');
        $this->setPaused(true);
        $this->startEngine();

        $this->homeAssistant()->pushStateChange(self::WINDOW, 'on');
        $this->timers()->advance(3600);

        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'No climate command, no notify, no persistent notification.');
        $alert = $this->alert('opening.'.$room->getId()->toRfc4122());
        self::assertTrue($alert?->isActive(), 'The alert is displayed.');
        self::assertNull($alert->getNotifiedAt());
        self::assertNull($alert->getPersistentNotificationId());
        self::assertSame([], $this->auditEntries('audit.notification.sent'));
        $simulated = $this->auditEntries('audit.decision.simulated');
        self::assertCount(1, $simulated);
        self::assertSame('off', $simulated[0]->getParams()['mode']);
        self::assertSame('opening_open', $simulated[0]->getParams()['reason']);
    }

    public function testPausedAManualChangeOnADeviceCreatesNoOverride(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->setPaused(true);
        $this->startEngine();

        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 24.0, 'current_temperature' => 19.5], new StateContext('ui-1', null, 'user-42'));

        self::assertSame([], $this->service(RoomOverrideRepository::class)->findAll());
        self::assertSame([], $this->auditEntries('audit.override.manual_started'));
        self::assertCount(1, $this->auditEntries('audit.manual_change.detected'), 'The change is still noted.');
        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'The user is in charge: the setting is not put back.');
    }

    public function testPausedNothingIsPostponedByAntiCycling(): void
    {
        $room = $this->createRoom();
        foreach ($this->em()->find(\App\Infrastructure\Persistence\Entity\Room::class, $room->getId())?->getDevices() ?? [] as $device) {
            $device->configureAntiCycling(0, 600, 1.0);
        }
        $this->em()->flush();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->setPaused(true);
        $this->startEngine();

        self::assertSame([], $this->auditEntries('audit.command.deferred'));
        $deferred = array_filter($this->service(TimerRepository::class)->findAllPending(), static fn ($timer): bool => str_starts_with($timer->getKey(), 'command.deferred.'));
        self::assertSame([], array_values($deferred));
    }

    public function testPausingDismissesOurPersistentNotificationsAndResumingCatchesUp(): void
    {
        $this->configureType(NotificationType::SensorUnavailable, true, 600, true);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->homeAssistant()->seedState(self::WINDOW, 'off');
        $this->startEngine();
        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');
        self::assertCount(1, $this->notifications());
        self::assertCount(1, $this->homeAssistant()->serviceCallsTo('persistent_notification', 'create'));
        $this->homeAssistant()->clearServiceCalls();

        // Pause: the persistent notification goes away, the alert stays.
        $this->setPaused(true);
        $this->service(ConfigurationReloader::class)->reload();

        $dismissed = $this->homeAssistant()->serviceCallsTo('persistent_notification', 'dismiss');
        self::assertCount(1, $dismissed);
        self::assertSame('ha_climate_entity_sensor_living_temperature', $dismissed[0]->serviceData['notification_id']);
        self::assertCount(1, $this->homeAssistant()->serviceCalls(), 'Dismissing is the only call.');
        $first = $this->alert('entity.'.self::SENSOR);
        self::assertTrue($first?->isActive(), 'The alert stays visible.');
        self::assertNull($first->getPersistentNotificationId());
        $this->homeAssistant()->clearServiceCalls();

        // While paused: a new alert, a schedule boundary. Nothing is sent.
        $this->homeAssistant()->pushStateChange(self::WINDOW, 'unavailable');
        $this->timers()->advance(14 * 3600 + 1800);
        self::assertSame([], $this->homeAssistant()->serviceCalls());
        $second = $this->alert('entity.'.self::WINDOW);
        self::assertTrue($second?->isActive());
        self::assertNull($second->getNotifiedAt());

        // Resume: the room is evaluated at once (night: 17), pending alerts are notified.
        $this->setPaused(false);
        $this->service(ConfigurationReloader::class)->reload();

        $commands = $this->homeAssistant()->serviceCallsTo('climate', 'set_temperature');
        self::assertCount(1, $commands);
        self::assertSame(17.0, $commands[0]->serviceData['temperature']);
        $sent = $this->notifications();
        self::assertCount(1, $sent, 'Only the alert opened while paused is notified.');
        self::assertStringContainsString(self::WINDOW, self::stringData($sent[0], 'message'));
        self::assertNotNull($this->alert('entity.'.self::WINDOW)?->getNotifiedAt());
        $created = $this->homeAssistant()->serviceCallsTo('persistent_notification', 'create');
        self::assertEqualsCanonicalizing(
            ['ha_climate_entity_sensor_living_temperature', 'ha_climate_entity_binary_sensor_living_room_window'],
            array_map(static fn ($call): mixed => $call->serviceData['notification_id'], $created),
        );
        self::assertNotNull($this->alert('entity.'.self::SENSOR)?->getPersistentNotificationId());
        self::assertCount(2, $this->service(AlertRepository::class)->findActive());
    }

    public function testPausingDisarmsTheDeviceUnresponsiveTimersAndNothingNewIsArmed(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->startEngine();
        $timers = $this->service(TimerRepository::class);
        self::assertNotNull($timers->findOneByKey('device.unresponsive.'.self::DEVICE), 'The startup command armed the watch.');

        $this->setPaused(true);
        $this->service(ConfigurationReloader::class)->reload();

        self::assertNull($timers->findOneByKey('device.unresponsive.'.self::DEVICE));
        $this->timers()->advance(3600);
        self::assertSame([], $this->auditEntries('audit.alert.opened'));
    }

    public function testADeviceUnresponsiveTimerThatFiresWhilePausedDoesNothing(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->startEngine();
        $timer = $this->service(TimerRepository::class)->findOneByKey('device.unresponsive.'.self::DEVICE);
        self::assertNotNull($timer);
        $this->setPaused(true);
        $this->service(\App\Application\Engine\Configuration\ConfigurationRepository::class)->reload();

        $this->service(DeviceUnresponsiveHandler::class)->handle($timer);

        self::assertSame([], $this->auditEntries('audit.alert.opened'));
    }

    public function testStartingPausedDismissesWhatWasLeftBehind(): void
    {
        $this->configureType(NotificationType::SensorUnavailable, true, 600, true);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->homeAssistant()->seedState(self::SENSOR, 'unavailable');
        $this->startEngine();
        self::assertCount(1, $this->homeAssistant()->serviceCallsTo('persistent_notification', 'create'));

        $this->restartEngine(60, function (): void {
            $this->setPaused(true);
            $this->seedDevice(mode: 'heat', target: 21.0);
            $this->homeAssistant()->seedState(self::SENSOR, 'unavailable');
        });

        self::assertCount(1, $this->homeAssistant()->serviceCallsTo('persistent_notification', 'dismiss'));
        self::assertSame([], $this->homeAssistant()->serviceCallsTo('persistent_notification', 'create'));
        self::assertSame([], $this->notifications());
    }

    public function testATestNotificationIsAnExplicitRequestAndStillGoesOutWhilePaused(): void
    {
        $this->setPaused(true);
        $this->startEngine();

        $result = $this->service(NotificationService::class)->sendTest();

        self::assertSame(['sent' => 1, 'failed' => 0], $result);
        self::assertCount(1, $this->notifications());
    }

    public function testResumingDoesNotNotifyAgainWhatWasNotifiedBeforeThePause(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();
        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');
        self::assertCount(1, $this->notifications());
        self::assertSame('sensor_unavailable', $this->alert('entity.'.self::SENSOR)?->getNotificationType());
        $this->homeAssistant()->clearServiceCalls();

        $this->setPaused(true);
        $this->service(ConfigurationReloader::class)->reload();
        $this->homeAssistant()->clearServiceCalls();
        $this->setPaused(false);
        $this->service(ConfigurationReloader::class)->reload();

        self::assertSame([], $this->notifications(), 'Already notified before the pause.');
    }
}
