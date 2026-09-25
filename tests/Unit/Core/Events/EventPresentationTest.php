<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\EventPresentation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EventPresentationTest extends TestCase
{
    public function testRecurrencePhraseDescribesCommonRules(): void
    {
        self::assertSame('One-off event', EventPresentation::recurrencePhrase(null));
        self::assertSame(
            'Every week on Tuesday and Thursday for 5 occurrences',
            EventPresentation::recurrencePhrase('FREQ=WEEKLY;BYDAY=TU,TH;COUNT=5')
        );
        self::assertSame(
            'Every month on first Friday',
            EventPresentation::recurrencePhrase('FREQ=MONTHLY;BYDAY=1FR')
        );
    }

    public function testGoogleCalendarUrlUsesUtcTimingAndAllDayDates(): void
    {
        $start = new DateTimeImmutable('2026-10-02T18:00:00+02:00');
        $end = new DateTimeImmutable('2026-10-02T19:00:00+02:00');
        $timed = EventPresentation::googleCalendarUrl(
            'Fictional event',
            'Public details',
            '2 Sample Road, Cape Town',
            $start,
            $end,
            false,
            'FREQ=WEEKLY;COUNT=5'
        );
        $allDay = EventPresentation::googleCalendarUrl(
            'All day event',
            'Public details',
            '',
            new DateTimeImmutable('2026-10-02T00:00:00+02:00'),
            new DateTimeImmutable('2026-10-02T00:00:00+02:00'),
            true,
            null
        );
        $multiDayAllDay = EventPresentation::googleCalendarUrl(
            'Multi day event',
            'Public details',
            '',
            new DateTimeImmutable('2026-10-02T00:00:00+02:00'),
            new DateTimeImmutable('2026-10-03T00:00:00+02:00'),
            true,
            null
        );

        parse_str((string) parse_url($timed, PHP_URL_QUERY), $timedQuery);
        parse_str((string) parse_url($allDay, PHP_URL_QUERY), $allDayQuery);
        parse_str((string) parse_url($multiDayAllDay, PHP_URL_QUERY), $multiDayQuery);

        self::assertSame('TEMPLATE', $timedQuery['action'] ?? null);
        self::assertSame('Fictional event', $timedQuery['text'] ?? null);
        self::assertSame('Public details', $timedQuery['details'] ?? null);
        self::assertSame('2 Sample Road, Cape Town', $timedQuery['location'] ?? null);
        self::assertSame('20261002T160000Z/20261002T170000Z', $timedQuery['dates'] ?? null);
        self::assertSame('RRULE:FREQ=WEEKLY;COUNT=5', $timedQuery['recur'] ?? null);
        self::assertSame('20261002/20261003', $allDayQuery['dates'] ?? null);
        self::assertSame('20261002/20261004', $multiDayQuery['dates'] ?? null);
        self::assertArrayNotHasKey('recur', $allDayQuery);
    }

    public function testMapUrlUsesCoordinatesOrAddress(): void
    {
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=-33.9249%2C18.4241',
            EventPresentation::mapUrl(-33.9249, 18.4241, null)
        );
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=2%20Sample%20Road%2C%20Cape%20Town',
            EventPresentation::mapUrl(null, null, '2 Sample Road, Cape Town')
        );
        self::assertNull(EventPresentation::mapUrl(null, null, ''));
    }
}
