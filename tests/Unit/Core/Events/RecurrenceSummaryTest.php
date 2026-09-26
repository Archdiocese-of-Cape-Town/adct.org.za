<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\RecurrenceSummary;
use PHPUnit\Framework\TestCase;

final class RecurrenceSummaryTest extends TestCase
{
    public function testSupportedSeriesAreDescribedWithoutClaimingAnUnsupportedWeekday(): void
    {
        self::assertSame('Every first Friday', RecurrenceSummary::describe('FREQ=MONTHLY;BYDAY=FR;BYSETPOS=1'));
        self::assertSame('Every last Sunday', RecurrenceSummary::describe('FREQ=MONTHLY;BYDAY=-1SU'));
        self::assertSame('Every Tuesday', RecurrenceSummary::describe('FREQ=WEEKLY;BYDAY=TU'));
        self::assertSame('Every week', RecurrenceSummary::describe('FREQ=WEEKLY;BYDAY=TU,TH'));
        self::assertSame('Every 2 months', RecurrenceSummary::describe('FREQ=MONTHLY;INTERVAL=2'));
    }
}
