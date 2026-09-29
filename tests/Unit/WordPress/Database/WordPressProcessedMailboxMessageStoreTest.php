<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\MailboxMoveReceipt;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\WordPressProcessedMailboxMessageStore;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WordPressProcessedMailboxMessageStoreTest extends TestCase
{
    public function testOwnershipQueriesUseTheExactMailboxAndUidValidity(): void
    {
        $database = new ProcessedMailboxMessageStoreTestDatabase();
        $database->rows = [['uid' => '827']];
        $store = new WordPressProcessedMailboxMessageStore($database);
        $settings = new MailboxSettings(
            'Processed mailbox',
            'imap.example.test',
            993,
            MailboxEncryption::SSL,
            'intake@example.test',
            'INBOX',
            'Processed',
            30 * 1024 * 1024,
            true,
            1,
            23
        );
        $identity = $settings->processedFolderIdentity();
        $receipt = new MailboxMoveReceipt(314159, 827);

        $store->recordMoved(
            $settings,
            $receipt,
            new DateTimeImmutable('2026-08-01T04:00:00+02:00'),
            new DateTimeImmutable('2026-09-25T04:30:00+02:00')
        );
        $uids = $store->findExpired(
            $settings,
            314159,
            new DateTimeImmutable('2026-08-26T00:00:00+00:00'),
            1000
        );
        $store->discardStale($settings, 314159);
        $store->deleteOwned($settings, 314159, 827);

        self::assertSame([827], $uids);
        self::assertStringContainsString(
            'INSERT INTO `wp_adct_pi_processed_mail_ownership`',
            $database->preparedQueries[0]['query']
        );
        self::assertSame([
            23,
            $identity,
            'Processed',
            314159,
            827,
            '2026-08-01 02:00:00',
            '2026-09-25 02:30:00',
            '2026-09-25 02:30:00',
        ], $database->preparedQueries[0]['arguments']);
        self::assertStringContainsString(
            'WHERE source_id = %d AND mailbox_identity = %s AND processed_folder = %s',
            $database->preparedQueries[1]['query']
        );
        self::assertStringContainsString('AND uid_validity = %d AND internal_date <= %s', $database->preparedQueries[1]['query']);
        self::assertStringContainsString('ORDER BY uid ASC LIMIT %d', $database->preparedQueries[1]['query']);
        self::assertSame([
            23,
            $identity,
            'Processed',
            314159,
            '2026-08-26 00:00:00',
            100,
        ], $database->preparedQueries[1]['arguments']);
        self::assertStringContainsString(
            'mailbox_identity <> %s OR processed_folder <> %s OR uid_validity <> %d',
            $database->preparedQueries[2]['query']
        );
        self::assertStringContainsString(
            'processed_folder = %s AND uid_validity = %d AND uid = %d',
            $database->preparedQueries[3]['query']
        );
    }

    public function testRejectsAnInvalidLookupLimitBeforeQuerying(): void
    {
        $database = new ProcessedMailboxMessageStoreTestDatabase();
        $store = new WordPressProcessedMailboxMessageStore($database);
        $settings = $this->settings();

        $this->expectException(InvalidArgumentException::class);
        $store->findExpired($settings, 314159, new DateTimeImmutable('2026-08-26T00:00:00+00:00'), 0);
    }

    public function testReportsDatabaseWriteFailures(): void
    {
        $database = new ProcessedMailboxMessageStoreTestDatabase();
        $database->queryResult = false;
        $store = new WordPressProcessedMailboxMessageStore($database);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Processed mailbox ownership could not be recorded.');
        $store->recordMoved(
            $this->settings(),
            new MailboxMoveReceipt(314159, 827),
            new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-09-25T00:00:00+00:00')
        );
    }

    private function settings(): MailboxSettings
    {
        return new MailboxSettings(
            'Processed mailbox',
            'imap.example.test',
            993,
            MailboxEncryption::SSL,
            'intake@example.test',
            'INBOX',
            'Processed',
            30 * 1024 * 1024,
            true,
            1,
            23
        );
    }
}

final class ProcessedMailboxMessageStoreTestDatabase implements DatabaseConnectionInterface
{
    /** @var list<array{query: string, arguments: array<int, mixed>}> */
    public array $preparedQueries = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

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
        return null;
    }

    public function getResults(string $query): array
    {
        return $this->rows;
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
