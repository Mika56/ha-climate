<?php

declare(strict_types=1);

namespace App\Application\Engine\Override;

use App\Application\Engine\Evaluation\LiveStatusPublisher;
use App\Application\Engine\Evaluation\RoomEvaluator;
use App\Application\Engine\Evaluation\RoomStatus;
use App\Domain\Climate\TemperatureDelta;
use App\Domain\Room\RoomId;

/**
 * What the boost API does: change the room's override, evaluate the room at once so the devices follow,
 * and answer with the resulting status.
 */
final readonly class OverrideCommands
{
    public function __construct(
        private OverrideManager $overrides,
        private RoomEvaluator $evaluator,
        private LiveStatusPublisher $publisher,
    ) {
    }

    /**
     * @throws OverrideRejectedException
     */
    public function boost(RoomId $roomId, TemperatureDelta $delta, ?int $durationMinutes, bool $untilNextSlot, string $actor): RoomStatus
    {
        $this->overrides->startBoost($roomId, $delta, $durationMinutes, $untilNextSlot, $actor);

        return $this->evaluate($roomId, 'boost');
    }

    /**
     * @throws OverrideRejectedException
     */
    public function cancel(RoomId $roomId, string $actor): RoomStatus
    {
        $this->overrides->cancel($roomId, $actor);

        return $this->evaluate($roomId, 'override_cancelled');
    }

    private function evaluate(RoomId $roomId, string $cause): RoomStatus
    {
        $this->evaluator->evaluate([$roomId], $cause);

        foreach ($this->publisher->snapshot() as $status) {
            if ($status->roomId->equals($roomId)) {
                return $status;
            }
        }

        throw new \RuntimeException(\sprintf('The room "%s" could not be evaluated.', $roomId));
    }
}
