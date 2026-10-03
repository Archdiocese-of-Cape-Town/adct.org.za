<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\IcsCalendar;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

final class IcsCalendarTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function event(array $overrides = []): array
    {
        return $overrides + [
            'id' => 42,
            'uid_domain' => 'adct.org.za',
            'title' => 'Fictional event',
            'description' => '',
            'url' => 'https://example.test/events/42',
            'modified' => '2026-09-25 11:00:00 UTC',
            'start' => '2026-10-02T18:00',
            'end' => '2026-10-02T19:00',
            'all_day' => false,
            'rrule' => '',
            'exdates' => [],
            'rdates' => [],
            'cancelled' => false,
        ];
    }

    /** @return list<string> */
    private static function lines(string $body): array
    {
        $lines = explode("\r\n", $body);
        self::assertSame('', end($lines), 'The calendar body must end with a CRLF.');
        array_pop($lines);
        return $lines;
    }
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

            public function testBodyUsesCrlfOnlyAndNoFloatingTimestamps(): void
            {
                $body = (new IcsCalendar())->render([self::event([
                    'title' => 'Bell auditorium',
                    'description' => 'Line one\nLine two',
                ])]);

                self::assertStringNotContainsString("\n", str_replace("\r\n", '', $body), 'Bare LF must not appear.');
                self::assertSame("\r\n", substr($body, -2));

                // Inside VEVENT every datetime either carries TZID, an explicit UTC designator, or is a DATE value.
                $inEvent = false;
                $checked = 0;
                foreach (self::lines($body) as $line) {
                    if ($line === 'BEGIN:VEVENT') {
                        $inEvent = true;
                        continue;
                    }
                    if ($line === 'END:VEVENT') {
                        $inEvent = false;
                        continue;
                    }
                    if (! $inEvent) {
                        continue;
                    }
                    foreach (['DTSTART', 'DTEND', 'DTSTAMP', 'LAST-MODIFIED', 'EXDATE', 'RDATE'] as $property) {
                        if (! str_starts_with($line, $property . ':') && ! str_starts_with($line, $property . ';')) {
                            continue;
                        }
                        $checked++;
                        self::assertMatchesRegularExpression(
                            '/(TZID=Africa\/Johannesburg:[0-9]{8}T[0-9]{6}'
                            . '|VALUE=DATE:[0-9]{8}'
                            . '|[0-9]{8}T[0-9]{6}Z)$/',
                            $line,
                            'Floating local timestamps are not allowed: ' . $line
                        );
                    }
                }
                self::assertGreaterThanOrEqual(4, $checked, 'Expected DTSTART, DTEND, DTSTAMP and LAST-MODIFIED.');
            }

            public function testControlCharactersAreRemovedBecauseFeedReadersCannotRenderThem(): void
            {
                $body = (new IcsCalendar())->render([self::event([
                    'title' => "Bell\x07auditorium\x00and\x1B\tvtab\x7Fdel",
                    'description' => "Para\x0Cform\rfeed",
                ])]);
                $unfolded = str_replace("\r\n ", '', $body);

                // Only the control characters go; the readable words around them are untouched.
                self::assertStringContainsString("SUMMARY:Bellauditoriumand\tvtabdel", $unfolded);
                self::assertStringContainsString('DESCRIPTION:Paraform\nfeed', $unfolded);
                foreach (self::lines($body) as $line) {
                    self::assertSame(
                        1,
                        preg_match('/\A[^\x00-\x08\x0A-\x1F\x7F]*\z/', $line) === 1 ? 1 : 0,
                        'Control characters must not survive into the feed: ' . bin2hex($line)
                    );
                }
            }

            public function testInvalidUtf8KeepsThePropertyInsteadOfDroppingIt(): void
            {
                $body = (new IcsCalendar())->render([self::event([
                    'title' => "Caf\xE9 \xFF bell",
                    'description' => '',
                ])]);
                $unfolded = str_replace("\r\n ", '', $body);

                self::assertStringContainsString('SUMMARY:', $unfolded, 'A SUMMARY line must always be emitted.');
                self::assertMatchesRegularExpression('/SUMMARY:.*Caf.{1,4} bell/', $unfolded);
                self::assertSame(1, preg_match('//u', $body), 'The body must be valid UTF-8.');
            }

            public function testLongValuesAreTruncatedOnACharacterBoundary(): void
            {
                $body = (new IcsCalendar())->render([self::event([
                    'title' => 'A' . str_repeat('ä', 4000),
                    'description' => 'B' . str_repeat('é', 40000),
                ])]);
                $unfolded = str_replace("\r\n ", '', $body);

                self::assertSame(1, preg_match('//u', $body));
                $summary = $this->valueOf($unfolded, 'SUMMARY:');
                $description = $this->valueOf($unfolded, 'DESCRIPTION:');
                self::assertLessThanOrEqual(IcsCalendar::SUMMARY_MAX_CHARS + 1, mb_strlen($summary, 'UTF-8'));
                self::assertLessThanOrEqual(IcsCalendar::DESCRIPTION_MAX_CHARS + 1, mb_strlen($description, 'UTF-8'));
                self::assertStringEndsWith('…', $summary);
                self::assertStringEndsWith('…', $description);
            }

            public function testMultiByteCharactersNeverSplitAcrossAFold(): void
            {
                // 60 four-byte characters need three folded lines; a naive byte counter
                // would fold mid-character and corrupt the value.
                $body = (new IcsCalendar())->render([self::event([
                                    'title' => str_repeat('🛎', 60),
                ])]);

                self::assertSame(1, preg_match('//u', $body), 'Folding must not split a UTF-8 character.');
                foreach (self::lines($body) as $line) {
                    self::assertLessThanOrEqual(75, strlen($line), 'Content lines must fold at 75 octets.');
                }
                self::assertSame(
                                    str_repeat('🛎', 60),
                    $this->valueOf(str_replace("\r\n ", '', $body), 'SUMMARY:'),
                    'Unfolding must restore the exact value.'
                );
                                $continuations = array_filter(self::lines($body), static fn (string $l): bool => str_starts_with($l, ' '));
                                self::assertGreaterThan(
                                    1,
                                    count($continuations),
                                    'This title is expected to need more than one folded line.'
                                );
            }

            public function testRenderedCalendarParsesAsValidRfc5545(): void
            {
                $body = (new IcsCalendar())->render([
                    self::event([
                        'id' => 42,
                        'title' => "Fictional, gathering; Café\nEvening",
                        'description' => "A \\ sample\r\nSecond line",
                        'rrule' => 'FREQ=WEEKLY;COUNT=5',
                        'exdates' => ['2026-10-09T18:00'],
                        'rdates' => ['2026-10-10T18:00'],
                        'cancelled' => true,
                    ]),
                    self::event([
                        'id' => 43,
                        'title' => str_repeat('Long ', 200),
                        'description' => str_repeat('Body ', 2000),
                        'start' => '2026-11-05T09:30',
                        'end' => '2026-11-05T12:00',
                        'all_day' => true,
                        'rrule' => 'FREQ=YEARLY;UNTIL=20291002',
                    ]),
                ]);

                $calendar = Reader::read($body);
                self::assertSame('2.0', (string) $calendar->VERSION);
                self::assertSame('GREGORIAN', (string) $calendar->CALSCALE);
                self::assertSame('PUBLISH', (string) $calendar->METHOD);

                $events = $calendar->select('VEVENT');
                self::assertCount(2, $events);
                self::assertSame('adct-event-42@adct.org.za', (string) $events[0]->UID);
                self::assertSame('Fictional, gathering; Café' . "\n" . 'Evening', $events[0]->SUMMARY->getValue());
                self::assertSame('A \\ sample' . "\n" . 'Second line', $events[0]->DESCRIPTION->getValue());
                self::assertSame('CANCELLED', (string) $events[0]->STATUS);
                self::assertSame('FREQ=WEEKLY;COUNT=5', (string) $events[0]->RRULE);
                self::assertSame('2026-10-02T18:00:00+02:00', $events[0]->DTSTART->getDateTime()->format('c'));

                self::assertStringStartsWith('Long Long', $events[1]->SUMMARY->getValue());
                self::assertSame('20261105', (string) $events[1]->DTSTART);
                self::assertSame('20261106', (string) $events[1]->DTEND, 'All-day end is exclusive.');
            }

            private function valueOf(string $body, string $prefix): string
            {
                foreach (explode("\r\n", $body) as $line) {
                    if (str_starts_with($line, $prefix)) {
                        return substr($line, strlen($prefix));
                    }
                }
                self::fail('Missing ' . $prefix . ' line.');
            }
        }
