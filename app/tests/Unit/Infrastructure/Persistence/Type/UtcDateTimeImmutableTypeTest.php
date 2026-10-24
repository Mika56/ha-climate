<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\Type;

use App\Infrastructure\Persistence\Type\UtcDateTimeImmutableType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UtcDateTimeImmutableType::class)]
final class UtcDateTimeImmutableTypeTest extends TestCase
{
    private UtcDateTimeImmutableType $type;
    private SQLitePlatform $platform;

    protected function setUp(): void
    {
        $this->type = new UtcDateTimeImmutableType();
        $this->platform = new SQLitePlatform();
    }

    private function toDatabase(mixed $value): ?string
    {
        return $this->type->convertToDatabaseValue($value, $this->platform);
    }

    private function toPhp(mixed $value): ?\DateTimeImmutable
    {
        return $this->type->convertToPHPValue($value, $this->platform);
    }

    public function testStoresInstantsInUtc(): void
    {
        $paris = new \DateTimeImmutable('2026-07-01 12:00:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame('2026-07-01 10:00:00', $this->toDatabase($paris));
        self::assertNull($this->toDatabase(self::nothing()));
    }

    public function testHydratesAsUtc(): void
    {
        $date = $this->toPhp('2026-07-01 10:00:00');

        self::assertNotNull($date);
        self::assertSame('UTC', $date->getTimezone()->getName());
        self::assertSame('2026-07-01T10:00:00+00:00', $date->format(\DATE_ATOM));
        self::assertNull($this->toPhp(self::nothing()));
    }

    public function testNormalisesDateObjectsToUtc(): void
    {
        $paris = new \DateTimeImmutable('2026-07-01 12:00:00', new \DateTimeZone('Europe/Paris'));
        $date = $this->toPhp($paris);

        self::assertSame('2026-07-01 10:00:00', $date?->format('Y-m-d H:i:s'));
    }

    public function testRejectsMalformedStrings(): void
    {
        $this->expectException(InvalidFormat::class);
        $this->toPhp('not a date');
    }

    public function testRejectsOtherTypes(): void
    {
        $this->expectException(InvalidType::class);
        $this->toPhp(12);
    }

    private static function nothing(): mixed
    {
        return null;
    }
}
