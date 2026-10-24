<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\Season;
use App\Tests\Double\ImmediateShutdown;
use App\Tests\Double\RecordingControlServer;
use App\UI\Console\EngineRunCommand;
use App\UI\Console\WatchEntitiesCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Revolt\EventLoop;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(EngineRunCommand::class)]
#[CoversClass(WatchEntitiesCommand::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class ConsoleCommandsTest extends EngineTestCase
{
    public function testTheEngineCommandRunsTheEngineUntilShutdown(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();

        $tester = $this->tester('app:engine:run');
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $this->service(RecordingControlServer::class)->starts);
        self::assertSame(1, $this->service(RecordingControlServer::class)->stops);
        self::assertFalse($this->homeAssistant()->isConnected());
    }

    public function testTheEngineCommandFailsWhenHomeAssistantCannotBeReached(): void
    {
        $this->homeAssistant()->simulateConnectionLoss();

        $status = $this->tester('app:engine:run')->execute([]);

        self::assertSame(Command::FAILURE, $status);
    }

    public function testWatchPrintsTheInitialStateThenTheChanges(): void
    {
        $this->homeAssistant()->seedState('input_boolean.window', 'off', ['friendly_name' => 'Window']);
        $this->homeAssistant()->seedState('sensor.temp', '19.5', ['unit_of_measurement' => '°C']);
        EventLoop::delay(0.01, function (): void {
            $this->homeAssistant()->pushStateChange('input_boolean.window', 'on', ['friendly_name' => 'Window', 'icon' => 'mdi:window'], new StateContext('ui-1', null, 'user-1'));
            $this->homeAssistant()->pushStateChange('sensor.temp', '19.5', ['unit_of_measurement' => '°C'], new StateContext('ctx-2'));
            $this->homeAssistant()->removeEntity('input_boolean.window');
        });

        $tester = $this->tester('app:debug:watch');
        $status = $tester->execute(['entity_ids' => ['input_boolean.window', 'sensor.temp'], '--duration' => '0.05']);

        self::assertSame(Command::SUCCESS, $status);
        $output = $tester->getDisplay();
        self::assertStringContainsString('input_boolean.window (new) -> off [attributes changed: friendly_name]', $output);
        self::assertStringContainsString('sensor.temp (new) -> 19.5 [attributes changed: unit_of_measurement]', $output);
        self::assertStringContainsString('input_boolean.window off -> on [attributes changed: icon] context=ui-1 user=user-1', $output);
        self::assertStringContainsString('sensor.temp 19.5 -> 19.5 [attributes changed: none] context=ctx-2 user=-', $output);
        self::assertStringContainsString('input_boolean.window removed (was on)', $output);
        self::assertSame(0, $this->homeAssistant()->subscriptionCount(), 'The subscription is dropped on exit.');
    }

    public function testWatchCanPrintJson(): void
    {
        $this->homeAssistant()->seedState('input_boolean.window', 'off');
        EventLoop::delay(0.01, function (): void {
            $this->homeAssistant()->pushStateChange('input_boolean.window', 'on', [], new StateContext('ui-1', 'parent-1', 'user-1'));
            $this->homeAssistant()->removeEntity('input_boolean.window');
        });

        $tester = $this->tester('app:debug:watch');
        $tester->execute(['entity_ids' => ['input_boolean.window'], '--duration' => '0.05', '--json' => true]);

        $lines = array_values(array_filter(explode("\n", $tester->getDisplay()), static fn (string $line): bool => '' !== $line));
        self::assertCount(3, $lines);
        /** @var array{entityId: string, old: array{state: string}|null, new: array{state: string, context: array<string, mixed>}|null} $change */
        $change = json_decode($lines[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('input_boolean.window', $change['entityId']);
        self::assertSame('off', $change['old']['state'] ?? null);
        self::assertSame('on', $change['new']['state'] ?? null);
        self::assertSame(['id' => 'ui-1', 'parentId' => 'parent-1', 'userId' => 'user-1'], $change['new']['context']);
        /** @var array{new: mixed} $removal */
        $removal = json_decode($lines[2], true, 512, \JSON_THROW_ON_ERROR);
        self::assertNull($removal['new']);
    }

    public function testWatchWithoutDurationWaitsForTheShutdownSignal(): void
    {
        $this->homeAssistant()->seedState('input_boolean.window', 'off');

        $tester = $this->tester('app:debug:watch');
        $status = $tester->execute(['entity_ids' => ['input_boolean.window']]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $this->service(ImmediateShutdown::class)->waits);
    }

    public function testWatchReportsAnUnreachableHomeAssistant(): void
    {
        $this->homeAssistant()->simulateConnectionLoss();

        $tester = $this->tester('app:debug:watch');
        $status = $tester->execute(['entity_ids' => ['input_boolean.window'], '--duration' => '0']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('connection is down', $tester->getDisplay());
    }

    public function testWatchRefusesToWatchEverything(): void
    {
        $this->expectException(RuntimeException::class);

        $this->tester('app:debug:watch')->execute([]);
    }

    private function tester(string $command): CommandTester
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('Kernel not booted.'));

        return new CommandTester($application->find($command));
    }
}
