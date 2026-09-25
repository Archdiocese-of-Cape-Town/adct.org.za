<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\IcsCalendar;
use PHPUnit\Framework\TestCase;

final class IcsCalendarTest extends TestCase
{
    public function testRecurringTimedEventUsesSastZoneUtcUntilExceptionsCancellationAndStableUid(): void
    {
        $calendar = new IcsCalendar();
        $body = $calendar->render([[
            'id' => 42,
            'uid_domain' => 'adct.org.za',
            'title' => "Fictional, gathering; Café\nEvening",
            'description' => "A \\ sample\r\nSecond line",
            'url' => 'https://example.test/events/fictional/',
            'modified' => '2026-09-25 11:00:00 UTC',
            'start' => '2026-10-02T18:00',
            'end' => '2026-10-02T19:00',
            'all_day' => false,
            'rrule' => 'FREQ=WEEKLY;COUNT=5',
            'exdates' => ['2026-10-09T18:00'],
            'rdates' => ['2026-10-10T18:00'],
            'cancelled' => true,
        ]]);
        $unfolded = str_replace("\r\n ", '', $body);
        self::assertStringContainsString("BEGIN:VTIMEZONE\r\nTZID:Africa/Johannesburg\r\n", $body);
        self::assertStringContainsString("UID:adct-event-42@adct.org.za\r\n", $body);
        self::assertStringContainsString("DTSTART;TZID=Africa/Johannesburg:20261002T180000\r\n", $body);
        self::assertStringContainsString("RRULE:FREQ=WEEKLY;COUNT=5\r\n", $body);
        self::assertStringContainsString("EXDATE;TZID=Africa/Johannesburg:20261009T180000\r\n", $body);
        self::assertStringContainsString("RDATE;TZID=Africa/Johannesburg:20261010T180000\r\n", $body);
        self::assertStringContainsString("STATUS:CANCELLED\r\n", $body);
        self::assertStringContainsString('SUMMARY:Fictional\, gathering\; Café\nEvening', $unfolded);
        self::assertStringContainsString('DESCRIPTION:A \\\\ sample\nSecond line', $unfolded);
        self::assertStringNotContainsString("\n\n", $body);
        foreach (explode("\r\n", trim($body)) as $line) {
            self::assertLessThanOrEqual(75, strlen($line));
        }
        self::assertSame($body, $calendar->render([[
            'id' => 42,
            'uid_domain' => 'adct.org.za',
            'title' => "Fictional, gathering; Café\nEvening",
            'description' => "A \\ sample\r\nSecond line",
            'url' => 'https://example.test/events/fictional/',
            'modified' => '2026-09-25 11:00:00 UTC',
            'start' => '2026-10-02T18:00',
            'end' => '2026-10-02T19:00',
            'all_day' => false,
            'rrule' => 'FREQ=WEEKLY;COUNT=5',
            'exdates' => ['2026-10-09T18:00'],
            'rdates' => ['2026-10-10T18:00'],
            'cancelled' => true,
        ]]));
    }

    public function testAllDayUsesExclusiveEndAndDateExceptions(): void
    {
        $body = (new IcsCalendar())->render([[
            'id' => 4,
            'uid_domain' => 'adct.org.za',
            'title' => 'Fictional fair',
            'description' => '',
            'url' => 'https://example.test/events/4',
            'modified' => '2026-09-25 11:00:00 UTC',
            'start' => '2026-10-02T00:00',
            'end' => '2026-10-03T00:00',
            'all_day' => true,
            'rrule' => 'FREQ=YEARLY;UNTIL=20291002',
            'exdates' => ['2027-10-02T00:00'],
            'rdates' => [],
            'cancelled' => false,
        ]]);
        self::assertStringContainsString("DTSTART;VALUE=DATE:20261002\r\nDTEND;VALUE=DATE:20261004", $body);
        self::assertStringContainsString('EXDATE;VALUE=DATE:20271002', $body);
        self::assertStringNotContainsString('STATUS:CANCELLED', $body);
    }

    public function testLocalUntilIsConvertedToUtcForTimezoneQualifiedStart(): void
    {
        $body = (new IcsCalendar())->render([[
            'id' => 5,
            'uid_domain' => 'adct.org.za',
            'title' => 'Fictional event',
            'description' => '',
            'url' => 'https://example.test/events/5',
            'modified' => '2026-09-25 11:00:00 UTC',
            'start' => '2026-10-02T18:00',
            'end' => null,
            'all_day' => false,
            'rrule' => 'FREQ=DAILY;UNTIL=20261009T180000',
            'exdates' => [],
            'rdates' => [],
            'cancelled' => false,
        ]]);
        self::assertStringContainsString("RRULE:FREQ=DAILY;UNTIL=20261009T160000Z\r\n", $body);
    }
}
