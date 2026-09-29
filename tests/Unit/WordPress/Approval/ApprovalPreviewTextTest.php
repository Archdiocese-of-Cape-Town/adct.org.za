<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Approval;

use ADCT\ParishIntake\WordPress\Approval\ApprovalPreviewText;
use PHPUnit\Framework\TestCase;

final class ApprovalPreviewTextTest extends TestCase
{
    public function testPreviewUsesJohannesburgDayFirstDates(): void
    {
        self::assertSame('12 October 2026', ApprovalPreviewText::date('2026-10-12'));
        self::assertSame('12/10/2026', ApprovalPreviewText::editDate('2026-10-12'));
        self::assertSame('not a date', ApprovalPreviewText::date('not a date'));
    }

    public function testLongPreviewIsShortenedWithoutBreakingUtf8(): void
    {
        $text = str_repeat('x', 999) . 'é' . str_repeat('y', 50);
        $shortened = ApprovalPreviewText::shorten($text);
        self::assertSame(1, preg_match('//u', $shortened));
        self::assertStringEndsWith('...', $shortened);
    }
}
