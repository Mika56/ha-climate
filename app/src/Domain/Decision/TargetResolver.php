<?php

declare(strict_types=1);

namespace App\Domain\Decision;

/**
 * Runs the rules, highest priority first, as a middleware chain.
 */
final readonly class TargetResolver
{
    /** @var list<RoomRuleInterface> */
    private array $rules;

    /**
     * @param iterable<RoomRuleInterface> $rules already sorted by priority
     */
    public function __construct(iterable $rules)
    {
        $this->rules = array_values([...$rules]);
    }

    public function resolve(RoomEvaluationContext $context): Decision
    {
        return $this->runFrom(0, $context);
    }

    private function runFrom(int $index, RoomEvaluationContext $context): Decision
    {
        $rule = $this->rules[$index] ?? null;

        if (null === $rule) {
            return Decision::off(new Reason('reason.no_rule'));
        }

        return $rule->apply($context, fn (RoomEvaluationContext $next): Decision => $this->runFrom($index + 1, $next));
    }
}
