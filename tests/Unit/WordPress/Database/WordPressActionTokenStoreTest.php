<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenStore;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WordPressActionTokenStoreTest extends TestCase
{
    public function testCreatePersistsOnlyTheHashAndBoundContext(): void
    {
        $database = new ActionTokenStoreTestDatabase();
        $store = new WordPressActionTokenStore($database);
        $secret = '1234567890123456789012345678901234567890123';
        $record = new ActionTokenRecord(
            hash('sha256', $secret),
            $this->binding(),
            new DateTimeImmutable('2026-10-09 00:00:00', new DateTimeZone('UTC')),
            null,
            new DateTimeImmutable('2026-09-25 00:00:00', new DateTimeZone('UTC'))
        );

        $store->create($record);

        self::assertSame(1, $database->queryResult);
        self::assertStringContainsString(
            'INSERT INTO `wp_adct_pi_action_tokens`',
            $database->preparedQueries[0]['query']
        );
        self::assertSame($record->tokenHash, $database->preparedQueries[0]['arguments'][0]);
        self::assertSame('confirm', $database->preparedQueries[0]['arguments'][1]);
        self::assertSame('candidate', $database->preparedQueries[0]['arguments'][2]);
        self::assertSame(17, $database->preparedQueries[0]['arguments'][3]);
        self::assertSame('events@example.test', $database->preparedQueries[0]['arguments'][4]);
        self::assertNotContains($secret, $database->preparedQueries[0]['arguments']);
        self::assertStringContainsString('created_ip', $database->preparedQueries[0]['query']);
    }

    public function testFindByHashReturnsTheUtcStoredBindingAndExpiration(): void
    {
        $database = new ActionTokenStoreTestDatabase();
        $hash = str_repeat('a', 64);
        $database->row = [
            'token_hash' => $hash,
            'purpose' => 'confirm',
            'subject_type' => 'candidate',
            'subject_id' => '17',
            'email' => 'events@example.test',
            'expires_at' => '2026-10-09 00:00:00',
            'used_at' => null,
            'created_at' => '2026-09-25 00:00:00',
        ];
        $store = new WordPressActionTokenStore($database);

        $record = $store->findByHash($hash);

        self::assertNotNull($record);
        self::assertTrue($this->binding()->equals($record->binding));
        self::assertSame($hash, $record->tokenHash);
        self::assertSame(
            '2026-10-09 00:00:00',
            $record->expiresAt->format('Y-m-d H:i:s')
        );
        self::assertSame([$hash], $database->preparedQueries[0]['arguments']);
    }

    public function testConsumeUsesOneConditionalPreparedUpdateForSingleUseBindingAndExpiry(): void
    {
        $database = new ActionTokenStoreTestDatabase();
        $store = new WordPressActionTokenStore($database);
        $binding = $this->binding();
        $hash = str_repeat('b', 64);
        $now = new DateTimeImmutable('2026-09-25 02:03:04', new DateTimeZone('Africa/Johannesburg'));

        self::assertTrue($store->consume($hash, $binding, $now));
        self::assertStringContainsString('UPDATE `wp_adct_pi_action_tokens` SET used_at = %s', $database->preparedQueries[0]['query']);
        self::assertStringContainsString('AND used_at IS NULL AND expires_at > %s', $database->preparedQueries[0]['query']);
        self::assertSame([
            '2026-09-25 00:03:04',
            '2026-09-25 00:03:04',
            $hash,
            'confirm',
            'candidate',
            17,
            'events@example.test',
            '2026-09-25 00:03:04',
        ], $database->preparedQueries[0]['arguments']);

        $database->queryResult = 0;

        self::assertFalse($store->consume($hash, $binding, $now));
    }

    public function testDatabaseFailureDoesNotExposeTokenOrEmail(): void
    {
        $database = new ActionTokenStoreTestDatabase();
        $database->queryResult = false;
        $database->error = 'synthetic database failure';
        $store = new WordPressActionTokenStore($database);
        $secret = '1234567890123456789012345678901234567890123';
        $record = new ActionTokenRecord(
            hash('sha256', $secret),
            $this->binding(),
            new DateTimeImmutable('2026-10-09 00:00:00', new DateTimeZone('UTC')),
            null,
            new DateTimeImmutable('2026-09-25 00:00:00', new DateTimeZone('UTC'))
        );

        try {
            $store->create($record);
            self::fail('A failed insert must be reported.');
        } catch (RuntimeException $failure) {
            self::assertSame('The action token could not be stored.', $failure->getMessage());
            self::assertStringNotContainsString($secret, $failure->getMessage());
            self::assertStringNotContainsString('events@example.test', $failure->getMessage());
        }
    }

    private function binding(): ActionTokenBinding
    {
        return new ActionTokenBinding(
            ActionTokenPurpose::CONFIRM,
            'candidate',
            17,
            'events@example.test'
        );
    }
}

final class ActionTokenStoreTestDatabase implements DatabaseConnectionInterface
{
    /**
     * @var list<array{query: string, arguments: array<int, mixed>}>
     */
    public array $preparedQueries = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $row = null;

    public int|false $queryResult = 1;
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
        return $this->queryResult;
    }

    public function getRow(string $query): ?array
    {
        return $this->row;
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
