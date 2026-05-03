<?php

namespace App\Custom\ZavetyMichurina\ElectricityStatementImport;

final class ZavetyMichurinaPersonNameNormalizer
{
    public static function normalizeFullName(?string $fullName): ?string
    {
        if ($fullName === null) {
            return null;
        }

        $parts = preg_split('/\s+/u', trim($fullName));

        if (!is_array($parts) || $parts === []) {
            return null;
        }

        $normalized = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $normalized[] = self::normalizeNamePart($part);
        }

        return $normalized === [] ? null : implode(' ', $normalized);
    }

    private static function normalizeNamePart(string $part): string
    {
        return preg_replace_callback(
            '/\p{L}+/u',
            static fn (array $matches): string => self::uppercaseFirst(mb_strtolower($matches[0], 'UTF-8')),
            $part,
        ) ?? $part;
    }

    private static function uppercaseFirst(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8')
            .mb_substr($value, 1, null, 'UTF-8');
    }
}
