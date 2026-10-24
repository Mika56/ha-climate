<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Preset;

use App\Domain\Exception\InvalidValueException;
use App\Domain\Preset\PresetKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresetKey::class)]
final class PresetKeyTest extends TestCase
{
    #[DataProvider('provideValidKeys')]
    public function testValidKeys(string $value): void
    {
        self::assertSame($value, (string) new PresetKey($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidKeys(): iterable
    {
        yield 'word' => ['comfort'];
        yield 'with digits and underscore' => ['night_2'];
        yield 'single char' => ['a'];
        yield 'max length' => [str_repeat('a', 32)];
    }

    #[DataProvider('provideInvalidKeys')]
    public function testInvalidKeys(string $value): void
    {
        $this->expectException(InvalidValueException::class);

        new PresetKey($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'uppercase' => ['Comfort'];
        yield 'dash' => ['night-time'];
        yield 'space' => ['night time'];
        yield 'trailing newline' => ["eco\n"];
    }

    public function testEquality(): void
    {
        self::assertTrue((new PresetKey('eco'))->equals(new PresetKey('eco')));
        self::assertFalse((new PresetKey('eco'))->equals(new PresetKey('away')));
    }
}
