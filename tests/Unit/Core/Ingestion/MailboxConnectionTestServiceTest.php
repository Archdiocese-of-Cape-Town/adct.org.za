<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\Imap\AuthenticationFailed;
use ADCT\ParishIntake\Core\Ingestion\Imap\ConnectionFailed;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxConnectionConfig;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxException;
use ADCT\ParishIntake\Core\Ingestion\Imap\ProtocolError;
use ADCT\ParishIntake\Core\Ingestion\Imap\Timeout;
use ADCT\ParishIntake\Core\Ingestion\Imap\TlsFailed;
use ADCT\ParishIntake\Core\Ingestion\MailboxConnectionTestService;
use ADCT\ParishIntake\Core\Ingestion\MailboxConnectionTestStatus;
use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Ports\SourceHealthStoreInterface;
use ADCT\ParishIntake\Core\Sources\SourceHealthRecorder;
use ADCT\ParishIntake\Core\Sources\SourceHealthState;
use ADCT\ParishIntake\Core\Sources\SourceStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailboxConnectionTestServiceTest extends TestCase
{
    #[DataProvider('failureCases')]
    public function testTypedImapFailuresProduceSafeMessagesAndRecordHealth(
        MailboxException $failure,
        MailboxConnectionTestStatus $status,
        string $message,
        string $healthError
    ): void {
        $health = new MailboxTestHealthStore();
        $service = new MailboxConnectionTestService(
            new SourceHealthRecorder($health, new MailboxTestClock()),
            static function (MailboxConnectionConfig $config) use ($failure): MailboxInterface {
                throw $failure;
            }
        );

        $result = $service->test($this->settings(), 'imap-test-DO-NOT-ECHO-456');

        self::assertSame($status, $result->status);
        self::assertSame($message, $result->message());
        self::assertNotNull($health->state);
        self::assertSame(1, $health->state->consecutiveFailures);
        self::assertSame($healthError, $health->state->lastError);
        self::assertStringNotContainsString('imap-test-DO-NOT-ECHO-456', (string) $health->state->lastError);
        self::assertStringNotContainsString('server-private-response', (string) $health->state->lastError);
    }

    public function testFailureMessageDoesNotExposePasswordOrRawServerResponse(): void
    {
        $password = 'imap-test-DO-NOT-ECHO-456';
        $rawServerResponse = 'AUTH failed for ' . $password . '; server-private-response';
        $health = new MailboxTestHealthStore();
        $service = new MailboxConnectionTestService(
            new SourceHealthRecorder($health, new MailboxTestClock()),
            static function (MailboxConnectionConfig $config) use ($rawServerResponse): MailboxInterface {
                throw new ProtocolError($rawServerResponse);
            }
        );

        $result = $service->test($this->settings(), $password);

        self::assertSame(MailboxConnectionTestStatus::PROTOCOL_ERROR, $result->status);
        self::assertSame('The server returned an unexpected response', $result->message());
        self::assertStringNotContainsString($password, $result->message());
        self::assertStringNotContainsString($rawServerResponse, $result->message());
        self::assertNotNull($health->state);
        self::assertStringNotContainsString($password, (string) $health->state->lastError);
        self::assertStringNotContainsString($rawServerResponse, (string) $health->state->lastError);
    }

    public static function failureCases(): array
    {
        return [
            'authentication' => [
                new AuthenticationFailed(),
                MailboxConnectionTestStatus::AUTHENTICATION_FAILED,
                'Wrong username or password',
                'Mailbox test failed: authentication was rejected.',
            ],
            'connection' => [
                new ConnectionFailed(),
                MailboxConnectionTestStatus::CONNECTION_FAILED,
                "Can't reach the server - check host and port",
                'Mailbox test failed: the server could not be reached.',
            ],
            'tls' => [
                new TlsFailed(),
                MailboxConnectionTestStatus::TLS_FAILED,
                    'Secure connection failed - check the encryption setting or certificate. '
                    . 'Use the mail server name shown on the certificate (for xneelo, the server hostname from your hosting control panel) instead of a custom alias.',
                'Mailbox test failed: the secure connection could not be verified.',
            ],
            'timeout' => [
                new Timeout(),
                MailboxConnectionTestStatus::TIMEOUT,
                'The server took too long to respond',
                'Mailbox test failed: the server timed out.',
            ],
            'protocol' => [
                new ProtocolError('server-private-response: password=imap-test-DO-NOT-ECHO-456'),
                MailboxConnectionTestStatus::PROTOCOL_ERROR,
                'The server returned an unexpected response',
                'Mailbox test failed: the server returned an unexpected response.',
            ],
        ];
    }

    public function testSuccessCountsUnseenMessagesClosesTheMailboxAndRecordsSuccess(): void
    {
        $health = new MailboxTestHealthStore();
        $mailbox = new FakeMailboxConnection(['INBOX', 'Processed'], [12, 13]);
        $receivedConfig = null;
        $service = new MailboxConnectionTestService(
            new SourceHealthRecorder($health, new MailboxTestClock()),
            static function (MailboxConnectionConfig $config) use ($mailbox, &$receivedConfig): MailboxInterface {
                $receivedConfig = $config;

                return $mailbox;
            }
        );

        $result = $service->test($this->settings(), 'imap-test-DO-NOT-ECHO-456');

        self::assertSame(MailboxConnectionTestStatus::CONNECTED, $result->status);
        self::assertSame('Connected - 2 messages waiting', $result->message());
        self::assertCount(1, $mailbox->searchCriteria);
        self::assertTrue($mailbox->searchCriteria[0]->unseen);
        self::assertTrue($mailbox->closed);
        self::assertNotNull($receivedConfig);
        self::assertSame(10, $receivedConfig->connectTimeout);
        self::assertSame(10, $receivedConfig->readTimeout);
        self::assertSame('imap-test-DO-NOT-ECHO-456', $receivedConfig->password);
        self::assertNotNull($health->state);
        self::assertSame('2026-09-25 00:03:04', $health->state->lastSuccessAt);
        self::assertSame(0, $health->state->consecutiveFailures);
        self::assertNull($health->state->lastError);
    }

    public function testMissingProcessedFolderRecordsFailureAndCanBeCreatedOnASecondAction(): void
    {
        $health = new MailboxTestHealthStore();
        $mailbox = new FakeMailboxConnection(['INBOX'], [1]);
        $service = new MailboxConnectionTestService(
            new SourceHealthRecorder($health, new MailboxTestClock()),
            static fn (MailboxConnectionConfig $config): MailboxInterface => $mailbox
        );

        $missing = $service->test($this->settings(), 'password');

        self::assertSame(MailboxConnectionTestStatus::PROCESSED_FOLDER_MISSING, $missing->status);
        self::assertSame('Processed folder "Processed" does not exist', $missing->message());
        self::assertTrue($missing->needsProcessedFolder());
        self::assertSame(1, $health->state?->consecutiveFailures);
        self::assertTrue($mailbox->closed);

        $mailbox->closed = false;
        $created = $service->createProcessedFolder($this->settings(), 'password');

        self::assertSame(MailboxConnectionTestStatus::PROCESSED_FOLDER_CREATED, $created->status);
        self::assertSame('Connected - 1 message waiting. Processed folder "Processed" created', $created->message());
        self::assertSame(['INBOX', 'Processed'], $mailbox->folders);
        self::assertSame(0, $health->state?->consecutiveFailures);
        self::assertNull($health->state?->lastError);
    }

    public function testMissingPasswordIsReportedWithoutOpeningAMailbox(): void
    {
        $health = new MailboxTestHealthStore();
        $factoryCalled = false;
        $service = new MailboxConnectionTestService(
            new SourceHealthRecorder($health, new MailboxTestClock()),
            static function (MailboxConnectionConfig $config) use (&$factoryCalled): MailboxInterface {
                $factoryCalled = true;

                return new FakeMailboxConnection([]);
            }
        );

        $result = $service->test($this->settings(), '');

        self::assertSame(MailboxConnectionTestStatus::PASSWORD_MISSING, $result->status);
        self::assertFalse($factoryCalled);
        self::assertSame(1, $health->state?->consecutiveFailures);
    }

    private function settings(): MailboxSettings
    {
        return new MailboxSettings(
            'Events',
            'imap.example.test',
            993,
            MailboxEncryption::SSL,
            'events@example.test',
            'INBOX',
            'Processed',
            30 * 1024 * 1024,
            true,
            4,
            17
        );
    }
}

