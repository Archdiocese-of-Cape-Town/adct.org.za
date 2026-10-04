<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Jobs;

use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Jobs\JobState;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Ports\JobLockInterface;
use ADCT\ParishIntake\Core\Ports\JobStateStoreInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Ports\MailboxSettingsStoreInterface;
use ADCT\ParishIntake\Core\Ports\ProcessedMailboxMessageStoreInterface;
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
        $retentionAtNow = '2026-09-24 23:00:00';
        $receivedAtCutoff = '2025-09-24 23:00:00';
        $deps->database->messages = [
            1 => $deps->message(1, 'parsed', $rawOne, 'body one', $retentionAtNow, $receivedAtCutoff),
            2 => $deps->message(2, 'parsed', $rawTwo, 'body two', $retentionAtNow, $receivedAtCutoff),
            3 => $deps->message(3, 'parsed', $rawThree, 'body three', $retentionAtNow, $receivedAtCutoff),
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
        self::assertNull($deps->database->messages[1]['body_text']);
        self::assertNull($deps->database->messages[3]['raw_path']);
        self::assertNull($deps->database->messages[3]['body_text']);
        self::assertSame($rawTwo, $deps->database->messages[2]['raw_path']);
        self::assertSame('body two', $deps->database->messages[2]['body_text']);
        self::assertSame([$rawOne, $attOne, $rawThree, $attThree], $deps->storage->deleted);
        self::assertCount(1, array_filter(
            $deps->database->selects,
            static fn (string $query): bool => str_contains($query, 'FROM `wp_adct_pi_inbound_messages`')
        ));
        self::assertStringNotContainsString('body_text', $deps->database->selects[0]);
    }

    public function testRawCleanupBatchesAndResumesFromCheckpoint(): void
    {
        $deps = new RetentionTestDependencies();
        $rawOne = str_repeat('1', 64) . '.eml';
        $rawTwo = str_repeat('2', 64) . '.eml';
        $rawThree = str_repeat('3', 64) . '.eml';
        $retentionAtNow = '2026-09-24 23:00:00';
        $receivedAtCutoff = '2025-09-24 23:00:00';
        $deps->database->messages = [
            1 => $deps->message(1, 'parsed', $rawOne, 'body one', $retentionAtNow, $receivedAtCutoff),
            2 => $deps->message(2, 'parsed', $rawTwo, 'body two', $retentionAtNow, $receivedAtCutoff),
            3 => $deps->message(3, 'parsed', $rawThree, 'body three', $retentionAtNow, $receivedAtCutoff),
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
        self::assertNull($deps->database->messages[1]['body_text']);
        self::assertNull($deps->database->messages[2]['raw_path']);
        self::assertNull($deps->database->messages[2]['body_text']);
        self::assertSame($rawThree, $deps->database->messages[3]['raw_path']);
        self::assertSame('body three', $deps->database->messages[3]['body_text']);

        $second = $job->processNext($first->checkpoint());

        self::assertInstanceOf(JobStepResult::class, $second);
        self::assertTrue($second->isComplete());
        self::assertSame([$rawOne, $rawTwo, $rawThree], $deps->storage->deleted);
        self::assertNull($deps->database->messages[3]['raw_path']);
        self::assertNull($deps->database->messages[3]['body_text']);
    }

    public function testRawCleanupHonoursTheStoredRetentionFloorBeforeConfiguredAgeCutoff(): void
    {
        $deps = new RetentionTestDependencies();
        $flooredRaw = str_repeat('7', 64) . '.eml';
        $eligibleRaw = str_repeat('8', 64) . '.eml';
        $deps->database->messages = [
            1 => $deps->message(
                1,
                'parsed',
                $flooredRaw,
                'floored body',
                '2026-09-25 23:00:00',
                '2025-09-24 23:00:00'
            ),
            2 => $deps->message(
                2,
                'parsed',
                $eligibleRaw,
                'eligible body',
                '2026-09-24 23:00:00',
                '2025-09-24 23:00:00'
            ),
        ];
        $deps->storage->files = [
            $flooredRaw => 'floored message',
            $eligibleRaw => 'eligible message',
        ];

        $job = $this->job($deps, RetentionSettings::fromValues('1', '30', '0', '30'), 5);
        $result = $job->processNext($this->encodeCheckpoint('raw', 0));

        self::assertInstanceOf(JobStepResult::class, $result);
        self::assertTrue($result->isComplete());
        self::assertSame([$eligibleRaw], $deps->storage->deleted);
        self::assertSame($flooredRaw, $deps->database->messages[1]['raw_path']);
        self::assertSame('floored body', $deps->database->messages[1]['body_text']);
        self::assertNull($deps->database->messages[2]['raw_path']);
        self::assertNull($deps->database->messages[2]['body_text']);
    }

    public function testTokenAndAuditCleanupStayOptIn(): void
    {
        $deps = new RetentionTestDependencies();
        $deps->database->actionTokenRows = [
            1 => ['id' => 1, 'expires_at' => '2025-09-24 00:00:00'],
        ];
        $deps->database->auditRows = [
            ['id' => 1, 'created_at' => '2023-09-24 00:00:00'],
        ];

        $job = $this->job($deps, RetentionSettings::fromValues('1', '30', '0', '30', '0', '0'));
        $result = $job->processNext(null);

        self::assertInstanceOf(JobStepResult::class, $result);
        self::assertFalse($result->isComplete());
        self::assertSame(
            [['id' => 1, 'expires_at' => '2025-09-24 00:00:00']],
            array_values($deps->database->actionTokenRows)
        );
        self::assertSame(
            [['id' => 1, 'created_at' => '2023-09-24 00:00:00']],
            array_values($deps->database->auditRows)
        );
    }

    /**
     * The 24-month audit window is a promise about what is kept, so the
     * boundary is asserted rather than inferred: a row one second older than
     * the cutoff goes, a row one second inside it stays. Without this the only
     * audit coverage is the opt-out path above, which proves nothing is
     * deleted when the operator has not switched cleanup on — it would still
     * pass if every cutoff were wrong.
     */
    public function testAuditCleanupDeletesOnlyRowsOlderThanTheTwentyFourMonthWindow(): void
    {
        $deps = new RetentionTestDependencies();
        $deps->database->auditRows = [
            ['id' => 1, 'created_at' => '2024-09-24 22:59:59'],
            ['id' => 2, 'created_at' => '2024-09-24 23:00:00'],
            ['id' => 3, 'created_at' => '2024-09-24 23:00:01'],
            ['id' => 4, 'created_at' => '2026-09-24 23:00:00'],
        ];

        $job = $this->job($deps, RetentionSettings::fromValues('0', '30', '0', '30', '0', '1'));
        $result = $job->processNext($this->encodeCheckpoint('audit', 0));

        self::assertInstanceOf(JobStepResult::class, $result);
        self::assertTrue($result->isComplete());
        self::assertSame([2, 3, 4], array_column($deps->database->auditRows, 'id'));

        // The test double above re-implements the cutoff in PHP, so the row
        // assertions alone would survive a wrong operator in the real SQL.
        // The query the job actually sends is asserted as well.
        self::assertStringContainsString('WHERE created_at < %s', $deps->database->selects[0]);
    }

    public function testRawCleanupRetriesSafelyWhenTheDatabaseUpdateFails(): void
    {
        $deps = new RetentionTestDependencies();
        $rawPath = str_repeat('9', 64) . '.eml';
        $deps->database->messages = [
            1 => $deps->message(
                1,
                'parsed',
                $rawPath,
                'retry body',
                '2026-09-24 23:00:00',
                '2025-09-24 23:00:00'
            ),
        ];
        $deps->storage->files = [
            $rawPath => 'message',
        ];
        $deps->database->rawUpdateFailuresRemaining = 1;
        $job = $this->job($deps, RetentionSettings::fromValues('1', '30', '0', '30'), 5);

        try {
            $job->processNext($this->encodeCheckpoint('raw', 0));
            self::fail('Expected the injected database failure to stop the cleanup step.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('raw message pointer', $exception->getMessage());
        }

        self::assertSame([$rawPath], $deps->storage->deleted);
        self::assertSame($rawPath, $deps->database->messages[1]['raw_path']);
        self::assertSame('retry body', $deps->database->messages[1]['body_text']);

        $retry = $job->processNext($this->encodeCheckpoint('raw', 0));

        self::assertInstanceOf(JobStepResult::class, $retry);
        self::assertTrue($retry->isComplete());
        self::assertSame([$rawPath, $rawPath], $deps->storage->deleted);
        self::assertNull($deps->database->messages[1]['raw_path']);
        self::assertNull($deps->database->messages[1]['body_text']);
    }

    public function testProcessedCleanupDeletesBatchesAndResumesWithTheSameMailbox(): void
    {
        $deps = new RetentionTestDependencies();
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
            1
        );
        $deps->mailbox = new RetentionMailbox([
            'Processed' => [10, 11, 12],
        ]);
        $deps->mailboxSettingsStore = new RetentionMailboxSettingsStore([$settings]);

        foreach ([10, 11, 12] as $uid) {
            $deps->processedMessages->add(
                $settings,
                17,
                $uid,
                new DateTimeImmutable('2026-08-01T00:00:00+00:00')
            );
        }

        $job = $this->job($deps, RetentionSettings::fromValues('0', '30', '1', '30'), 2);

        $first = $job->processNext($this->encodeProcessedCheckpoint(1, 0));

        self::assertInstanceOf(JobStepResult::class, $first);
        self::assertFalse($first->isComplete());
        self::assertSame([10, 11], $deps->mailbox->deleted['Processed']);
        self::assertSame([2], array_column($deps->processedMessages->lookups, 'limit'));

        $second = $job->processNext($first->checkpoint());

        self::assertInstanceOf(JobStepResult::class, $second);
        self::assertTrue($second->isComplete());
        self::assertSame([10, 11, 12], $deps->mailbox->deleted['Processed']);
        self::assertSame([2, 2], array_column($deps->processedMessages->lookups, 'limit'));
        self::assertTrue($deps->mailbox->closed);
    }

    public function testProcessedCleanupScansLargeFoldersInBoundedWindows(): void
    {
        $deps = new RetentionTestDependencies();
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
            1
        );
        $deps->mailbox = new RetentionMailbox([
            'Processed' => range(1, 2000),
        ]);
        $deps->mailboxSettingsStore = new RetentionMailboxSettingsStore([$settings]);

        foreach (range(1, 2000) as $uid) {
            $deps->processedMessages->add(
                $settings,
                17,
                $uid,
                new DateTimeImmutable('2026-08-01T00:00:00+00:00')
            );
        }

        $job = $this->job($deps, RetentionSettings::fromValues('0', '30', '1', '30'), 25);
        $result = $job->processNext($this->encodeProcessedCheckpoint(1, 0));

        self::assertInstanceOf(JobStepResult::class, $result);
        self::assertFalse($result->isComplete());
        self::assertSame([25], array_column($deps->processedMessages->lookups, 'limit'));
        self::assertSame(range(1, 25), $deps->mailbox->deleted['Processed']);
    }

    public function testProcessedCleanupDeletesSparseTrackedUidsWithoutScanningUnownedMessages(): void
    {
        $deps = new RetentionTestDependencies();
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
            1
        );
        $deps->mailbox = new RetentionMailbox([
            'Processed' => [50, 900],
        ]);
        $deps->mailboxSettingsStore = new RetentionMailboxSettingsStore([$settings]);
        $deps->processedMessages->add(
            $settings,
            17,
            900,
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        );

        $job = $this->job($deps, RetentionSettings::fromValues('0', '30', '1', '30'), 25);

        $result = $job->processNext($this->encodeProcessedCheckpoint(1, 0));

        self::assertInstanceOf(JobStepResult::class, $result);
        self::assertTrue($result->isComplete());
        self::assertSame([900], $deps->mailbox->deleted['Processed']);
        self::assertSame([50], $deps->mailbox->uidsByFolder['Processed']);
    }

    public function testProcessedCleanupLeavesMailWithoutMoveReceiptsUntouched(): void
    {
        $deps = new RetentionTestDependencies();
        $deps->mailbox = new RetentionMailbox([
            'Processed' => [499],
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
        $job = $this->job($deps, RetentionSettings::fromValues('0', '30', '1', '30'), 25);
        $result = $job->processNext($this->encodeProcessedCheckpoint(1, 0));
        self::assertInstanceOf(JobStepResult::class, $result);
        self::assertTrue($result->isComplete());
        self::assertSame([], $deps->mailbox->deleted['Processed'] ?? []);
        self::assertSame([499], $deps->mailbox->uidsByFolder['Processed']);
    }

    public function testProcessedCleanupDeletesOnlyRecordedOwnedMailInASharedFolder(): void
    {
        $deps = new RetentionTestDependencies();
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
            1
        );
        $deps->mailbox = new RetentionMailbox([
            'Processed' => [50, 150, 200, 250, 300],
        ]);
        $deps->mailbox->uidValidityByFolder['Processed'] = 17;
        $deps->mailboxSettingsStore = new RetentionMailboxSettingsStore([$settings]);
        $deps->processedMessages->add(
            $settings,
            17,
            150,
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        );
        $deps->processedMessages->add(
            $settings,
            17,
            250,
            new DateTimeImmutable('2026-09-01T00:00:00+00:00')
        );
        $deps->processedMessages->add(
            $settings,
            99,
            300,
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        );

        $job = $this->job($deps, RetentionSettings::fromValues('0', '30', '1', '30'), 25);
        $result = $job->processNext($this->encodeProcessedCheckpoint(1, 0));

        self::assertInstanceOf(JobStepResult::class, $result);
        self::assertTrue($result->isComplete());
        self::assertSame([150], $deps->mailbox->deleted['Processed']);
        self::assertSame([50, 200, 250, 300], $deps->mailbox->uidsByFolder['Processed']);
        self::assertSame([250], array_column($deps->processedMessages->records, 'uid'));
        self::assertSame([300], $deps->processedMessages->discardedUids);
    }

    public function testProcessedCleanupDiscardsMoveReceiptsWhenUidValidityChanges(): void
    {
        $deps = new RetentionTestDependencies();
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
            1
        );
        $deps->mailbox = new RetentionMailbox([
            'Processed' => [150],
        ]);
        $deps->mailbox->uidValidityByFolder['Processed'] = 99;
        $deps->mailboxSettingsStore = new RetentionMailboxSettingsStore([$settings]);
        $deps->processedMessages->add(
            $settings,
            17,
            150,
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        );

        $job = $this->job($deps, RetentionSettings::fromValues('0', '30', '1', '30'), 25);
        $result = $job->processNext($this->encodeProcessedCheckpoint(1, 0));

        self::assertInstanceOf(JobStepResult::class, $result);
        self::assertTrue($result->isComplete());
        self::assertSame([], $deps->mailbox->deleted['Processed'] ?? []);
        self::assertSame([150], $deps->mailbox->uidsByFolder['Processed']);
        self::assertSame([], $deps->processedMessages->records);
        self::assertSame([150], $deps->processedMessages->discardedUids);
    }

    public function testTerminalDoneCheckpointClearsAndFutureScheduledRunsProcessOwnedMail(): void
    {
        $clock = new RetentionRunnerClock();
        $stateStore = new RetentionRunnerStateStore(
            JobState::empty()->withCheckpoint($this->encodeCheckpoint('done', 0), 0)
        );
        $runner = new JobRunner(new RetentionRunnerLock(), $stateStore, $clock, 60, 10, 180);
        $deps = new RetentionTestDependencies();
        $mailboxSettings = new MailboxSettings(
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
        );
        $deps->mailbox = new RetentionMailbox(['Processed' => [150]]);
        $deps->mailboxSettingsStore = new RetentionMailboxSettingsStore([$mailboxSettings]);
        $deps->processedMessages->add(
            $mailboxSettings,
            17,
            150,
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        );
        $settings = RetentionSettings::fromValues('0', '30', '1', '30');
        $job = $this->job($deps, $settings, 25, $clock);

        $first = $runner->run($job);

        self::assertSame(\ADCT\ParishIntake\Core\Jobs\JobRunStatus::COMPLETED, $first->status);
        self::assertNull($stateStore->load($job->id())->checkpoint);
        self::assertSame([], $deps->mailbox->deleted['Processed'] ?? []);

        $notDue = $runner->run($job);

        self::assertSame(\ADCT\ParishIntake\Core\Jobs\JobRunStatus::NOT_DUE, $notDue->status);
        $clock->advanceDays(2);

        $second = $runner->run($job);

        self::assertSame(\ADCT\ParishIntake\Core\Jobs\JobRunStatus::COMPLETED, $second->status);
        self::assertSame([150], $deps->mailbox->deleted['Processed']);
        self::assertNull($stateStore->load($job->id())->checkpoint);
    }

    private function job(
        RetentionTestDependencies $deps,
        RetentionSettings $settings,
        int $batchSize = 25,
        ?ClockInterface $clock = null
    ): RetentionCleanupJob
    {
        return new RetentionCleanupJob(
            $deps->database,
            $deps->processedMessages,
            $deps->storage,
            $deps->mailboxSettingsStore,
            static fn (): RetentionSettings => $settings,
            static fn (MailboxSettings $mailbox): string => 'password-' . $mailbox->id,
            static fn (MailboxSettings $mailbox, string $password): MailboxInterface => $deps->mailbox,
            $clock ?? new RetentionTestClock(),
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
    public RetentionProcessedMailboxMessageStore $processedMessages;

    public function __construct()
    {
        $this->database = new RetentionTestDatabase();
        $this->storage = new RetentionTestStorage();
        $this->mailboxSettingsStore = new RetentionMailboxSettingsStore([]);
        $this->mailbox = new RetentionMailbox([]);
        $this->processedMessages = new RetentionProcessedMailboxMessageStore();
    }

    /**
     * @return array<string, mixed>
     */
    public function message(
        int $id,
        string $status,
        ?string $rawPath,
        ?string $bodyText,
        string $retentionUntil,
        string $receivedAt
    ): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'raw_path' => $rawPath,
            'body_text' => $bodyText,
            'retention_until' => $retentionUntil,
            'received_at' => $receivedAt,
            'updated_at' => '2025-09-24 00:00:00',
        ];
    }
}

