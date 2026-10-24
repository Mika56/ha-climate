<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Device;

use App\Domain\Device\AntiCyclingPolicy;
use App\Domain\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AntiCyclingPolicy::class)]
final class AntiCyclingPolicyTest extends TestCase
{
    public function testValidPolicy(): void
    {
        $policy = new AntiCyclingPolicy(0, 600, 2.0);

        self::assertSame(0, $policy->minOnSeconds);
        self::assertSame(600, $policy->minOffSeconds);
        self::assertSame(2.0, $policy->hysteresis);
    }

    #[DataProvider('provideInvalidPolicies')]
    public function testInvalidPolicies(int $on, int $off, float $hysteresis): void
    {
        $this->expectException(InvalidValueException::class);

        new AntiCyclingPolicy($on, $off, $hysteresis);
    }

    /**
     * @return iterable<string, array{int, int, float}>
     */
    public static function provideInvalidPolicies(): iterable
    {
        yield 'negative min on' => [-1, 0, 0.5];
        yield 'negative min off' => [0, -1, 0.5];
        yield 'negative hysteresis' => [0, 0, -0.1];
        yield 'hysteresis too large' => [0, 0, 2.1];
        yield 'NaN hysteresis' => [0, 0, \NAN];
    }
}
