<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;

use function array_map;
use function count;
use function implode;
use function is_array;
use function is_resource;
use function is_string;
use function strlen;
use function str_replace;
use function stream_get_contents;

final class PostgreSQLTextArrayType extends Type
{
    public const NAME = 'text_array';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'text[]';
    }

    /**
     * @param list<string>|null $value
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('PostgreSQL text array value must be an array or null.');
        }

        if ($value === []) {
            return '{}';
        }

        return '{'.implode(',', array_map($this->quoteElement(...), $value)).'}';
    }

    /**
     * @return list<string>|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('PostgreSQL text array database value must be a string, array or null.');
        }

        return $this->parseArrayLiteral($value);
    }

    private function quoteElement(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('PostgreSQL text array elements must be strings.');
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * @return list<string>
     */
    private function parseArrayLiteral(string $value): array
    {
        if ($value === '{}') {
            return [];
        }

        if (strlen($value) < 2 || $value[0] !== '{' || $value[-1] !== '}') {
            return [$value];
        }

        $items = [];
        $buffer = '';
        $quoted = false;
        $inQuotes = false;
        $length = strlen($value);

        for ($i = 1; $i < $length - 1; ++$i) {
            $char = $value[$i];

            if ($inQuotes) {
                if ($char === '\\' && $i + 1 < $length - 1) {
                    $buffer .= $value[++$i];

                    continue;
                }

                if ($char === '"') {
                    $inQuotes = false;

                    continue;
                }

                $buffer .= $char;

                continue;
            }

            if ($char === '"') {
                $quoted = true;
                $inQuotes = true;

                continue;
            }

            if ($char === ',') {
                if ($quoted || $buffer !== 'NULL') {
                    $items[] = $buffer;
                }

                $buffer = '';
                $quoted = false;

                continue;
            }

            $buffer .= $char;
        }

        if ($quoted || $buffer !== '' || count($items) > 0) {
            if ($quoted || $buffer !== 'NULL') {
                $items[] = $buffer;
            }
        }

        return $items;
    }
}
