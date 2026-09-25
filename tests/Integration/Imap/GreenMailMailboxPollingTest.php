<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Integration\Imap;

use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
use ADCT\ParishIntake\Core\Ingestion\AuthenticationResults;
use ADCT\ParishIntake\Core\Ingestion\Imap\ConnectionFailed;
use ADCT\ParishIntake\Core\Ingestion\Imap\ImapMailbox;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxConnectionConfig;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageStoreResult;
use ADCT\ParishIntake\Core\Ingestion\MailboxCheckpoint;
use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ingestion\MessageContentHasher;
use ADCT\ParishIntake\Core\Ingestion\RawMessageInspector;
use ADCT\ParishIntake\Core\Jobs\JobRunStatus;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Jobs\JobState;
use ADCT\ParishIntake\Core\Jobs\MailboxPollingJob;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageInterface;
use ADCT\ParishIntake\Core\Ports\InboundMessageStoreInterface;
use ADCT\ParishIntake\Core\Ports\JobLockInterface;
use ADCT\ParishIntake\Core\Ports\JobStateStoreInterface;
use ADCT\ParishIntake\Core\Ports\MailboxCheckpointStoreInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Ports\MailboxSettingsStoreInterface;
use ADCT\ParishIntake\Core\Ports\SourceHealthStoreInterface;
use ADCT\ParishIntake\Core\Sources\SourceHealthRecorder;
use ADCT\ParishIntake\Core\Sources\SourceHealthState;
use ADCT\ParishIntake\Core\Sources\SourceStatus;
use ADCT\ParishIntake\Core\Support\SystemClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('greenmail')]
final class GreenMailMailboxPollingTest extends TestCase
{
    private const MAX_MESSAGE_SIZE_BYTES = 1024;
    private const ARCHIVE_FOLDER = 'Polling integration archive';
    public const SOURCE_ID = 23;

    public function testPollingResumesDeduplicatesAndMovesOversizedMailWithoutBlocking(): void
    {
        $this->waitForGreenMail();
        $this->prepareMailbox();
        $processedFolder = 'Processed-Polling-' . bin2hex(random_bytes(4));
        $this->deliverMessage($this->multipartMessage(
            'resend-one@example.test',
            "A  shared\r\nnotice.",
            'shared-event-poster.pdf'
        ));
        $this->deliverMessage($this->multipartMessage(
            'resend-two@example.test',
            "A shared\nnotice.",
            'shared-event-poster.pdf'
        ));
        $firstBatchUids = $this->inboxUids();
        self::assertCount(2, $firstBatchUids);

        $settings = $this->settings($processedFolder);
        $messages = new GreenMailPollingMessageStore();
        $checkpoints = new GreenMailPollingCheckpointStore();
        $health = new GreenMailPollingHealthStore();
        $files = new GreenMailPollingFileStorage();
        $job = $this->job($settings, $messages, $checkpoints, $health, $files);
        $stateStore = new GreenMailPollingJobStateStore();
        $runner = new JobRunner(
            new GreenMailPollingJobLock(),
            $stateStore,
            new SystemClock()
        );

        $interrupted = $runner->run($job, true, 60, 1);

        self::assertSame(JobRunStatus::ITEM_BUDGET_REACHED, $interrupted->status);
        self::assertCount(1, $messages->records);
        self::assertSame($firstBatchUids[0], $checkpoints->checkpoints[self::SOURCE_ID]->lastUid);
        self::assertCount(1, $messages->records[0]->attachments);
        self::assertSame('pending', $messages->records[0]->attachments[0]->status);
        self::assertCount(2, $files->files);

        $resumed = $runner->run($job, true, 60, 100);

        self::assertSame(JobRunStatus::COMPLETED, $resumed->status);
        self::assertCount(1, $messages->records);
        self::assertSame($firstBatchUids[1], $checkpoints->checkpoints[self::SOURCE_ID]->lastUid);

        $this->deliverMessage($this->plainMessage(
            'oversized@example.test',
            str_repeat('oversized notice content ', 100)
        ));
        $this->deliverMessage($this->plainMessage('later@example.test', 'A later valid notice.'));
        $laterBatchUids = $this->inboxUids();
        self::assertCount(2, $laterBatchUids);

        $completed = $runner->run($job, true, 60, 100);

        self::assertSame(JobRunStatus::COMPLETED, $completed->status);
        self::assertCount(3, $messages->records);
        self::assertSame(InboundMessageRecord::STATUS_SKIPPED, $messages->records[1]->status);
        self::assertSame(InboundMessageRecord::STATUS_RECEIVED, $messages->records[2]->status);
        self::assertSame($laterBatchUids[1], $checkpoints->checkpoints[self::SOURCE_ID]->lastUid);
        self::assertCount(3, $files->files);

        $processed = new ImapMailbox($this->config($processedFolder));

        try {
            self::assertCount(3, $processed->search(MailboxSearchCriteria::all()));
        } finally {
            $processed->close();
        }

        $tooLarge = new ImapMailbox($this->config(MailboxPollingJob::TOO_LARGE_FOLDER));

        try {
            self::assertCount(1, $tooLarge->search(MailboxSearchCriteria::all()));
        } finally {
            $tooLarge->close();
        }
    }

