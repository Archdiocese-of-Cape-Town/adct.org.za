<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Ingestion;

use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailOutcome;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailReason;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResult;
use ADCT\ParishIntake\Core\Ports\InboundHeaderStorageInterface;
use ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Ingestion\WordPressConfirmationEmailJobSource;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WordPressConfirmationEmailJobSourceTest extends TestCase
{
    public function testLoadsEveryDraftCandidateAndResolvesTrustedReplyToFromBoundedHeaders(): void
    {
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_');
        $database->method('prepare')->willReturnCallback(static fn (string $query): string => $query);
        $database->method('lastError')->willReturn('');
        $database->method('getRow')->willReturnCallback(static function (string $query): ?array {
            if (! str_contains($query, 'SELECT m.id')) {
                return null;
            }

            return [
                'id' => '901',
                'source_id' => '4',
                'sender_email' => 'sender@example.test',
                'sender_name' => 'Example Sender',
                'subject' => 'Event notice',
                'received_at' => '2026-10-01 12:00:00',
                'raw_path' => str_repeat('a', 64) . '.eml',
                'is_auto_reply' => '0',
            ];
        });
        $database->method('getResults')->willReturn([
            [
                'id' => '101',
                'fields' => '{"title":"Harvest lunch","event_date":"2026-10-12"}',
                'recurrence' => '{}',
                'confidence' => '0.910',
                'notes' => '[]',
            ],
            [
                'id' => '102',
                'fields' => '{"title":"Evening prayer","event_date":"2026-10-12"}',
                'recurrence' => '{}',
                'confidence' => '0.820',
                'notes' => '[]',
            ],
        ]);
        $contacts = $this->createMock(ParishContactStoreInterface::class);
        $contacts->method('findByEmail')->willReturnCallback(static function (string $email): array {
            return $email === 'trusted@example.test'
                ? [['parish_id' => 4, 'trust' => SenderTrust::VERIFIED]]
                : [];
        });
        $storage = $this->createMock(InboundHeaderStorageInterface::class);
        $storage->expects(self::once())
            ->method('readHeaderBlock')
            ->with(str_repeat('a', 64) . '.eml')
            ->willReturn(
                "Reply-To: Parish office <trusted@example.test>\r\n"
                . "Message-ID: <inbound-901@example.test>\r\n"
            );

        $source = new WordPressConfirmationEmailJobSource($database, $contacts, $storage);
        $batch = $source->nextPending();

        self::assertNotNull($batch);
        self::assertSame(901, $batch->messageId);
        self::assertSame(4, $batch->sourceId);
        self::assertSame('trusted@example.test', $batch->replyToEmail);
        self::assertSame(SenderTrust::VERIFIED, $batch->replyToTrust);
        self::assertSame('<inbound-901@example.test>', $batch->originalMessageId);
        self::assertFalse($batch->automatedOrList);
        self::assertCount(2, $batch->candidates);
        self::assertSame('Harvest lunch', $batch->candidates[0]->fields['title']);
        self::assertSame('Evening prayer', $batch->candidates[1]->fields['title']);
    }

    public function testDetectsListMailAndRecordsSafeSuppressionReasonOnInboundMessage(): void
    {
        $preparedQueries = [];
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_');
        $database->method('prepare')->willReturnCallback(
            static function (string $query, mixed ...$arguments) use (&$preparedQueries): string {
                $preparedQueries[] = [$query, $arguments];

                return $query;
            }
        );
        $database->method('lastError')->willReturn('');
        $database->expects(self::once())->method('query')->willReturn(1);
        $contacts = $this->createMock(ParishContactStoreInterface::class);
        $contacts->method('findByEmail')->willReturn([]);
        $storage = $this->createMock(InboundHeaderStorageInterface::class);
        $storage->method('readHeaderBlock')->willReturn(
            "List-Id: Parish events <events.example.test>\r\n"
        );
        $database->method('getRow')->willReturnCallback(static function (string $query): ?array {
            if (! str_contains($query, 'SELECT m.id')) {
                return null;
            }

            return [
                'id' => '901',
                'source_id' => '4',
                'sender_email' => 'sender@example.test',
                'sender_name' => null,
                'subject' => 'Event notice',
                'received_at' => '2026-10-01 12:00:00',
                'raw_path' => str_repeat('a', 64) . '.eml',
                'is_auto_reply' => '0',
            ];
        });
        $database->method('getResults')->willReturn([
            [
                'id' => '101',
                'fields' => '{"title":"Harvest lunch"}',
                'recurrence' => '{}',
                'confidence' => '0.800',
                'notes' => '[]',
            ],
        ]);

        $source = new WordPressConfirmationEmailJobSource($database, $contacts, $storage);
        $batch = $source->nextPending();
        self::assertNotNull($batch);
        self::assertTrue($batch->automatedOrList);

        $source->recordResult(
            $batch->messageId,
            new ConfirmationEmailResult(
                ConfirmationEmailOutcome::SUPPRESSED,
                ConfirmationEmailReason::AUTOMATED_OR_LIST
            ),
            new DateTimeImmutable('2026-10-01 12:30:00')
        );

        self::assertCount(3, $preparedQueries);
        self::assertStringContainsString('confirmation_status = %s', $preparedQueries[2][0]);
        self::assertStringContainsString('confirmation_reason = %s', $preparedQueries[2][0]);
        self::assertSame('suppressed', $preparedQueries[2][1][0]);
        self::assertSame('automated_or_list', $preparedQueries[2][1][1]);
        self::assertSame(901, $preparedQueries[2][1][3]);
    }
}
