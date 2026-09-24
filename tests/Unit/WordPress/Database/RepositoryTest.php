<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\EventCandidateRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    public function testRepositoryCrudUsesPreparedQueriesAndAllowsNullValues(): void
    {
        $database = new FakeDatabaseConnection();
        $database->nextInsertId = 12;
        $repository = new ParishRepository($database);

        $insertedId = $repository->insert([
            'name' => 'Sample Parish',
            'slug' => 'sample-parish',
            'deanery_id' => null,
            'created_at' => '2026-09-25 00:00:00',
            'updated_at' => '2026-09-25 00:00:00',
        ]);
        $repository->findById(12);
        $repository->update(12, ['status' => 'inactive', 'deanery_id' => null]);
        $repository->delete(12);

        self::assertSame(12, $insertedId);
        self::assertCount(4, $database->preparedQueries);
        self::assertStringContainsString('INSERT INTO wp_adct_pi_parishes', $database->preparedQueries[0]['query']);
        self::assertStringContainsString('`deanery_id` = NULL', $database->preparedQueries[2]['query']);
        self::assertSame([12], $database->preparedQueries[1]['arguments']);
        self::assertStringContainsString('UPDATE wp_adct_pi_parishes', $database->preparedQueries[2]['query']);
        self::assertStringContainsString('DELETE FROM wp_adct_pi_parishes', $database->preparedQueries[3]['query']);
    }

    public function testAllRequiredRepositoriesUseTheirVersionedTableNames(): void
    {
        $repositoryClasses = [
            DeaneryRepository::class,
            ParishRepository::class,
            SourceRepository::class,
            InboundMessageRepository::class,
            EventCandidateRepository::class,
        ];
        $expectedTables = [
            'wp_adct_pi_deaneries',
            'wp_adct_pi_parishes',
            'wp_adct_pi_sources',
            'wp_adct_pi_inbound_messages',
            'wp_adct_pi_event_candidates',
        ];

        foreach ($repositoryClasses as $index => $repositoryClass) {
            $database = new FakeDatabaseConnection();
            $repository = new $repositoryClass($database);
            $repository->findById(1);

            self::assertStringContainsString(
                $expectedTables[$index],
                $database->preparedQueries[0]['query']
            );
        }
    }

    public function testRepositoriesRejectColumnsOutsideTheirAllowlist(): void
    {
        $database = new FakeDatabaseConnection();
        $repository = new DeaneryRepository($database);

        try {
            $repository->insert(['name; DROP TABLE wp_users' => 'invalid']);
            self::fail('An unknown column was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame([], $database->preparedQueries);
            self::assertSame('The requested database column is not writable.', $exception->getMessage());
        }
    }
}

final class FakeDatabaseConnection implements DatabaseConnectionInterface
{
    /**
     * @var array<int, array{query: string, arguments: array<int, mixed>}>
     */
    public array $preparedQueries = [];

    public int $nextInsertId = 1;

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->preparedQueries[] = ['query' => $query, 'arguments' => $arguments];

        return $query;
    }

    public function query(string $query): int|false
    {
        return 1;
    }

    public function getRow(string $query): ?array
    {
        return ['id' => 1];
    }

    public function insertId(): int
    {
        return $this->nextInsertId;
    }

    public function charsetCollate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function clearLastError(): void
    {
    }

    public function lastError(): string
    {
        return '';
    }
}