    public function testPollingStoresAutomationFlagsAndUntrustedAuthenticationVerdicts(): void
    {
        $this->waitForGreenMail();
        $this->prepareMailbox();
        $processedFolder = 'Processed-Screening-' . bin2hex(random_bytes(4));
        $this->deliverMessage($this->screeningMessage('screening-' . bin2hex(random_bytes(4)) . '@example.test'));
        $messages = new GreenMailPollingMessageStore();
        $checkpoints = new GreenMailPollingCheckpointStore();
        $health = new GreenMailPollingHealthStore();
        $files = new GreenMailPollingFileStorage();
        $job = $this->job($this->settings($processedFolder), $messages, $checkpoints, $health, $files);
        $runner = new JobRunner(
            new GreenMailPollingJobLock(),
            new GreenMailPollingJobStateStore(),
            new SystemClock()
        );

        $result = $runner->run($job, true, 60, 100);

        self::assertSame(JobRunStatus::COMPLETED, $result->status);
        self::assertCount(1, $messages->records);
        self::assertTrue($messages->records[0]->isAutoReply);
        self::assertInstanceOf(AuthenticationResults::class, $messages->records[0]->authResults);
        self::assertSame('pass', $messages->records[0]->authResults?->checksFor('spf')[0]->result);
        self::assertSame('fail', $messages->records[0]->authResults?->checksFor('dmarc')[0]->result);
        self::assertFalse($messages->records[0]->authResults?->hasTrustedPass());
        self::assertFalse($messages->records[0]->authResults?->checksFor('dmarc')[0]->trusted);
    }

    private function prepareMailbox(): void
    {
        $inbox = new ImapMailbox($this->config());

        try {
            $inbox->ensureFolder('Processed');
            $inbox->ensureFolder(MailboxPollingJob::TOO_LARGE_FOLDER);
            $inbox->ensureFolder(self::ARCHIVE_FOLDER);
        } finally {
            $inbox->close();
        }

        foreach (['INBOX', 'Processed', MailboxPollingJob::TOO_LARGE_FOLDER] as $folder) {
            $mailbox = new ImapMailbox($this->config($folder));

            try {
                foreach ($mailbox->search(MailboxSearchCriteria::all()) as $uid) {
                    $mailbox->move($uid, self::ARCHIVE_FOLDER);
                }
            } finally {
                $mailbox->close();
            }
        }
    }

    /**
     * @return list<int>
     */
    private function inboxUids(): array
    {
        $mailbox = new ImapMailbox($this->config());

        try {
            $uids = $mailbox->search(MailboxSearchCriteria::all());
            sort($uids, SORT_NUMERIC);

            return $uids;
        } finally {
            $mailbox->close();
        }
    }

    private function job(
        MailboxSettings $settings,
        GreenMailPollingMessageStore $messages,
        GreenMailPollingCheckpointStore $checkpoints,
        GreenMailPollingHealthStore $health,
        GreenMailPollingFileStorage $files
    ): MailboxPollingJob {
        return new MailboxPollingJob(
            new GreenMailPollingMailboxSettingsStore($settings),
            $checkpoints,
            $messages,
            $files,
            new SourceHealthRecorder($health, new SystemClock()),
            new RawMessageInspector(),
            new AttachmentStoragePolicy(),
            new MessageContentHasher(),
            new SystemClock(),
            fn (MailboxSettings $mailboxSettings): string => $this->password(),
            fn (MailboxSettings $mailboxSettings, string $password): MailboxInterface =>
                new ImapMailbox(new MailboxConnectionConfig(
                    host: $this->host(),
                    port: $this->port(),
                    encryption: MailboxEncryption::NONE,
                    username: $mailboxSettings->username,
                    password: $password,
                    folders: [
                        'inbox' => $mailboxSettings->inboxFolder,
                        'processed' => $mailboxSettings->processedFolder,
                    ],
                    connectTimeout: 5,
                    readTimeout: 5,
                    maxMessageSizeBytes: $mailboxSettings->maxMessageSizeBytes,
                    allowInsecureForTesting: true
                ))
        );
    }

