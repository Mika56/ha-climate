<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;

/**
 * Overrides the built-in "datetime_immutable" type so that every instant is
 * stored in UTC and always hydrated as a UTC DateTimeImmutable, whatever the
 * default timezone of the PHP process (php-fpm and the engine may differ).
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    private static ?\DateTimeZone $utc = null;

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            $value = $value->setTimezone(self::utc());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value->setTimezone(self::utc());
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, self::class, ['null', 'string', \DateTimeImmutable::class]);
        }

        $converted = \DateTimeImmutable::createFromFormat(
            $platform->getDateTimeFormatString(),
            $value,
            self::utc(),
        );

        if (false === $converted) {
            throw InvalidFormat::new($value, self::class, $platform->getDateTimeFormatString());
        }

        return $converted;
    }

    private static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }
}
