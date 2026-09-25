<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Integration\Imap;

use ADCT\ParishIntake\Core\Ingestion\Imap\ConnectionFailed;
use ADCT\ParishIntake\Core\Ingestion\Imap\ImapMailbox;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxConnectionConfig;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\Imap\Timeout;
use ADCT\ParishIntake\Core\Ingestion\MailboxConnectionTestService;
use ADCT\ParishIntake\Core\Ingestion\MailboxConnectionTestStatus;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Ports\SourceHealthStoreInterface;
use ADCT\ParishIntake\Core\Sources\SourceHealthRecorder;
use ADCT\ParishIntake\Core\Sources\SourceHealthState;
use ADCT\ParishIntake\Core\Sources\SourceStatus;
use ADCT\ParishIntake\Core\Support\SystemClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('greenmail')]
final class GreenMailConnectionTestServiceTest extends TestCase
{
    public function testConnectionServiceConnectsToGreenMailAndRecordsSuccess(): void
    {
        $this->deliverTestMessage();
        $this->waitForGreenMail();
        $settings = $this->settings('Processed-Connection-Test');
        $setupMailbox = new ImapMailbox($this->config());

        try {
            $setupMailbox->ensureFolder($settings->processedFolder);
        } finally {
            $setupMailbox->close();
        }

        $health = new GreenMailConnectionHealthStore();
        $result = $this->service($health)->test($settings, $this->password());

        self::assertSame(MailboxConnectionTestStatus::CONNECTED, $result->status);
        self::assertStringStartsWith('Connected - ', $result->message());
        self::assertGreaterThanOrEqual(1, $result->waitingCount);
        self::assertNotNull($health->state);
        self::assertNotNull($health->state->lastSuccessAt);
        self::assertSame(0, $health->state->consecutiveFailures);
        self::assertNull($health->state->lastError);
    }

    public function testConnectionServiceMapsWrongPasswordFromGreenMail(): void
    {
        $this->deliverTestMessage();
        $this->waitForGreenMail();
        $health = new GreenMailConnectionHealthStore();
        $result = $this->service($health)->test(
            $this->settings('Processed-Wrong-Password'),
            'not-the-greenmail-test-password'
        );

        self::assertSame(MailboxConnectionTestStatus::AUTHENTICATION_FAILED, $result->status);
        self::assertSame('Wrong username or password', $result->message());
        self::assertSame(1, $health->state?->consecutiveFailures);
        self::assertSame(
            'Mailbox test failed: authentication was rejected.',
            $health->state?->lastError
        );
    }

    public function testConnectionServiceReportsMissingProcessedFolderFromGreenMail(): void
    {
        $this->deliverTestMessage();
        $this->waitForGreenMail();
        $folder = 'Processed-Missing-' . bin2hex(random_bytes(6));
        $health = new GreenMailConnectionHealthStore();
        $result = $this->service($health)->test($this->settings($folder), $this->password());

        self::assertSame(MailboxConnectionTestStatus::PROCESSED_FOLDER_MISSING, $result->status);
        self::assertSame('Processed folder "' . $folder . '" does not exist', $result->message());
        self::assertSame(1, $health->state?->consecutiveFailures);
        self::assertSame(
            'Mailbox test failed: the processed folder is missing.',
            $health->state?->lastError
        );
    }

    private function service(GreenMailConnectionHealthStore $health): MailboxConnectionTestService
    {
        return new MailboxConnectionTestService(
            new SourceHealthRecorder($health, new SystemClock()),
            static fn (MailboxConnectionConfig $config): MailboxInterface => new ImapMailbox($config),
            true
        );
    }

    private function settings(string $processedFolder): MailboxSettings
    {
        return new MailboxSettings(
            'GreenMail test mailbox',
            $this->host(),
            $this->port(),
            MailboxEncryption::NONE,
            $this->username(),
            'INBOX',
            $processedFolder,
            30 * 1024 * 1024,
            true,
            4,
            17
        );
    }

