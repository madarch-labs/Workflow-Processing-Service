<?php

declare(strict_types=1);

namespace Wps\Time;

/**
 * Durations in definitions: "500ms", "2s", "10m", "1h", "1d", "1h30m" or ISO 8601 ("PT5M", "P7D"); an integer is
 * milliseconds. Shared by retry profiles (7.1) and delays (4.7).
 */
final class Duration
{
    private const UNITS = ['ms' => 1, 's' => 1000, 'm' => 60000, 'h' => 3600000, 'd' => 86400000, 'w' => 604800000];

    /**
     * Milliseconds, or null when the value is not a duration.
     */
    public static function toMs(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $text = strtolower(trim($value));
        if (preg_match('/^\d+$/', $text)) {
            return (int)$text;
        }
        if (preg_match('/^(?:\d+(?:ms|[smhdw])\s*)+$/', $text)) {
            preg_match_all('/(\d+)(ms|[smhdw])/', $text, $parts, PREG_SET_ORDER);
            return array_sum(array_map(static fn (array $p) => (int)$p[1] * self::UNITS[$p[2]], $parts));
        }
        if (str_starts_with($text, 'p')) {
            try {
                $interval = new \DateInterval(strtoupper($text));
            } catch (\Exception) {
                return null;
            }
            if ($interval->y > 0 || $interval->m > 0) {
                return null; // months and years have no fixed length
            }
            return (($interval->d * 24 + $interval->h) * 60 + $interval->i) * 60000 + $interval->s * 1000;
        }
        return null;
    }
}
