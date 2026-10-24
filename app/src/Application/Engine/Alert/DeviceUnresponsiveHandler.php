<?php

declare(strict_types=1);

namespace App\Application\Engine\Alert;

use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Engine\State\StateStore;
use App\Application\Engine\Timer\TimerHandlerInterface;
use App\Domain\Climate\HvacMode;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\Timer;
use App\Infrastructure\Persistence\Enum\NotificationType;

/**
 * The `device_unresponsive` delay elapsed since a command: if the device still is not in the commanded state,
 * raises the alert `device.<entity_id>` and notifies. A device that is unavailable is left to the entity
 * alert, which already says so.
 */
final readonly class DeviceUnresponsiveHandler implements TimerHandlerInterface
{
    public function __construct(
        private StateStore $states,
        private AlertService $alerts,
        private CommandedStateMatcher $matcher,
        private ConfigurationRepository $configuration,
    ) {
    }

    public function type(): string
    {
        return DeviceResponseWatcher::TIMER_TYPE;
    }

    public function handle(Timer $timer): void
    {
        $payload = $timer->getPayload();
        $device = $payload['device'] ?? null;
        $mode = HvacMode::tryFrom(\is_string($payload['mode'] ?? null) ? $payload['mode'] : '');
        $temperature = $payload['temperature'] ?? null;
        $delay = $payload['delay'] ?? 0;
        if (!\is_string($device) || null === $mode) {
            return;
        }

        $state = $this->states->get($device);
        if (null === $state || \in_array($state->state, ['unavailable', 'unknown'], true)) {
            return;
        }

        $key = DeviceResponseWatcher::ALERT_KEY_PREFIX.$device;
        $temperature = \is_float($temperature) || \is_int($temperature) ? (float) $temperature : null;
        if ($this->matcher->matches($state, $mode, $temperature)) {
            $this->alerts->resolve($key);

            return;
        }

        $roomId = $timer->getRoomId();
        $room = null === $roomId ? null : $this->configuration->snapshot()->room(new RoomId($roomId->toRfc4122()));
        $this->alerts->raise(
            $key,
            'device_unresponsive',
            NotificationType::DeviceUnresponsive,
            [
                'device' => $device,
                'room' => $room->name ?? '',
                'minutes' => \is_int($delay) ? intdiv($delay, 60) : 0,
                'mode' => $mode->value,
                'target' => $temperature,
                'state' => $state->state,
            ],
            $device,
            $room?->id,
        );
    }
}
