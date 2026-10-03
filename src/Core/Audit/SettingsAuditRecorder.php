<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Audit;

/**
 * Builds the diff recorded for a settings change.
 *
 * Two things this is careful about:
 *
 * - It records only what actually changed. A settings form posts every field on
 *   every save, so recording the submitted values would bury the one real
 *   change in a wall of no-ops that make the log unreadable and grow the table
 *   for no evidential gain.
 * - It never records a secret's value. An option name that holds a key is
 *   recorded as "was set / is set", so an operator can still see that a key
 *   was added or removed on a given day without the log ever becoming a place
 *   credentials are written down.
 */
final class SettingsAuditRecorder
{
    /**
     * @var array<string, bool>
     */
    private readonly array $secretOptions;

    /**
     * @var list<array{option: string, before: string, after: string}>
     */
    private array $changes = [];

    /**
     * @param list<string> $secretOptions Option names whose values must never be recorded.
     */
    public function __construct(array $secretOptions = [])
    {
        $secretOptions = array_values(array_unique(array_filter(
            $secretOptions,
            static fn (string $option): bool => $option !== ''
        )));

        $this->secretOptions = array_fill_keys($secretOptions, true);
    }

    /**
     * Record a setting that was written.
     *
     * @param mixed $before The stored value before the write.
     * @param mixed $after  The value that was written.
     */
    public function record(string $option, mixed $before, mixed $after): void
    {
        if ($this->isSecret($option)) {
            $this->changes[] = [
                'option' => $option,
                'before' => $this->secretState($before),
                'after' => $this->secretState($after),
            ];

            return;
        }

        if ($this->same($before, $after)) {
            return;
        }

        $this->changes[] = [
            'option' => $option,
            'before' => $this->describe($before),
            'after' => $this->describe($after),
        ];
    }

    /**
     * Record a setting that was removed, such as a revoked API key.
     *
     * @param mixed $before The stored value before the removal.
     */
    public function recordRemoval(string $option, mixed $before): void
    {
        $this->changes[] = [
            'option' => $option,
            'before' => $this->isSecret($option) ? $this->secretState($before) : $this->describe($before),
            'after' => 'removed',
        ];
    }

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }

    /**
     * @return list<array{option: string, before: string, after: string}>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * @return array{changed: list<array{option: string, before: string, after: string}>}
     */
    public function toDetails(): array
    {
        return ['changed' => $this->changes];
    }

    private function isSecret(string $option): bool
    {
        return isset($this->secretOptions[$option]);
    }

    /**
     * Whether a stored value was in use. A secret is "set" for any non-empty
     * value, so the log records that a key exists without holding it.
     */
    private function secretState(mixed $value): string
    {
        return $this->inUse($value) ? 'set' : 'not set';
    }

    private function inUse(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }

    /**
     * Whether a write would change anything.
     *
     * A checkbox is stored as the string '1' or '0', but WordPress returns the
     * string '0' for an option that has never been written at all. Comparing
     * the two as strings is what keeps a form that re-submits an unchanged
     * checkbox out of the log; a stored false or '' is the same "off" state.
     */
    private function same(mixed $before, mixed $after): bool
    {
        if (is_bool($before) || is_bool($after)) {
            return $this->flagValue($before) === $this->flagValue($after);
        }

        if (is_scalar($before) || $before === null) {
            if (is_scalar($after) || $after === null) {
                return (string) $before === (string) $after;
            }

            return false;
        }

        return $before === $after;
    }

    private function flagValue(mixed $value): string
    {
        if ($value === true) {
            return '1';
        }

        if ($value === false || $value === null || $value === '') {
            return '0';
        }

        return (string) $value;
    }

    /**
     * Settings values are option rows, so they are scalars, arrays or null.
     * Arrays are summarised by size rather than dumped, which keeps a long
     * keyword list from becoming one enormous log cell.
     */
    private function describe(mixed $value): string
    {
        if ($value === null) {
            return 'not set';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            return sprintf('%d item(s)', count($value));
        }

        if (is_object($value)) {
            return 'object';
        }

        return (string) $value;
    }
}