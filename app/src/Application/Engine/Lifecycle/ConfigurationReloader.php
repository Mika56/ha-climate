<?php

declare(strict_types=1);

namespace App\Application\Engine\Lifecycle;

use App\Application\Engine\Audit\AuditLogger;
use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Engine\Evaluation\RoomEvaluator;
use App\Application\Engine\State\EntityRoomIndex;
use App\Application\Engine\State\StateStore;
use App\Application\Engine\State\StateSynchronizer;
use App\Application\Engine\State\SubscriptionManager;
use App\Application\Port\HomeAssistant\HomeAssistantException;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Enum\ActorType;
use Psr\Log\LoggerInterface;

/**
 * Applies a configuration change made on the web side to the running engine: re-reads the database,
 * rebuilds the entity index, moves the subscription to the new entity set and re-evaluates every room.
 */
final readonly class ConfigurationReloader
{
    public function __construct(
        private ConfigurationRepository $configuration,
        private EntityRoomIndex $index,
        private StateStore $states,
        private StateSynchronizer $synchronizer,
        private SubscriptionManager $subscriptions,
        private RoomEvaluator $evaluator,
        private AuditLogger $audit,
        private LoggerInterface $logger,
    ) {
    }

    public function reload(): void
    {
        $before = array_map(strval(...), $this->configuration->snapshot()->roomIds());
        $configuration = $this->configuration->reload();

        $this->index->rebuild($configuration);
        $this->states->retain($this->index->entityIds());
        try {
            $this->synchronizer->loadMissing();
            $this->subscriptions->refresh();
        } catch (HomeAssistantException $e) {
            // Retried by the reconnection handler; the rooms are still evaluated with what is known.
            $this->logger->warning('Subscription not updated after the reload: {message}', ['message' => $e->getMessage()]);
        }

        $after = array_map(strval(...), $configuration->roomIds());
        foreach (array_diff($before, $after) as $removed) {
            $this->evaluator->forget(new RoomId($removed));
        }

        $this->audit->log(ActorType::System, 'engine', 'audit.configuration.reloaded', ['rooms' => \count($after), 'entities' => \count($this->index->entityIds())]);
        $this->evaluator->evaluate($configuration->roomIds(), 'reload');
    }
}
