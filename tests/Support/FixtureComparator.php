<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Support;

final class FixtureComparator
{
    private const MISSING = "\0ADCT fixture value missing\0";

    /**
     * Each returned check represents one expected field. Lists also check their item count.
     *
     * @return array<string, array{expected: mixed, actual: mixed, matches: bool}>
     */
    public static function compare(array $expected, array $actual): array
    {
        unset($expected['known_failures'], $expected['directory_snapshot'], $expected['existing_event']);

        $checks = [];

        foreach ($expected as $key => $value) {
            self::compareValue(
                $value,
                array_key_exists($key, $actual) ? $actual[$key] : self::MISSING,
                (string) $key,
                $checks
            );
        }

        return $checks;
    }

    public static function knownIssueForPath(string $path, array $knownFailures): ?string
    {
        $candidate = $path;

        while ($candidate !== '') {
            if (isset($knownFailures[$candidate])) {
                $issue = $knownFailures[$candidate];

                return is_string($issue) && preg_match('/^#\d+$/', $issue) ? $issue : null;
            }

            $separator = strrpos($candidate, '.');
            $bracket = strrpos($candidate, '[');

            if ($bracket !== false && ($separator === false || $bracket > $separator)) {
                $candidate = substr($candidate, 0, $bracket);
                continue;
            }

            if ($separator === false) {
                break;
            }

            $candidate = substr($candidate, 0, $separator);
        }

        return null;
    }

    public static function describeValue(mixed $value): string
    {
        if ($value === self::MISSING) {
            return '<missing>';
        }

        return var_export($value, true);
    }

    /**
     * @param array<string, array{expected: mixed, actual: mixed, matches: bool}> $checks
     */
    private static function compareValue(mixed $expected, mixed $actual, string $path, array &$checks): void
    {
        if ($actual === self::MISSING || ! is_array($expected) || ! is_array($actual)) {
            $checks[$path] = [
                'expected' => $expected,
                'actual' => $actual,
                'matches' => $expected === $actual,
            ];

            return;
        }

        $expectedIsList = array_is_list($expected);
        $actualIsList = array_is_list($actual);

        if ($expected !== [] && $actual !== [] && $expectedIsList !== $actualIsList) {
            $checks[$path] = [
                'expected' => $expected,
                'actual' => $actual,
                'matches' => false,
            ];

            return;
        }

        if ($expectedIsList) {
            $checks[$path . '[count]'] = [
                'expected' => count($expected),
                'actual' => count($actual),
                'matches' => count($expected) === count($actual),
            ];

            foreach ($expected as $index => $value) {
                self::compareValue(
                    $value,
                    array_key_exists($index, $actual) ? $actual[$index] : self::MISSING,
                    $path . '[' . $index . ']',
                    $checks
                );
            }

            return;
        }

        foreach ($expected as $key => $value) {
            self::compareValue(
                $value,
                array_key_exists($key, $actual) ? $actual[$key] : self::MISSING,
                $path . '.' . (string) $key,
                $checks
            );
        }
    }
}
