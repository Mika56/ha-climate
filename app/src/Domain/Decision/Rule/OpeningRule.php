<?php

declare(strict_types=1);

namespace App\Domain\Decision\Rule;

use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomRuleInterface;

/**
 * Switches the room off once a window or door has been open for longer than the room's cut delay. Until then
 * the chain carries on, and the engine is asked to look again at the instant the delay elapses.
 */
final readonly class OpeningRule implements RoomRuleInterface
{
    public static function priority(): int
    {
        return 600;
    }

    public function apply(RoomEvaluationContext $context, callable $next): Decision
    {
        $since = $context->facts->openingOpenSince;
        if (null === $since) {
            return $next($context);
        }

        $cutAt = $since->modify(\sprintf('+%d seconds', $context->room->windowCutDelay));
        if ($context->now >= $cutAt) {
            return Decision::off(new Reason('reason.opening_open', ['since' => $since->format(\DATE_ATOM)]));
        }

        return $next($context)->withReevaluateAt($cutAt);
    }
}
