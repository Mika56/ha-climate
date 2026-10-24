<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Support;

use App\Domain\Decision\Decision;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomRuleInterface;

/**
 * Rule that records its calls; it answers with $answer when given, otherwise delegates.
 */
final class SpyRule implements RoomRuleInterface
{
    public int $calls = 0;

    public function __construct(private readonly ?Decision $answer = null)
    {
    }

    public static function priority(): int
    {
        return 0;
    }

    public function apply(RoomEvaluationContext $context, callable $next): Decision
    {
        ++$this->calls;

        return $this->answer ?? $next($context);
    }
}
