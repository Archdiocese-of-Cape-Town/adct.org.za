<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Jobs;

use ADCT\ParishIntake\Core\Jobs\MailboxPollingSummary;
use PHPUnit\Framework\TestCase;

final class MailboxPollingSummaryTest extends TestCase
{
    public function testOutcomeCountsRoundTripWithoutReflectingUntrustedText(): void
    {
        $summary = new MailboxPollingSummary(5, 1, 1, 1, 1, 1);
        self::assertEquals($summary, MailboxPollingSummary::fromQueryValue($summary->toQueryValue()));
        foreach ([null, [], '1,0,0,0,0,0', '0,0,0,0,0,-1', '<script>', '1,1,0,0,0,0,0'] as $invalid) {
            self::assertNull(MailboxPollingSummary::fromQueryValue($invalid));
        }
        self::assertStringContainsString('1 failed', $summary->message());
        self::assertStringContainsString('1 unfinished', $summary->message());
    }
}
