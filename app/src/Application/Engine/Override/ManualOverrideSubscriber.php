<?php

declare(strict_types=1);

namespace App\Application\Engine\Override;

use App\Application\Engine\Command\ManualDeviceChangeDetected;
use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;
use App\Domain\Exception\DomainExceptionInterface;
use App\Infrastructure\Persistence\Enum\ActorType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Turns a manual change of a device into a manual override of its room, so that the engine keeps the
 * user's setting for `manualOverrideMinutes` instead of reverting it, then resumes the schedule.
 *
 * - The override pins the new target in the new mode; a device switched off pins "off" in the mode of the
 *   season. Modes the engine does not drive (`auto`, `heat_cool`, `dry`...) and devices reporting no target
 *   create nothing.
 * - A device that merely comes back from `unavailable` / `unknown` was not touched by anybody: nothing is created.
 * - Season off: nothing is created, the season rule beats any override anyway.
 * - A mode that differs from the season's (heat set in summer) is recorded but ignored while it lasts: the
 *   decision follows the season, so the engine puts the device back on the cooling schedule.
 * - A new manual change replaces the previous override (boost included) and restarts the countdown.
 *
 * No re-evaluation is requested here: the change processor evaluates the room right after the event, and that
 * evaluation reads the new override and arms the timer of its end. Runs after the audit of the detection
 * (negative priority), so the audit trail reads "change detected", then "override started".
 */
#[AsEventListener(priority: -10)]
final readonly class ManualOverrideSubscriber
{
    public function __construct(
        private ConfigurationRepository $configuration,
        private OverrideManager $overrides,
    ) {
    }

    public function __invoke(ManualDeviceChangeDetected $event): void
    {
        $configuration = $this->configuration->snapshot();
        $minutes = $configuration->manualOverrideMinutes;
        $seasonMode = $configuration->season->activeHvacMode();
        $mode = HvacMode::tryFrom($event->newMode);
        if ($minutes < 1 || null === $mode || HvacMode::Off === $seasonMode || \in_array($event->oldMode, ['unavailable', 'unknown'], true)) {
            return;
        }

        $target = null;
        if (HvacMode::Off !== $mode) {
            $target = self::target($event->newTemperature);
            if (null === $target) {
                return;
            }
        }

        try {
            $this->overrides->startManual(
                $event->roomId,
                HvacMode::Off === $mode ? $seasonMode : $mode,
                $target,
                $event->occurredAt,
                $minutes,
                null === $event->userId ? ActorType::Device : ActorType::User,
                $event->userId ?? (string) $event->device,
            );
        } catch (OverrideRejectedException) {
            // The room vanished between the detection and now: nothing to hold.
        }
    }

    private static function target(?float $celsius): ?Temperature
    {
        if (null === $celsius) {
            return null;
        }

        try {
            return new Temperature($celsius);
        } catch (DomainExceptionInterface) {
            return null;
        }
    }
}
