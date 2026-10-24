<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\Support;

use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Support\Guard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Guard::class)]
final class GuardTest extends TestCase
{
    public function testValidValuesPassThrough(): void
    {
        self::assertSame('x', Guard::notBlank('x', 'f'));
        self::assertNull(Guard::nullableNotBlank(null, 'f'));
        self::assertSame(0, Guard::nonNegativeInt(0, 'f'));
        self::assertSame(0.0, Guard::nonNegativeFloat(0.0, 'f'));
        self::assertSame(1, Guard::positiveInt(1, 'f'));
        self::assertSame(7, Guard::between(7, 1, 7, 'f'));
        self::assertSame('a-b_1', Guard::slug('a-b_1', 'f'));
        self::assertSame('#aBc123', Guard::hexColor('#aBc123', 'f'));
        self::assertSame(HvacMode::Heat, Guard::activeMode(HvacMode::Heat, 'f'));
        self::assertSame(['a', 'b'], Guard::stringList(['a', 'b', 'a'], 'f'));
    }

    public function testInvalidValuesThrow(): void
    {
        foreach ([
            static fn () => Guard::notBlank(' ', 'f'),
            static fn () => Guard::nullableNotBlank('', 'f'),
            static fn () => Guard::nonNegativeInt(-1, 'f'),
            static fn () => Guard::nonNegativeFloat(-0.1, 'f'),
            static fn () => Guard::positiveInt(0, 'f'),
            static fn () => Guard::between(8, 1, 7, 'f'),
            static fn () => Guard::slug('Bad Slug', 'f'),
            static fn () => Guard::hexColor('#12345', 'f'),
            static fn () => Guard::activeMode(HvacMode::Off, 'f'),
            static fn () => Guard::stringList(['a', ''], 'f'),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
