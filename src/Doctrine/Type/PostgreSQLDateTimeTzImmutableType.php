<?php

namespace App\Doctrine\Type;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Exception;

use function is_string;

final class PostgreSQLDateTimeTzImmutableType extends DateTimeTzImmutableType
{
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d H:i:s.uP');
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value)) {
            try {
                $dateTime = new DateTimeImmutable($value);
            } catch (Exception) {
                throw InvalidFormat::new(
                    $value,
                    static::class,
                    $platform->getDateTimeTzFormatString(),
                );
            }

            return $dateTime;
        }

        throw InvalidFormat::new(
            $value,
            static::class,
            $platform->getDateTimeTzFormatString(),
        );
    }
}
