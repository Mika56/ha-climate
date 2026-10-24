<?php

declare(strict_types=1);

namespace App\Application\Engine\Command;

use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Repository\CommandLogRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Remembers the commands the engine sent, to tell its own device changes from manual ones.
 *
 * Two memories: the Home Assistant context ids returned by our service calls (recent ones in memory,
 * older ones and those from before a restart found in the command log), and the state each device was
 * last asked to take.
 */
final class OwnCommandRegistry
{
    private const int MAX_REMEMBERED_CONTEXTS = 500;

    /** A command younger than this is assumed to be on its way: the device state may not show it yet. */
    private const int IN_FLIGHT_SECONDS = 15;

    /** @var array<string, true> */
    private array $contexts = [];

    /** @var array<string, CommandedState> */
    private array $commanded = [];

    public function __construct(
        private readonly CommandLogRepository $commandLog,
        private readonly ConfigurationRepository $configuration,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Rebuilds the last commanded state of every configured device from the command log.
     *
     * @return int the number of devices for which a previous command was found
     */
    public function restore(): int
    {
        $this->commanded = [];
        foreach ($this->configuration->rooms() as $room) {
            foreach ($room->devices as $device) {
                $entityId = (string) $device->entityId;
                $last = $this->commandLog->findLastForDevice($entityId);
                if (null !== $last && null !== $last->getHvacMode()) {
                    $this->commanded[$entityId] = new CommandedState($last->getHvacMode(), $last->getTargetTemperature(), $last->getSentAt());
                }
            }
        }

        return \count($this->commanded);
    }

    /**
     * Records the intent before the service call is made: the device may report its new state before the
     * call returns, and that report must not look like a manual change.
     *
     * @return CommandedState|null what was commanded before, to pass to {@see revert()} if the call fails
     */
    public function expect(string $deviceEntityId, HvacMode $mode, ?float $temperature): ?CommandedState
    {
        $previous = $this->commanded[$deviceEntityId] ?? null;
        $this->commanded[$deviceEntityId] = new CommandedState($mode, $temperature, $this->clock->now());

        return $previous;
    }

    /**
     * Whether the very same command was sent a moment ago, so that repeating it would be redundant.
     */
    public function isInFlight(string $deviceEntityId, HvacMode $mode, ?float $temperature): bool
    {
        $commanded = $this->commanded[$deviceEntityId] ?? null;
        if (null === $commanded || null === $commanded->sentAt || $commanded->mode !== $mode || $commanded->temperature !== $temperature) {
            return false;
        }

        return $this->clock->now()->getTimestamp() - $commanded->sentAt->getTimestamp() < self::IN_FLIGHT_SECONDS;
    }

    public function revert(string $deviceEntityId, ?CommandedState $previous): void
    {
        if (null === $previous) {
            unset($this->commanded[$deviceEntityId]);
        } else {
            $this->commanded[$deviceEntityId] = $previous;
        }
    }

    /**
     * Takes the observed state of a device as the new reference, after a manual change was reported.
     */
    public function adopt(string $deviceEntityId, HvacMode $mode, ?float $temperature): void
    {
        $this->commanded[$deviceEntityId] = new CommandedState($mode, $temperature);
    }

    public function remember(string $contextId): void
    {
        $this->contexts[$contextId] = true;
        if (\count($this->contexts) > self::MAX_REMEMBERED_CONTEXTS) {
            unset($this->contexts[array_key_first($this->contexts)]);
        }
    }

    public function isOwn(string $contextId): bool
    {
        return isset($this->contexts[$contextId]) || null !== $this->commandLog->findOneByContextId($contextId);
    }

    public function lastCommanded(string $deviceEntityId): ?CommandedState
    {
        return $this->commanded[$deviceEntityId] ?? null;
    }
}
