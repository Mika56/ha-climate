<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Enum\ActorType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Records, in the audit trail, the configuration changes made through the API: category `configuration`,
 * message key `audit.configuration.<change>`, the user as actor, and what the change was (before/after) in the
 * payload. The entry is only persisted: it is written by the same flush as the change itself.
 */
final readonly class ConfigurationAudit
{
    public const string CATEGORY = 'configuration';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Records that HA Climate was paused or resumed (`audit.engine.paused` / `audit.engine.resumed`, category `engine`).
     */
    public function recordPause(?IngressUser $user, bool $paused): void
    {
        $this->entityManager->persist(new AuditEntry(
            $this->clock->now(),
            ActorType::User,
            'engine',
            $paused ? 'audit.engine.paused' : 'audit.engine.resumed',
            [],
            ['paused' => $paused],
            $user?->id,
            $user?->displayName,
        ));
    }

    /**
     * @param array<string, scalar|null> $params
     * @param array<string, mixed>|null  $before
     * @param array<string, mixed>|null  $after
     */
    public function record(?IngressUser $user, string $change, array $params, ?array $before, ?array $after, ?Room $room = null): void
    {
        $this->entityManager->persist(new AuditEntry(
            $this->clock->now(),
            ActorType::User,
            self::CATEGORY,
            'audit.configuration.'.$change,
            $params,
            ['before' => $before, 'after' => $after],
            $user?->id,
            $user?->displayName,
            $room,
        ));
    }
}
