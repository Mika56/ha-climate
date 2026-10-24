<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\HomeAssistant;

use App\Infrastructure\HomeAssistant\ReconnectBackoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReconnectBackoff::class)]
final class ReconnectBackoffTest extends TestCase
{
    public function testDelaysDoubleUpToTheMaximum(): void
    {
        $backoff = new ReconnectBackoff(1.0, 60.0, static fn (): float => 0.0);

        $delays = array_map($backoff->delayForAttempt(...), range(0, 8));

        self::assertSame([1.0, 2.0, 4.0, 8.0, 16.0, 32.0, 60.0, 60.0, 60.0], $delays);
    }

    public function testHugeAttemptNumbersStayCapped(): void
    {
        $backoff = new ReconnectBackoff(1.0, 60.0, static fn (): float => 0.0);

        self::assertSame(60.0, $backoff->delayForAttempt(10_000));
    }

    public function testJitterOnlyReducesTheDelayByAQuarterAtMost(): void
    {
        $backoff = new ReconnectBackoff(4.0, 60.0, static fn (): float => 1.0);

        self::assertSame(3.0, $backoff->delayForAttempt(0));
    }

    public function testDefaultJitterStaysWithinBounds(): void
    {
        $backoff = new ReconnectBackoff();

        for ($i = 0; $i < 50; ++$i) {
            $delay = $backoff->delayForAttempt(3);
            self::assertGreaterThanOrEqual(6.0, $delay);
            self::assertLessThanOrEqual(8.0, $delay);
        }
    }
}