    private function config(): MailboxConnectionConfig
    {
        return new MailboxConnectionConfig(
            host: $this->host(),
            port: $this->port(),
            encryption: MailboxEncryption::NONE,
            username: $this->username(),
            password: $this->password(),
            connectTimeout: 5,
            readTimeout: 5,
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
            } catch (ConnectionFailed | Timeout) {
                usleep(250000);
            }
        } while (microtime(true) < $deadline);

        self::fail('GreenMail IMAP did not become ready.');
    }

    private function deliverTestMessage(): void
    {
        $host = $this->host();
        $port = getenv('IMAP_TEST_SMTP_PORT');
        $port = is_string($port) && $port !== '' ? (int) $port : 3025;
        $deadline = microtime(true) + 30;
        $socket = false;

        while (microtime(true) < $deadline) {
            $errorNumber = 0;
            $errorMessage = '';
            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', $host, $port),
                $errorNumber,
                $errorMessage,
                1,
                STREAM_CLIENT_CONNECT
            );

            if (is_resource($socket)) {
                break;
            }

            usleep(250000);
        }

        self::assertIsResource($socket, 'GreenMail SMTP did not become ready.');
        stream_set_timeout($socket, 5);

        try {
            $this->expectSmtpCode($socket, '220');
            $this->sendSmtpCommand($socket, 'EHLO example.test', '250');
            $this->sendSmtpCommand($socket, 'MAIL FROM:<notices@example.test>', '250');
            $this->sendSmtpCommand($socket, 'RCPT TO:<' . $this->username() . '>', '250');
            $this->sendSmtpCommand($socket, 'DATA', '354');

            $message = implode("\r\n", [
                'From: notices@example.test',
                'To: ' . $this->username(),
                'Date: Fri, 25 Sep 2026 04:00:00 +0000',
                'Message-ID: <greenmail-connection-' . bin2hex(random_bytes(4)) . '@example.test>',
                'Subject: GreenMail connection-test fixture',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                '',
                'Invented integration-test message.',
                '',
            ]);
            $message = preg_replace('/(?m)^\./', '..', $message) ?? $message;
            self::assertSame(strlen($message . ".\r\n"), fwrite($socket, $message . ".\r\n"));
            $this->expectSmtpCode($socket, '250');
            $this->sendSmtpCommand($socket, 'QUIT', '221');
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     */
    private function sendSmtpCommand($socket, string $command, string $expectedCode): void
    {
        self::assertSame(strlen($command . "\r\n"), fwrite($socket, $command . "\r\n"));
        $this->expectSmtpCode($socket, $expectedCode);
    }

    /**
     * @param resource $socket
     */
    private function expectSmtpCode($socket, string $expectedCode): void
    {
        do {
            $line = fgets($socket);
            self::assertNotFalse($line, 'GreenMail closed the SMTP connection unexpectedly.');
            self::assertSame($expectedCode, substr($line, 0, 3), 'Unexpected GreenMail SMTP response.');
        } while (isset($line[3]) && $line[3] === '-');
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

    private function username(): string
    {
        $username = getenv('IMAP_TEST_USERNAME');

        return is_string($username) && $username !== '' ? $username : 'intake@example.test';
    }

    private function password(): string
    {
        $password = getenv('IMAP_TEST_PASSWORD');

        return is_string($password) && $password !== '' ? $password : 'greenmail-test-password';
    }
}

final class GreenMailConnectionHealthStore implements SourceHealthStoreInterface
{
    public ?SourceHealthState $state = null;

    public function findHealth(int $sourceId): ?SourceHealthState
    {
        if ($sourceId !== 17) {
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
        if ($sourceId !== 17) {
            return false;
        }

        $current = $this->state ?? new SourceHealthState(SourceStatus::ACTIVE, null, null, null, 0, null);

        if (! $current->equals($expected)) {
            return false;
        }

        $this->state = $replacement;

        return true;
    }
}
