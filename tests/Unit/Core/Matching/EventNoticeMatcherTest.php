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
            self::assertSame(17, $result['event_id'], $pair['name']);

            if ($pair['expected'] === 'duplicate') {
                $original['event_id'] = null;
                $pending = $matcher->match($incoming, [$original]);
                self::assertSame('duplicate', $pending['kind']);
                self::assertSame(9, $pending['candidate_id']);
                self::assertNull($pending['event_id']);
            }
        }
    }

    public function testUnsafeMatchesRemainNewAndRequireReview(): void
    {
        $matcher = new EventNoticeMatcher();
        $incoming = ['parish_id' => 4, 'fields' => [
            'title' => 'Parish market', 'event_date' => '2026-10-17', 'event_time' => '11:00',
        ], 'recurrence' => []];
        $prior = [
            'id' => 9, 'parish_id' => 4, 'event_id' => null, 'recurrence' => [],
            'fields' => ['title' => 'Parish market', 'event_date' => '2026-10-17', 'event_time' => '10:00'],
        ];
        self::assertSame('new', $matcher->match($incoming, [$prior])['kind']);
        self::assertNotSame('', $matcher->match($incoming, [$prior])['note']);
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
}
