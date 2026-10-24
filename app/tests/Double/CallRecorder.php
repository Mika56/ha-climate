<?php

declare(strict_types=1);

namespace App\Tests\Double;

/**
 * Collects what callbacks under test hand over, in order.
 */
final class CallRecorder
{
    /** @var list<mixed> */
    public array $calls = [];

    public function record(mixed $value = true): void
    {
        $this->calls[] = $value;
    }
}
