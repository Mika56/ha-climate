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
     * Position in the chain: rules with a higher priority run first. Wired automatically through the
     * `app.room_rule` service tag (see config/services.yaml), so a new rule only needs a new file.
     *
     * Taken: no device for mode 900, vacation 800, house empty 700, opening 600, override 500, room vacancy 400,
     * pre-heating -900, schedule -1000. The pause is not a rule: decisions are computed as usual and the engine
     * simply sends nothing while paused.
     */
    public static function priority(): int;

    /**
     * @param callable(RoomEvaluationContext): Decision $next runs the remaining rules
     */
    public function apply(RoomEvaluationContext $context, callable $next): Decision;
}
