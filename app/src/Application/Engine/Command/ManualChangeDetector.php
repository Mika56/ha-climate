<?php

declare(strict_types=1);

namespace App\Application\Engine\Command;

use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Port\HomeAssistant\EntityState;
use App\Domain\Climate\HvacMode;
use App\Domain\Exception\DomainExceptionInterface;
use App\Domain\HomeAssistant\EntityId;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Spots changes of a device's mode or target that the engine did not cause.
 *
 * A change is ours when its Home Assistant context is one of our service calls. Otherwise it is manual when
 * the mode or target moved since the previous observation and differ from what the engine last commanded
 * (or, for a device never commanded, simply moved). Attribute noise such as `hvac_action` or the measured
 * temperature therefore never triggers anything, even while a command of ours is still on its way.
 */
final readonly class ManualChangeDetector
{
    private const float TEMPERATURE_EPSILON = 0.05;

    public function __construct(
        private ConfigurationRepository $configuration,
        private OwnCommandRegistry $ownCommands,
        private EventDispatcherInterface $dispatcher,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param EntityState|null $previous what the state store knew before this change
     */
    public function inspect(string $entityId, ?EntityState $previous, ?EntityState $new): void
    {
        if (null === $new || \in_array($new->state, ['unavailable', 'unknown'], true)) {
            return;
        }

        try {
            $device = new EntityId($entityId);
        } catch (DomainExceptionInterface) {
            return;
        }
        $room = $this->configuration->snapshot()->roomOfDevice($device);
        if (null === $room || $this->ownCommands->isOwn($new->context->id)) {
            return;
        }

        $newTemperature = self::temperature($new);
        if (null !== $previous && $previous->state === $new->state && self::sameTemperature(self::temperature($previous), $newTemperature)) {
            return; // Neither mode nor target moved: whoever caused this update, nobody changed the setting.
        }
        if (!$this->differsFromReference($entityId, $previous, $new->state, $newTemperature)) {
            return;
        }

        $this->logger->info('Manual change detected on {device}: {mode} {temperature}', ['device' => $entityId, 'mode' => $new->state, 'temperature' => $newTemperature]);
        $this->dispatcher->dispatch(new ManualDeviceChangeDetected(
            $room->id,
            $device,
            $previous?->state,
            null === $previous ? null : self::temperature($previous),
            $new->state,
            $newTemperature,
            $new->context->userId,
            $this->clock->now(),
        ));

        // The observed state is the new reference: later noise on the same device must not repeat the event.
        $mode = HvacMode::tryFrom($new->state);
        if (null !== $mode) {
            $this->ownCommands->adopt($entityId, $mode, $newTemperature);
        }
    }

    private function differsFromReference(string $entityId, ?EntityState $previous, string $newMode, ?float $newTemperature): bool
    {
        $commanded = $this->ownCommands->lastCommanded($entityId);
        if (null !== $commanded) {
            return $commanded->mode->value !== $newMode
                || (null !== $commanded->temperature && HvacMode::Off !== $commanded->mode && !self::sameTemperature($commanded->temperature, $newTemperature));
        }

        // Never commanded: any move since the previous observation is manual; the very first observation is no change.
        return null !== $previous;
    }

    private static function temperature(EntityState $state): ?float
    {
        $value = $state->attributes['temperature'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private static function sameTemperature(?float $a, ?float $b): bool
    {
        return null === $a || null === $b ? $a === $b : abs($a - $b) < self::TEMPERATURE_EPSILON;
    }
}
