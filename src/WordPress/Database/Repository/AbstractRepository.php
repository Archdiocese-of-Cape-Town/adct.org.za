<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use InvalidArgumentException;
use RuntimeException;

abstract class AbstractRepository
{
    protected const TABLE_SUFFIX = '';

    /**
     * @var array<string, string> Database column to prepared-statement format.
     */
    protected const FIELD_FORMATS = [];

    public function __construct(protected DatabaseConnectionInterface $database)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $this->assertValidId($id);
        $table = $this->tableName();
        $query = $this->database->prepare(
            "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
            $id
        );
        $this->database->clearLastError();
        $row = $this->database->getRow($query);

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The database read failed: ' . $this->database->lastError());
        }

        return $row;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function insert(array $values): int
    {
        if ($values === []) {
            throw new InvalidArgumentException('At least one column is required for an insert.');
        }

        [$columns, $placeholders, $arguments] = $this->prepareColumns($values);
        $table = $this->tableName();
        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $result = $this->execute($this->prepare($query, $arguments), 'insert');

        $id = $this->database->insertId();

        if ($id < 1) {
            throw new RuntimeException('The database insert did not return a row ID.');
        }

        return $id;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function update(int $id, array $values): int
    {
        $this->assertValidId($id);

        if ($values === []) {
            throw new InvalidArgumentException('At least one column is required for an update.');
        }

        [, $assignments, $arguments] = $this->prepareColumns($values, true);
        $arguments[] = $id;
        $table = $this->tableName();
        $query = sprintf(
            'UPDATE %s SET %s WHERE id = %%d',
            $table,
            implode(', ', $assignments)
        );
        return $this->execute($this->prepare($query, $arguments), 'update');
    }

    public function delete(int $id): int
    {
        $this->assertValidId($id);
        $table = $this->tableName();
        $query = $this->database->prepare(
            "DELETE FROM {$table} WHERE id = %d",
            $id
        );
        return $this->execute($query, 'delete');
    }

    private function tableName(): string
    {
        return $this->database->prefix() . static::TABLE_SUFFIX;
    }

    private function assertValidId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('A database row ID must be positive.');
        }
    }

    /**
     * @param array<string, scalar|null> $values
     * @return array{0: string[], 1: string[], 2: array<int, scalar>}
     */
    private function prepareColumns(array $values, bool $asAssignments = false): array
    {
        $columns = [];
        $placeholders = [];
        $arguments = [];

        foreach ($values as $column => $value) {
            if (! is_string($column) || ! isset(static::FIELD_FORMATS[$column])) {
                throw new InvalidArgumentException('The requested database column is not writable.');
            }

            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Database values must be scalar or null.');
            }

            $format = static::FIELD_FORMATS[$column];
            $this->validateValue($format, $value);

            if ($value === null) {
                $placeholder = 'NULL';
            } else {
                $placeholder = $format;
                $arguments[] = $value;
            }

            $quotedColumn = '`' . $column . '`';
            $columns[] = $quotedColumn;
            $placeholders[] = $asAssignments
                ? $quotedColumn . ' = ' . $placeholder
                : $placeholder;
        }

        return [$columns, $placeholders, $arguments];
    }

    private function execute(string $query, string $operation): int
    {
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false) {
            throw new RuntimeException(
                'The database ' . $operation . ' failed: ' . $this->database->lastError()
            );
        }

        return $result;
    }

    /**
     * @param array<int, scalar> $arguments
     */
    private function prepare(string $query, array $arguments): string
    {
        return $arguments === []
            ? $query
            : $this->database->prepare($query, ...$arguments);
    }

    private function validateValue(string $format, mixed $value): void
    {
        if ($value === null || $format === '%s') {
            return;
        }

        if (
            $format === '%d'
            && ! is_int($value)
            && ! is_bool($value)
            && (! is_string($value) || preg_match('/^-?\d+$/D', $value) !== 1)
        ) {
            throw new InvalidArgumentException('An integer database column requires an integer value.');
        }

        if ($format === '%f' && ! is_numeric($value)) {
            throw new InvalidArgumentException('A decimal database column requires a numeric value.');
        }
    }
}
