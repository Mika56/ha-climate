<?php

declare(strict_types=1);

namespace App\Application\Engine\Audit;

use App\Domain\Climate\Season;
use App\Domain\Decision\Decision;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Repository\AuditEntryRepository;
use App\Infrastructure\Persistence\Repository\PresetRepository;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the audit trail. Message keys are translation keys of the `audit` domain; `params` feed the
 * translation and `payload` keeps the technical details for the UI.
 *
 * Automatic decisions are only recorded when they change, so the trail stays readable.
 */
final class AuditLogger
{
    /** @var array<string, Decision> last decision seen per room id */
    private array $lastDecisions = [];

    /** @var array<string, string> last evaluation failure reported per room id */
    private array $lastFailures = [];

    public function __construct(
        private readonly AuditEntryRepository $entries,
        private readonly RoomRepository $rooms,
        private readonly ClockInterface $clock,
        private readonly PresetRepository $presets,
    ) {
    }

    /**
     * @param array<string, scalar|null> $params
     * @param array<string, mixed>       $payload
     */
    public function log(
        ActorType $actor,
        string $category,
        string $messageKey,
        array $params = [],
        ?RoomId $room = null,
        array $payload = [],
        ?string $actorId = null,
    ): void {
        $this->entries->save(new AuditEntry(
            $this->clock->now(),
            $actor,
            $category,
            $messageKey,
            $params,
            $payload,
            $actorId,
            null,
            null === $room ? null : $this->rooms->find(Uuid::fromString((string) $room)),
        ));
    }

    /**
     * Records the decision if it differs from the previous one of the room. The very first decision seen
     * for a room (after a start) is remembered but not recorded: nothing changed from the user's viewpoint.
     *
     * While HA Climate is paused ($pausedIn is the season it would be driving) the change is recorded as what it
     * would have done, flagged as not applied (`audit.decision.simulated`).
     */
    public function logDecisionChange(RoomId $room, Decision $decision, string $cause, ?Season $pausedIn = null): void
    {
        $previous = $this->lastDecisions[(string) $room] ?? null;
        $this->lastDecisions[(string) $room] = $decision;
        $this->lastFailures[(string) $room] = '';

        if (null === $previous || self::isSame($previous, $decision)) {
            return;
        }

        $params = ['mode' => $decision->mode->value];
        if (null !== $decision->target) {
            $params['target'] = $decision->target->toFloat();
        }
        if (null !== $pausedIn) {
            $params = $this->simulatedParams($decision, $params, $pausedIn);
        }

        $this->log(ActorType::Automation, 'decision', null === $pausedIn ? 'audit.decision.changed' : 'audit.decision.simulated', $params, $room, [
            'cause' => $cause,
            'simulated' => null !== $pausedIn,
            'reason' => ['code' => $decision->reason->code, 'params' => $decision->reason->params],
            'previous' => [
                'mode' => $previous->mode->value,
                'target' => $previous->target?->toFloat(),
                'reason' => ['code' => $previous->reason->code, 'params' => $previous->reason->params],
            ],
        ]);
    }

    /**
     * Records that a room could not be evaluated, once per distinct problem.
     */
    public function logEvaluationFailure(RoomId $room, string $message): void
    {
        if (($this->lastFailures[(string) $room] ?? '') === $message) {
            return;
        }
        $this->lastFailures[(string) $room] = $message;

        $this->log(ActorType::System, 'engine', 'audit.evaluation.failed', ['error' => $message], $room);
    }

    /**
     * Drops what is remembered about a room that no longer exists.
     */
    public function forget(RoomId $room): void
    {
        unset($this->lastDecisions[(string) $room], $this->lastFailures[(string) $room]);
    }

    /**
     * The values the "paused" wording needs: what would be applied (mode, target, season) and why (the reason
     * code with its own parameters, the preset shown by its label).
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, scalar|null>
     */
    private function simulatedParams(Decision $decision, array $params, Season $season): array
    {
        $reason = $decision->reason->params;
        $preset = $reason['preset'] ?? null;
        if (\is_string($preset)) {
            $reason['preset'] = $this->presets->findOneByKey($preset)?->getLabel() ?? $preset;
        }

        return [...$reason, ...$params, 'season' => $season->value, 'reason' => preg_replace('/^reason\./', '', $decision->reason->code) ?? $decision->reason->code];
    }

    private static function isSame(Decision $a, Decision $b): bool
    {
        return $a->mode === $b->mode
            && $a->target?->toFloat() === $b->target?->toFloat()
            && $a->reason->code === $b->reason->code
            && $a->reason->params === $b->reason->params;
    }
}
