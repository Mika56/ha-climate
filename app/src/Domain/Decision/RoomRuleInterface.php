<?php

declare(strict_types=1);

namespace App\Domain\Decision;

/**
 * One link of the resolution chain. A rule either answers by itself (terminal)
 * or delegates to the remaining rules through $next.
 */
interface RoomRuleInterface
{
    /**
     * @param callable(RoomEvaluationContext): Decision $next runs the remaining rules
     */
    public function apply(RoomEvaluationContext $context, callable $next): Decision;
}
