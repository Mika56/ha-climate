<?php

declare(strict_types=1);

namespace App\Domain\Decision\Rule;

use App\Domain\Climate\Season;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomRuleInterface;

final readonly class SeasonOffRule implements RoomRuleInterface
{
    public static function priority(): int
    {
        return 1000;
    }

    public function apply(RoomEvaluationContext $context, callable $next): Decision
    {
        if (Season::Off === $context->season) {
            return Decision::off(new Reason('reason.season_off'));
        }

        return $next($context);
    }
}
