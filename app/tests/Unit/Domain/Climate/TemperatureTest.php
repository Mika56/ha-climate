<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Climate;

use App\Domain\Climate\Temperature;
use App\Domain\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Temperature::class)]
final class TemperatureTest extends TestCase
{
    #[DataProvider('provideValidValues')]
    public function testValidValuesAreRoundedToTenths(float $input, float $expected): void
    {
        self::assertSame($expected, (new Temperature($input))->toFloat());
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function provideValidValues(): iterable
    {
        yield 'lower bound' => [5.0, 5.0];
        yield 'upper bound' => [35.0, 35.0];
        yield 'plain' => [21.5, 21.5];
        yield 'rounded down' => [21.54, 21.5];
        yield 'rounded up' => [21.56, 21.6];
        yield 'rounds into range' => [4.96, 5.0];
    }

    #[DataProvider('provideInvalidValues')]
    public function testInvalidValuesAreRejected(float $input): void
    {
        $this->expectException(InvalidValueException::class);

        new Temperature($input);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideInvalidValues(): iterable
    {
        yield 'too cold' => [4.9];
        yield 'too hot' => [35.1];
        yield 'NaN' => [\NAN];
        yield 'infinite' => [\INF];
    }

    public function testPlusAndMinusReturnNewInstances(): void
    {
        $base = new Temperature(20.0);

        self::assertSame(20.3, $base->plus(0.3)->toFloat());
        self::assertSame(19.7, $base->minus(0.3)->toFloat());
        self::assertSame(20.0, $base->toFloat());
    }

    public function testPlusBeyondRangeFails(): void
    {
        $this->expectException(InvalidValueException::class);

        (new Temperature(34.5))->plus(1.0);
    }

    public function testMinusBeyondRangeFails(): void
    {
        $this->expectException(InvalidValueException::class);

        (new Temperature(5.5))->minus(1.0);
    }

    public function testComparisons(): void
    {
        $low = new Temperature(19.0);
        $high = new Temperature(21.0);

        self::assertTrue($low->equals(new Temperature(19.04)));
        self::assertFalse($low->equals($high));
        self::assertTrue($high->isAbove($low));
        self::assertFalse($low->isAbove($low));
        self::assertTrue($low->isBelow($high));
        self::assertFalse($low->isBelow($low));
        self::assertTrue($low->isAtLeast($low));
        self::assertFalse($low->isAtLeast($high));
        self::assertTrue($low->isAtMost($low));
        self::assertFalse($high->isAtMost($low));
    }
}
