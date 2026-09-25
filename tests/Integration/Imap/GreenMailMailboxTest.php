<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Integration\Imap;

use ADCT\ParishIntake\Core\Ingestion\Imap\ImapMailbox;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxConnectionConfig;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('greenmail')]
final class GreenMailMailboxTest extends TestCase
{
    public function testSmtpDeliveryCanBeSearchedFetchedFiledAndMovedOverImap(): void
    {
        $this->deliverMessage();
        $mailbox = new ImapMailbox($this->config());
        $uid = 0;

        try {
            $unseen = $mailbox->search(MailboxSearchCriteria::unseen());
            self::assertNotSame([], $unseen, 'GreenMail did not expose the delivered test message.');
            $uid = end($unseen);
            self::assertIsInt($uid);

            $message = $mailbox->fetch($uid);
            self::assertSame($uid, $message->uid);
            self::assertSame(strlen($message->raw), $message->size);
            self::assertStringContainsString('Subject: GreenMail adapter test', $message->raw);
            self::assertStringContainsString('Example test message.', $message->raw);

            $mailbox->ensureFolder('Processed');
            self::assertContains('Processed', $mailbox->listFolders());

            $mailbox->markSeen($uid);
            self::assertNotContains($uid, $mailbox->search(MailboxSearchCriteria::unseen()));
            $mailbox->move($uid, 'Processed');
            self::assertNotContains($uid, $mailbox->search(MailboxSearchCriteria::all()));
        } finally {
            $mailbox->close();
        }

        $processedMailbox = new ImapMailbox($this->config('Processed'));

        try {
            self::assertContains($uid, $processedMailbox->search(MailboxSearchCriteria::all()));
        } finally {
            $processedMailbox->close();
        }
    }

    private function config(string $folder = 'INBOX'): MailboxConnectionConfig
    {
        $host = getenv('IMAP_TEST_HOST');
        $port = getenv('IMAP_TEST_PORT');
        $username = getenv('IMAP_TEST_USERNAME');
        $password = getenv('IMAP_TEST_PASSWORD');

        return new MailboxConnectionConfig(
            host: is_string($host) && $host !== '' ? $host : '127.0.0.1',
            port: is_string($port) && $port !== '' ? (int) $port : 3143,
            encryption: MailboxEncryption::NONE,
            username: is_string($username) && $username !== '' ? $username : 'intake@example.test',
            password: is_string($password) && $password !== '' ? $password : 'greenmail-test-password',
            folders: ['inbox' => $folder, 'processed' => 'Processed'],
            connectTimeout: 5,
            readTimeout: 5,
            allowInsecureForTesting: true
        );
    }

    private function deliverMessage(): void
    {
        $host = getenv('IMAP_TEST_HOST');
        $port = getenv('IMAP_TEST_SMTP_PORT');
        $host = is_string($host) && $host !== '' ? $host : '127.0.0.1';
        $port = is_string($port) && $port !== '' ? (int) $port : 3025;
        $socket = $this->connectToSmtp($host, $port);

        try {
            $this->expectSmtpCode($socket, '220');
            $this->sendSmtpCommand($socket, 'EHLO example.test', '250');
            $this->sendSmtpCommand($socket, 'MAIL FROM:<notices@example.test>', '250');
            $this->sendSmtpCommand($socket, 'RCPT TO:<intake@example.test>', '250');
            $this->sendSmtpCommand($socket, 'DATA', '354');

            $raw = implode("\r\n", [
                'From: notices@example.test',
                'To: intake@example.test',
                'Date: Fri, 25 Sep 2026 04:00:00 +0000',
                'Message-ID: <greenmail-adapter-test@example.test>',
                'Subject: GreenMail adapter test',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                '',
                'Example test message.',
                '',
            ]);
            $raw = preg_replace('/(?m)^\./', '..', $raw) ?? $raw;
            self::assertSame(strlen($raw . ".\r\n"), fwrite($socket, $raw . ".\r\n"));
            $this->expectSmtpCode($socket, '250');
            $this->sendSmtpCommand($socket, 'QUIT', '221');
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return resource
     */
    private function connectToSmtp(string $host, int $port)
    {
        $deadline = microtime(true) + 30;
        $lastError = '';

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
                stream_set_timeout($socket, 5);

                return $socket;
            }

            $lastError = $errorMessage;
            usleep(250000);
        }

        self::fail('GreenMail SMTP did not become ready: ' . $lastError);
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
}