final class RetentionProcessedMailboxMessageStore implements ProcessedMailboxMessageStoreInterface
{
    /**
     * @var list<array{
     *     source_id: int,
     *     identity: string,
     *     uid_validity: int,
     *     uid: int,
     *     internal_date: DateTimeImmutable
     * }>
     */
    public array $records = [];

    /** @var list<int> */
    public array $deletedUids = [];

    /** @var list<int> */
    public array $discardedUids = [];

    /**
     * @var list<array{source_id: int, uid_validity: int, cutoff: DateTimeImmutable, limit: int}>
     */
    public array $lookups = [];

    public function add(
        MailboxSettings $settings,
        int $uidValidity,
        int $uid,
        DateTimeImmutable $internalDate
    ): void {
        $this->records[] = [
            'source_id' => $settings->sourceId,
            'identity' => $settings->processedFolderIdentity(),
            'uid_validity' => $uidValidity,
            'uid' => $uid,
            'internal_date' => $internalDate,
        ];
    }

    public function recordMoved(
        MailboxSettings $settings,
        \ADCT\ParishIntake\Core\Ingestion\MailboxMoveReceipt $receipt,
        DateTimeImmutable $internalDate,
        DateTimeImmutable $recordedAt
    ): void {
        $this->add($settings, $receipt->destinationUidValidity, $receipt->destinationUid, $internalDate);
    }

