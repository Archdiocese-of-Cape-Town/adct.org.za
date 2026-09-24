<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

interface DatabaseConnectionInterface
{
    public function prefix(): string;

    public function prepare(string $query, mixed ...$arguments): string;

    public function query(string $query): int|false;

    public function getRow(string $query): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getResults(string $query): array;

    public function escapeLike(string $text): string;

    public function insertId(): int;

    public function charsetCollate(): string;

    public function clearLastError(): void;

    public function lastError(): string;
}
