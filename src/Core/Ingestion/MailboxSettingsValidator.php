<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use ADCT\ParishIntake\Core\Directory\EmailAddress;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use InvalidArgumentException;

final class MailboxSettingsValidator
{
    public const MAX_MESSAGE_SIZE_MB = 30;

    /**
     * @param array<string, mixed> $input
     */
    public function validate(array $input, int $id = 0, int $sourceId = 0): MailboxSettings
    {
        $label = trim($this->stringValue($input, 'label'));

        if ($label === '' || strlen($label) > 191) {
            throw new InvalidArgumentException('Enter a mailbox label of at most 191 characters.');
        }

        $host = trim($this->stringValue($input, 'host'));

        if (
            $host === ''
            || strlen($host) > 253
            || preg_match('/[\x00-\x20\x7F\/\\\\@?#%]/', $host) === 1
        ) {
            throw new InvalidArgumentException('Enter a valid IMAP server host.');
        }

        $port = $this->integerValue($input, 'port', 1, 65535, 'Enter a valid IMAP server port.');
        $encryptionValue = strtolower(trim($this->stringValue($input, 'encryption')));
        $encryption = MailboxEncryption::tryFrom($encryptionValue);

        if ($encryption === null || $encryption === MailboxEncryption::NONE) {
            throw new InvalidArgumentException('Choose SSL/TLS or STARTTLS for the mailbox connection.');
        }

        $username = trim($this->stringValue($input, 'username'));

        if (strlen($username) > 191) {
            throw new InvalidArgumentException('Enter a mailbox email address of at most 191 characters.');
        }

        try {
            EmailAddress::normalize($username);
        } catch (InvalidArgumentException $failure) {
            throw new InvalidArgumentException('Enter a valid mailbox email address.', 0, $failure);
        }

        $inboxFolder = $this->folderValue($input, 'inbox_folder', 'inbox');
        $processedFolder = $this->folderValue($input, 'processed_folder', 'processed');

        if (strcasecmp($inboxFolder, $processedFolder) === 0) {
            throw new InvalidArgumentException('The inbox and processed folders must be different.');
        }

        $maxMessageSizeMb = $this->integerValue(
            $input,
            'max_message_size_mb',
            1,
            self::MAX_MESSAGE_SIZE_MB,
            'Set the maximum message size between 1 and 30 MB.'
        );

        return new MailboxSettings(
            $label,
            $host,
            $port,
            $encryption,
            $username,
            $inboxFolder,
            $processedFolder,
            $maxMessageSizeMb * 1024 * 1024,
            $this->booleanValue($input, 'active', true),
            $id,
            $sourceId
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function stringValue(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('The mailbox setting "' . $key . '" must be text.');
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function integerValue(
        array $input,
        string $key,
        int $minimum,
        int $maximum,
        string $error
    ): int {
        $value = $input[$key] ?? null;

        if (
            (! is_int($value) && ! is_string($value))
            || preg_match('/\A\d+\z/D', (string) $value) !== 1
        ) {
            throw new InvalidArgumentException($error);
        }

        $integer = (int) $value;

        if ($integer < $minimum || $integer > $maximum) {
            throw new InvalidArgumentException($error);
        }

        return $integer;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function folderValue(array $input, string $key, string $label): string
    {
        $folder = trim($this->stringValue($input, $key));

        if (
            $folder === ''
            || strlen($folder) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $folder) === 1
        ) {
            throw new InvalidArgumentException('Enter a valid ' . $label . ' folder name.');
        }

        return $folder;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function booleanValue(array $input, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $input)) {
            return $default;
        }

        return match ($input[$key]) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => throw new InvalidArgumentException('The mailbox setting "' . $key . '" is invalid.'),
        };
    }
}
