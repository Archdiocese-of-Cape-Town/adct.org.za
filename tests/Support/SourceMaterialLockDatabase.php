<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Support;

use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;

final class SourceMaterialLockDatabase implements DatabaseConnectionInterface
{
    public array $held = [];
    public array $queries = [];

    public function prefix(): string { return 'wp_'; }
    public function prepare(string $query, mixed ...$arguments): string
    {
        return str_replace('%s', "'" . (string) $arguments[0] . "'", $query);
    }
    public function query(string $query): int|false { return 1; }
    public function getRow(string $query): ?array
    {
        $this->queries[] = $query;
        preg_match("/'([^']+)'/", $query, $matches);
        $name = $matches[1] ?? '';
        if (str_contains($query, 'IS_USED_LOCK')) {
            return ['owner' => isset($this->held[$name]) ? 1 : null, 'connection_id' => 1];
        }
        if (str_contains($query, 'GET_LOCK')) {
            if (isset($this->held[$name])) {
                return ['acquired' => 0];
            }
            $this->held[$name] = true;
            return ['acquired' => 1];
        }
        unset($this->held[$name]);
        return ['released' => 1];
    }
    public function getResults(string $query): array { return []; }
    public function escapeLike(string $text): string { return addcslashes($text, '_%\\'); }
    public function insertId(): int { return 0; }
    public function charsetCollate(): string { return ''; }
    public function clearLastError(): void {}
    public function lastError(): string { return ''; }
}
