<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ingestion\Imap;

use ADCT\ParishIntake\Core\Ingestion\Imap\AuthenticationFailed;
use ADCT\ParishIntake\Core\Ingestion\Imap\ConnectionFailed;
use ADCT\ParishIntake\Core\Ingestion\Imap\ImapMailbox;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxConnectionConfig;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\Imap\MessageTooLarge;
use ADCT\ParishIntake\Core\Ingestion\Imap\ProtocolError;
use ADCT\ParishIntake\Core\Ingestion\Imap\TlsFailed;
use ADCT\ParishIntake\Core\Ingestion\Imap\Timeout;
use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ImapMailboxTest extends TestCase
{
    public function testSearchParsesUntaggedResultsAndBuildsSupportedCriteria(): void
    {
        $transport = new ScriptedTransport(
            $this->successfulHandshake()
            . "* 2 EXISTS\r\nA0003 OK SELECT completed\r\n"
            . "* SEARCH 7 9\r\nA0004 OK SEARCH completed\r\n"
            . "* SEARCH\r\nA0005 OK SEARCH completed\r\n"
            . "* SEARCH 7 9\r\nA0006 OK SEARCH completed\r\n"
        );
        $mailbox = new ImapMailbox($this->config(), $transport);

        self::assertSame(
            [7, 9],
            $mailbox->search(new MailboxSearchCriteria(
                unseen: true,
                since: new DateTimeImmutable('2026-10-07T18:00:00+02:00')
            ))
        );
        self::assertSame([], $mailbox->search(MailboxSearchCriteria::all()));
        self::assertSame([7, 9], $mailbox->search(MailboxSearchCriteria::unseen()));
        self::assertSame("A0004 UID SEARCH UNSEEN SINCE 07-Oct-2026\r\n", $transport->writes[3]);
        self::assertSame("A0005 UID SEARCH ALL\r\n", $transport->writes[4]);
    }

    public function testListsFoldersAndCreatesAMissingFolder(): void
    {
        $listTransport = new ScriptedTransport(
            $this->successfulHandshake()
            . "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n"
            . "* LIST (\\HasNoChildren) \"/\" \"Parish Inbox\"\r\n"
            . "A0003 OK LIST completed\r\n"
        );
        $mailbox = new ImapMailbox($this->config(), $listTransport);

        self::assertSame(['INBOX', 'Parish Inbox'], $mailbox->listFolders());
        self::assertSame("A0003 LIST \"\" \"*\"\r\n", $listTransport->writes[2]);

        $createTransport = new ScriptedTransport(
            $this->successfulHandshake()
            . "A0003 OK LIST completed\r\n"
            . "A0004 OK CREATE completed\r\n"
        );
        $mailbox = new ImapMailbox($this->config(), $createTransport);
        $mailbox->ensureFolder('Processed');

        self::assertSame("A0004 CREATE \"Processed\"\r\n", $createTransport->writes[3]);
    }

    public function testEmptyFolderNamesAreRejected(): void
    {
        $mailbox = new ImapMailbox(
            $this->config(),
            new ScriptedTransport($this->successfulHandshake())
        );

        $this->expectException(InvalidArgumentException::class);
        $mailbox->ensureFolder(' ');
    }

    public function testEscapesQuotesAndBackslashesInLoginCredentials(): void
    {
        $transport = new ScriptedTransport($this->successfulHandshake());
        $mailbox = new ImapMailbox(
            $this->config(username: 'user"\\name', password: 'pa"\\ss'),
            $transport
        );

        self::assertSame('A0001 LOGIN "user\"\\\\name" "pa\"\\\\ss"' . "\r\n", $transport->writes[0]);
        self::assertNotNull($mailbox);
    }

    public function testAuthenticationFailureDoesNotExposeThePassword(): void
    {
        $transport = new ScriptedTransport(
            "* OK ready\r\nA0001 NO [AUTHENTICATIONFAILED] credentials rejected\r\n"
        );

        try {
            new ImapMailbox($this->config(password: 'secret-not-for-logs'), $transport);
            self::fail('Expected invalid mailbox credentials to fail.');
        } catch (AuthenticationFailed $exception) {
            self::assertStringNotContainsString('secret-not-for-logs', $exception->getMessage());
            self::assertTrue($transport->closed);
        }
    }

    public function testMailboxPasswordIsRedactedFromDebugOutput(): void
    {
        $password = 'test-secret-not-for-debug';
        $config = $this->config(password: $password);

        ob_start();
        var_dump($config);
        $debugOutput = (string) ob_get_clean();

        self::assertStringNotContainsString($password, $debugOutput);
        self::assertStringNotContainsString($password, print_r($config, true));
        self::assertStringContainsString('[redacted]', $debugOutput);
    }

    public function testBadCommandStatusBecomesPlainLanguageProtocolError(): void
    {
        $transport = new ScriptedTransport(
            $this->successfulHandshake()
            . "A0003 BAD unsupported LIST\r\n"
        );
        $mailbox = new ImapMailbox($this->config(), $transport);

        try {
            $mailbox->listFolders();
            self::fail('Expected the unsupported command to fail.');
        } catch (ProtocolError $exception) {
            self::assertStringNotContainsString('unsupported LIST', $exception->getMessage());
            self::assertStringContainsString('mailbox', strtolower($exception->getMessage()));
        }
    }

    public function testNoCommandStatusBecomesProtocolError(): void
    {
        $transport = new ScriptedTransport(
            $this->successfulHandshake()
            . "A0003 NO [NOPERM] not allowed\r\n"
        );
        $mailbox = new ImapMailbox($this->config(), $transport);

        $this->expectException(ProtocolError::class);
        $mailbox->listFolders();
    }

    public function testFetchReadsACompleteRawLiteralAndMetadata(): void
    {
        $raw = "From: notices@example.test\r\nSubject: Scripted event\r\n\r\nA raw body.\r\n";
        $size = strlen($raw);
        $metadata = sprintf(
            '* 1 FETCH (UID 42 RFC822.SIZE %d INTERNALDATE "07-Oct-2026 09:30:00 +0200" FLAGS (\\Seen))',
            $size
        );
        $body = sprintf(
            '* 1 FETCH (UID 42 RFC822.SIZE %d INTERNALDATE "07-Oct-2026 09:30:00 +0200" FLAGS (\\Seen) BODY[] {%d}',
            $size,
            $size
        ) . "\r\n" . $raw . ")\r\n";
        $transport = new ScriptedTransport(
            $this->successfulHandshake()
            . "A0003 OK SELECT completed\r\n"
            . $metadata . "\r\nA0004 OK FETCH metadata completed\r\n"
            . $body . "A0005 OK FETCH body completed\r\n"
        );
        $mailbox = new ImapMailbox($this->config(), $transport);

        $message = $mailbox->fetch(42);

        self::assertSame(42, $message->uid);
        self::assertSame($raw, $message->raw);
        self::assertSame($size, $message->size);
        self::assertSame(['\\Seen'], $message->flags);
        self::assertSame('2026-10-07T09:30:00+02:00', $message->internalDate->format('Y-m-d\TH:i:sP'));
        self::assertSame("A0004 UID FETCH 42 (UID RFC822.SIZE INTERNALDATE FLAGS)\r\n", $transport->writes[3]);
        self::assertSame(
            "A0005 UID FETCH 42 (UID RFC822.SIZE INTERNALDATE FLAGS BODY.PEEK[])\r\n",
            $transport->writes[4]
        );
    }

    public function testOversizedMessageIsRejectedBeforeItsBodyIsFetched(): void
    {
        $transport = new ScriptedTransport(
            $this->successfulHandshake()
            . "A0003 OK SELECT completed\r\n"
            . "* 1 FETCH (UID 43 RFC822.SIZE 21 INTERNALDATE \"07-Oct-2026 09:30:00 +0200\" FLAGS ())\r\n"
            . "A0004 OK FETCH metadata completed\r\n"
        );
        $mailbox = new ImapMailbox($this->config(maxMessageSizeBytes: 20), $transport);

        try {
            $mailbox->fetch(43);
            self::fail('Expected the oversized message to be skipped.');
        } catch (MessageTooLarge $exception) {
            self::assertSame(21, $exception->sizeBytes);
            self::assertSame(20, $exception->maxBytes);
            self::assertStringContainsString('limit', strtolower($exception->getMessage()));
            self::assertStringContainsString('20 bytes', $exception->getMessage());
        }

        self::assertStringNotContainsString('BODY.PEEK[]', implode('', $transport->writes));
    }

    public function testOversizedLiteralClosesTheDesynchronizedConnection(): void
    {
        $transport = new ScriptedTransport(
            $this->successfulHandshake()
            . "A0003 OK SELECT completed\r\n"
            . "* 1 FETCH (UID 44 RFC822.SIZE 20 INTERNALDATE \"07-Oct-2026 09:30:00 +0200\" FLAGS ())\r\n"
            . "A0004 OK FETCH metadata completed\r\n"
            . "* 1 FETCH (UID 44 RFC822.SIZE 20 INTERNALDATE \"07-Oct-2026 09:30:00 +0200\" FLAGS () BODY[] {21}\r\n"
            . str_repeat('x', 21)
            . ")\r\nA0005 OK FETCH body completed\r\n"
        );
        $mailbox = new ImapMailbox($this->config(maxMessageSizeBytes: 20), $transport);

        try {
            $mailbox->fetch(44);
            self::fail('Expected the oversized literal to be rejected.');
        } catch (MessageTooLarge $exception) {
            self::assertSame(21, $exception->sizeBytes);
            self::assertTrue($transport->closed);
        }
    }

    public function testUsesUidMoveWhenServerAdvertisesMove(): void
    {
        $transport = new ScriptedTransport(
            $this->successfulHandshake('UIDPLUS MOVE')
            . "A0003 OK SELECT completed\r\nA0004 OK MOVE completed\r\n"
        );
        $mailbox = new ImapMailbox($this->config(), $transport);
        $mailbox->move(77, 'Processed');

        self::assertSame("A0004 UID MOVE 77 \"Processed\"\r\n", $transport->writes[3]);
        self::assertStringNotContainsString('UID COPY', implode('', $transport->writes));
    }

    public function testUsesUidExpungeFallbackWhenMoveIsUnavailableButUidplusExists(): void
    {
        $transport = new ScriptedTransport(
            $this->successfulHandshake('UIDPLUS')
            . "A0003 OK SELECT completed\r\n"
            . "A0004 OK COPY completed\r\n"
            . "A0005 OK STORE completed\r\n"
            . "A0006 OK EXPUNGE completed\r\n"
        );
        $mailbox = new ImapMailbox($this->config(), $transport);
        $mailbox->move(77, 'Processed');

        self::assertSame("A0004 UID COPY 77 \"Processed\"\r\n", $transport->writes[3]);
        self::assertSame("A0005 UID STORE 77 +FLAGS.SILENT (\\Deleted)\r\n", $transport->writes[4]);
        self::assertSame("A0006 UID EXPUNGE 77\r\n", $transport->writes[5]);
    }

    public function testUsesMailboxExpungeOnlyWhenUidplusIsUnavailable(): void
    {
        $transport = new ScriptedTransport(
            $this->successfulHandshake()
            . "A0003 OK SELECT completed\r\n"
            . "A0004 OK COPY completed\r\n"
            . "A0005 OK STORE completed\r\n"
            . "A0006 OK EXPUNGE completed\r\n"
        );
        $mailbox = new ImapMailbox($this->config(), $transport);
        $mailbox->move(77, 'Processed');

        self::assertSame("A0006 EXPUNGE\r\n", $transport->writes[5]);
    }

    public function testMarksMessageSeen(): void
    {
        $transport = new ScriptedTransport(
            $this->successfulHandshake()
            . "A0003 OK SELECT completed\r\nA0004 OK STORE completed\r\n"
        );
        $mailbox = new ImapMailbox($this->config(), $transport);
        $mailbox->markSeen(5);

        self::assertSame("A0004 UID STORE 5 +FLAGS.SILENT (\\Seen)\r\n", $transport->writes[3]);
    }

    public function testStartTlsIsRequiredAndPeerVerificationStaysEnabled(): void
    {
        $transport = new ScriptedTransport(
            "* OK ready\r\n"
            . "* CAPABILITY IMAP4rev1 STARTTLS\r\nA0001 OK capabilities\r\n"
            . "A0002 OK TLS started\r\n"
            . "A0003 OK login\r\n"
            . "* CAPABILITY IMAP4rev1\r\nA0004 OK capabilities\r\n"
        );
        $mailbox = new ImapMailbox(
            $this->config(encryption: MailboxEncryption::STARTTLS),
            $transport
        );

        self::assertTrue($transport->tlsEnabled);
        self::assertTrue($transport->config?->verifyPeer);
        self::assertSame("A0002 STARTTLS\r\n", $transport->writes[1]);
        self::assertNotNull($mailbox);
    }

    public function testStartTlsFailsWhenTheServerDoesNotAdvertiseIt(): void
    {
        $transport = new ScriptedTransport(
            "* OK ready\r\n* CAPABILITY IMAP4rev1\r\nA0001 OK capabilities\r\n"
        );

        try {
            new ImapMailbox($this->config(encryption: MailboxEncryption::STARTTLS), $transport);
            self::fail('Expected the mailbox to require STARTTLS.');
        } catch (TlsFailed $exception) {
            self::assertStringContainsString('secure', strtolower($exception->getMessage()));
            self::assertTrue($transport->closed);
        }
    }

    public function testConnectionFailureRemainsTypedAndClosesTheTransport(): void
    {
        $transport = new ScriptedTransport('', connectFailure: new ConnectionFailed());

        try {
            new ImapMailbox($this->config(), $transport);
            self::fail('Expected the connection attempt to fail.');
        } catch (ConnectionFailed $exception) {
            self::assertStringContainsString('connect', strtolower($exception->getMessage()));
            self::assertTrue($transport->closed);
        }
    }

    public function testCloseLogsOutAndClosesTheTransport(): void
    {
        $transport = new ScriptedTransport($this->successfulHandshake() . "A0003 OK logout completed\r\n");
        $mailbox = new ImapMailbox($this->config(), $transport);
        $mailbox->close();

        self::assertSame("A0003 LOGOUT\r\n", $transport->writes[2]);
        self::assertTrue($transport->closed);
    }

    public function testUnencryptedConnectionsRequireExplicitTestOptIn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailboxConnectionConfig(
            host: 'imap.example.test',
            port: 143,
            encryption: MailboxEncryption::NONE,
            username: 'events@example.test',
            password: 'test-secret'
        );
    }

    public function testTransportTimeoutRemainsTyped(): void
    {
        $transport = new ScriptedTransport('', new Timeout());

        $this->expectException(Timeout::class);

        new ImapMailbox($this->config(), $transport);
    }

    private function successfulHandshake(string $capabilities = ''): string
    {
        return "* OK ready\r\n"
            . "A0001 OK login completed\r\n"
            . "* CAPABILITY IMAP4rev1"
            . ($capabilities === '' ? '' : ' ' . $capabilities)
            . "\r\nA0002 OK capability completed\r\n";
    }

    private function config(
        MailboxEncryption $encryption = MailboxEncryption::SSL,
        int $maxMessageSizeBytes = MailboxConnectionConfig::DEFAULT_MAX_MESSAGE_SIZE_BYTES,
        string $username = 'events@example.test',
        string $password = 'test-secret',
    ): MailboxConnectionConfig {
        return new MailboxConnectionConfig(
            host: 'imap.example.test',
            port: $encryption === MailboxEncryption::SSL ? 993 : 143,
            encryption: $encryption,
            username: $username,
            password: $password,
            maxMessageSizeBytes: $maxMessageSizeBytes,
            allowInsecureForTesting: $encryption === MailboxEncryption::NONE
        );
    }
}
