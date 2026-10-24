<?php

declare(strict_types=1);

namespace App\Application\Engine\Lifecycle;

use App\Application\Engine\Audit\AuditLogger;
use App\Application\Engine\Command\OwnCommandRegistry;
use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Engine\Evaluation\RoomEvaluator;
use App\Application\Engine\State\EntityRoomIndex;
use App\Application\Engine\State\HomeAssistantContext;
use App\Application\Engine\State\StateSynchronizer;
use App\Application\Engine\State\SubscriptionManager;
use App\Application\Engine\Timer\DailyMaintenanceHandler;
use App\Application\Engine\Timer\TimerScheduler;
use App\Application\Port\HomeAssistant\HomeAssistantGatewayInterface;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Repository\RoomOverrideRepository;
use App\Infrastructure\Persistence\Seed\DefaultDataSeeder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The engine's life cycle: boot, run, reconnect, stop.
 *
 * Booting resumes exactly where the previous run stopped: the configuration, timers and last commands are
 * reloaded, the real device states are read from Home Assistant, and the startup evaluation only sends a
 * command to a device whose actual state differs from the wanted one.
 */
final class Engine
{
    private bool $started = false;

    public function __construct(
        private readonly DefaultDataSeeder $seeder,
        private readonly ConfigurationRepository $configuration,
        private readonly EntityRoomIndex $index,
        private readonly HomeAssistantGatewayInterface $gateway,
        private readonly HomeAssistantContext $context,
        private readonly StateSynchronizer $synchronizer,
        private readonly OwnCommandRegistry $ownCommands,
        private readonly TimerScheduler $timers,
        private readonly DailyMaintenanceHandler $maintenance,
        private readonly SubscriptionManager $subscriptions,
        private readonly RoomEvaluator $evaluator,
        private readonly RoomOverrideRepository $overrides,
        private readonly AuditLogger $audit,
        private readonly ControlServerInterface $server,
        private readonly ShutdownWaiterInterface $shutdown,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Boots the engine, serves until asked to stop (SIGTERM/SIGINT), then shuts down gracefully.
     */
    public function run(): void
    {
        $this->start();
        try {
            $this->shutdown->waitForShutdown();
        } finally {
            $this->stop();
        }
    }

    public function start(): void
    {
        $this->started = true;
        $this->seeder->seed();
        $configuration = $this->configuration->reload();
        $this->index->rebuild($configuration);

        $this->gateway->connect();
        $this->gateway->onConnectionLost($this->onConnectionLost(...));
        $this->gateway->onReconnected($this->onReconnected(...));
        $homeAssistant = $this->gateway->getConfig();
        $this->context->update($homeAssistant);
        $entities = $this->synchronizer->loadInitial();

        $commands = $this->ownCommands->restore();
        $overrides = \count($this->overrides->findAllActive($this->clock->now()));
        // Subscribed before the timers are restored: what a past-due timer sends must reach the state store.
        $this->subscriptions->refresh();
        $timers = $this->timers->restore();
        $this->maintenance->ensureScheduled();

        $this->logger->info('Engine started: {rooms} rooms, {entities} entities watched, {timers} timers restored.', ['rooms' => \count($configuration->rooms), 'entities' => $entities, 'timers' => $timers]);
        $this->audit->log(ActorType::System, 'engine', 'audit.engine.started', [
            'timers' => $timers,
            'overrides' => $overrides,
            'commands' => $commands,
            'rooms' => \count($configuration->rooms),
        ], null, ['timezone' => $homeAssistant->timeZone, 'language' => $homeAssistant->language, 'haVersion' => $homeAssistant->version]);

        $this->evaluator->evaluate($configuration->roomIds(), 'startup');
        $this->dispatcher->dispatch(new EngineStateSynchronized(EngineStateSynchronized::STARTUP));
        $this->server->start();
    }

    public function stop(): void
    {
        if (!$this->started) {
            return;
        }
        $this->started = false;

        $this->server->stop();
        $this->subscriptions->close();
        $this->timers->disarmAll();
        $this->gateway->disconnect();
        $this->logger->info('Engine stopped.');
    }

    private function onConnectionLost(): void
    {
        $this->context->connectionLost();
        $this->logger->warning('Connection to Home Assistant lost, waiting for it to come back.');
        $this->audit->log(ActorType::System, 'engine', 'audit.connection.lost');
    }

    private function onReconnected(): void
    {
        $this->context->connectionRestored();
        $this->logger->info('Connection to Home Assistant restored, resynchronizing.');
        $this->audit->log(ActorType::System, 'engine', 'audit.connection.restored');
        $this->subscriptions->repairIfStale();
        $this->synchronizer->catchUp();
        $this->evaluator->evaluate($this->configuration->snapshot()->roomIds(), 'reconnect');
    }
}