    private function settings(string $processedFolder): MailboxSettings
    {
        return new MailboxSettings(
            'GreenMail poller test mailbox',
            $this->host(),
            $this->port(),
            MailboxEncryption::NONE,
            $this->username(),
            'INBOX',
            $processedFolder,
            self::MAX_MESSAGE_SIZE_BYTES,
            true,
            9,
            self::SOURCE_ID
        );
    }

    private function config(string $folder = 'INBOX'): MailboxConnectionConfig
    {
        return new MailboxConnectionConfig(
            host: $this->host(),
            port: $this->port(),
            encryption: MailboxEncryption::NONE,
            username: $this->username(),
            password: $this->password(),
            folders: ['inbox' => $folder, 'processed' => 'Processed'],
            connectTimeout: 5,
            readTimeout: 5,
            maxMessageSizeBytes: self::MAX_MESSAGE_SIZE_BYTES,
            allowInsecureForTesting: true
        );
    }

    private function waitForGreenMail(): void
    {
        $deadline = microtime(true) + 30;

        do {
            try {
                $mailbox = new ImapMailbox($this->config());
                $mailbox->close();

                return;
            } catch (ConnectionFailed) {
                usleep(250000);
            }
        } while (microtime(true) < $deadline);

        self::fail('GreenMail IMAP did not become ready for the polling user.');
    }

    private function deliverMessage(string $message): void
    {
        $socket = $this->connectToSmtp();

        try {
            $this->expectSmtpCode($socket, '220');
            $this->sendSmtpCommand($socket, 'EHLO example.test', '250');
            $this->sendSmtpCommand($socket, 'MAIL FROM:<notices@example.test>', '250');
            $this->sendSmtpCommand($socket, 'RCPT TO:<' . $this->username() . '>', '250');
            $this->sendSmtpCommand($socket, 'DATA', '354');
            $message = preg_replace('/(?m)^\./', '..', $message) ?? $message;
            self::assertSame(strlen($message . ".\r\n"), fwrite($socket, $message . ".\r\n"));
            $this->expectSmtpCode($socket, '250');
            $this->sendSmtpCommand($socket, 'QUIT', '221');
        } finally {
            fclose($socket);
        }
    }

    private function connectToSmtp()
    {
        $deadline = microtime(true) + 30;
        $port = $this->smtpPort();

        do {
            $errorNumber = 0;
            $errorMessage = '';
            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', $this->host(), $port),
                $errorNumber,
                $errorMessage,
                1,
                STREAM_CLIENT_CONNECT
            );

            if (is_resource($socket)) {
                stream_set_timeout($socket, 5);

                return $socket;
            }

            usleep(250000);
        } while (microtime(true) < $deadline);

