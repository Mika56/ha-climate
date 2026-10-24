<?php

declare(strict_types=1);

namespace App\Application\Engine\Alert;

use App\Infrastructure\Persistence\Entity\Alert;

/**
 * An alert as the API shows it (`Alert` schema of the contract): instants in the Home Assistant time zone.
 */
final readonly class AlertView implements \JsonSerializable
{
    public function __construct(
        public string $key,
        public string $type,
        public ?string $entityId,
        public ?string $roomId,
        public ?string $roomName,
        public string $openedAt,
        public ?string $notifiedAt,
    ) {
    }

    public static function fromEntity(Alert $alert, \DateTimeZone $timeZone): self
    {
        $room = $alert->getRoom();

        return new self(
            $alert->getKey(),
            $alert->getType(),
            $alert->getEntityId(),
            $room?->getId()->toRfc4122(),
            $room?->getName(),
            $alert->getOpenedAt()->setTimezone($timeZone)->format(\DATE_ATOM),
            $alert->getNotifiedAt()?->setTimezone($timeZone)->format(\DATE_ATOM),
        );
    }

    /**
     * @return array{key: string, type: string, entityId: string|null, roomId: string|null, roomName: string|null, openedAt: string, notifiedAt: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'entityId' => $this->entityId,
            'roomId' => $this->roomId,
            'roomName' => $this->roomName,
            'openedAt' => $this->openedAt,
            'notifiedAt' => $this->notifiedAt,
        ];
    }
}
