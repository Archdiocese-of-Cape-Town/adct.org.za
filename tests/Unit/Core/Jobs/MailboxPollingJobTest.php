<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Jobs;

use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\Imap\MessageTooLarge;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageStoreResult;
use ADCT\ParishIntake\Core\Ingestion\MailboxCheckpoint;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use ADCT\ParishIntake\Core\Ingestion\MessageContentHasher;
use ADCT\ParishIntake\Core\Ingestion\RawMailMessage;
use ADCT\ParishIntake\Core\Ingestion\RawMessageInspector;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Jobs\MailboxPollingJob;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageInterface;
use ADCT\ParishIntake\Core\Ports\InboundMessageStoreInterface;
use ADCT\ParishIntake\Core\Ports\MailboxCheckpointStoreInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Ports\MailboxSettingsStoreInterface;
use ADCT\ParishIntake\Core\Ports\SourceHealthStoreInterface;
use ADCT\ParishIntake\Core\Sources\Source;
use ADCT\ParishIntake\Core\Sources\SourceHealthRecorder;
use ADCT\ParishIntake\Core\Sources\SourceHealthState;
use ADCT\ParishIntake\Core\Sources\SourceStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailboxPollingJobTest extends TestCase
{
    public const SOURCE_ID = 17;

    public function testResendsWithDifferentMessageIdsAreDeduplicatedByNormalisedContent(): void
    {
        $server = new PollingMailboxServer();
        $server->inbox[1] = $this->rawMessage('first@example.test', "Same  event\r\nnotice.");
        $server->inbox[2] = $this->rawMessage('second@example.test', "Same event\nnotice.");
        $fixture = $this->fixture($server);

        $this->finish($fixture['job']);

        self::assertCount(1, $fixture['messages']->records);
        self::assertSame(2, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
        self::assertSame([], $server->inbox);
        self::assertSame([1, 2], $server->movedTo['Processed']);
    }

    public function testMailboxWithoutAPreviousCheckIsDueImmediately(): void
    {
        $server = new PollingMailboxServer();
        $fixture = $this->fixture($server);

        $this->finish($fixture['job']);

        self::assertSame(1, $server->connectionCount);
        self::assertSame('2026-09-25 03:00:00', $fixture['health']->state?->lastCheckedAt);
        self::assertTrue($fixture['checkpoints']->checkpoints[self::SOURCE_ID]->scanComplete);
    }

    public function testMailboxIsNotPolledBeforeItsConfiguredIntervalHasElapsed(): void
    {
        $server = new PollingMailboxServer();
        $server->inbox[1] = $this->rawMessage('not-due@example.test', 'Wait for the configured interval.');
        $settings = $this->settings(
            pollIntervalMinutes: 10,
            lastCheckedAt: '2026-09-25 02:55:00'
        );
        $fixture = $this->fixture($server, settings: $settings);

        $this->finish($fixture['job']);

        self::assertSame(0, $server->connectionCount);
        self::assertArrayHasKey(1, $server->inbox);
        self::assertSame([], $fixture['messages']->records);
        self::assertSame([], $fixture['checkpoints']->checkpoints);
    }

    public function testMailboxIsPolledWhenItsConfiguredIntervalHasElapsed(): void
    {
        $server = new PollingMailboxServer();
        $settings = $this->settings(
            pollIntervalMinutes: 10,
            lastCheckedAt: '2026-09-25 02:50:00'
        );
        $fixture = $this->fixture($server, settings: $settings);

        $this->finish($fixture['job']);

        self::assertSame(1, $server->connectionCount);
        self::assertSame('2026-09-25 03:00:00', $fixture['health']->state?->lastCheckedAt);
        self::assertTrue($fixture['checkpoints']->checkpoints[self::SOURCE_ID]->scanComplete);
    }

    public function testDueMailboxIsNotStarvedByAnEarlierNotDueSource(): void
    {
        $server = new PollingMailboxServer();
        $server->inbox[1] = $this->rawMessage('due@example.test', 'Process this due mailbox.');
        $settingsList = [
            $this->settings(
                pollIntervalMinutes: 10,
                lastCheckedAt: '2026-09-25 02:55:00',
                sourceId: 16
            ),
            $this->settings(),
        ];
        $fixture = $this->fixture($server, settingsList: $settingsList);

        $this->finish($fixture['job']);

        self::assertSame(0, $server->connectionsBySource[16] ?? 0);
        self::assertGreaterThan(0, $server->connectionsBySource[self::SOURCE_ID] ?? 0);
        self::assertSame([1], $server->movedTo['Processed']);
        self::assertArrayNotHasKey(16, $fixture['checkpoints']->checkpoints);
        self::assertSame(1, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
    }

    public function testIncompleteScanResumesImmediatelyDespiteARecentSuccessfulCheck(): void
    {
        $server = new PollingMailboxServer();
        $server->inbox[1] = $this->rawMessage('resume-first@example.test', 'Resume the first notice.');
        $server->inbox[2] = $this->rawMessage('resume-second@example.test', 'Resume the second notice.');
        $settings = $this->settings(lastCheckedAt: '2026-09-25 02:59:00');
        $fixture = $this->fixture($server, settings: $settings);
        $fixture['checkpoints']->checkpoints[self::SOURCE_ID] = new MailboxCheckpoint(12345, 0);

        $this->finish($fixture['job']);

        self::assertCount(2, $fixture['messages']->records);
        self::assertSame(2, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
        self::assertTrue($fixture['checkpoints']->checkpoints[self::SOURCE_ID]->scanComplete);
    }

    public function testFailedPollUsesShortRetryBackoffInsteadOfTheSourceInterval(): void
    {
        $server = new PollingMailboxServer();
        $server->failNextSearch = true;
        $fixture = $this->fixture($server);

        $this->finish($fixture['job']);

        self::assertSame(1, $server->connectionCount);
        self::assertSame(1, $server->searchCount);
        self::assertSame(1, $fixture['health']->state?->consecutiveFailures);
        self::assertSame('2026-09-25 03:00:00', $fixture['health']->state?->lastCheckedAt);

        $beforeBackoff = $this->settings(
            pollIntervalMinutes: Source::DEFAULT_POLL_INTERVAL_MINUTES,
            lastCheckedAt: '2026-09-25 02:55:00',
            consecutiveFailures: 1
        );
        $deferred = $this->fixture($server, $fixture['messages'], $fixture['checkpoints'], $fixture['health'], $beforeBackoff);
        $this->finish($deferred['job']);

        self::assertSame(1, $server->connectionCount);
        self::assertSame(1, $server->searchCount);

        $afterBackoff = $this->settings(
            pollIntervalMinutes: Source::DEFAULT_POLL_INTERVAL_MINUTES,
            lastCheckedAt: '2026-09-25 02:50:00',
            consecutiveFailures: 1
        );
        $retried = $this->fixture($server, $fixture['messages'], $fixture['checkpoints'], $fixture['health'], $afterBackoff);
        $this->finish($retried['job']);

        self::assertSame(2, $server->connectionCount);
        self::assertSame(2, $server->searchCount);
        self::assertTrue($fixture['checkpoints']->checkpoints[self::SOURCE_ID]->scanComplete);
        self::assertSame(0, $fixture['health']->state?->consecutiveFailures);
    }

    public function testRetryBackoffDoublesAndCapsAtSixHours(): void
    {
        foreach ([
            [2, '2026-09-25 02:41:00', 0],
            [2, '2026-09-25 02:40:00', 1],
            [7, '2026-09-24 21:01:00', 0],
            [7, '2026-09-24 21:00:00', 1],
        ] as [$failures, $lastCheckedAt, $expectedConnections]) {
            $server = new PollingMailboxServer();
            $settings = $this->settings(
                pollIntervalMinutes: Source::DEFAULT_POLL_INTERVAL_MINUTES,
                lastCheckedAt: $lastCheckedAt,
                consecutiveFailures: $failures
            );
            $fixture = $this->fixture($server, settings: $settings);

            $this->finish($fixture['job']);

            self::assertSame(
                $expectedConnections,
                $server->connectionCount,
                'Unexpected retry eligibility after ' . $failures . ' failures.'
            );
        }
    }

    public function testInterruptedRunResumesFromTheDurableMailboxCheckpoint(): void
    {
        $server = new PollingMailboxServer();
        $server->inbox[1] = $this->rawMessage('first@example.test', 'First event.');
        $server->inbox[2] = $this->rawMessage('second@example.test', 'Second event.');
        $fixture = $this->fixture($server);

        $firstStep = $fixture['job']->processNext(null);

        self::assertInstanceOf(JobStepResult::class, $firstStep);
        self::assertFalse($firstStep->isComplete());
        self::assertSame(1, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);

        $resumed = $this->fixture(
            $server,
            $fixture['messages'],
            $fixture['checkpoints'],
            $fixture['health'],
            $this->settings(lastCheckedAt: '2026-09-25 03:00:00')
        );
        $this->finish($resumed['job'], $firstStep->checkpoint());

        self::assertCount(2, $fixture['messages']->records);
        self::assertSame(2, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
        self::assertSame([], $server->inbox);
    }

    public function testUidValidityChangeRescansAndDeduplicatesAlreadyStoredMail(): void
    {
        $server = new PollingMailboxServer();
        $server->uidValidity = 54321;
        $raw = $this->rawMessage('already-stored@example.test', 'Already stored.');
        $server->inbox[4] = $raw;
        $fixture = $this->fixture($server);
        $record = new InboundMessageRecord(
            self::SOURCE_ID,
            '<already-stored@example.test>',
            (new MessageContentHasher())->hash('Already stored.'),
            'notices@example.test',
            'Example Notices',
            'Example notice',
            new DateTimeImmutable('2026-09-25T04:00:00+00:00'),
            'existing.eml'
        );
        $fixture['messages']->store($record, '2026-09-25 04:00:00');
        $fixture['checkpoints']->checkpoints[self::SOURCE_ID] = new MailboxCheckpoint(12345, 99);

        $this->finish($fixture['job']);

        self::assertCount(1, $fixture['messages']->records);
        self::assertSame(54321, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->uidValidity);
        self::assertSame(4, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
        self::assertSame([4], $server->movedTo['Processed']);
    }

    public function testOversizedMessageIsSkippedAndMovedAsideWithoutBlockingLaterMail(): void
    {
        $server = new PollingMailboxServer();
        $settings = $this->settings(maxMessageSizeBytes: 512);
        $server->inbox[1] = $this->rawMessage(
            'large@example.test',
            str_repeat('oversized text ', 100)
        );
        $server->inbox[2] = $this->rawMessage('valid@example.test', 'A valid notice.');
        $fixture = $this->fixture($server, settings: $settings);

        $this->finish($fixture['job']);

        self::assertCount(2, $fixture['messages']->records);
        self::assertSame(InboundMessageRecord::STATUS_SKIPPED, $fixture['messages']->records[0]->status);
        self::assertSame(InboundMessageRecord::STATUS_RECEIVED, $fixture['messages']->records[1]->status);
        self::assertSame([1], $server->movedTo[MailboxPollingJob::TOO_LARGE_FOLDER]);
        self::assertSame([2], $server->movedTo['Processed']);
        self::assertSame(2, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
    }

    public function testOversizedMessageUsesFreshConnectionWhenUidValidityMatches(): void
    {
        $server = new PollingMailboxServer();
        $server->oversizedFetchConnectionUsable = false;
        $settings = $this->settings(maxMessageSizeBytes: 512);
        $server->inbox[1] = $this->rawMessage('large@example.test', str_repeat('oversized text ', 100));
        $fixture = $this->fixture($server, settings: $settings);

        $this->finish($fixture['job']);

        self::assertCount(1, $fixture['messages']->records);
        self::assertSame(InboundMessageRecord::STATUS_SKIPPED, $fixture['messages']->records[0]->status);
        self::assertSame([1], $server->movedTo[MailboxPollingJob::TOO_LARGE_FOLDER]);
        self::assertSame(12345, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->uidValidity);
        self::assertSame(1, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
    }

    public function testOversizedMessageWaitsForNextRunWhenFreshUidValidityChanges(): void
    {
        $server = new PollingMailboxServer();
        $server->oversizedFetchConnectionUsable = false;
        $server->uidValidityAfterOversizedFetch = 54321;
        $settings = $this->settings(maxMessageSizeBytes: 512);
        $server->inbox[1] = $this->rawMessage('large@example.test', str_repeat('oversized text ', 100));
        $fixture = $this->fixture($server, settings: $settings);

        $this->finish($fixture['job']);

        self::assertSame([], $server->movedTo[MailboxPollingJob::TOO_LARGE_FOLDER] ?? []);
        self::assertArrayHasKey(1, $server->inbox);
        self::assertSame(12345, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->uidValidity);
        self::assertSame(0, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);

        $server->inbox[1] = $this->rawMessage('replacement@example.test', 'A message in the replacement mailbox.');
        $resumed = $this->fixture(
            $server,
            $fixture['messages'],
            $fixture['checkpoints'],
            $fixture['health'],
            $this->settings(
                maxMessageSizeBytes: 512,
                lastCheckedAt: '2026-09-25 02:50:00',
                consecutiveFailures: 1
            )
        );
        $this->finish($resumed['job']);

        self::assertSame([1], $server->movedTo['Processed']);
        self::assertSame(54321, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->uidValidity);
        self::assertSame(1, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
    }

    public function testPerMessageFailureDoesNotAdvancePastItOrPreventLaterMailFromBeingProcessed(): void
    {
        $server = new PollingMailboxServer();
        $server->inbox[1] = $this->rawMessage('retry@example.test', 'Retry this notice.');
        $server->inbox[2] = $this->rawMessage('later@example.test', 'Store this other notice.');
        $fixture = $this->fixture($server);
        $fixture['storage']->failNextRawWrite = true;

        $this->finish($fixture['job']);

        self::assertCount(1, $fixture['messages']->records);
        self::assertSame(0, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
        self::assertArrayHasKey(1, $server->inbox);
        self::assertSame([2], $server->movedTo['Processed']);
        self::assertSame(1, $fixture['health']->state?->consecutiveFailures);

        $resumed = $this->fixture(
            $server,
            $fixture['messages'],
            $fixture['checkpoints'],
            $fixture['health'],
            $this->settings(lastCheckedAt: '2026-09-25 02:50:00', consecutiveFailures: 1)
        );
        $this->finish($resumed['job']);

        self::assertCount(2, $fixture['messages']->records);
        self::assertSame(1, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
        self::assertSame([], $server->inbox);
    }

    public function testStoredMessageIsNotDuplicatedWhenItsMoveMustBeRetried(): void
    {
        $server = new PollingMailboxServer();
        $server->inbox[1] = $this->rawMessage('move-retry@example.test', 'Move after durable storage.');
        $fixture = $this->fixture($server);
        $server->failNextMove = true;

        $this->finish($fixture['job']);

        self::assertCount(1, $fixture['messages']->records);
        self::assertSame(0, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
        self::assertArrayHasKey(1, $server->inbox);

        $resumed = $this->fixture(
            $server,
            $fixture['messages'],
            $fixture['checkpoints'],
            $fixture['health'],
            $this->settings(lastCheckedAt: '2026-09-25 02:50:00', consecutiveFailures: 1)
        );
        $this->finish($resumed['job']);

        self::assertCount(1, $fixture['messages']->records);
        self::assertSame(1, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
        self::assertSame([1], $server->movedTo['Processed']);
    }

    public function testUniqueKeyRaceCleanupKeepsPreviouslyCommittedFiles(): void
    {
        $server = new PollingMailboxServer();
        $server->inbox[1] = $this->rawMessage('race@example.test', 'Store this once.');
        $fixture = $this->fixture($server);
        $fixture['storage']->files['committed.eml'] = 'Previously committed message.';
        $fixture['messages']->uniqueKeyRaceId = 99;

        $this->finish($fixture['job']);

        self::assertSame(
            ['committed.eml' => 'Previously committed message.'],
            $fixture['storage']->files
        );
        self::assertSame([1], $server->movedTo['Processed']);
        self::assertSame(1, $fixture['checkpoints']->checkpoints[self::SOURCE_ID]->lastUid);
    }

    /**
     * @param list<MailboxSettings>|null $settingsList
     * @return array{
     *     job: MailboxPollingJob,
     *     messages: PollingMessageStore,
     *     checkpoints: PollingCheckpointStore,
     *     storage: PollingFileStorage,
     *     health: PollingHealthStore
     * }
     */
    private function fixture(
        PollingMailboxServer $server,
        ?PollingMessageStore $messages = null,
        ?PollingCheckpointStore $checkpoints = null,
        ?PollingHealthStore $health = null,
        ?MailboxSettings $settings = null,
        ?array $settingsList = null
    ): array {
        $messages ??= new PollingMessageStore();
        $checkpoints ??= new PollingCheckpointStore();
        $storage = new PollingFileStorage();
        $health ??= new PollingHealthStore();
        $clock = new PollingClock();
        $mailboxes = new PollingMailboxSettingsStore($settingsList ?? [$settings ?? $this->settings()]);
        $job = new MailboxPollingJob(
            $mailboxes,
            $checkpoints,
            $messages,
            $storage,
            new SourceHealthRecorder($health, $clock),
            new RawMessageInspector(),
            new AttachmentStoragePolicy(),
            new MessageContentHasher(),
            $clock,
            static fn (MailboxSettings $mailboxSettings): string => 'greenmail-test-password',
            static function (MailboxSettings $mailboxSettings, string $password) use ($server): MailboxInterface {
                ++$server->connectionCount;
                $server->connectionsBySource[$mailboxSettings->sourceId] =
                    ($server->connectionsBySource[$mailboxSettings->sourceId] ?? 0) + 1;

                return new PollingMailbox($server, $mailboxSettings->maxMessageSizeBytes);
            }
        );

        return [
            'job' => $job,
            'messages' => $messages,
            'checkpoints' => $checkpoints,
            'storage' => $storage,
            'health' => $health,
        ];
    }

    private function settings(
        int $maxMessageSizeBytes = 30 * 1024 * 1024,
        int $pollIntervalMinutes = Source::DEFAULT_POLL_INTERVAL_MINUTES,
        ?string $lastCheckedAt = null,
        int $consecutiveFailures = 0,
        int $sourceId = self::SOURCE_ID
    ): MailboxSettings {
        return new MailboxSettings(
            'Invented test mailbox',
            'imap.example.test',
            993,
            MailboxEncryption::SSL,
            'intake@example.test',
            'INBOX',
            'Processed',
            $maxMessageSizeBytes,
            true,
            1,
            $sourceId,
            $pollIntervalMinutes,
            $lastCheckedAt,
            $consecutiveFailures
        );
    }

    private function rawMessage(string $messageId, string $body): string
    {
        return implode("\r\n", [
            'From: Example Notices <notices@example.test>',
            'To: intake@example.test',
            'Date: Fri, 25 Sep 2026 04:00:00 +0000',
            'Message-ID: <' . $messageId . '>',
            'Subject: Example event notice',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            $body,
            '',
        ]);
    }

    private function finish(MailboxPollingJob $job, ?string $checkpoint = null): void
    {
        for ($stepCount = 0; $stepCount < 100; ++$stepCount) {
            $step = $job->processNext($checkpoint);

            if ($step === null) {
                return;
            }

            $checkpoint = $step->checkpoint();

            if ($step->isComplete()) {
                return;
            }
        }

        self::fail('The mailbox polling job did not complete its bounded test batch.');
    }
}

final class PollingMailboxSettingsStore implements MailboxSettingsStoreInterface
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

final class PollingMailboxServer
{
    public int $uidValidity = 12345;
    public ?int $uidValidityAfterOversizedFetch = null;
    public int $connectionCount = 0;
    public int $searchCount = 0;
    /** @var array<int, int> */
    public array $connectionsBySource = [];
    public array $inbox = [];
    public array $folders = ['INBOX'];
    public array $movedTo = [];
    public bool $failNextMove = false;
    public bool $oversizedFetchConnectionUsable = true;
    public bool $failNextSearch = false;
}

final class PollingMailbox implements MailboxInterface
{
    public function __construct(
        private PollingMailboxServer $server,
        private int $maxMessageSizeBytes
    ) {
    }

    public function listFolders(): array
    {
        return $this->server->folders;
    }

    public function ensureFolder(string $folder): void
    {
        if (! in_array($folder, $this->server->folders, true)) {
            $this->server->folders[] = $folder;
        }
    }

    public function uidValidity(): int
    {
        return $this->server->uidValidity;
    }

    public function search(MailboxSearchCriteria $criteria): array
    {
        ++$this->server->searchCount;

        if ($this->server->failNextSearch) {
            $this->server->failNextSearch = false;
            throw new RuntimeException('The scripted search failed.');
        }

        $uids = array_map('intval', array_keys($this->server->inbox));

        if ($criteria->afterUid !== null) {
            $uids = array_values(array_filter(
                $uids,
                static fn (int $uid): bool => $uid > $criteria->afterUid
            ));
        }

        sort($uids, SORT_NUMERIC);

        return $uids;
    }

    public function fetch(int $uid): RawMailMessage
    {
        $raw = $this->server->inbox[$uid] ?? null;

        if (! is_string($raw)) {
            throw new RuntimeException('The scripted message was not found.');
        }

        if (strlen($raw) > $this->maxMessageSizeBytes) {
            if ($this->server->uidValidityAfterOversizedFetch !== null) {
                $this->server->uidValidity = $this->server->uidValidityAfterOversizedFetch;
                $this->server->uidValidityAfterOversizedFetch = null;
            }

            throw new MessageTooLarge(
                strlen($raw),
                $this->maxMessageSizeBytes,
                $this->server->oversizedFetchConnectionUsable
            );
        }

        return new RawMailMessage(
            $uid,
            $raw,
            strlen($raw),
            new DateTimeImmutable('2026-09-25T04:00:00+00:00'),
            []
        );
    }

    public function move(int $uid, string $folder): void
    {
        if ($this->server->failNextMove) {
            $this->server->failNextMove = false;
            throw new RuntimeException('The scripted move failed.');
        }

        if (! array_key_exists($uid, $this->server->inbox)) {
            throw new RuntimeException('The scripted message was already moved.');
        }

        unset($this->server->inbox[$uid]);
        $this->server->movedTo[$folder] ??= [];
        $this->server->movedTo[$folder][] = $uid;
    }

    public function markSeen(int $uid): void
    {
        throw new RuntimeException('The poller must not mark messages seen.');
    }

    public function close(): void
    {
    }
}

final class PollingCheckpointStore implements MailboxCheckpointStoreInterface
{
    /** @var array<int, MailboxCheckpoint> */
    public array $checkpoints = [];

    public function findCheckpoint(int $sourceId): ?MailboxCheckpoint
    {
        return $this->checkpoints[$sourceId] ?? null;
    }

    public function saveCheckpoint(int $sourceId, MailboxCheckpoint $checkpoint, string $timestamp): void
    {
        $this->checkpoints[$sourceId] = $checkpoint;
    }
}

final class PollingMessageStore implements InboundMessageStoreInterface
{
    /** @var list<InboundMessageRecord> */
    public array $records = [];

    public ?int $uniqueKeyRaceId = null;

    public function findDuplicate(int $sourceId, string $externalId, ?string $contentHash): ?int
    {
        foreach ($this->records as $index => $record) {
            if (
                $record->sourceId === $sourceId
                && (
                    $record->externalId === $externalId
                    || ($contentHash !== null && $record->contentHash === $contentHash)
                )
            ) {
                return $index + 1;
            }
        }

        return null;
    }

    public function store(InboundMessageRecord $message, string $timestamp): InboundMessageStoreResult
    {
        if ($this->uniqueKeyRaceId !== null) {
            $duplicateId = $this->uniqueKeyRaceId;
            $this->uniqueKeyRaceId = null;

            return new InboundMessageStoreResult($duplicateId, true);
        }

        $duplicateId = $this->findDuplicate($message->sourceId, $message->externalId, $message->contentHash);

        if ($duplicateId !== null) {
            return new InboundMessageStoreResult($duplicateId, true);
        }

        $this->records[] = $message;

        return new InboundMessageStoreResult(count($this->records), false);
    }
}

final class PollingFileStorage implements InboundMailStorageInterface
{
    /** @var array<string, string> */
    public array $files = [];

    public bool $failNextRawWrite = false;

    public function storeRawMessage(string $rawMessage): string
    {
        if ($this->failNextRawWrite) {
            $this->failNextRawWrite = false;
            throw new RuntimeException('The scripted raw file write failed.');
        }

        return $this->save($rawMessage, 'eml');
    }

    public function storeAttachment(string $content, string $extension): string
    {
        return $this->save($content, $extension);
    }

    public function delete(string $relativePath): void
    {
        unset($this->files[$relativePath]);
    }

    private function save(string $content, string $extension): string
    {
        $path = 'random-' . (count($this->files) + 1) . '.' . $extension;
        $this->files[$path] = $content;

        return $path;
    }
}

final class PollingHealthStore implements SourceHealthStoreInterface
{
    public ?SourceHealthState $state = null;

    public function findHealth(int $sourceId): ?SourceHealthState
    {
        if ($sourceId !== MailboxPollingJobTest::SOURCE_ID) {
            return null;
        }

        return $this->state ?? new SourceHealthState(SourceStatus::ACTIVE, null, null, null, 0, null);
    }

    public function saveHealthIfUnchanged(
        int $sourceId,
        SourceHealthState $expected,
        SourceHealthState $replacement,
        string $updatedAt
    ): bool {
        if ($sourceId !== MailboxPollingJobTest::SOURCE_ID) {
            return false;
        }

        $current = $this->findHealth($sourceId);

        if ($current === null || ! $current->equals($expected)) {
            return false;
        }

        $this->state = $replacement;

        return true;
    }
}

final class PollingClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-25T05:00:00+02:00', new DateTimeZone('Africa/Johannesburg'));
    }
}