        self::fail('GreenMail SMTP did not become ready.');
    }

    private function sendSmtpCommand($socket, string $command, string $expectedCode): void
    {
        self::assertSame(strlen($command . "\r\n"), fwrite($socket, $command . "\r\n"));
        $this->expectSmtpCode($socket, $expectedCode);
    }

    private function expectSmtpCode($socket, string $expectedCode): void
    {
        do {
            $line = fgets($socket);
            self::assertNotFalse($line, 'GreenMail closed the SMTP connection unexpectedly.');
            self::assertSame($expectedCode, substr($line, 0, 3), 'Unexpected GreenMail SMTP response.');
        } while (isset($line[3]) && $line[3] === '-');
    }

    private function multipartMessage(string $messageId, string $body, string $filename): string
    {
        $boundary = 'greenmail-polling-boundary';
        $pdf = "%PDF-1.7\nExample parish event poster\n";
        $lines = [
            'From: Example Notices <notices@example.test>',
            'To: ' . $this->username(),
            'Date: Fri, 25 Sep 2026 04:00:00 +0000',
            'Message-ID: <' . $messageId . '>',
            'Subject: Example event notice',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $body,
            '--' . $boundary,
            'Content-Type: application/pdf; name="' . $filename . '"',
            'Content-Disposition: attachment; filename="' . $filename . '"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode($pdf),
            '--' . $boundary . '--',
            '',
        ];

        return implode("\r\n", $lines);
    }

    private function plainMessage(string $messageId, string $body): string
    {
        return implode("\r\n", [
            'From: Example Notices <notices@example.test>',
            'To: ' . $this->username(),
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

    private function screeningMessage(string $messageId): string
    {
        return implode("\r\n", [
            'From: Example Parish Office <no-reply@example.test>',
            'To: ' . $this->username(),
            'Date: Fri, 25 Sep 2026 04:00:00 +0000',
            'Message-ID: <' . $messageId . '>',
            'Subject: Synthetic automated message',
            'Auto-Submitted: auto-replied',
            'X-Autoreply: yes',
            'Authentication-Results: external.example.test; spf=pass; dkim=pass; dmarc=fail',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'A synthetic automated message used only by GreenMail.',
            '',
        ]);
    }

    private function host(): string
    {
        $host = getenv('IMAP_TEST_HOST');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    private function port(): int
    {
        $port = getenv('IMAP_TEST_PORT');

        return is_string($port) && $port !== '' ? (int) $port : 3143;
    }

    private function smtpPort(): int
    {
        $port = getenv('IMAP_TEST_SMTP_PORT');

        return is_string($port) && $port !== '' ? (int) $port : 3025;
    }

    private function username(): string
    {
        $username = getenv('IMAP_POLL_TEST_USERNAME');

        return is_string($username) && $username !== '' ? $username : 'polling@example.test';
    }

    private function password(): string
    {
        $password = getenv('IMAP_TEST_PASSWORD');

        return is_string($password) && $password !== '' ? $password : 'greenmail-test-password';
    }
}

final class GreenMailPollingMailboxSettingsStore implements MailboxSettingsStoreInterface
{
    public function __construct(private MailboxSettings $settings)
    {
    }

    public function findActiveMailboxes(): array
    {
        return [$this->settings];
    }
}

final class GreenMailPollingCheckpointStore implements MailboxCheckpointStoreInterface
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

final class GreenMailPollingMessageStore implements InboundMessageStoreInterface
{
    /** @var list<InboundMessageRecord> */
    public array $records = [];

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
        $duplicate = $this->findDuplicate($message->sourceId, $message->externalId, $message->contentHash);

        if ($duplicate !== null) {
            return new InboundMessageStoreResult($duplicate, true);
        }

        $this->records[] = $message;

        return new InboundMessageStoreResult(count($this->records), false);
    }
}

final class GreenMailPollingFileStorage implements InboundMailStorageInterface
{
    /** @var array<string, string> */
    public array $files = [];

    public function storeRawMessage(string $rawMessage): string
    {
        return $this->store($rawMessage, 'eml');
    }

    public function storeAttachment(string $content, string $extension): string
    {
        return $this->store($content, $extension);
    }

    public function delete(string $relativePath): void
    {
        unset($this->files[$relativePath]);
    }

    private function store(string $content, string $extension): string
    {
        $path = 'greenmail-' . (count($this->files) + 1) . '.' . $extension;
        $this->files[$path] = $content;

        return $path;
    }
}

final class GreenMailPollingHealthStore implements SourceHealthStoreInterface
{
    public ?SourceHealthState $state = null;

    public function findHealth(int $sourceId): ?SourceHealthState
    {
        return $sourceId === GreenMailMailboxPollingTest::SOURCE_ID
            ? ($this->state ?? new SourceHealthState(SourceStatus::ACTIVE, null, null, null, 0, null))
            : null;
    }

    public function saveHealthIfUnchanged(
        int $sourceId,
        SourceHealthState $expected,
        SourceHealthState $replacement,
        string $updatedAt
    ): bool {
        $current = $this->findHealth($sourceId);

        if ($current === null || ! $current->equals($expected)) {
            return false;
        }

        $this->state = $replacement;

        return true;
    }
}

final class GreenMailPollingJobLock implements JobLockInterface
{
    private bool $held = false;

    public function acquire(string $jobId, \DateTimeImmutable $now, int $expiresInSeconds): ?string
    {
        if ($this->held) {
            return null;
        }

        $this->held = true;

        return 'polling-job-lock';
    }

    public function isHeldBy(string $jobId, string $token, \DateTimeImmutable $now): bool
    {
        return $this->held && $token === 'polling-job-lock';
    }

    public function release(string $jobId, string $token): bool
    {
        if (! $this->isHeldBy($jobId, $token, new \DateTimeImmutable())) {
            return false;
        }

        $this->held = false;

        return true;
    }
}

final class GreenMailPollingJobStateStore implements JobStateStoreInterface
{
    /** @var array<string, JobState> */
    private array $states = [];

    public function load(string $jobId): JobState
    {
        return $this->states[$jobId] ?? JobState::empty();
    }

    public function save(string $jobId, JobState $state): void
    {
        $this->states[$jobId] = $state;
    }
}
