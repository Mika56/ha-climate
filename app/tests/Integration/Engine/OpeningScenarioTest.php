<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Alert\AlertService;
use App\Application\Engine\Alert\RaisedAlert;
use App\Application\Engine\Facts\OpeningContributor;
use App\Application\Engine\Lifecycle\Engine;
use App\Application\Engine\Lifecycle\EngineStateSynchronized;
use App\Application\Engine\Notification\NotificationService;
use App\Application\Engine\Notification\NotificationTexts;
use App\Application\Engine\Opening\OpeningNotificationScheduler;
use App\Application\Engine\Opening\OpeningNotifyHandler;
use App\Application\Engine\Opening\OpeningObserver;
use App\Application\Engine\Opening\PendingSetpointResolver;
use App\Application\Engine\State\ChangeProcessor;
use App\Application\Engine\State\EntityStateChanged;
use App\Domain\Climate\Season;
use App\Domain\Decision\Rule\OpeningRule;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\TimerRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(OpeningRule::class)]
#[CoversClass(OpeningContributor::class)]
#[CoversClass(OpeningObserver::class)]
#[CoversClass(OpeningNotificationScheduler::class)]
#[CoversClass(OpeningNotifyHandler::class)]
#[CoversClass(PendingSetpointResolver::class)]
#[CoversClass(AlertService::class)]
#[CoversClass(RaisedAlert::class)]
#[CoversClass(NotificationService::class)]
#[CoversClass(NotificationTexts::class)]
#[CoversClass(ChangeProcessor::class)]
#[CoversClass(EntityStateChanged::class)]
#[CoversClass(EngineStateSynchronized::class)]
#[CoversClass(Engine::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class OpeningScenarioTest extends NotificationTestCase
{
    private const string LIVING_ALERT = 'opening.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSeason(Season::Winter);
        // The scenarios do not make the device obey: keep its own notification out of the way.
        $this->configureType(NotificationType::DeviceUnresponsive, false, 300, false);
    }

    public function testTheWindowCutsTheRoomAfterTheCutDelayThenNotifiesThenTheScheduleResumes(): void
    {
        $room = $this->startWithClosedWindow();

        $this->homeAssistant()->pushStateChange(self::WINDOW, 'on');
        $this->timers()->advance(119);
        self::assertSame([], $this->homeAssistant()->serviceCallsTo('climate', 'set_hvac_mode'), 'Not cut before the delay.');

        $this->timers()->advance(1);
        $calls = $this->homeAssistant()->serviceCallsTo('climate', 'set_hvac_mode');
        self::assertCount(1, $calls, 'Cut exactly when the cut delay elapses.');
        self::assertSame('off', $calls[0]->serviceData['hvac_mode']);
        $this->deviceAppliesCommand($calls[0]);
        self::assertSame([], $this->notifications(), 'Not notified yet.');

        $this->timers()->advance(1679);
        self::assertSame([], $this->notifications());
        $this->timers()->advance(1);

        $sent = $this->notifications();
        self::assertCount(2, $sent);
        self::assertSame('Fenêtre ouverte', self::stringData($sent[0], 'title'));
        self::assertStringContainsString('Living room', self::stringData($sent[0], 'message'));
        self::assertStringContainsString('30 minutes', self::stringData($sent[0], 'message'));
        self::assertSame('Consigne non applicable', self::stringData($sent[1], 'title'));
        self::assertStringContainsString('21 °C', self::stringData($sent[1], 'message'));
        self::assertSame([], $this->homeAssistant()->serviceCallsTo('persistent_notification', 'create'));
        $alert = $this->alert(self::LIVING_ALERT.$room);
        self::assertTrue($alert?->isActive());
        self::assertNotNull($alert->getNotifiedAt());
        self::assertCount(2, $this->auditEntries('audit.notification.sent'));

        $this->homeAssistant()->clearServiceCalls();
        $this->homeAssistant()->pushStateChange(self::WINDOW, 'off');

        $resumed = $this->homeAssistant()->serviceCallsTo('climate', 'set_temperature');
        self::assertCount(1, $resumed, 'The schedule resumes by itself.');
        self::assertSame(21.0, $resumed[0]->serviceData['temperature']);
        self::assertFalse($this->alert(self::LIVING_ALERT.$room)?->isActive());
        self::assertNull($this->service(TimerRepository::class)->findOneByKey('opening.notify.'.$room));
        self::assertCount(1, $this->auditEntries('audit.alert.resolved'));
    }

    public function testNoNotificationIsRepeatedAfterARestart(): void
    {
        $room = $this->startWithClosedWindow();
        $this->homeAssistant()->pushStateChange(self::WINDOW, 'on');
        $this->timers()->advance(1800);
        self::assertCount(2, $this->notifications());

        $this->restartEngine(1900, function (): void {
            $this->seedDevice(mode: 'off');
            $this->seedSensor();
            $this->homeAssistant()->seedState(self::WINDOW, 'on'); // Opened at 07:00, as before.
        });

        self::assertSame([], $this->notifications(), 'The window was already notified before the restart.');
        $this->timers()->advance(3600);
        self::assertSame([], $this->notifications());
        self::assertTrue($this->alert(self::LIVING_ALERT.$room)?->isActive());
    }

    public function testAWindowOpenAtStartIsCutAtOnceAndNotifiedWhenItsDelayElapses(): void
    {
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->homeAssistant()->seedState(self::WINDOW, 'open');
        $this->clock()->sleep(600); // Open for ten minutes already when the engine starts.

        $this->startEngine();

        $cut = $this->homeAssistant()->serviceCallsTo('climate', 'set_hvac_mode');
        self::assertCount(1, $cut);
        self::assertSame('off', $cut[0]->serviceData['hvac_mode']);
        self::assertSame([], $this->notifications());

        $this->timers()->advance(1200);
        self::assertCount(2, $this->notifications());
    }

    public function testClosingBeforeTheNotifyDelayCancelsTheNotification(): void
    {
        $room = $this->startWithClosedWindow();
        $this->homeAssistant()->pushStateChange(self::WINDOW, 'on');
        $this->timers()->advance(600);
        self::assertNotNull($this->service(TimerRepository::class)->findOneByKey('opening.notify.'.$room));

        $this->homeAssistant()->pushStateChange(self::WINDOW, 'off');
        self::assertNull($this->service(TimerRepository::class)->findOneByKey('opening.notify.'.$room));
        $this->timers()->advance(7200);

        self::assertSame([], $this->notifications());
        self::assertNull($this->alert(self::LIVING_ALERT.$room));
    }

    public function testAWindowThatClosedAndOpenedAgainRestartsTheDelay(): void
    {
        $this->startWithClosedWindow();
        $this->homeAssistant()->pushStateChange(self::WINDOW, 'on');
        $this->timers()->advance(1000);
        $this->homeAssistant()->pushStateChange(self::WINDOW, 'off');
        $this->homeAssistant()->pushStateChange(self::WINDOW, 'on');

        $this->timers()->advance(1799);
        self::assertSame([], $this->notifications());
        $this->timers()->advance(1);
        self::assertCount(2, $this->notifications());
    }

    public function testADisabledTypeSendsNothing(): void
    {
        $this->configureType(NotificationType::WindowOpen, false, 1800, false);
        $this->configureType(NotificationType::SetpointNotApplicable, false, null, false);
        $this->startWithClosedWindow();

        $this->homeAssistant()->pushStateChange(self::WINDOW, 'on');
        $this->timers()->advance(3600);

        self::assertSame([], $this->notifications());
        self::assertSame([], $this->auditEntries('audit.notification.sent'));
    }

    public function testOnlyTheEnabledTypeIsSent(): void
    {
        $this->configureType(NotificationType::SetpointNotApplicable, false, null, false);
        $this->startWithClosedWindow();

        $this->homeAssistant()->pushStateChange(self::WINDOW, 'on');
        $this->timers()->advance(1800);

        $sent = $this->notifications();
        self::assertCount(1, $sent);
        self::assertSame('Fenêtre ouverte', self::stringData($sent[0], 'title'));
    }

    public function testNoSetpointNotificationWhenTheRoomWouldBeOffAnyway(): void
    {
        $this->setSeason(Season::Off);
        $this->startWithClosedWindow();

        $this->homeAssistant()->pushStateChange(self::WINDOW, 'on');
        $this->timers()->advance(1800);

        $sent = $this->notifications();
        self::assertCount(1, $sent);
        self::assertSame('Fenêtre ouverte', self::stringData($sent[0], 'title'));
    }

    public function testAnUnavailableOpeningCountsAsClosed(): void
    {
        $room = $this->startWithClosedWindow();
        $this->homeAssistant()->pushStateChange(self::WINDOW, 'on');
        $this->timers()->advance(60);

        $this->homeAssistant()->pushStateChange(self::WINDOW, 'unavailable');

        self::assertNull($this->service(TimerRepository::class)->findOneByKey('opening.notify.'.$room));
        $this->timers()->advance(3600);
        self::assertSame([], $this->homeAssistant()->serviceCallsTo('climate', 'set_hvac_mode'));
    }

    /**
     * Winter, the standard room with its window closed, device heating at the comfort target.
     *
     * @return string the room id
     */
    private function startWithClosedWindow(): string
    {
        $room = $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->homeAssistant()->seedState(self::WINDOW, 'off');
        $this->startEngine();
        $this->homeAssistant()->clearServiceCalls();

        return $room->getId()->toRfc4122();
    }
}