    public function findExpired(
        MailboxSettings $settings,
        int $uidValidity,
        DateTimeImmutable $cutoff,
        int $limit
    ): array {
        $this->lookups[] = [
            'source_id' => $settings->sourceId,
            'uid_validity' => $uidValidity,
            'cutoff' => $cutoff,
            'limit' => $limit,
        ];
        $uids = [];

        foreach ($this->records as $record) {
            if (
                $record['source_id'] === $settings->sourceId
                && $record['identity'] === $settings->processedFolderIdentity()
                && $record['uid_validity'] === $uidValidity
                && $record['internal_date'] <= $cutoff
            ) {
                $uids[] = $record['uid'];
            }
        }

        sort($uids, SORT_NUMERIC);

        return array_slice($uids, 0, $limit);
    }

    public function discardStale(MailboxSettings $settings, int $currentUidValidity): void
    {
        $identity = $settings->processedFolderIdentity();
        $staleUids = [];

        foreach ($this->records as $record) {
            if (
                $record['source_id'] === $settings->sourceId
                && (
                    $record['identity'] !== $identity
                    || $record['uid_validity'] !== $currentUidValidity
                )
            ) {
                $staleUids[] = $record['uid'];
            }
        }

        $this->discardedUids = array_merge($this->discardedUids, $staleUids);
        $this->records = array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['source_id'] !== $settings->sourceId
                || (
                    $record['identity'] === $identity
                    && $record['uid_validity'] === $currentUidValidity
                )
        ));
    }

    public function deleteOwned(
        MailboxSettings $settings,
        int $uidValidity,
        int $uid
    ): void {
        $this->deletedUids[] = $uid;
        $identity = $settings->processedFolderIdentity();
        $this->records = array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['source_id'] !== $settings->sourceId
                || $record['identity'] !== $identity
                || $record['uid_validity'] !== $uidValidity
                || $record['uid'] !== $uid
        ));
    }
}

