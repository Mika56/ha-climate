<?php

declare(strict_types=1);

namespace App\Domain\Decision\Rule;

use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomRuleInterface;

/**
 * Switches the room off once nobody has been home for the configured delay. Before that, the room carries on
 * and asks to be evaluated again the instant the delay elapses, so no Home Assistant event is needed.
 */
final readonly class HouseEmptyRule implements RoomRuleInterface
{
    public static function priority(): int
    {
        return 700;
    }

    public function apply(RoomEvaluationContext $context, callable $next): Decision
    {
        $since = $context->facts->houseEmptySince;
        if (null === $since) {
            return $next($context);
        }

        $deadline = $since->modify(\sprintf('+%d seconds', $context->policy->houseEmptyDelaySeconds));
        if ($deadline <= $context->now) {
            return Decision::off(new Reason('reason.house_empty', ['since' => $since->format(\DATE_ATOM)]));
        }

        return $next($context)->withReevaluateAt($deadline);
    }
}
