<?php

declare(strict_types=1);

namespace App\Application\Engine\Evaluation;

use App\Domain\Climate\Temperature;
use App\Domain\Decision\ActiveOverride;
use App\Domain\Decision\Decision;
use App\Domain\Room\RoomId;

/**
 * View model of a room, as published to the live stream and the status endpoint.
 */
final readonly class RoomStatus implements \JsonSerializable
{
    /**
     * @param list<DeviceStatus> $devices
     */
    public function __construct(
        public RoomId $roomId,
        public string $name,
        public ?Temperature $currentTemperature,
        public Decision $decision,
        public ?ActiveOverride $override,
        public array $devices,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Same content, whatever the time of the evaluation.
     */
    public function sameContentAs(self $other): bool
    {
        return $this->content() === $other->content();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [...$this->content(), 'updatedAt' => $this->updatedAt->format(\DATE_ATOM)];
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $override = $this->override;

        return [
            'roomId' => (string) $this->roomId,
            'name' => $this->name,
            'currentTemperature' => $this->currentTemperature?->toFloat(),
            'decision' => [
                'mode' => $this->decision->mode->value,
                'target' => $this->decision->target?->toFloat(),
                'reason' => ['code' => $this->decision->reason->code, 'params' => $this->decision->reason->params],
            ],
            'override' => null === $override ? null : [
                'type' => $override->type->value,
                'delta' => $override->delta?->toFloat(),
                'target' => $override->target?->toFloat(),
                'endsAt' => $override->endsAt?->format(\DATE_ATOM),
                'untilNextSlot' => $override->untilNextSlot,
            ],
            'devices' => $this->devices,
        ];
    }
}
