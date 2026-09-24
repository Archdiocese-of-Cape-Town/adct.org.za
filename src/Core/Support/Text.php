<?php

namespace ADCT\ParishIntake\Core\Support;

final class Text
{
    public static function normalizeWhitespace(string $text): string
    {
        $text = preg_replace('/\R/u', "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    public static function lines(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn ($line) => $line !== ''));
    }

    public static function firstMeaningfulLine(string $text): ?string
    {
        foreach (self::lines($text) as $line) {
            if (mb_strlen($line) >= 4) {
                return $line;
            }
        }

        return null;
    }

    public static function extractEmails(string $text): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    public static function extractPhones(string $text): array
    {
        preg_match_all('/(?:\+?\d[\d\s\-()]{7,}\d)/', $text, $matches);

        return array_values(array_unique(array_map('trim', $matches[0] ?? [])));
    }
}
