<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use InvalidArgumentException;

/**
 * Narrow SQLite callbacks for the queue's MySQL JSON expressions.
 * Invalid JSON is guarded by JSON_VALID in SQL, not silently recovered here.
 */
final class ReviewQueueJson
{
    public static function containsUnknownSender(string $json): int
    {
        return self::contains(json_decode($json, false, 512, JSON_THROW_ON_ERROR)) ? 1 : 0;
    }

    private static function contains(mixed $value): bool
    {
        if ($value === 'unknown_sender') {
            return true;
        }
        // MySQL scalar containment descends into arrays, not object values.
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::contains($item)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function text(string $json, string $path): ?string
    {
        if (preg_match('/\A\$\.[a-z_]+\z/D', $path) !== 1) {
            throw new InvalidArgumentException('Only top-level review queue JSON fields are supported.');
        }
        $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $key = substr($path, 2);
        if (! is_object($value) || ! property_exists($value, $key)) {
            return null;
        }
        return is_string($value->$key)
            ? $value->$key
            : json_encode($value->$key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
