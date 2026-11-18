<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

class TableSort
{
    public static function compare(mixed $left, mixed $right, string $direction = 'asc'): int
    {
        $a = self::value($left);
        $b = self::value($right);
        if ($a === null || $b === null) {
            return $a === $b ? 0 : ($a === null ? 1 : -1);
        }
        $result = is_float($a) && is_float($b) ? $a <=> $b : strnatcasecmp((string) $a, (string) $b);

        return $direction === 'desc' ? -$result : $result;
    }

    private static function value(mixed $value): string|float|null
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($text === '' || preg_match('/^(?:[—–-]|Not recorded|Not provided|Unconfigured|Unconfirmed.*|Missing)$/iu', $text)) {
            return null;
        }
        if (preg_match('/^(?:₱|PHP\s*)?(-?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?)(?:\s*\/.*)?$/iu', $text, $match)) {
            return (float) str_replace(',', '', $match[1]);
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?)(?:\s|$)/u', $text, $match)) {
            try {
                $date = new DateTimeImmutable($match[1], new DateTimeZone('UTC'));

                return (float) $date->getTimestamp();
            } catch (\Exception) {
                // Unrecognized historical values retain natural text ordering.
            }
        }
        if (preg_match('/^[A-Z][a-z]{2} \d{1,2}, \d{4}(?: \d{1,2}:\d{2} [AP]M)?/', $text, $match)) {
            $format = str_contains($match[0], ':') ? '!M j, Y g:i A' : '!M j, Y';
            $date = DateTimeImmutable::createFromFormat($format, $match[0], new DateTimeZone('UTC'));
            if ($date !== false) {
                return (float) $date->getTimestamp();
            }
        }

        return mb_strtolower($text);
    }
}
