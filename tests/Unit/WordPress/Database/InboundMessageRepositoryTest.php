<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InboundMessageRepositoryTest extends TestCase
{
    public function testInboxFiltersMessagesWithoutSelectingPrivateBodyOrRawFile(): void
    {
        $database = new InboundMessageRepositoryDatabase();
        $database->rowResult = ['total' => '4'];
        $repository = new InboundMessageRepository($database);

        $repository->findForInbox(InboundMessageRecord::STATUS_IGNORED, 25, 50);
        $count = $repository->countForInbox(InboundMessageRecord::STATUS_FAILED);

        self::assertSame(4, $count);
        self::assertStringContainsString('status IN (%s, %s)', $database->prepared[0]['query']);
        self::assertStringContainsString('received_at DESC, id DESC LIMIT %d OFFSET %d', $database->prepared[0]['query']);
        self::assertStringNotContainsString('body_text', $database->prepared[0]['query']);
        self::assertStringNotContainsString('raw_path', $database->prepared[0]['query']);
        self::assertSame(['ignored', 'skipped', 25, 50], $database->prepared[0]['arguments']);
        self::assertSame(['failed'], $database->prepared[1]['arguments']);
    }

    public function testProcessingTransitionUsesAnAllowedPreviousStatusAndStoresTheBody(): void
    {
        $database = new InboundMessageRepositoryDatabase();
        $repository = new InboundMessageRepository($database);

        self::assertTrue($repository->markParsed(41, 'Fictional event notice.', '2026-09-25 04:10:00'));

        self::assertStringContainsString(
            'SET status = %s, error = NULL, updated_at = %s, body_text = %s',
            $database->prepared[0]['query']
        );
        self::assertStringContainsString('WHERE id = %d AND status IN (%s)', $database->prepared[0]['query']);
        self::assertSame(
            ['parsed', '2026-09-25 04:10:00', 'Fictional event notice.', 41, 'extracting'],
            $database->prepared[0]['arguments']
        );
    }

    public function testRequeueLocksAndReturnsOnlyFailedMessageIds(): void
    {
        $database = new InboundMessageRepositoryDatabase();
        $database->resultRows = [['id' => '41'], ['id' => '43']];
        $database->queryResults = [1, 2, 1];
        $repository = new InboundMessageRepository($database);

        $requeued = $repository->requeueFailedMessages([41, 42, 43], '2026-09-25 04:10:00');

        self::assertSame([41, 43], $requeued);
        self::assertSame(['START TRANSACTION', 'UPDATE wp_adct_pi_inbound_messages SET status = %s, error = NULL, updated_at = %s WHERE status = %s AND id IN (%d, %d)', 'COMMIT'], $database->queries);
        self::assertStringContainsString('status = %s AND id IN (%d, %d, %d)', $database->prepared[0]['query']);
        self::assertStringContainsString('ORDER BY id ASC FOR UPDATE', $database->prepared[0]['query']);
        self::assertSame(['failed', 41, 42, 43], $database->prepared[0]['arguments']);
        self::assertSame(
            ['received', '2026-09-25 04:10:00', 'failed', 41, 43],
            $database->prepared[1]['arguments']
        );
    }

    public function testRequeueRejectsBatchesLargerThanTwentyBeforeOpeningATransaction(): void
    {
        $database = new InboundMessageRepositoryDatabase();
        $repository = new InboundMessageRepository($database);

        try {
            $repository->requeueFailedMessages(range(1, 21), '2026-09-25 04:10:00');
            self::fail('An oversized reprocess batch was accepted.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame(
                'A reprocess batch cannot contain more than 20 messages.',
                $failure->getMessage()
            );
        }

        self::assertSame([], $database->queries);
    }

    public function testInboxRejectsAnUnknownStatusFilter(): void
    {
        $repository = new InboundMessageRepository(new InboundMessageRepositoryDatabase());

        $this->expectException(InvalidArgumentException::class);
        $repository->findForInbox('anything', 25, 0);
    }
}

final class InboundMessageRepositoryDatabase implements DatabaseConnectionInterface
{
    /**
     * @var list<array{query: string, arguments: array<int, mixed>}>
     */
    public array $prepared = [];

    /** @var list<string> */
    public array $queries = [];

    /** @var list<array<string, mixed>> */
    public array $resultRows = [];

    /** @var list<int|false> */
    public array $queryResults = [];

    public ?array $rowResult = null;

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

        return $this->queryResults === [] ? 1 : array_shift($this->queryResults);
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
        return 91;
    }

    public function charsetCollate(): string
    {
        return '';
    }

    public function clearLastError(): void
    {
    }

    public function lastError(): string
    {
        return '';
    }
}
