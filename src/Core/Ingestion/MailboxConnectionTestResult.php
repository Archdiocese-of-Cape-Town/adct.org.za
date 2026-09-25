<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use InvalidArgumentException;

final readonly class MailboxConnectionTestResult
{
    public function __construct(
        public MailboxConnectionTestStatus $status,
        public int $waitingCount = 0,
        public ?string $processedFolder = null
    ) {
        if ($waitingCount < 0) {
            throw new InvalidArgumentException('A waiting-message count cannot be negative.');
        }
    }

    public function isSuccessful(): bool
    {
        return in_array(
            $this->status,
            [
                MailboxConnectionTestStatus::CONNECTED,
                MailboxConnectionTestStatus::PROCESSED_FOLDER_CREATED,
            ],
            true
        );
    }

    public function needsProcessedFolder(): bool
    {
        return $this->status === MailboxConnectionTestStatus::PROCESSED_FOLDER_MISSING;
    }

    public function message(): string
    {
        return match ($this->status) {
            MailboxConnectionTestStatus::CONNECTED => sprintf(
                'Connected - %d %s waiting',
                $this->waitingCount,
                $this->waitingCount === 1 ? 'message' : 'messages'
            ),
            MailboxConnectionTestStatus::PROCESSED_FOLDER_MISSING => sprintf(
                'Processed folder "%s" does not exist',
                $this->processedFolder ?? ''
            ),
            MailboxConnectionTestStatus::PROCESSED_FOLDER_CREATED => sprintf(
                'Connected - %d %s waiting. Processed folder "%s" created',
                $this->waitingCount,
                $this->waitingCount === 1 ? 'message' : 'messages',
                $this->processedFolder ?? ''
            ),
            MailboxConnectionTestStatus::PASSWORD_MISSING =>
                'Set a mailbox password in this form or wp-config.php before testing the connection',
            MailboxConnectionTestStatus::AUTHENTICATION_FAILED => 'Wrong username or password',
            MailboxConnectionTestStatus::CONNECTION_FAILED =>
                "Can't reach the server - check host and port",
            MailboxConnectionTestStatus::TLS_FAILED =>
                'Secure connection failed - check the encryption setting or certificate. '
                . 'Use the mail server name shown on the certificate (for xneelo, the server hostname from your hosting control panel) instead of a custom alias.',
            MailboxConnectionTestStatus::TIMEOUT => 'The server took too long to respond',
            MailboxConnectionTestStatus::PROTOCOL_ERROR => 'The server returned an unexpected response',
        };
    }
}
