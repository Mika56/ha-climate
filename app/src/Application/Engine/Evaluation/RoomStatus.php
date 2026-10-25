<?php

declare(strict_types=1);

namespace App\Application\Engine\Evaluation;

use App\Application\Engine\Alert\AlertView;
use App\Domain\Climate\Temperature;
use App\Domain\Decision\ActiveOverride;
use App\Domain\Decision\Decision;
use App\Domain\Decision\RoomFacts;
use App\Domain\Room\RoomId;

/**
 * View model of a room, as published to the live stream and the status endpoint.
 */
final readonly class RoomStatus implements \JsonSerializable
{
    /**
     * @param list<DeviceStatus> $devices
     * @param list<AlertView>    $alerts  the active alerts concerning the room
     */
    public function __construct(
        public RoomId $roomId,
        public string $name,
        public ?Temperature $currentTemperature,
        public Decision $decision,
        public ?ActiveOverride $override,
        public array $devices,
        public \DateTimeImmutable $updatedAt,
        public RoomFacts $facts = new RoomFacts(),
        public array $alerts = [],
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
        // An empty JSON object, not an empty list: the contract types the parameters as an object.
        $params = [] === $this->decision->reason->params ? new \stdClass() : $this->decision->reason->params;

        return [
            ...$this->content(),
            'decision' => $this->decision($params),
            'updatedAt' => $this->updatedAt->format(\DATE_ATOM),
        ];
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
            'decision' => $this->decision($this->decision->reason->params),
            'override' => null === $override ? null : [
                'type' => $override->type->value,
                'delta' => $override->delta?->toFloat(),
                'target' => $override->target?->toFloat(),
                'endsAt' => $override->endsAt?->format(\DATE_ATOM),
                'untilNextSlot' => $override->untilNextSlot,
            ],
            // Plain arrays: the content is compared with ===, which would never match two distinct objects.
            'devices' => array_map(static fn (DeviceStatus $device): array => $device->jsonSerialize(), $this->devices),
            'facts' => [
                'openingOpenSince' => $this->instant($this->facts->openingOpenSince),
                'roomVacantSince' => $this->instant($this->facts->roomVacantSince),
                'houseEmptySince' => $this->instant($this->facts->houseEmptySince),
                'heatingRatePerHour' => null === $this->facts->heatingRatePerHour ? null : round($this->facts->heatingRatePerHour, 2),
            ],
            'alerts' => array_map(static fn (AlertView $alert): array => $alert->jsonSerialize(), $this->alerts),
        ];
    }

    /**
     * @param array<string, scalar|null>|\stdClass $params
     *
     * @return array{mode: string, target: float|null, reason: array{code: string, params: array<string, scalar|null>|\stdClass}}
     */
    private function decision(array|\stdClass $params): array
    {
        return [
            'mode' => $this->decision->mode->value,
            'target' => $this->decision->target?->toFloat(),
            'reason' => ['code' => $this->decision->reason->code, 'params' => $params],
        ];
    }

    private function instant(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone($this->updatedAt->getTimezone())->format(\DATE_ATOM);
    }
}
