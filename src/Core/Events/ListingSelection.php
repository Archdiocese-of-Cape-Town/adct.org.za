<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use InvalidArgumentException;

final class ListingSelection
{
    public readonly string $period;
    public readonly string $from;
    public readonly string $through;
    public readonly int $page;
    /** @var list<int> */
    public readonly array $types;
    public readonly ?int $parish;
    public readonly ?int $deanery;
    public readonly bool $collapse;
    public readonly bool $pin;

    /** @param array<string, mixed> $input */
    public function __construct(array $input, string $defaultPeriod = 'upcoming')
    {
        $this->period = self::text($input['adct_period'] ?? $defaultPeriod);
        $this->from = self::text($input['adct_from'] ?? '');
        $this->through = self::text($input['adct_to'] ?? '');
        $this->page = self::id($input['adct_page'] ?? '1', 100);
        $this->parish = self::optionalId($input['adct_parish'] ?? '');
        $this->deanery = self::optionalId($input['adct_deanery'] ?? '');
        $this->collapse = self::toggle($input['adct_collapse'] ?? '');
        $this->pin = self::toggle($input['adct_pin'] ?? '');
        $values = $input['adct_types'] ?? [];
        if (! is_array($values) || ! array_is_list($values) || count($values) > 20) {
            throw new InvalidArgumentException('Choose at most 20 event types.');
        }
        $types = [];
        foreach ($values as $value) {
            $types[] = self::id($value);
        }
        $types = array_values(array_unique($types));
        sort($types, SORT_NUMERIC);
        $this->types = $types;
    }

    /** @return array<string, string|int|list<int>> */
    public function query(int $page = 0): array
    {
        $query = ['adct_period' => $this->period];
        if ($this->period === 'range') {
            $query['adct_from'] = $this->from;
            $query['adct_to'] = $this->through;
        }
        if ($this->types !== []) {
            $query['adct_types'] = $this->types;
        }
        if ($this->parish !== null) {
            $query['adct_parish'] = $this->parish;
        }
        if ($this->deanery !== null) {
            $query['adct_deanery'] = $this->deanery;
        }
        if ($this->collapse) {
            $query['adct_collapse'] = '1';
        }
        if ($this->pin) {
            $query['adct_pin'] = '1';
        }
        if ($page > 0 || $this->page > 1) {
            $query['adct_page'] = $page > 0 ? $page : $this->page;
        }

        return $query;
    }

    private static function toggle(mixed $value): bool
    {
        if ($value !== '' && $value !== '1') {
            throw new InvalidArgumentException('Choose a valid event display option.');
        }

        return $value === '1';
    }

    private static function text(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 32) {
            throw new InvalidArgumentException('Enter a valid event filter.');
        }

        return $value;
    }

    private static function optionalId(mixed $value): ?int
    {
        if ($value === '') {
            return null;
        }

        return self::id($value);
    }

    private static function id(mixed $value, int $maximum = 2147483647): int
    {
        if (
            (! is_string($value) && ! is_int($value))
            || preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $value) !== 1
            || (float) $value > $maximum
        ) {
            throw new InvalidArgumentException('Choose a valid event filter value.');
        }

        return (int) $value;
    }
}
