<?php

declare(strict_types=1);

namespace App\Application\Api\Audit;

use App\Application\Api\ApiTime;
use App\Infrastructure\Persistence\Entity\AuditEntry;

/**
 * An audit entry as the API shows it, its message rendered in the request locale.
 */
final readonly class AuditEntryNormalizer
{
    public function __construct(
        private ApiTime $time,
        private AuditMessageRenderer $messages,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(AuditEntry $entry, string $locale): array
    {
        return [
            'id' => $entry->getId()->toRfc4122(),
            'occurredAt' => $this->time->format($entry->getOccurredAt()),
            'actorType' => $entry->getActorType()->value,
            'actorName' => $entry->getActorName() ?? $entry->getActorId(),
            'roomId' => $entry->getRoom()?->getId()->toRfc4122(),
            'roomName' => $entry->getRoomName(),
            'category' => $entry->getCategory(),
            'messageKey' => $entry->getMessageKey(),
            // An empty list would be encoded as `[]`; the contract wants an object.
            'params' => [] === $entry->getParams() ? new \stdClass() : $entry->getParams(),
            'message' => $this->messages->render($entry, $locale),
        ];
    }
}
