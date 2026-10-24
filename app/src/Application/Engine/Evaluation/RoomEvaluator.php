<?php

declare(strict_types=1);

namespace App\Application\Engine\Evaluation;

use Amp\Sync\LocalMutex;
use App\Application\Engine\Alert\RoomAlertsProvider;
use App\Application\Engine\Audit\AuditLogger;
use App\Application\Engine\Command\CommandExecutor;
use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\EngineSnapshotFactory;
use App\Application\Engine\Facts\RoomFactsBuilder;
use App\Application\Engine\State\TimeAnchorResolver;
use App\Application\Engine\Timer\TimerScheduler;
use App\Domain\Decision\Decision;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\TargetResolver;
use App\Domain\Room\RoomId;
use App\Domain\Schedule\WeeklySchedule;
use Psr\Log\LoggerInterface;

/**
 * Decides what each room should do right now and makes it happen.
 *
 * For every room: gather the facts, resolve the decision through the rule chain, record it if it changed,
 * send the commands the devices need, publish the status, and arm a timer at the next schedule boundary so
 * the next slot is applied on time without polling. Evaluations are serialized: two events arriving close
 * together never interleave their commands.
 */
final class RoomEvaluator
{
    public const string BOUNDARY_TIMER_TYPE = 'room_reevaluate';

    private readonly LocalMutex $mutex;

    public function __construct(
        private readonly EngineSnapshotFactory $snapshots,
        private readonly RoomFactsBuilder $factsBuilder,
        private readonly TargetResolver $resolver,
        private readonly TimeAnchorResolver $anchors,
        private readonly CommandExecutor $executor,
        private readonly AuditLogger $audit,
        private readonly RoomStatusFactory $statusFactory,
        private readonly LiveStatusPublisher $publisher,
        private readonly TimerScheduler $timers,
        private readonly LoggerInterface $logger,
        private readonly RoomAlertsProvider $alerts,
    ) {
        $this->mutex = new LocalMutex();
    }

    /**
     * @param list<RoomId> $roomIds
     * @param string       $cause   why the evaluation happens (`startup`, `state:climate.x`, `schedule`...); recorded in the audit
     */
    public function evaluate(array $roomIds, string $cause): void
    {
        $lock = $this->mutex->acquire();
        try {
            $snapshot = $this->snapshots->create();
            foreach ($roomIds as $roomId) {
                $room = $snapshot->configuration->room($roomId);
                if (null === $room) {
                    continue;
                }

                try {
                    $this->evaluateRoom($room, $snapshot, $cause);
                } catch (\Throwable $e) {
                    $this->logger->error('Evaluation of room "{room}" failed: {message}', ['room' => $room->name, 'message' => $e->getMessage(), 'exception' => $e]);
                    $this->audit->logEvaluationFailure($room->id, $e->getMessage());
                }
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Drops everything the engine holds about a room removed from the configuration.
     */
    public function forget(RoomId $roomId): void
    {
        $this->timers->cancel(self::boundaryTimerKey($roomId));
        $this->publisher->remove($roomId);
        $this->audit->forget($roomId);
    }

    private function evaluateRoom(RoomConfiguration $room, EngineSnapshot $snapshot, string $cause): void
    {
        $season = $snapshot->configuration->season;
        $schedule = $snapshot->configuration->scheduleFor($room, $season->activeHvacMode());
        $facts = $this->factsBuilder->build($room, $snapshot);

        $decision = $this->resolver->resolve(new RoomEvaluationContext(
            $snapshot->now,
            $season,
            $room,
            $facts,
            $schedule,
            $this->anchors,
            $snapshot->configuration->policy,
        ));

        $this->audit->logDecisionChange($room->id, $decision, $cause);
        $this->executor->execute($room, $decision, $snapshot);
        $this->publisher->publish($this->statusFactory->create($room, $decision, $facts, $snapshot, $this->alerts->forRoom($room->id, $snapshot->now->getTimezone())));
        $this->armNextBoundary($room, $schedule, $decision, $snapshot);
    }

    /**
     * Arms the room's single boundary timer at the earliest of the next schedule slot and the instant a rule
     * asked to be evaluated again at (requests in the past are ignored).
     */
    private function armNextBoundary(RoomConfiguration $room, ?WeeklySchedule $schedule, Decision $decision, EngineSnapshot $snapshot): void
    {
        $key = self::boundaryTimerKey($room->id);
        $dueAt = null;
        $cause = 'schedule';

        if (null !== $schedule) {
            $dueAt = $schedule->nextSlotAfter($snapshot->now, $this->anchors)->start;
        }

        $requested = $decision->reevaluateAt;
        if (null !== $requested && $requested > $snapshot->now && (null === $dueAt || $requested < $dueAt)) {
            $dueAt = $requested;
            $cause = 'rule_deadline';
        }

        if (null === $dueAt) {
            $this->timers->cancel($key);

            return;
        }

        $this->timers->schedule($key, $dueAt, self::BOUNDARY_TIMER_TYPE, $room->id, ['cause' => $cause]);
    }

    private static function boundaryTimerKey(RoomId $roomId): string
    {
        return 'room.boundary.'.$roomId;
    }
}
