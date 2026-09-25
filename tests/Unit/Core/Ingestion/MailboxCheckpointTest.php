<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\MailboxCheckpoint;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class MailboxCheckpointTest extends TestCase
{
    public function testCheckpointRoundTripsUidValidityAndLastUid(): void
    {
        $checkpoint = new MailboxCheckpoint(12345, 67);

        self::assertSame(
            '{"uidvalidity":12345,"last_uid":67,"scan_complete":false}',
            $checkpoint->toJson()
        );
        self::assertEquals($checkpoint, MailboxCheckpoint::fromJson($checkpoint->toJson()));
    }

    public function testLegacyCheckpointIsTreatedAsAnIncompleteScan(): void
    {
        $checkpoint = MailboxCheckpoint::fromJson('{"uidvalidity":12345,"last_uid":67}');

        self::assertNotNull($checkpoint);
        self::assertFalse($checkpoint->scanComplete);
    }

    public function testUidValidityChangeResetsTheLastUid(): void
    {
        $checkpoint = new MailboxCheckpoint(12345, 67);

        self::assertEquals(new MailboxCheckpoint(54321, 0), $checkpoint->forUidValidity(54321));
        self::assertSame($checkpoint, $checkpoint->forUidValidity(12345));
    }

    public function testCheckpointAdvancesOnlyToAHigherUid(): void
    {
        $checkpoint = new MailboxCheckpoint(12345, 67);

        self::assertEquals(new MailboxCheckpoint(12345, 70), $checkpoint->advanceTo(70));
        self::assertSame($checkpoint, $checkpoint->advanceTo(67));
    }

    public function testScanCanBeMarkedInProgressAndComplete(): void
    {
        $checkpoint = new MailboxCheckpoint(12345, 67, true);

        self::assertEquals(new MailboxCheckpoint(12345, 67), $checkpoint->beginScan());
        self::assertEquals(new MailboxCheckpoint(12345, 67, true), $checkpoint->completeScan());
        self::assertFalse($checkpoint->advanceTo(70)->scanComplete);
    }

    public function testEmptyStoredCheckpointMeansNoPreviousPoll(): void
    {
        self::assertNull(MailboxCheckpoint::fromJson(null));
        self::assertNull(MailboxCheckpoint::fromJson(''));
    }

    public function testMalformedCheckpointIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        MailboxCheckpoint::fromJson('{"uidvalidity":"unknown","last_uid":4}');
    }
}
