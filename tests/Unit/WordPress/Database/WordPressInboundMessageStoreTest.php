<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Ingestion\InboundAttachmentRecord;
use ADCT\ParishIntake\Core\Ingestion\AuthenticationResult;
use ADCT\ParishIntake\Core\Ingestion\AuthenticationResults;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageProcessingRecord;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressInboundMessageStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WordPressInboundMessageStoreTest extends TestCase
{
    public function testStoresMessageAndAttachmentRowsInOneTransaction(): void
    {
        $database = new FakeInboundStoreDatabase();
        $store = new WordPressInboundMessageStore(
            $database,
            new InboundMessageRepository($database),
            new AttachmentRepository($database)
        );
        $message = new InboundMessageRecord(
            17,
            '<message@example.test>',
            hash('sha256', 'normalized content'),
            'notices@example.test',
            'Example Notices',
            'Example event notice',
            new DateTimeImmutable('2026-09-25T04:00:00+00:00'),
            'a1b2c3.eml',
            [
                new InboundAttachmentRecord(
                    'poster.pdf',
                    'application/pdf',
                    23,
                    'd4e5f6.pdf',
                    hash('sha256', '%PDF-1.7'),
                    'pending'
                ),
                new InboundAttachmentRecord(
                    'poster.docx',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    31,
                    '',
                    hash('sha256', 'docx'),
                    'skipped_type'
                ),
            ]
        );

        $result = $store->store($message, '2026-09-25 04:01:00');

        self::assertSame(44, $result->messageId);
        self::assertFalse($result->duplicate);
        self::assertSame('START TRANSACTION', $database->queries[0]);
        self::assertSame('COMMIT', $database->queries[count($database->queries) - 1]);
        self::assertStringContainsString('INSERT INTO wp_adct_pi_inbound_messages', $database->prepared[1]['query']);
        self::assertStringContainsString('INSERT INTO wp_adct_pi_attachments', $database->prepared[2]['query']);
        self::assertSame('2027-09-25 04:00:00', $database->prepared[1]['arguments'][10]);
        self::assertSame('skipped_type', $database->prepared[3]['arguments'][7]);
    }

    public function testExistingDuplicateIsReturnedWithoutInsertingRows(): void
    {
        $database = new FakeInboundStoreDatabase();
        $database->rowResult = ['id' => '21'];
        $store = new WordPressInboundMessageStore(
            $database,
            new InboundMessageRepository($database),
            new AttachmentRepository($database)
        );
        $message = new InboundMessageRecord(
            17,
            '<duplicate@example.test>',
            null,
            null,
            null,
            'Oversized message',
            new DateTimeImmutable('2026-09-25T04:00:00+00:00'),
            null,
            [],
            InboundMessageRecord::STATUS_SKIPPED,
            'Message exceeded the configured size limit.'
        );

        $result = $store->store($message, '2026-09-25 04:01:00');

        self::assertSame(21, $result->messageId);
        self::assertTrue($result->duplicate);
        self::assertCount(1, $database->prepared);
        self::assertSame('COMMIT', $database->queries[1]);
    }

    public function testStoresAutomationFlagAndStructuredAuthenticationResults(): void
    {
        $database = new FakeInboundStoreDatabase();
        $authenticationResults = new AuthenticationResults([
            new AuthenticationResult('spf', 'pass', 'external.example.test', false),
            new AuthenticationResult('dmarc', 'fail', 'external.example.test', false),
        ]);
        $store = new WordPressInboundMessageStore(
            $database,
            new InboundMessageRepository($database),
            new AttachmentRepository($database)
        );
        $message = new InboundMessageRecord(
            17,
            '<screened-message@example.test>',
            null,
            'no-reply@example.test',
            null,
            'Synthetic screening message',
            new DateTimeImmutable('2026-09-25T04:00:00+00:00'),
            'screened-message.eml',
            [],
            InboundMessageRecord::STATUS_RECEIVED,
            null,
            true,
            $authenticationResults
        );

        $store->store($message, '2026-09-25 04:01:00');

        $query = $database->prepared[1]['query'];
        $arguments = $database->prepared[1]['arguments'];
        $authenticationIndex = array_search($authenticationResults->toJson(), $arguments, true);

        self::assertStringContainsString('`auth_results`', $query);
        self::assertStringContainsString('`is_auto_reply`', $query);
        self::assertNotFalse($authenticationIndex);
        self::assertSame(1, $arguments[$authenticationIndex + 1]);
    }

    public function testLoadsTheSameStoredMessageForBoundedProcessing(): void
    {
        $database = new FakeInboundStoreDatabase();
        $database->rowResult = [
            'id' => '41',
            'source_id' => '17',
            'external_id' => '<processing@example.test>',
            'sender_email' => 'notices@example.test',
            'sender_name' => 'Example Notices',
            'subject' => 'Example Parish notice',
            'received_at' => '2026-09-25 04:00:00',
            'raw_path' => 'a1b2c3.eml',
            'is_auto_reply' => '1',
        ];
        $store = new WordPressInboundMessageStore(
            $database,
            new InboundMessageRepository($database),
            new AttachmentRepository($database)
        );

        $message = $store->findForProcessingById(41);

        self::assertInstanceOf(InboundMessageProcessingRecord::class, $message);
        self::assertSame(41, $message->id);
        self::assertSame(17, $message->sourceId);
        self::assertSame('<processing@example.test>', $message->externalId);
        self::assertSame('a1b2c3.eml', $message->rawPath);
        self::assertTrue($message->isAutoReply);
        self::assertSame(
            '2026-09-25 04:00:00',
            $message->receivedAt?->format('Y-m-d H:i:s')
        );
    }
}

final class FakeInboundStoreDatabase implements DatabaseConnectionInterface
{
    /**
     * @var list<array{query: string, arguments: array<int, mixed>}>
     */
    public array $prepared = [];

    /** @var list<string> */
    public array $queries = [];

    public ?array $rowResult = null;

    /** @var list<array<string, mixed>> */
    public array $resultRows = [];

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->prepared[] = ['query' => $query, 'arguments' => $arguments];

        return $query;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;

        return 1;
    }

    public function getRow(string $query): ?array
    {
        return $this->rowResult;
    }

    public function getResults(string $query): array
    {
        return $this->resultRows;
    }

    public function escapeLike(string $text): string
    {
        return $text;
    }

    public function insertId(): int
    {
        return 44;
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
