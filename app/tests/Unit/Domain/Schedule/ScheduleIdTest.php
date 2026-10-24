<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Schedule;

use App\Domain\Exception\InvalidValueException;
use App\Domain\Schedule\ScheduleId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScheduleId::class)]
final class ScheduleIdTest extends TestCase
{
    public function testStringAndEquality(): void
    {
        $id = new ScheduleId('week');

        self::assertSame('week', (string) $id);
        self::assertTrue($id->equals(new ScheduleId('week')));
        self::assertFalse($id->equals(new ScheduleId('weekend')));
    }

    public function testBlankIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new ScheduleId(' ');
    }
}
