<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Jobs;

use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Ports\MailboxSettingsStoreInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Jobs\RetentionCleanupJob;
use ADCT\ParishIntake\WordPress\Jobs\RetentionSettings;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RetentionCleanupJobTest extends TestCase
{
    public function testInvalidRetentionConfigurationIsRejectedBeforeCleanupRuns(): void
    {
        $settings = RetentionSettings::fromValues('1', '0', '0', '30');

        self::assertTrue($settings->hasAnyCleanupEnabled());
        self::assertSame(
            'Raw-data retention is enabled, but the retention period must be at least 1 day.',
            $settings->configurationError()
        );

        $job = $this->job(new RetentionTestDependencies(), $settings);

        self::assertNull($job->processNext(null));
    }

    public function testRawCleanupSkipsMessagesStillWaitingOnCandidateReview(): void
    {
        $deps = new RetentionTestDependencies();
        $rawOne = str_repeat('1', 64) . '.eml';
        $rawTwo = str_repeat('2', 64) . '.eml';
        $rawThree = str_repeat('3', 64) . '.eml';
        $attOne = str_repeat('4', 64) . '.pdf';
        $attTwo = str_repeat('5', 64) . '.pdf';
        $attThree = str_repeat('6', 64) . '.pdf';
        $deps->database->messages = [
            1 => $deps->message(1, 'parsed', $rawOne, '2025-09-24 00:00:00'),
            2 => $deps->message(2, 'parsed', $rawTwo, '2025-09-24 00:00:00'),
            3 => $deps->message(3, 'parsed', $rawThree, '2025-09-24 00:00:00'),
        ];
        $deps->database->candidates = [
            2 => ['draft'],
        ];
        $deps->database->attachments = [
            1 => [$attOne],
            2 => [$attTwo],
            3 => [$attThree],
        ];
        $deps->storage->files = [
            $rawOne => 'message 1',
            $rawTwo => 'message 2',
            $rawThree => 'message 3',
            $attOne => 'attachment 1',
            $attTwo => 'attachment 2',
            $attThree => 'attachment 3',
        ];

        $job = $this->job($deps, RetentionSettings::fromValues('1', '30', '0', '30'), 2);
        $checkpoint = $this->encodeCheckpoint('raw', 0);
        $first = $job->processNext($checkpoint);

        self::assertInstanceOf(JobStepResult::class, $first);
        self::assertFalse($first->isComplete());
        self::assertSame(3, $deps->database->messages[3]['id']);
        self::assertNull($deps->database->messages[1]['raw_path']);
        self::assertNull($deps->database->messages[3]['raw_path']);
        self::assertSame($rawTwo, $deps->database->messages[2]['raw_path']);
        self::assertSame([$rawOne, $attOne, $rawThree, $attThree], $deps->storage->deleted);
    }

    public function testRawCleanupBatchesAndResumesFromCheckpoint(): void
    {
        $deps = new RetentionTestDependencies();
        $rawOne = str_repeat('1', 64) . '.eml';
        $rawTwo = str_repeat('2', 64) . '.eml';
        $rawThree = str_repeat('3', 64) . '.eml';
        $deps->database->messages = [
            1 => $deps->message(1, 'parsed', $rawOne, '2025-09-24 00:00:00'),
            2 => $deps->message(2, 'parsed', $rawTwo, '2025-09-24 00:00:00'),
            3 => $deps->message(3, 'parsed', $rawThree, '2025-09-24 00:00:00'),
        ];
        $deps->storage->files = [
            $rawOne => 'message 1',
            $rawTwo => 'message 2',
            $rawThree => 'message 3',
        ];

        $job = $this->job($deps, RetentionSettings::fromValues('1', '30', '0', '30'), 2);

        $first = $job->processNext($this->encodeCheckpoint('raw', 0));

        self::assertInstanceOf(JobStepResult::class, $first);
        self::assertFalse($first->isComplete());
        self::assertSame([$rawOne, $rawTwo], $deps->storage->deleted);
        self::assertNull($deps->database->messages[1]['raw_path']);
        self::assertNull($deps->database->messages[2]['raw_path']);
        self::assertSame($rawThree, $deps->database->messages[3]['raw_path']);

        $second = $job->processNext($first->checkpoint());

        self::assertInstanceOf(JobStepResult::class, $second);
        self::assertTrue($second->isComplete());
        self::assertSame([$rawOne, $rawTwo, $rawThree], $deps->storage->deleted);
        self::assertNull($deps->database->messages[3]['raw_path']);
    }

    public function testProcessedCleanupDeletesBatchesAndResumesWithTheSameMailbox(): void
    {
        $deps = new RetentionTestDependencies();
        $deps->mailbox = new RetentionMailbox([
            'Processed' => [10, 11, 12],
        ]);
        $deps->mailboxSettingsStore = new RetentionMailboxSettingsStore([
            new MailboxSettings(
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
                1
            ),
        ]);

        $job = $this->job($deps, RetentionSettings::fromValues('0', '30', '1', '30'), 2);

        $first = $job->processNext($this->encodeProcessedCheckpoint(1, 0));

        self::assertInstanceOf(JobStepResult::class, $first);
        self::assertFalse($first->isComplete());
        self::assertSame([10, 11], $deps->mailbox->deleted['Processed']);
        self::assertCount(1, $deps->mailbox->searchCriteria);
        self::assertInstanceOf(MailboxSearchCriteria::class, $deps->mailbox->searchCriteria[0]['criteria']);
        self::assertSame('Processed', $deps->mailbox->searchCriteria[0]['folder']);
        self::assertInstanceOf(DateTimeImmutable::class, $deps->mailbox->searchCriteria[0]['criteria']->before);

        $second = $job->processNext($first->checkpoint());

        self::assertInstanceOf(JobStepResult::class, $second);
        self::assertTrue($second->isComplete());
        self::assertSame([10, 11, 12], $deps->mailbox->deleted['Processed']);
        self::assertCount(2, $deps->mailbox->searchCriteria);
        self::assertSame(11, $deps->mailbox->searchCriteria[1]['criteria']->afterUid);
        self::assertTrue($deps->mailbox->closed);
    }

    private function job(RetentionTestDependencies $deps, RetentionSettings $settings, int $batchSize = 25): RetentionCleanupJob
    {
        return new RetentionCleanupJob(
            $deps->database,
            $deps->storage,
            $deps->mailboxSettingsStore,
            static fn (): RetentionSettings => $settings,
            static fn (MailboxSettings $mailbox): string => 'password-' . $mailbox->id,
            static fn (MailboxSettings $mailbox, string $password): MailboxInterface => $deps->mailbox,
            new RetentionTestClock(),
            $batchSize
        );
    }

    private function encodeCheckpoint(string $phase, int $cursor): string
    {
        return json_encode([
            'phase' => $phase,
            'tokens_last_id' => 0,
            'audit_last_id' => 0,
            'raw_last_id' => $cursor,
            'processed' => [
                'source_id' => 0,
                'last_uid' => 0,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function encodeProcessedCheckpoint(int $sourceId, int $lastUid): string
    {
        return json_encode([
            'phase' => 'processed',
            'tokens_last_id' => 0,
            'audit_last_id' => 0,
            'raw_last_id' => 0,
            'processed' => [
                'source_id' => $sourceId,
                'last_uid' => $lastUid,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}

final class RetentionTestDependencies
{
    public RetentionTestDatabase $database;
    public RetentionTestStorage $storage;
    public RetentionMailboxSettingsStore $mailboxSettingsStore;
    public RetentionMailbox $mailbox;

    public function __construct()
    {
        $this->database = new RetentionTestDatabase();
        $this->storage = new RetentionTestStorage();
        $this->mailboxSettingsStore = new RetentionMailboxSettingsStore([]);
        $this->mailbox = new RetentionMailbox([]);
    }

    /**
     * @return array<string, mixed>
     */
    public function message(int $id, string $status, ?string $rawPath, string $retentionUntil): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'raw_path' => $rawPath,
            'retention_until' => $retentionUntil,
            'updated_at' => '2025-09-24 00:00:00',
        ];
    }
}

final class RetentionTestDatabase implements DatabaseConnectionInterface
{
    /** @var list<array{query: string, arguments: array<int, mixed>}> */
    public array $prepared = [];

    /** @var list<string> */
    public array $queries = [];

    /** @var array<int, array<string, mixed>> */
    public array $messages = [];

    /** @var array<int, list<string>> */
    public array $candidates = [];

    /** @var array<int, list<string>> */
    public array $attachments = [];

    /** @var list<array<string, mixed>> */
    public array $auditRows = [];

    /** @var list<array<string, mixed>> */
    public array $actionTokenRows = [];

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

        if (in_array($query, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) {
            return 1;
        }

        $prepared = array_shift($this->prepared);

        if (! is_array($prepared)) {
            return 1;
        }

        if (str_contains($prepared['query'], 'DELETE FROM `wp_adct_pi_action_tokens`')) {
            $deleted = 0;

            foreach ($prepared['arguments'] as $id) {
                $id = (int) $id;
                if (isset($this->actionTokenRows[$id])) {
                    unset($this->actionTokenRows[$id]);
                    ++$deleted;
                }
            }

            return $deleted;
        }

        if (str_contains($prepared['query'], 'DELETE FROM `wp_adct_pi_audit_log`')) {
            $deleted = 0;

            foreach ($prepared['arguments'] as $id) {
                $id = (int) $id;
                foreach ($this->auditRows as $index => $row) {
                    if ((int) ($row['id'] ?? 0) === $id) {
                        unset($this->auditRows[$index]);
                        ++$deleted;
                    }
                }
            }

            $this->auditRows = array_values($this->auditRows);

            return $deleted;
        }

        if (str_contains($prepared['query'], 'UPDATE `wp_adct_pi_inbound_messages` SET raw_path = NULL')) {
            $messageId = (int) ($prepared['arguments'][1] ?? 0);

            if (! isset($this->messages[$messageId])) {
                return 0;
            }

            $this->messages[$messageId]['raw_path'] = null;
            $this->messages[$messageId]['updated_at'] = (string) ($prepared['arguments'][0] ?? '');

            return 1;
        }

        return 1;
    }

    public function getRow(string $query): ?array
    {
        return null;
    }

    public function getResults(string $query): array
    {
        $prepared = array_shift($this->prepared);

        if (! is_array($prepared)) {
            return [];
        }

        $sql = $prepared['query'];
        $arguments = $prepared['arguments'];

        if (str_contains($sql, 'FROM `wp_adct_pi_action_tokens`')) {
            [$cutoff, $lastId, $limit] = $arguments;
            $rows = [];

            foreach ($this->actionTokenRows as $row) {
                if ((int) ($row['id'] ?? 0) <= (int) $lastId) {
                    continue;
                }

                if ((string) ($row['expires_at'] ?? '') > (string) $cutoff) {
                    continue;
                }

                $rows[] = ['id' => (int) $row['id']];
            }

            usort($rows, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

            return array_slice($rows, 0, (int) $limit);
        }

        if (str_contains($sql, 'FROM `wp_adct_pi_audit_log`')) {
            [$cutoff, $lastId, $limit] = $arguments;
            $rows = [];

            foreach ($this->auditRows as $row) {
                if ((int) ($row['id'] ?? 0) <= (int) $lastId) {
                    continue;
                }

                if ((string) ($row['created_at'] ?? '') >= (string) $cutoff) {
                    continue;
                }

                $rows[] = ['id' => (int) $row['id']];
            }

            usort($rows, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

            return array_slice($rows, 0, (int) $limit);
        }

        if (str_contains($sql, 'FROM `wp_adct_pi_inbound_messages`')) {
            [$cutoff, $pattern, $statusA, $statusB, $statusC, $lastId, $candA, $candB, $candC, $candD, $limit] = $arguments;
            $rows = [];

            foreach ($this->messages as $row) {
                $messageId = (int) ($row['id'] ?? 0);

                if ($messageId <= (int) $lastId) {
                    continue;
                }

                if (($row['retention_until'] ?? '') > $cutoff) {
                    continue;
                }

                if (! is_string($row['raw_path'] ?? null) || preg_match('/' . $pattern . '/', (string) $row['raw_path']) !== 1) {
                    continue;
                }

                if (! in_array($row['status'] ?? null, [$statusA, $statusB, $statusC], true)) {
                    continue;
                }

                $candidateStatuses = $this->candidates[$messageId] ?? [];

                if (array_intersect($candidateStatuses, [$candA, $candB, $candC, $candD]) !== []) {
                    continue;
                }

                $rows[] = ['id' => $messageId, 'raw_path' => $row['raw_path']];
            }

            usort($rows, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

            return array_slice($rows, 0, (int) $limit);
        }

        if (str_contains($sql, 'FROM `wp_adct_pi_attachments`')) {
            [$messageId, $pattern] = $arguments;
            $rows = [];

            foreach ($this->attachments[(int) $messageId] ?? [] as $path) {
                if (preg_match('/' . $pattern . '/', $path) !== 1) {
                    continue;
                }

                $rows[] = ['storage_path' => $path];
            }

            return $rows;
        }

        return [];
    }

    public function escapeLike(string $text): string
    {
        return $text;
    }

    public function insertId(): int
    {
        return 1;
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

final class RetentionTestStorage implements InboundMailStorageReaderInterface
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var list<string> */
    public array $deleted = [];

    public function storeRawMessage(string $rawMessage): string
    {
        throw new RuntimeException('Not used in retention tests.');
    }

    public function storeAttachment(string $content, string $extension): string
    {
        throw new RuntimeException('Not used in retention tests.');
    }

    public function readRawMessage(string $relativePath): string
    {
        return $this->files[$relativePath] ?? throw new RuntimeException('Missing file.');
    }

    public function delete(string $relativePath): void
    {
        $this->deleted[] = $relativePath;
        unset($this->files[$relativePath]);
    }
}

final class RetentionMailboxSettingsStore implements MailboxSettingsStoreInterface
{
    /**
     * @param list<MailboxSettings> $mailboxes
     */
    public function __construct(private array $mailboxes)
    {
    }

    public function findActiveMailboxes(): array
    {
        return $this->mailboxes;
    }
}

final class RetentionMailbox implements MailboxInterface
{
    /**
     * @param array<string, list<int>> $uidsByFolder
     */
    public function __construct(
        public array $uidsByFolder
    ) {
    }

    /** @var list<array{criteria: MailboxSearchCriteria, folder: string}> */
    public array $searchCriteria = [];

    /** @var array<string, list<int>> */
    public array $deleted = [];

    public bool $closed = false;

    public function listFolders(): array
    {
        return array_keys($this->uidsByFolder);
    }

    public function ensureFolder(string $folder): void
    {
        $this->uidsByFolder[$folder] ??= [];
    }

    public function uidValidity(): int
    {
        return 17;
    }

    public function search(MailboxSearchCriteria $criteria): array
    {
        return $this->searchFolder($criteria, $criteria->folder ?? 'INBOX');
    }

    public function searchFolder(MailboxSearchCriteria $criteria, string $folder): array
    {
        $this->searchCriteria[] = ['criteria' => $criteria, 'folder' => $folder];
        $uids = $this->uidsByFolder[$folder] ?? [];

        if ($criteria->afterUid !== null) {
            $uids = array_values(array_filter(
                $uids,
                static fn (int $uid): bool => $uid > $criteria->afterUid
            ));
        }

        sort($uids, SORT_NUMERIC);

        return $uids;
    }

    public function fetch(int $uid): \ADCT\ParishIntake\Core\Ingestion\RawMailMessage
    {
        throw new RuntimeException('Not used in retention tests.');
    }

    public function move(int $uid, string $folder): void
    {
        throw new RuntimeException('Not used in retention tests.');
    }

    public function delete(int $uid, string $folder): void
    {
        $this->deleted[$folder] ??= [];
        $this->deleted[$folder][] = $uid;
        $uids = $this->uidsByFolder[$folder] ?? [];
        $this->uidsByFolder[$folder] = array_values(array_filter(
            $uids,
            static fn (int $existingUid): bool => $existingUid !== $uid
        ));
    }

    public function markSeen(int $uid): void
    {
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class RetentionTestClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-25T01:00:00+02:00', new DateTimeZone('Africa/Johannesburg'));
    }
}
