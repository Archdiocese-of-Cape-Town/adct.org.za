<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenRateLimitStore;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WordPressActionTokenRateLimitStoreTest extends TestCase
{
    public function testBucketUsesAtomicUpsertAndStoresOnlyHashedScopeKeys(): void
    {
        $database = new ActionTokenRateLimitStoreTestDatabase();
        $store = new WordPressActionTokenRateLimitStore($database);
        $scopeHash = hash('sha256', 'email:events@example.test');
        $windowStart = new DateTimeImmutable('2026-09-25 00:00:00', new DateTimeZone('UTC'));

        self::assertTrue($store->consume($scopeHash, $windowStart, 3));

        self::assertStringContainsString(
            'DELETE FROM `wp_adct_pi_action_token_rate_limits` WHERE window_started_at < %s',
            $database->preparedQueries[0]['query']
        );
        self::assertSame(['2026-09-23 00:00:00'], $database->preparedQueries[0]['arguments']);
        self::assertStringContainsString(
            'INSERT INTO `wp_adct_pi_action_token_rate_limits`',
            $database->preparedQueries[1]['query']
        );
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $database->preparedQueries[1]['query']);
        self::assertStringContainsString('LAST_INSERT_ID(IF(', $database->preparedQueries[1]['query']);
        self::assertStringContainsString('hit_count < %d', $database->preparedQueries[1]['query']);
        self::assertSame([
            $scopeHash,
            '2026-09-25 00:00:00',
            '2026-09-25 00:00:00',
            '2026-09-25 00:00:00',
            3,
            4,
        ], $database->preparedQueries[1]['arguments']);
    }

    public function testAdmissionMarkerDecidesLimitRegardlessOfAffectedRows(): void
    {
        $database = new ActionTokenRateLimitStoreTestDatabase();
        $database->queryResults = [0, 0];
        $database->lastIdResult = ['request_count' => '2'];
        $store = new WordPressActionTokenRateLimitStore($database);

        self::assertTrue($store->consume(
            str_repeat('c', 64),
            new DateTimeImmutable('2026-09-25 00:00:00', new DateTimeZone('UTC')),
            3
        ));

        $database->queryResults = [0, 1];
        $database->lastIdResult = ['request_count' => '4'];

        self::assertFalse($store->consume(
            str_repeat('d', 64),
            new DateTimeImmutable('2026-09-25 00:00:00', new DateTimeZone('UTC')),
            3
        ));
    }

    public function testMissingAtomicAdmissionMarkerFailsClosed(): void
    {
        $database = new ActionTokenRateLimitStoreTestDatabase();
        $database->lastIdResult = null;
        $store = new WordPressActionTokenRateLimitStore($database);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The action token rate-limit decision could not be read.');
        $store->consume(
            str_repeat('e', 64),
            new DateTimeImmutable('2026-09-25 00:00:00', new DateTimeZone('UTC')),
            3
        );
    }

    public function testDatabaseFailureIsReportedWithoutScopeData(): void
    {
        $database = new ActionTokenRateLimitStoreTestDatabase();
        $database->queryResults = [0, false];
        $store = new WordPressActionTokenRateLimitStore($database);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The action token rate limit could not be recorded.');
        $store->consume(
            str_repeat('d', 64),
            new DateTimeImmutable('2026-09-25 00:00:00', new DateTimeZone('UTC')),
            3
        );
    }
}

final class ActionTokenRateLimitStoreTestDatabase implements DatabaseConnectionInterface
{
    /**
     * @var list<array{query: string, arguments: array<int, mixed>}>
     */
    public array $preparedQueries = [];

    public int|false $queryResult = 1;
    /**
     * @var list<int|false>
     */
    public array $queryResults = [];
    /**
     * @var array<string, mixed>|null
     */
    public ?array $lastIdResult = ['request_count' => '1'];
    public string $error = '';

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
        return $this->queryResults === []
            ? $this->queryResult
            : array_shift($this->queryResults);
    }

    public function getRow(string $query): ?array
    {
        return $this->lastIdResult;
    }

    public function getResults(string $query): array
    {
        return [];
    }

    public function escapeLike(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function insertId(): int
    {
        return 0;
    }

    public function charsetCollate(): string
    {
        return '';
    }

    public function clearLastError(): void
    {
        $this->error = '';
    }

    public function lastError(): string
    {
        return $this->error;
    }
}
