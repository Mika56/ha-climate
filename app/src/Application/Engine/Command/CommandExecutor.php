<?php

declare(strict_types=1);

namespace App\Application\Engine\Command;

use App\Application\Engine\Audit\AuditLogger;
use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\Timer\TimerScheduler;
use App\Application\Port\HomeAssistant\HomeAssistantException;
use App\Application\Port\HomeAssistant\HomeAssistantGatewayInterface;
use App\Domain\Decision\Decision;
use App\Domain\Decision\RoomConfiguration;
use App\Infrastructure\Persistence\Entity\CommandLog;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Repository\CommandLogRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Brings the devices of a room to the state a decision wants, sending as little as possible.
 *
 * For each device: plan (nothing if it is already in the wanted state), ask the guards, and either send
 * the command or postpone it with a timer. Every command is logged with the context id Home Assistant
 * returned, so that the device's resulting state change is recognised as ours, and announced with a
 * {@see DeviceCommandSent} event.
 */
final class CommandExecutor
{
    /** @var array<string, string> deferred-until (ATOM) per device, to audit a postponement only once */
    private array $deferrals = [];

    /**
     * @param iterable<CommandGuardInterface> $guards
     */
    public function __construct(
        private readonly HomeAssistantGatewayInterface $gateway,
        private readonly CommandPlanner $planner,
        private readonly iterable $guards,
        private readonly OwnCommandRegistry $ownCommands,
        private readonly CommandLogRepository $commandLog,
        private readonly TimerScheduler $timers,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function execute(RoomConfiguration $room, Decision $decision, EngineSnapshot $snapshot): void
    {
        foreach ($room->devices as $device) {
            $current = $snapshot->states->get($device->entityId);
            if (null === $current || \in_array($current->state, ['unavailable', 'unknown'], true)) {
                $this->logger->debug('Device {device} has no usable state, nothing sent.', ['device' => (string) $device->entityId]);

                continue;
            }

            $command = $this->planner->plan($room, $device, $decision, $current);
            if (null === $command) {
                $this->clearDeferral((string) $device->entityId);

                continue;
            }

            $verdict = $this->verdict($command, $snapshot);
            if ($verdict->isDeferred()) {
                $this->defer($command, $verdict);

                continue;
            }

            $this->clearDeferral((string) $device->entityId);
            $this->send($command);
        }
    }

    private function verdict(PlannedCommand $command, EngineSnapshot $snapshot): GuardVerdict
    {
        $latest = GuardVerdict::allow();
        foreach ($this->guards as $guard) {
            $verdict = $guard->check($command, $snapshot);
            if ($verdict->isDeferred() && (!$latest->isDeferred() || $verdict->deferredUntil > $latest->deferredUntil)) {
                $latest = $verdict;
            }
        }

        return $latest;
    }

    private function defer(PlannedCommand $command, GuardVerdict $verdict): void
    {
        $until = $verdict->deferredUntil;
        $reason = $verdict->reason;
        if (null === $until || null === $reason) {
            return;
        }

        $device = (string) $command->device->entityId;
        $this->timers->schedule('command.deferred.'.$device, $until, 'room_reevaluate', $command->roomId, ['cause' => 'command_deferred']);

        $marker = $until->format(\DATE_ATOM);
        if (($this->deferrals[$device] ?? null) === $marker) {
            return;
        }
        $this->deferrals[$device] = $marker;

        $this->audit->log(ActorType::Automation, 'command', 'audit.command.deferred', [
            'device' => $device,
            'until' => $marker,
            'reason' => $reason->code,
        ], $command->roomId, [
            'service' => 'climate.'.$command->service(),
            'data' => $command->serviceData(),
            'reason' => ['code' => $reason->code, 'params' => $reason->params],
        ]);
    }

    private function clearDeferral(string $device): void
    {
        if (isset($this->deferrals[$device])) {
            unset($this->deferrals[$device]);
            $this->timers->cancel('command.deferred.'.$device);
        }
    }

    private function send(PlannedCommand $command): void
    {
        $device = (string) $command->device->entityId;
        $service = $command->service();
        $data = $command->serviceData();
        if ($this->ownCommands->isInFlight($device, $command->mode, $command->temperature?->toFloat())) {
            return;
        }
        $previous = $this->ownCommands->expect($device, $command->mode, $command->temperature?->toFloat());

        try {
            $result = $this->gateway->callService('climate', $service, $data, ['entity_id' => $device]);
        } catch (HomeAssistantException $e) {
            $this->ownCommands->revert($device, $previous);
            $this->logger->error('Command climate.{service} on {device} failed: {message}', ['service' => $service, 'device' => $device, 'message' => $e->getMessage()]);
            $this->audit->log(ActorType::Automation, 'command', 'audit.command.failed', [
                'device' => $device,
                'service' => 'climate.'.$service,
                'error' => $e->getMessage(),
            ], $command->roomId, ['data' => $data]);

            return;
        }

        $this->ownCommands->remember($result->context->id);
        $this->commandLog->save(new CommandLog(
            $device,
            $this->clock->now(),
            'climate.'.$service,
            $result->context->id,
            $data,
            $command->temperature?->toFloat(),
            $command->mode,
        ));
        $this->audit->log(ActorType::Automation, 'command', 'audit.command.sent', [
            'device' => $device,
            'service' => 'climate.'.$service,
            'mode' => $command->mode->value,
            'target' => $command->temperature?->toFloat(),
        ], $command->roomId, ['data' => $data, 'contextId' => $result->context->id]);
        $this->dispatcher->dispatch(new DeviceCommandSent(
            $command->roomId,
            $command->device->entityId,
            $command->mode,
            $command->temperature?->toFloat(),
            $this->clock->now(),
            $result->context->id,
        ));
    }
}
