<?php

declare(strict_types=1);

namespace App\Application\Engine\Evaluation;

/**
 * A device of a room as last seen: its Home Assistant state (hvac mode, "unavailable"...) and attributes.
 */
final readonly class DeviceStatus implements \JsonSerializable
{
    public function __construct(
        public string $entityId,
        public string $role,
        public ?string $state,
        public ?float $targetTemperature,
        public ?float $currentTemperature,
    ) {
    }

    /**
     * @return array{entityId: string, role: string, state: string|null, targetTemperature: float|null, currentTemperature: float|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'entityId' => $this->entityId,
            'role' => $this->role,
            'state' => $this->state,
            'targetTemperature' => $this->targetTemperature,
            'currentTemperature' => $this->currentTemperature,
        ];
    }
}
