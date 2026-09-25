<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Mail\MailQueueConfiguration;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\MailQueueRecord;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\WordPressMailQueueRepository;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class WordPressMailQueueRepositoryTest extends TestCase
{
    public function testRecipientGroupLookupRestoresFingerprintAndThreadHeaders(): void
    {
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $preparedQuery = 'prepared idempotency lookup';
        $fingerprint = str_repeat('a', 64);
        $database->expects(self::once())->method('prefix')->willReturn('wp_');
        $database->expects(self::once())
            ->method('prepare')
            ->with(
                self::stringContains('WHERE recipient = %s AND group_key = %s'),
                'sender@example.test',
                'confirmation:901'
            )
            ->willReturn($preparedQuery);
        $database->expects(self::once())->method('clearLastError');
        $database->expects(self::once())
            ->method('getRow')
            ->with($preparedQuery)
            ->willReturn([
                'id' => '17',
                'recipient' => 'sender@example.test',
                'subject' => 'Preview',
                'body_html' => '<p>Preview</p>',
                'body_text' => 'Preview',
                'priority' => (string) MailPriority::LOGIN_OR_CONFIRMATION->value,
                'group_key' => 'confirmation:901',
                'thread_headers' => '{"in_reply_to":"<inbound@example.test>","references":"<inbound@example.test>"}',
                'payload_fingerprint' => $fingerprint,
                'status' => MailQueueStatus::QUEUED->value,
                'attempts' => '0',
                'next_attempt_at' => null,
                'sent_at' => null,
                'error' => null,
                'created_at' => '2026-10-01 12:00:00',
                'updated_at' => '2026-10-01 12:00:00',
            ]);
        $database->expects(self::once())->method('lastError')->willReturn('');

        $record = (new WordPressMailQueueRepository($database))
            ->findByRecipientAndGroupKey(' Sender@Example.Test ', 'confirmation:901');

        self::assertNotNull($record);
        self::assertSame($fingerprint, $record->email->payloadFingerprint);
        self::assertNotNull($record->email->threadHeaders);
        self::assertSame('<inbound@example.test>', $record->email->threadHeaders->inReplyTo);
    }

    public function testGroupKeyLookupReturnsRowsAcrossRecipients(): void
    {
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $preparedQuery = 'prepared group key lookup';
        $row = [
            'id' => '17',
            'recipient' => 'sender@example.test',
            'subject' => 'Preview',
            'body_html' => '<p>Preview</p>',
            'body_text' => 'Preview',
            'priority' => (string) MailPriority::LOGIN_OR_CONFIRMATION->value,
            'group_key' => 'confirmation:901',
            'thread_headers' => null,
            'payload_fingerprint' => str_repeat('a', 64),
            'status' => MailQueueStatus::QUEUED->value,
            'attempts' => '0',
            'next_attempt_at' => null,
            'sent_at' => null,
            'error' => null,
            'created_at' => '2026-10-01 12:00:00',
            'updated_at' => '2026-10-01 12:00:00',
        ];
        $otherRecipientRow = $row;
        $otherRecipientRow['id'] = '18';
        $otherRecipientRow['recipient'] = 'trusted-contact@example.test';
        $database->expects(self::once())->method('prefix')->willReturn('wp_');
        $database->expects(self::once())
            ->method('prepare')
            ->with(
                self::stringContains('WHERE group_key = %s ORDER BY id ASC'),
                'confirmation:901'
            )
            ->willReturn($preparedQuery);
        $database->expects(self::once())->method('clearLastError');
        $database->expects(self::once())
            ->method('getResults')
            ->with($preparedQuery)
            ->willReturn([$row, $otherRecipientRow]);
        $database->expects(self::once())->method('lastError')->willReturn('');

        $records = (new WordPressMailQueueRepository($database))->findAllByGroupKey('confirmation:901');

        self::assertCount(2, $records);
        self::assertSame(
            ['sender@example.test', 'trusted-contact@example.test'],
            array_map(
                static fn (MailQueueRecord $record): string => $record->email->recipient,
                $records
            )
        );
    }

    public function testRecentSuppressedPreviewQueryIsBoundedAndReturnsOnlyTheSafePreviewFields(): void
    {
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $preparedQuery = 'prepared suppressed preview';
        $database->expects(self::once())
            ->method('prefix')
            ->willReturn('wp_');
        $database->expects(self::once())
            ->method('prepare')
            ->with(
                self::stringContains('FROM wp_adct_pi_mail_queue'),
                '',
                WordPressMailQueueRepository::SUPPRESSED_PREVIEW_BODY_LIMIT,
                MailQueueStatus::SUPPRESSED->value,
                WordPressMailQueueRepository::SUPPRESSED_PREVIEW_LIMIT
            )
            ->willReturn($preparedQuery);
        $database->expects(self::once())
            ->method('clearLastError');
        $database->expects(self::once())
            ->method('getResults')
            ->with($preparedQuery)
            ->willReturn([[
                'id' => '17',
                'recipient' => 'blocked@example.test',
                'subject' => '<script>preview marker</script>',
                'body_preview' => '<img src=x onerror=alert(1)>',
                'updated_at' => '2026-09-25 08:00:00',
            ]]);
        $database->expects(self::once())
            ->method('lastError')
            ->willReturn('');

        $repository = new WordPressMailQueueRepository($database);

        self::assertSame([[
            'id' => 17,
            'recipient' => 'blocked@example.test',
            'subject' => '<script>preview marker</script>',
            'body_preview' => '<img src=x onerror=alert(1)>',
            'suppressed_at' => '2026-09-25 08:00:00',
        ]], $repository->findRecentSuppressed());
    }

    public function testPruningUsesTheConfiguredBatchLimitAndQueueTable(): void
    {
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $cutoff = new DateTimeImmutable('2026-08-26 00:00:00', new DateTimeZone('UTC'));
        $preparedQuery = 'prepared queue prune';

        $database->expects(self::once())
            ->method('prefix')
            ->willReturn('wp_');
        $database->expects(self::once())
            ->method('prepare')
            ->with(
                self::stringContains('DELETE FROM wp_adct_pi_mail_queue'),
                MailQueueStatus::SENT->value,
                '2026-08-26 00:00:00',
                MailQueueConfiguration::PRUNE_BATCH_SIZE
            )
            ->willReturn($preparedQuery);
        $database->expects(self::once())
            ->method('clearLastError');
        $database->expects(self::once())
            ->method('query')
            ->with($preparedQuery)
            ->willReturn(7);
        $database->expects(self::once())
            ->method('lastError')
            ->willReturn('');

        $repository = new WordPressMailQueueRepository($database);

        self::assertSame(
            7,
            $repository->pruneSentBefore($cutoff, MailQueueConfiguration::PRUNE_BATCH_SIZE)
        );
    }
}
