<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettingsValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MailboxSettingsValidatorTest extends TestCase
{
    public function testValidatesAndNormalizesTheMailboxSettings(): void
    {
        $settings = (new MailboxSettingsValidator())->validate($this->validInput());

        self::assertSame('Events mailbox', $settings->label);
        self::assertSame('imap.example.test', $settings->host);
        self::assertSame(993, $settings->port);
        self::assertSame(MailboxEncryption::SSL, $settings->encryption);
        self::assertSame('events@example.test', $settings->username);
        self::assertSame('INBOX', $settings->inboxFolder);
        self::assertSame('Processed', $settings->processedFolder);
        self::assertSame(30 * 1024 * 1024, $settings->maxMessageSizeBytes);
        self::assertTrue($settings->active);
    }

    public function testRejectsInvalidHosts(): void
    {
        $input = $this->validInput();
        $input['host'] = 'imap.example.test/INBOX';

        $this->expectException(InvalidArgumentException::class);
        (new MailboxSettingsValidator())->validate($input);
    }

    public function testRejectsPortsOutsideTheValidRange(): void
    {
        foreach (['0', '65536', '993x'] as $port) {
            try {
                $input = $this->validInput();
                $input['port'] = $port;
                (new MailboxSettingsValidator())->validate($input);
                self::fail('Invalid port was accepted: ' . $port);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testOnlyTlsEncryptionModesAreAccepted(): void
    {
        foreach (['none', 'unknown'] as $encryption) {
            try {
                $input = $this->validInput();
                $input['encryption'] = $encryption;
                (new MailboxSettingsValidator())->validate($input);
                self::fail('Invalid encryption was accepted: ' . $encryption);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $input = $this->validInput();
        $input['encryption'] = 'starttls';
        self::assertSame(
            MailboxEncryption::STARTTLS,
            (new MailboxSettingsValidator())->validate($input)->encryption
        );
    }

    public function testRejectsInvalidOrUnsafeFolderNames(): void
    {
        foreach (['', "Inbox\r\nLOGOUT"] as $folder) {
            try {
                $input = $this->validInput();
                $input['processed_folder'] = $folder;
                (new MailboxSettingsValidator())->validate($input);
                self::fail('Invalid folder name was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $input = $this->validInput();
        $input['processed_folder'] = 'inbox';
        $this->expectException(InvalidArgumentException::class);
        (new MailboxSettingsValidator())->validate($input);
    }

    public function testMessageSizeMustBeBetweenOneAndThirtyMegabytes(): void
    {
        foreach (['0', '31', 'large'] as $size) {
            try {
                $input = $this->validInput();
                $input['max_message_size_mb'] = $size;
                (new MailboxSettingsValidator())->validate($input);
                self::fail('Invalid message-size limit was accepted: ' . $size);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $input = $this->validInput();
        $input['max_message_size_mb'] = '15';
        self::assertSame(
            15 * 1024 * 1024,
            (new MailboxSettingsValidator())->validate($input)->maxMessageSizeBytes
        );
    }

    /**
     * @return array<string, string>
     */
    private function validInput(): array
    {
        return [
            'label' => 'Events mailbox',
            'host' => 'imap.example.test',
            'port' => '993',
            'encryption' => 'ssl',
            'username' => 'events@example.test',
            'inbox_folder' => 'INBOX',
            'processed_folder' => 'Processed',
            'max_message_size_mb' => '30',
            'active' => '1',
        ];
    }
}
