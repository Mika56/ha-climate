<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Climate;

use App\Domain\Climate\TemperatureDelta;
use App\Domain\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemperatureDelta::class)]
final class TemperatureDeltaTest extends TestCase
{
    #[DataProvider('provideValidValues')]
    public function testValidValues(float $input, float $expected): void
    {
        self::assertSame($expected, (new TemperatureDelta($input))->toFloat());
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function provideValidValues(): iterable
    {
        yield 'lower bound' => [0.5, 0.5];
        yield 'upper bound' => [10.0, 10.0];
        yield 'rounded' => [2.04, 2.0];
    }

    #[DataProvider('provideInvalidValues')]
    public function testInvalidValues(float $input): void
    {
        $this->expectException(InvalidValueException::class);

        new TemperatureDelta($input);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideInvalidValues(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'below minimum' => [0.4];
        yield 'above maximum' => [10.1];
        yield 'NaN' => [\NAN];
    }
}