final class FakeMailboxConnection implements MailboxInterface
{
    /**
     * @param list<string> $folders
     * @param list<int> $unseen
     */
    public function __construct(
        public array $folders,
        private array $unseen = [],
        public bool $closed = false
    ) {
    }

    /** @var list<MailboxSearchCriteria> */
    public array $searchCriteria = [];

    public function listFolders(): array
    {
        return $this->folders;
    }

    public function ensureFolder(string $folder): void
    {
        if (! in_array($folder, $this->folders, true)) {
            $this->folders[] = $folder;
        }
    }

    public function search(MailboxSearchCriteria $criteria): array
    {
        $this->searchCriteria[] = $criteria;

        return $this->unseen;
    }

    public function fetch(int $uid): \ADCT\ParishIntake\Core\Ingestion\RawMailMessage
    {
        throw new \LogicException('The connection test must not fetch message bodies.');
    }

    public function move(int $uid, string $folder): void
    {
        throw new \LogicException('The connection test must not move messages.');
    }

    public function markSeen(int $uid): void
    {
        throw new \LogicException('The connection test must not mark messages as seen.');
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class MailboxTestHealthStore implements SourceHealthStoreInterface
{
    public ?SourceHealthState $state;

    public function __construct()
    {
        $this->state = new SourceHealthState(SourceStatus::ACTIVE, null, null, null, 0, null);
    }

    public function findHealth(int $sourceId): ?SourceHealthState
    {
        return $sourceId === 17 ? $this->state : null;
    }

    public function saveHealthIfUnchanged(
        int $sourceId,
        SourceHealthState $expected,
        SourceHealthState $replacement,
        string $updatedAt
    ): bool {
        if ($sourceId !== 17 || $this->state === null || ! $this->state->equals($expected)) {
            return false;
        }

        $this->state = $replacement;

        return true;
    }
}

final class MailboxTestClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-25 02:03:04', new DateTimeZone('Africa/Johannesburg'));
    }
}
