<?php

declare(strict_types=1);

namespace App\Application\Engine\Timer;

use App\Application\Engine\Evaluation\RoomEvaluator;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\Timer;

/**
 * Re-evaluates the room of a timer that came due: schedule boundaries, postponed commands...
 * The `cause` payload entry is passed to the evaluation (defaults to `timer`).
 */
final readonly class RoomReevaluateHandler implements TimerHandlerInterface
{
    public function __construct(private RoomEvaluator $evaluator)
    {
    }

    public function type(): string
    {
        return RoomEvaluator::BOUNDARY_TIMER_TYPE;
    }

    public function handle(Timer $timer): void
    {
        $roomId = $timer->getRoomId();
        if (null === $roomId) {
            return;
        }

        $cause = $timer->getPayload()['cause'] ?? null;
        $this->evaluator->evaluate([new RoomId($roomId->toRfc4122())], \is_string($cause) ? $cause : 'timer');
    }
}
