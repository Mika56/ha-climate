<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Alert\AlertManager;
use App\Application\Engine\Alert\AlertService;
use App\Application\Engine\Alert\CommandedStateMatcher;
use App\Application\Engine\Alert\DeviceResponseWatcher;
use App\Application\Engine\Alert\DeviceUnresponsiveHandler;
use App\Application\Engine\Alert\MonitoredEntities;
use App\Application\Engine\Alert\RaisedAlert;
use App\Application\Engine\Command\CommandExecutor;
use App\Application\Engine\Command\DeviceCommandSent;
use App\Application\Engine\Lifecycle\ConfigurationReloader;
use App\Application\Engine\Lifecycle\Engine;
use App\Application\Engine\Lifecycle\EngineStateSynchronized;
use App\Application\Engine\Notification\NotificationService;
use App\Application\Engine\Notification\NotificationTexts;
use App\Application\Engine\Notification\PersistentNotifier;
use App\Application\Engine\State\ChangeProcessor;
use App\Application\Engine\State\EntityStateChanged;
use App\Application\Engine\State\StateStore;
use App\Application\Port\HomeAssistant\CommandFailedException;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use App\Domain\Climate\Season;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\AlertRepository;
use App\Infrastructure\Persistence\Repository\NotificationTargetRepository;
use App\Infrastructure\Persistence\Repository\TimerRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(AlertManager::class)]
#[CoversClass(AlertService::class)]
#[CoversClass(RaisedAlert::class)]
#[CoversClass(MonitoredEntities::class)]
#[CoversClass(DeviceResponseWatcher::class)]
#[CoversClass(DeviceUnresponsiveHandler::class)]
#[CoversClass(CommandedStateMatcher::class)]
#[CoversClass(DeviceCommandSent::class)]
#[CoversClass(NotificationService::class)]
#[CoversClass(NotificationTexts::class)]
#[CoversClass(PersistentNotifier::class)]
#[CoversClass(CommandExecutor::class)]
#[CoversClass(ChangeProcessor::class)]
#[CoversClass(EntityStateChanged::class)]
#[CoversClass(EngineStateSynchronized::class)]
#[CoversClass(ConfigurationReloader::class)]
#[CoversClass(Engine::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class AlertScenarioTest extends NotificationTestCase
{
    private const string ALERT = 'entity.'.self::SENSOR;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSeason(Season::Winter);
    }

    public function testAnUnavailableSensorOpensAnAlertNotifiesAndCreatesAPersistentNotificationThenDismissesIt(): void
    {
        $this->startStandardHouse();

        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');

        $alert = $this->alert(self::ALERT);
        self::assertNotNull($alert);
        self::assertTrue($alert->isActive());
        self::assertSame(self::SENSOR, $alert->getEntityId());
        self::assertSame('Living room', $alert->getRoom()?->getName());
        self::assertNotNull($alert->getNotifiedAt());
        self::assertSame('ha_climate_entity_sensor_living_temperature', $alert->getPersistentNotificationId());

        $sent = $this->notifications();
        self::assertCount(1, $sent);
        self::assertSame('Entité indisponible', self::stringData($sent[0], 'title'));
        self::assertStringContainsString('sensor.living_temperature (Living room) est indisponible', self::stringData($sent[0], 'message'));

        $created = $this->homeAssistant()->serviceCallsTo('persistent_notification', 'create');
        self::assertCount(1, $created);
        self::assertSame('ha_climate_entity_sensor_living_temperature', $created[0]->serviceData['notification_id']);
        self::assertSame('Entité indisponible', $created[0]->serviceData['title']);
        self::assertCount(1, $this->auditEntries('audit.alert.opened'));
        self::assertCount(1, $this->service(AlertRepository::class)->findActive());

        $this->homeAssistant()->pushStateChange(self::SENSOR, '19.5');

        $dismissed = $this->homeAssistant()->serviceCallsTo('persistent_notification', 'dismiss');
        self::assertCount(1, $dismissed);
        self::assertSame('ha_climate_entity_sensor_living_temperature', $dismissed[0]->serviceData['notification_id']);
        self::assertFalse($this->alert(self::ALERT)?->isActive());
        self::assertSame([], $this->service(AlertRepository::class)->findActive());
        self::assertCount(1, $this->auditEntries('audit.alert.resolved'));
        self::assertCount(1, $this->homeAssistant()->serviceCallsTo('notify', 'mobile_app_phone'), 'Nothing is sent when it comes back.');
    }

    public function testAnUnknownStateAlsoCountsAndRepeatedStatesNeverNotifyTwice(): void
    {
        $this->startStandardHouse();

        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unknown');
        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');

        self::assertCount(1, $this->notifications());
        self::assertCount(1, $this->homeAssistant()->serviceCallsTo('persistent_notification', 'create'));
        self::assertCount(1, $this->auditEntries('audit.alert.opened'));
    }

    public function testAnAlertThatFlapsIsReopenedAndNotifiedAgain(): void
    {
        $this->startStandardHouse();

        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');
        $this->homeAssistant()->pushStateChange(self::SENSOR, '20');
        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');

        self::assertCount(2, $this->notifications());
        self::assertTrue($this->alert(self::ALERT)?->isActive());
        self::assertCount(2, $this->auditEntries('audit.alert.opened'));
    }

    public function testASensorAlreadyUnavailableAtStartIsAlertedOnceEvenAcrossRestarts(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->homeAssistant()->seedState(self::SENSOR, 'unavailable');
        $this->startEngine();

        self::assertCount(1, $this->notifications());
        self::assertCount(1, $this->homeAssistant()->serviceCallsTo('persistent_notification', 'create'));

        $this->restartEngine(60, function (): void {
            $this->seedDevice(mode: 'heat', target: 21.0);
            $this->homeAssistant()->seedState(self::SENSOR, 'unavailable');
        });

        self::assertSame([], $this->notifications(), 'Already notified before the restart.');
        self::assertSame([], $this->homeAssistant()->serviceCallsTo('persistent_notification', 'create'));
        self::assertTrue($this->alert(self::ALERT)?->isActive());
    }

    public function testASensorBackAfterARestartResolvesItsAlert(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->homeAssistant()->seedState(self::SENSOR, 'unavailable');
        $this->startEngine();

        $this->restartEngine(60, function (): void {
            $this->seedDevice(mode: 'heat', target: 21.0);
            $this->seedSensor();
        });

        self::assertFalse($this->alert(self::ALERT)?->isActive());
        self::assertCount(1, $this->homeAssistant()->serviceCallsTo('persistent_notification', 'dismiss'));
    }

    public function testTheEngineKeepsDrivingTheRoomWhileTheSensorIsUnavailable(): void
    {
        $this->startStandardHouse();
        $this->homeAssistant()->clearServiceCalls();

        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');

        self::assertSame([], $this->homeAssistant()->serviceCallsTo('climate', 'set_temperature'));
        self::assertSame([], $this->homeAssistant()->serviceCallsTo('climate', 'set_hvac_mode'));
    }

    public function testNoPersistentNotificationWhenItIsNotEnabledForTheType(): void
    {
        $this->configureType(NotificationType::SensorUnavailable, true, 600, false);
        $this->startStandardHouse();

        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');

        self::assertCount(1, $this->notifications());
        self::assertSame([], $this->homeAssistant()->serviceCallsTo('persistent_notification', 'create'));
        self::assertNull($this->alert(self::ALERT)?->getPersistentNotificationId());

        $this->homeAssistant()->pushStateChange(self::SENSOR, '20');
        self::assertSame([], $this->homeAssistant()->serviceCallsTo('persistent_notification', 'dismiss'));
    }

    public function testADisabledTypeStillOpensTheAlertButSendsNothing(): void
    {
        $this->configureType(NotificationType::SensorUnavailable, false, 600, false);
        $this->startStandardHouse();

        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');

        self::assertSame([], $this->notifications());
        $alert = $this->alert(self::ALERT);
        self::assertTrue($alert?->isActive());
        self::assertNull($alert->getNotifiedAt());
    }

    public function testWithoutDestinationNothingIsSentAndTheAlertStaysToBeNotified(): void
    {
        $this->startStandardHouse(withTarget: false);

        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');

        self::assertSame([], $this->homeAssistant()->serviceCallsTo('notify', 'mobile_app_phone'));
        self::assertNull($this->alert(self::ALERT)?->getNotifiedAt());
    }

    public function testEveryEnabledDestinationIsServedAndADisabledOneIsNot(): void
    {
        $this->addTarget('notify.family_group');
        $this->addTarget('notify.old_tablet', false);
        $this->startStandardHouse();

        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');

        self::assertCount(1, $this->notifications('family_group'));
        self::assertCount(1, $this->notifications('mobile_app_phone'));
        self::assertSame([], $this->notifications('old_tablet'));
    }

    public function testAFailingDestinationIsAuditedAndDoesNotStopTheOthers(): void
    {
        $this->addTarget('notify.family_group');
        $this->startStandardHouse();
        $this->homeAssistant()->failNextServiceCall(new CommandFailedException('service_not_found', 'No such service'));

        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');

        // Destinations are served in alphabetical order: the first failed, the second got it.
        self::assertSame(1, \count($this->notifications('family_group')) + \count($this->notifications('mobile_app_phone')));
        $failures = $this->auditEntries('audit.notification.failed');
        self::assertCount(1, $failures);
        self::assertSame('notify.family_group', self::auditString($failures[0], 'target'));
        self::assertNotNull($this->alert(self::ALERT)?->getNotifiedAt());
    }

    public function testAFailingPersistentNotificationIsAuditedAndRetriedLater(): void
    {
        $this->configureType(NotificationType::SensorUnavailable, false, 600, true);
        $this->startStandardHouse();
        $this->homeAssistant()->failNextServiceCall(new CommandFailedException('failed', 'Boom'));

        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');

        self::assertNull($this->alert(self::ALERT)?->getPersistentNotificationId());
        $failures = $this->auditEntries('audit.notification.failed');
        self::assertCount(1, $failures);
        self::assertSame('persistent_notification', self::auditString($failures[0], 'target'));

        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unknown');
        self::assertSame('ha_climate_entity_sensor_living_temperature', $this->alert(self::ALERT)?->getPersistentNotificationId());
    }

    public function testADismissalThatFailsDoesNotPreventTheResolution(): void
    {
        $this->startStandardHouse();
        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');
        $this->homeAssistant()->failNextServiceCall(new CommandFailedException('failed', 'Boom'));

        $this->homeAssistant()->pushStateChange(self::SENSOR, '20');

        self::assertFalse($this->alert(self::ALERT)?->isActive());
    }

    public function testAnEntityThatLeavesTheConfigurationResolvesItsAlert(): void
    {
        $room = $this->startStandardHouse();
        $this->homeAssistant()->pushStateChange(self::SENSOR, 'unavailable');
        self::assertTrue($this->alert(self::ALERT)?->isActive());

        $entity = $this->em()->find(Room::class, $room->getId());
        self::assertInstanceOf(Room::class, $entity);
        $entity->configureSensors('sensor.other_temperature', [], []);
        $this->em()->flush();
        $this->homeAssistant()->seedState('sensor.other_temperature', '20');
        $this->service(ConfigurationReloader::class)->reload();

        self::assertFalse($this->alert(self::ALERT)?->isActive());
        self::assertCount(1, $this->homeAssistant()->serviceCallsTo('persistent_notification', 'dismiss'));
    }

    public function testNormalStatesAreNotAlerts(): void
    {
        $this->startStandardHouse();

        $this->homeAssistant()->pushStateChange(self::SENSOR, '21');
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 21.0]);

        self::assertSame([], $this->service(AlertRepository::class)->findActive());
        self::assertSame([], $this->notifications());
    }

    public function testADeviceThatIgnoresACommandIsReportedAfterTheDelayAndClearedWhenItObeys(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->startEngine();
        $command = $this->homeAssistant()->serviceCalls()[0];
        $this->homeAssistant()->clearServiceCalls();

        $this->timers()->advance(299);
        self::assertSame([], $this->notifications());
        self::assertNull($this->alert('device.'.self::DEVICE));

        $this->timers()->advance(1);

        $alert = $this->alert('device.'.self::DEVICE);
        self::assertTrue($alert?->isActive());
        self::assertNotNull($alert->getNotifiedAt());
        $sent = $this->notifications();
        self::assertCount(1, $sent);
        self::assertSame('Appareil sans réponse', self::stringData($sent[0], 'title'));
        self::assertStringContainsString('climate.living (Living room)', self::stringData($sent[0], 'message'));
        self::assertStringContainsString('5 minutes', self::stringData($sent[0], 'message'));
        self::assertStringContainsString('21 °C', self::stringData($sent[0], 'message'));
        self::assertCount(1, $this->homeAssistant()->serviceCallsTo('persistent_notification', 'create'));

        $this->deviceAppliesCommand($command);

        self::assertFalse($this->alert('device.'.self::DEVICE)?->isActive());
        self::assertCount(1, $this->homeAssistant()->serviceCallsTo('persistent_notification', 'dismiss'));
    }

    public function testADeviceThatObeysInTimeNeverRaisesAnything(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->startEngine();
        $repository = $this->service(TimerRepository::class);
        self::assertNotNull($repository->findOneByKey('device.unresponsive.'.self::DEVICE));

        $this->deviceAppliesCommand($this->homeAssistant()->serviceCalls()[0]);

        self::assertNull($repository->findOneByKey('device.unresponsive.'.self::DEVICE));
        $this->timers()->advance(3600);
        self::assertNull($this->alert('device.'.self::DEVICE));
        self::assertSame([], $this->notifications());
    }

    public function testTheUnresponsiveDelayComesFromTheSettings(): void
    {
        $this->configureType(NotificationType::DeviceUnresponsive, true, 60, false);
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->startEngine();

        $this->timers()->advance(60);

        self::assertTrue($this->alert('device.'.self::DEVICE)?->isActive());
        self::assertStringContainsString('1 minute', self::stringData($this->notifications()[0], 'message'));
        self::assertSame([], $this->homeAssistant()->serviceCallsTo('persistent_notification', 'create'));
    }

    public function testAnUnavailableDeviceIsLeftToTheEntityAlert(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->startEngine();
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'unavailable');
        $this->homeAssistant()->clearServiceCalls();

        $this->timers()->advance(300);

        self::assertNull($this->alert('device.'.self::DEVICE));
        self::assertTrue($this->alert('entity.'.self::DEVICE)?->isActive());
    }

    public function testADeviceFoundAtTheCommandedStateWhenTheTimerFiresIsNotReported(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->startEngine();
        // The store learns the device obeyed without an event announcing it.
        $reached = $this->homeAssistant()->seedState(self::DEVICE, 'heat', ['temperature' => 21.0]);
        $this->service(StateStore::class)->apply(new EntityStateChange(self::DEVICE, null, $reached));

        $this->timers()->advance(300);

        self::assertNull($this->alert('device.'.self::DEVICE));
        self::assertSame([], $this->notifications());
    }

    public function testTheNotificationLanguageIsTheForcedOneThenHomeAssistantsThenEnglish(): void
    {
        $this->startStandardHouse();
        $texts = $this->service(NotificationTexts::class);

        self::assertSame('fr', $texts->locale(), 'Home Assistant speaks French in the scenarios.');
        self::assertSame('Fenêtre ouverte', $texts->title(NotificationType::WindowOpen));

        $this->settings()->configureLocales(null, 'en');
        $this->em()->flush();
        self::assertSame('en', $texts->locale());
        self::assertSame('Window open', $texts->title(NotificationType::WindowOpen));

        $this->settings()->configureLocales(null, 'de');
        $this->em()->flush();
        self::assertSame('en', $texts->locale(), 'An unsupported forced language falls back to English.');

        $this->settings()->configureLocales(null, 'FR-ca');
        $this->em()->flush();
        self::assertSame('fr', $texts->locale());

        $this->settings()->configureLocales(null, null);
        $this->em()->flush();
        self::assertSame('fr', $texts->locale());
    }

    public function testHomeAssistantInAnUnsupportedLanguageGivesEnglish(): void
    {
        $this->homeAssistant()->withConfig(new HomeAssistantConfig('de', 'Europe/Paris', '°C', '2026.10.0'));
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->homeAssistant()->seedState(self::SENSOR, 'unavailable');
        $this->startEngine();

        self::assertSame('en', $this->service(NotificationTexts::class)->locale());
        self::assertSame('Entity unavailable', self::stringData($this->notifications()[0], 'title'));
        self::assertStringContainsString('is unavailable', self::stringData($this->notifications()[0], 'message'));
    }

    public function testTheEnglishTextsOfEveryType(): void
    {
        $this->startStandardHouse();
        $this->settings()->configureLocales(null, 'en');
        $this->em()->flush();
        $texts = $this->service(NotificationTexts::class);

        self::assertSame('Setpoint not applicable', $texts->title(NotificationType::SetpointNotApplicable));
        self::assertSame('Device not responding', $texts->title(NotificationType::DeviceUnresponsive));
        self::assertSame('Entity unavailable', $texts->title(NotificationType::SensorUnavailable));
        self::assertSame(
            'Living room: a window or door has been open for 1 minute. Climate control is paused in this room until it is closed.',
            $texts->message(NotificationType::WindowOpen, ['room' => 'Living room', 'minutes' => 1]),
        );
        self::assertSame(
            'Living room: the setpoint of 21 °C cannot be applied while a window or door is open.',
            $texts->message(NotificationType::SetpointNotApplicable, ['room' => 'Living room', 'target' => 21.0]),
        );
        self::assertSame(
            'climate.living (Living room) did not reach the requested state (off) 5 minutes after the command. It is now "heat".',
            $texts->message(NotificationType::DeviceUnresponsive, ['device' => 'climate.living', 'room' => 'Living room', 'mode' => 'off', 'target' => null, 'minutes' => 5, 'state' => 'heat']),
        );
        self::assertSame(
            'sensor.x (Kitchen) is unknown. The room keeps being driven with what is known.',
            $texts->message(NotificationType::SensorUnavailable, ['entity' => 'sensor.x', 'rooms' => 'Kitchen', 'state' => 'unknown']),
        );
    }

    public function testThePersistentNotificationIdIsStableAndSanitized(): void
    {
        self::assertSame('ha_climate_entity_sensor_living_temperature', PersistentNotifier::idFor('entity.sensor.living_temperature'));
        self::assertSame('ha_climate_device_climate_living_room', PersistentNotifier::idFor('device.climate.Living-Room!'));
    }

    private function startStandardHouse(bool $withTarget = true): Room
    {
        if (!$withTarget) {
            $this->removeTargets();
        }
        $room = $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();
        $this->homeAssistant()->clearServiceCalls();

        return $room;
    }

    private function removeTargets(): void
    {
        $repository = $this->service(NotificationTargetRepository::class);
        foreach ($repository->findEnabled() as $target) {
            $repository->remove($target);
        }
    }
}
