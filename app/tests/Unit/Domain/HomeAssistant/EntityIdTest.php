<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\HomeAssistant;

use App\Domain\Exception\InvalidValueException;
use App\Domain\HomeAssistant\EntityId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityId::class)]
final class EntityIdTest extends TestCase
{
    public function testExposesDomainAndObjectId(): void
    {
        $id = new EntityId('climate.living_room_2');

        self::assertSame('climate', $id->domain());
        self::assertSame('living_room_2', $id->objectId());
        self::assertSame('climate.living_room_2', (string) $id);
    }

    public function testEquality(): void
    {
        self::assertTrue((new EntityId('climate.a'))->equals(new EntityId('climate.a')));
        self::assertFalse((new EntityId('climate.a'))->equals(new EntityId('climate.b')));
    }

    #[DataProvider('provideInvalidIds')]
    public function testInvalidIdsAreRejected(string $value): void
    {
        $this->expectException(InvalidValueException::class);

        new EntityId($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'no dot' => ['climate'];
        yield 'two dots' => ['climate.a.b'];
        yield 'uppercase' => ['Climate.living'];
        yield 'empty object id' => ['climate.'];
        yield 'empty domain' => ['.living'];
        yield 'leading underscore' => ['_climate.living'];
        yield 'leading underscore object' => ['climate._living'];
        yield 'trailing underscore' => ['climate.living_'];
        yield 'double underscore' => ['climate.liv__ing'];
        yield 'space' => ['climate.liv ing'];
        yield 'trailing newline' => ["climate.living\n"];
    }
}