final class RetentionTestDatabase implements DatabaseConnectionInterface
{
    /** @var list<array{query: string, arguments: array<int, mixed>}> */
    public array $prepared = [];

    /** @var list<string> */
    public array $queries = [];

    /** @var list<string> */
    public array $selects = [];

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

    public int $rawUpdateFailuresRemaining = 0;

    private string $lastError = '';

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
        $this->lastError = '';

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

        if (str_contains($prepared['query'], 'UPDATE `wp_adct_pi_inbound_messages` SET raw_path = NULL, body_text = NULL')) {
            if ($this->rawUpdateFailuresRemaining > 0) {
                --$this->rawUpdateFailuresRemaining;
                $this->lastError = 'Injected raw cleanup update failure.';

                return false;
            }

            $messageId = (int) ($prepared['arguments'][1] ?? 0);

            if (! isset($this->messages[$messageId])) {
                return 0;
            }

            $this->messages[$messageId]['raw_path'] = null;
            $this->messages[$messageId]['body_text'] = null;
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
        $this->selects[] = $sql;

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
            [$retentionCutoff, $receivedCutoff, $pattern, $statusA, $statusB, $statusC, $lastId, $candA, $candB, $candC, $candD, $limit] = $arguments;
            $rows = [];

            foreach ($this->messages as $row) {
                $messageId = (int) ($row['id'] ?? 0);

                if ($messageId <= (int) $lastId) {
                    continue;
                }

                if (($row['retention_until'] ?? '') > $retentionCutoff) {
                    continue;
                }

                if (($row['received_at'] ?? '') > $receivedCutoff) {
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
        $this->lastError = '';
    }

    public function lastError(): string
    {
        return $this->lastError;
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

    public function resolveAttachmentPath(string $relativePath): string
    {
        throw new RuntimeException('Not used in retention tests.');
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

    /** @var array<string, int> */
    public array $uidValidityByFolder = [];

    public bool $closed = false;

    public function listFolders(): array
    {
        return array_keys($this->uidsByFolder);
    }

    public function ensureFolder(string $folder): void
    {
        $this->uidsByFolder[$folder] ??= [];
    }

    public function uidValidity(?string $folder = null): int
    {
        $folder = $folder ?? 'INBOX';

        return $this->uidValidityByFolder[$folder] ?? 17;
    }

    public function uidNext(string $folder): int
    {
        $uids = $this->uidsByFolder[$folder] ?? [];

        return $uids === [] ? 1 : max($uids) + 1;
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

        if ($criteria->beforeUid !== null) {
            $uids = array_values(array_filter(
                $uids,
                static fn (int $uid): bool => $uid <= $criteria->beforeUid
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

final class RetentionRunnerClock implements ClockInterface
{
    private DateTimeImmutable $instant;

    public function __construct()
    {
        $this->instant = new DateTimeImmutable('2026-09-25T02:00:00+02:00', new DateTimeZone('Africa/Johannesburg'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function advanceDays(int $days): void
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('The test clock can only advance by whole days.');
        }

        $this->instant = $this->instant->add(new \DateInterval('P' . $days . 'D'));
    }
}

final class RetentionRunnerLock implements JobLockInterface
{
    public function acquire(string $jobId, DateTimeImmutable $now, int $expiresInSeconds): ?string
    {
        return 'token-1';
    }

    public function isHeldBy(string $jobId, string $token, DateTimeImmutable $now): bool
    {
        return true;
    }

    public function release(string $jobId, string $token): bool
    {
        return true;
    }
}

final class RetentionRunnerStateStore implements JobStateStoreInterface
{
    private JobState $state;

    public function __construct(?JobState $state = null)
    {
        $this->state = $state ?? JobState::empty();
    }

    public function load(string $jobId): JobState
    {
        return $this->state;
    }

    public function save(string $jobId, JobState $state): void
    {
        $this->state = $state;
    }
}
