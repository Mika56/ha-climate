<?php

declare(strict_types=1);

namespace App\Application\Engine\Alert;

use App\Application\Engine\Evaluation\RoomEvaluator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Publishes the status of a room again when one of its alerts opens or resolves, so the live stream shows it.
 */
#[AsEventListener(event: AlertsChanged::class)]
final readonly class RoomStatusRefresher
{
    public function __construct(private RoomEvaluator $evaluator)
    {
    }

    public function __invoke(AlertsChanged $event): void
    {
        $this->evaluator->evaluate([$event->room], 'alert');
    }
}
