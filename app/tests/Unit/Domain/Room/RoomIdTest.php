<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Room;

use App\Domain\Exception\InvalidValueException;
use App\Domain\Room\RoomId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoomId::class)]
final class RoomIdTest extends TestCase
{
    public function testStringAndEquality(): void
    {
        $id = new RoomId('abc-123');

        self::assertSame('abc-123', (string) $id);
        self::assertTrue($id->equals(new RoomId('abc-123')));
        self::assertFalse($id->equals(new RoomId('other')));
    }

    #[DataProvider('provideBlank')]
    public function testBlankIsRejected(string $value): void
    {
        $this->expectException(InvalidValueException::class);

        new RoomId($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBlank(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['  '];
    }
}
