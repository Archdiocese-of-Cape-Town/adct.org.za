<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use RuntimeException;

final class WordPressDatabaseConnection implements DatabaseConnectionInterface
{
    private ?object $database;

    public function __construct(?object $database = null)
    {
        if ($database === null) {
            global $wpdb;
            $database = $wpdb ?? null;
        }

        $this->database = is_object($database) ? $database : null;
    }

    public function prefix(): string
    {
        return (string) $this->connection()->prefix;
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        $prepared = $this->connection()->prepare($query, ...$arguments);

        if (! is_string($prepared)) {
            throw new RuntimeException('The WordPress database query could not be prepared.');
        }

        return $prepared;
    }

    public function query(string $query): int|false
    {
        $result = $this->connection()->query($query);

        return is_int($result) || $result === false ? $result : (int) $result;
    }

    public function getRow(string $query): ?array
    {
        $row = $this->connection()->get_row($query, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function getResults(string $query): array
    {
        $rows = $this->connection()->get_results($query, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function escapeLike(string $text): string
    {
        return $this->connection()->esc_like($text);
    }

    public function insertId(): int
    {
        return (int) $this->connection()->insert_id;
    }

    public function charsetCollate(): string
    {
        return (string) $this->connection()->get_charset_collate();
    }

    public function clearLastError(): void
    {
        $this->connection()->last_error = '';
    }

    public function lastError(): string
    {
        return (string) $this->connection()->last_error;
    }

    private function connection(): object
    {
        if ($this->database === null) {
            global $wpdb;
            $this->database = is_object($wpdb ?? null) ? $wpdb : null;
        }

        if ($this->database === null) {
            throw new RuntimeException('The WordPress database connection is unavailable.');
        }

        return $this->database;
    }
}
