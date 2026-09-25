<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\Imap\AuthenticationFailed;
use ADCT\ParishIntake\Core\Ingestion\Imap\ConnectionFailed;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxConnectionConfig;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxException;
use ADCT\ParishIntake\Core\Ingestion\Imap\ProtocolError;
use ADCT\ParishIntake\Core\Ingestion\Imap\Timeout;
use ADCT\ParishIntake\Core\Ingestion\Imap\TlsFailed;
use ADCT\ParishIntake\Core\Ports\MailboxInterface as MailboxPort;
use ADCT\ParishIntake\Core\Sources\SourceHealthRecorder;
use Closure;
use InvalidArgumentException;
use RuntimeException;

final class MailboxConnectionTestService
{
    /** @var Closure(MailboxConnectionConfig): MailboxPort */
    private Closure $mailboxFactory;

    /**
     * @param callable(MailboxConnectionConfig): MailboxPort $mailboxFactory
     */
    public function __construct(
        private SourceHealthRecorder $healthRecorder,
        callable $mailboxFactory,
        private bool $allowInsecureForTesting = false
    ) {
        $this->mailboxFactory = Closure::fromCallable($mailboxFactory);
    }

    public function test(MailboxSettings $settings, string $password): MailboxConnectionTestResult
    {
        return $this->run($settings, $password, false);
    }

    public function createProcessedFolder(
        MailboxSettings $settings,
        string $password
    ): MailboxConnectionTestResult {
        return $this->run($settings, $password, true);
    }

    private function run(
        MailboxSettings $settings,
        string $password,
        bool $createProcessedFolder
    ): MailboxConnectionTestResult {
        if ($settings->sourceId < 1) {
            throw new InvalidArgumentException('A saved mailbox source is required for a connection test.');
        }

        if (trim($password) === '') {
            return $this->failure(
                $settings,
                MailboxConnectionTestStatus::PASSWORD_MISSING
            );
        }

        $config = new MailboxConnectionConfig(
            host: $settings->host,
            port: $settings->port,
            encryption: $settings->encryption,
            username: $settings->username,
            password: $password,
            folders: [
                'inbox' => $settings->inboxFolder,
                'processed' => $settings->processedFolder,
            ],
            connectTimeout: 10,
            readTimeout: 10,
            verifyPeer: true,
            maxMessageSizeBytes: $settings->maxMessageSizeBytes,
            allowInsecureForTesting: $this->allowInsecureForTesting
        );

        $mailbox = null;
        $failure = null;
        $waitingCount = 0;
        $processedFolderMissing = false;
        $processedFolderCreated = false;

        try {
            $mailbox = ($this->mailboxFactory)($config);

            if (! $mailbox instanceof MailboxPort) {
                throw new RuntimeException('The mailbox factory returned an invalid adapter.');
            }

            $waitingCount = count($mailbox->search(MailboxSearchCriteria::unseen()));
            $folders = $mailbox->listFolders();

            if ($createProcessedFolder && ! in_array($settings->processedFolder, $folders, true)) {
                $mailbox->ensureFolder($settings->processedFolder);
                $processedFolderCreated = true;
                $folders = $mailbox->listFolders();
            }

            $processedFolderMissing = ! in_array($settings->processedFolder, $folders, true);
        } catch (MailboxException $exception) {
            $failure = $exception;
        } finally {
            if ($mailbox instanceof MailboxPort) {
                try {
                    $mailbox->close();
                } catch (MailboxException $exception) {
                    $failure ??= $exception;
                }
            }
        }

        if ($failure !== null) {
            return $this->failure($settings, $this->statusFor($failure));
        }

        if ($processedFolderMissing) {
            return $this->failure(
                $settings,
                MailboxConnectionTestStatus::PROCESSED_FOLDER_MISSING,
                $settings->processedFolder
            );
        }

        $this->healthRecorder->recordSuccess($settings->sourceId);

        return new MailboxConnectionTestResult(
            $processedFolderCreated
                ? MailboxConnectionTestStatus::PROCESSED_FOLDER_CREATED
                : MailboxConnectionTestStatus::CONNECTED,
            $waitingCount,
            $settings->processedFolder
        );
    }

    private function failure(
        MailboxSettings $settings,
        MailboxConnectionTestStatus $status,
        ?string $processedFolder = null
    ): MailboxConnectionTestResult {
        $this->healthRecorder->recordFailure(
            $settings->sourceId,
            $this->healthErrorFor($status)
        );

        return new MailboxConnectionTestResult($status, processedFolder: $processedFolder);
    }

    private function statusFor(MailboxException $failure): MailboxConnectionTestStatus
    {
        return match (true) {
            $failure instanceof AuthenticationFailed => MailboxConnectionTestStatus::AUTHENTICATION_FAILED,
            $failure instanceof ConnectionFailed => MailboxConnectionTestStatus::CONNECTION_FAILED,
            $failure instanceof TlsFailed => MailboxConnectionTestStatus::TLS_FAILED,
            $failure instanceof Timeout => MailboxConnectionTestStatus::TIMEOUT,
            $failure instanceof ProtocolError => MailboxConnectionTestStatus::PROTOCOL_ERROR,
            default => MailboxConnectionTestStatus::PROTOCOL_ERROR,
        };
    }

    private function healthErrorFor(MailboxConnectionTestStatus $status): string
    {
        return match ($status) {
            MailboxConnectionTestStatus::PASSWORD_MISSING => 'Mailbox test failed: no password is configured.',
            MailboxConnectionTestStatus::PROCESSED_FOLDER_MISSING =>
                'Mailbox test failed: the processed folder is missing.',
            MailboxConnectionTestStatus::AUTHENTICATION_FAILED =>
                'Mailbox test failed: authentication was rejected.',
            MailboxConnectionTestStatus::CONNECTION_FAILED =>
                'Mailbox test failed: the server could not be reached.',
            MailboxConnectionTestStatus::TLS_FAILED =>
                'Mailbox test failed: the secure connection could not be verified.',
            MailboxConnectionTestStatus::TIMEOUT =>
                'Mailbox test failed: the server timed out.',
            MailboxConnectionTestStatus::PROTOCOL_ERROR =>
                'Mailbox test failed: the server returned an unexpected response.',
            MailboxConnectionTestStatus::CONNECTED,
            MailboxConnectionTestStatus::PROCESSED_FOLDER_CREATED =>
                throw new InvalidArgumentException('A successful mailbox test has no failure message.'),
        };
    }
}
