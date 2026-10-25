<?php

declare(strict_types=1);

namespace App\Application\Engine\Evaluation;

/**
 * A device of a room as last seen: its Home Assistant state (hvac mode, "unavailable"...), temperatures and
 * whether it is actively heating or cooling (`hvac_action`, when it reports one).
 */
final readonly class DeviceStatus implements \JsonSerializable
{
    public function __construct(
        public string $entityId,
        public string $role,
        public ?string $state,
        public ?float $targetTemperature,
        public ?float $currentTemperature,
        public ?bool $running = null,
    ) {
    }

    /**
     * @return array{entityId: string, role: string, state: string|null, targetTemperature: float|null, currentTemperature: float|null, running: bool|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'entityId' => $this->entityId,
            'role' => $this->role,
            'state' => $this->state,
            'targetTemperature' => $this->targetTemperature,
            'currentTemperature' => $this->currentTemperature,
            'running' => $this->running,
        ];
    }
}
