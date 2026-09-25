<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

use InvalidArgumentException;

final readonly class MailboxConnectionConfig
{
    public const DEFAULT_MAX_MESSAGE_SIZE_BYTES = 30 * 1024 * 1024;

    /** @var array<string, string> */
    public array $folders;

    /**
     * @param array<string, string> $folders Logical folder names mapped to server folder names.
     */
    public function __construct(
        public string $host,
        public int $port,
        public MailboxEncryption $encryption,
        public string $username,
        public string $password,
        array $folders = ['inbox' => 'INBOX', 'processed' => 'Processed'],
        public int $connectTimeout = 10,
        public int $readTimeout = 15,
        public bool $verifyPeer = true,
        public int $maxMessageSizeBytes = self::DEFAULT_MAX_MESSAGE_SIZE_BYTES,
        public bool $allowInsecureForTesting = false,
    ) {
        if (
            trim($host) === ''
            || preg_match('/[\x00-\x20\x7F\/\\\\@?#%]/', $host) === 1
        ) {
            throw new InvalidArgumentException('Enter a valid IMAP server host.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Enter a valid IMAP server port.');
        }

        if ($username === '' || preg_match('/[\x00-\x1F\x7F]/', $username) === 1) {
            throw new InvalidArgumentException('Enter a valid IMAP username.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            throw new InvalidArgumentException('The IMAP password contains an unsupported control character.');
        }

        if ($connectTimeout < 1 || $readTimeout < 1) {
            throw new InvalidArgumentException('IMAP connection and read timeouts must be positive.');
        }

        if ($maxMessageSizeBytes < 1) {
            throw new InvalidArgumentException('The IMAP message-size limit must be positive.');
        }

        if (
            ! $allowInsecureForTesting
            && ($encryption === MailboxEncryption::NONE || ! $verifyPeer)
        ) {
            throw new InvalidArgumentException(
                'Unencrypted connections and disabled TLS verification are only allowed in tests.'
            );
        }

        foreach ($folders as $name => $folder) {
            if (
                ! is_string($name)
                || $name === ''
                || ! is_string($folder)
                || trim($folder) === ''
            ) {
                throw new InvalidArgumentException('Mailbox folders must have non-empty names.');
            }
        }

        $this->folders = array_replace(['inbox' => 'INBOX', 'processed' => 'Processed'], $folders);
    }
}
