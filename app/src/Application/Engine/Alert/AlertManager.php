<?php

declare(strict_types=1);

namespace App\Application\Engine\Alert;

use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Engine\Lifecycle\EngineStateSynchronized;
use App\Application\Engine\State\EntityStateChanged;
use App\Application\Engine\State\StateStore;
use App\Domain\Decision\RoomConfiguration;
use App\Infrastructure\Persistence\Entity\Alert;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\AlertRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Raises an alert (key `entity.<entity_id>`) when an entity a room relies on becomes `unavailable` or
 * `unknown`, and resolves it when the entity is back. Changes are followed live; after the start and after a
 * configuration reload every monitored entity is checked, since no change event announces what was already
 * wrong. Alerts are information: the rooms keep being driven exactly as before.
 */
#[AsEventListener(event: EntityStateChanged::class, method: 'onStateChanged')]
#[AsEventListener(event: EngineStateSynchronized::class, method: 'onSynchronized')]
final readonly class AlertManager
{
    public const string KEY_PREFIX = 'entity.';
    public const string TYPE = 'entity_unavailable';

    private const array UNUSABLE_STATES = ['unavailable', 'unknown'];

    public function __construct(
        private AlertService $alerts,
        private AlertRepository $repository,
        private ConfigurationRepository $configuration,
        private StateStore $states,
        private MonitoredEntities $monitored,
    ) {
    }

    public function onStateChanged(EntityStateChanged $event): void
    {
        $entityId = $event->change->entityId;
        $rooms = $this->monitored->roomsUsing($this->configuration->snapshot(), $entityId);
        if ([] === $rooms) {
            return;
        }

        $this->check($entityId, $event->change->new?->state, $rooms);
    }

    public function onSynchronized(EngineStateSynchronized $event): void
    {
        $monitored = $this->monitored->all($this->configuration->snapshot());

        foreach ($monitored as $entityId => $rooms) {
            $this->check($entityId, $this->states->get($entityId)?->state, $rooms);
        }

        // Entities that left the configuration no longer concern anybody.
        foreach ($this->repository->findActive() as $alert) {
            if (self::isEntityAlert($alert) && !isset($monitored[(string) $alert->getEntityId()])) {
                $this->alerts->resolve($alert->getKey());
            }
        }
    }

    /**
     * @param list<RoomConfiguration> $rooms
     */
    private function check(string $entityId, ?string $state, array $rooms): void
    {
        if (null === $state) {
            return; // Not known to Home Assistant (yet): nothing to say about its availability.
        }

        $key = self::KEY_PREFIX.$entityId;
        if (!\in_array($state, self::UNUSABLE_STATES, true)) {
            $this->alerts->resolve($key);

            return;
        }

        $this->alerts->raise(
            $key,
            self::TYPE,
            NotificationType::SensorUnavailable,
            [
                'entity' => $entityId,
                'rooms' => implode(', ', array_map(static fn (RoomConfiguration $room): string => $room->name, $rooms)),
                'state' => $state,
            ],
            $entityId,
            $rooms[0]->id,
        );
    }

    private static function isEntityAlert(Alert $alert): bool
    {
        return self::TYPE === $alert->getType() && null !== $alert->getEntityId();
    }
}
