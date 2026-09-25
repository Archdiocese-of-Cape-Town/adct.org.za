<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Matching;

use ADCT\ParishIntake\Core\Matching\EventNoticeMatcher;
use PHPUnit\Framework\TestCase;

final class EventNoticeMatcherTest extends TestCase
{
    public function testNoticePairsClassifyPublishedAndPendingMatches(): void
    {
        $pairs = json_decode(
            file_get_contents(dirname(__DIR__, 3) . '/fixtures/matching/notices.json'),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $matcher = new EventNoticeMatcher();
        foreach ($pairs as $pair) {
            $recurrence = isset($pair['rrule']) ? ['rrule' => $pair['rrule']] : [];
            $original = [
                'id' => 9, 'parish_id' => 4, 'event_id' => 17,
                'fields' => $pair['original'], 'recurrence' => $recurrence,
            ];
            $incoming = [
                'parish_id' => 4, 'fields' => $pair['repeat'], 'recurrence' => $recurrence,
            ];
            $result = $matcher->match($incoming, [$original]);
            self::assertSame($pair['expected'], $result['kind'], $pair['name']);
            self::assertSame(
                $pair['expected'] === 'new' ? null : 17,
                $result['event_id'],
                $pair['name']
            );

            if ($pair['expected'] === 'duplicate') {
                $original['event_id'] = null;
                $pending = $matcher->match($incoming, [$original]);
                self::assertSame('duplicate', $pending['kind']);
                self::assertSame(9, $pending['candidate_id']);
                self::assertNull($pending['event_id']);
            }
        }
    }

    public function testChangesToPendingCandidatesRetainKindAndRequireManualReview(): void
    {
        $matcher = new EventNoticeMatcher();
        $incoming = ['parish_id' => 4, 'fields' => [
            'title' => 'Parish market', 'event_date' => '2026-10-17', 'event_time' => '11:00',
        ], 'recurrence' => []];
        $prior = [
            'id' => 9, 'parish_id' => 4, 'event_id' => null, 'recurrence' => [],
            'fields' => ['title' => 'Parish market', 'event_date' => '2026-10-17', 'event_time' => '10:00'],
        ];
        $pendingChange = $matcher->match($incoming, [$prior]);
        self::assertSame('update', $pendingChange['kind']);
        self::assertSame(9, $pendingChange['candidate_id']);
        self::assertNull($pendingChange['event_id']);
        self::assertNotSame('', $pendingChange['note']);
        $incoming['fields']['title'] = 'Cancellation: Parish market';
        $cancellation = $matcher->match($incoming, [$prior]);
        self::assertSame('cancellation', $cancellation['kind']);
        self::assertSame(9, $cancellation['candidate_id']);
        $incoming['fields']['title'] = 'Parish market';
        $prior['parish_id'] = 5;
        self::assertSame(0.0, $matcher->match($incoming, [$prior])['score']);
        $prior['parish_id'] = 4;
        $prior['event_id'] = 17;
        $prior['fields']['event_date'] = '2026-12-31';
        self::assertSame('new', $matcher->match($incoming, [$prior])['kind']);
        $prior['fields'] = $incoming['fields'];
        self::assertSame('new', $matcher->match($incoming, [
            $prior, array_merge($prior, ['id' => 10, 'event_id' => 18]),
        ])['kind']);
    }

    public function testExplicitNearTitleDateChangeMatchesWithTheDocumentedThreshold(): void
    {
        $matcher = new EventNoticeMatcher();
        $result = $matcher->match([
            'parish_id' => 4,
            'fields' => [
                'title' => 'Community parish summer markets',
                'event_date' => '2026-10-24',
                'event_time' => '10:00',
                'source_snippet' => 'The event has been rescheduled.',
            ],
            'recurrence' => [],
        ], [[
            'id' => 9,
            'parish_id' => 4,
            'event_id' => 17,
            'fields' => [
                'title' => 'Community parish summer market',
                'event_date' => '2026-10-17',
                'event_time' => '10:00',
            ],
            'recurrence' => [],
        ]]);

        self::assertSame('update', $result['kind']);
        self::assertSame(17, $result['event_id']);
        self::assertGreaterThanOrEqual(0.88, $result['score']);
    }

    public function testChangedRecurrenceDatesAreNotClassifiedAsAnUnchangedDuplicate(): void
    {
        $matcher = new EventNoticeMatcher();
        $result = $matcher->match([
            'parish_id' => 4,
            'fields' => [
                'title' => 'Community Bible study',
                'event_date' => '2026-10-08',
                'event_time' => '18:00',
                'description' => 'Community Bible study every Thursday.',
                'exdates' => ['2026-12-24T18:00'],
            ],
            'recurrence' => ['rrule' => 'FREQ=WEEKLY;BYDAY=TH'],
        ], [[
            'id' => 9,
            'parish_id' => 4,
            'event_id' => 17,
            'fields' => [
                'title' => 'Community Bible study',
                'event_date' => '2026-10-01',
                'event_time' => '18:00',
                'description' => 'Community Bible study every Thursday.',
            ],
            'recurrence' => ['rrule' => 'FREQ=WEEKLY;BYDAY=TH'],
        ]]);

        self::assertSame('update', $result['kind']);
    }

    public function testUnresolvedReplacementCannotSilentlyUpdateAPublishedEvent(): void
    {
        $match = (new EventNoticeMatcher())->match([
            'parish_id' => 4,
            'fields' => [
                'title' => 'Postponement: Parish market',
                'event_date' => '2026-10-10',
                'event_time' => '09:00',
                'replacement_schedule_unresolved' => true,
                'source_snippet' => 'The market is postponed to a later date.',
            ],
            'recurrence' => [],
        ], [[
            'id' => 9,
            'parish_id' => 4,
            'event_id' => 17,
            'fields' => [
                'title' => 'Parish market',
                'event_date' => '2026-10-10',
                'event_time' => '09:00',
            ],
            'recurrence' => [],
        ]]);

        self::assertSame('new', $match['kind']);
        self::assertNull($match['event_id']);
        self::assertNotSame('', $match['note']);
    }
}
