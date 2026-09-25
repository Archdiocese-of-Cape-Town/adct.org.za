<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Mail\MailQueueConfiguration;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\WordPressMailQueueRepository;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class WordPressMailQueueRepositoryTest extends TestCase
{
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
