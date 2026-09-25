<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use InvalidArgumentException;

final class VenueNameNormalizer
{
    public function normalize(string $name): string
    {
        if (preg_match('//u', $name) !== 1) {
            throw new InvalidArgumentException('Venue names must be valid UTF-8 text.');
        }

        $name = str_replace(["'", '’', '‘', 'ʼ', '＇'], '', $name);
        $name = function_exists('mb_strtolower')
            ? mb_strtolower($name, 'UTF-8')
            : strtolower($name);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);

        if (! is_string($name)) {
            throw new InvalidArgumentException('Venue names must be valid UTF-8 text.');
        }

        $name = preg_replace('/\s+/u', ' ', trim($name));

        return is_string($name) ? $name : '';
    }

    /**
     * @return list<string>
     */
    public function variants(string $name): array
    {
        $normalized = $this->normalize($name);

        if ($normalized === '') {
            return [];
        }

        $pending = [
            $normalized,
            preg_replace('/(?<![\p{L}\p{N}])saint(?![\p{L}\p{N}])/u', 'st', $normalized) ?? '',
            preg_replace('/(?<![\p{L}\p{N}])st(?![\p{L}\p{N}])/u', 'saint', $normalized) ?? '',
        ];
        $variants = [];

        while ($pending !== []) {
            $variant = array_shift($pending);

            if (! is_string($variant) || $variant === '' || isset($variants[$variant])) {
                continue;
            }

            $variants[$variant] = true;
            $withoutSuffix = preg_replace('/\s+(?:church|hall)$/u', '', $variant);

            if (is_string($withoutSuffix) && $withoutSuffix !== '' && $withoutSuffix !== $variant) {
                $pending[] = $withoutSuffix;
            }
        }

        return array_keys($variants);
    }

    /**
     * @param list<string> $aliases
     * @return list<string>
     */
    public function uniqueAliases(array $aliases): array
    {
        $unique = [];
        $seen = [];

        foreach ($aliases as $alias) {
            $alias = trim($alias);
            $variants = $this->variants($alias);

            if ($variants === []) {
                continue;
            }

            sort($variants, SORT_STRING);
            $key = implode('|', $variants);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $alias;
        }

        return $unique;
    }
}
