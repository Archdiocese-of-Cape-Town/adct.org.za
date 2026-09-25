<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Parsing\ParseOutcome;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\EventCandidateRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressEventCandidateStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EventCandidateRepositoryTest extends TestCase
{
    public function testReprocessingUpdatesDraftCandidateAndInsertsNewBlockOnce(): void
    {
        $database = new EventCandidateRepositoryDatabase();
        $database->resultRows = [['id' => '12', 'block_index' => '0', 'status' => 'draft']];
        $repository = new EventCandidateRepository($database);

        $repository->replaceDraftCandidatesForMessage(
            41,
            [
                $this->candidate(0, 'Updated community supper'),
                $this->candidate(1, 'New parish meeting'),
            ],
            '2026-09-25 04:10:00'
        );

        self::assertCount(4, $database->queries);
        self::assertSame('START TRANSACTION', $database->queries[0]);
        self::assertStringContainsString('UPDATE wp_adct_pi_event_candidates', $database->queries[1]);
        self::assertStringContainsString('`match_event_id` = NULL', $database->prepared[1]['query']);
        self::assertStringContainsString('INSERT INTO wp_adct_pi_event_candidates', $database->queries[2]);
        self::assertSame('COMMIT', $database->queries[3]);
        self::assertStringContainsString('FOR UPDATE', $database->prepared[0]['query']);
        self::assertStringNotContainsString('status =', $database->prepared[1]['query']);
        self::assertCount(3, $database->prepared);
    }

    public function testReprocessingPreservesReviewedCandidateAndItsStatus(): void
    {
        $database = new EventCandidateRepositoryDatabase();
        $database->resultRows = [[
            'id' => '12',
            'block_index' => '0',
            'status' => 'awaiting_approval',
            'match_event_id' => '573',
            'match_kind' => 'exact',
        ]];
        $repository = new EventCandidateRepository($database);

        $repository->replaceDraftCandidatesForMessage(
            41,
            [$this->candidate(0, 'Reparsed but reviewed event')],
            '2026-09-25 04:10:00'
        );

        self::assertSame(['START TRANSACTION', 'COMMIT'], $database->queries);
        self::assertCount(1, $database->prepared);
        self::assertStringContainsString('SELECT `id`, `block_index`, `status`', $database->prepared[0]['query']);
    }

    public function testReprocessingRemovesStaleDraftsWhenTheNewParseHasNoCandidates(): void
    {
        $database = new EventCandidateRepositoryDatabase();
        $database->resultRows = [['id' => '12', 'block_index' => '0', 'status' => 'draft']];
        $repository = new EventCandidateRepository($database);

        $repository->replaceDraftCandidatesForMessage(41, [], '2026-09-25 04:10:00');

        self::assertCount(3, $database->queries);
        self::assertStringContainsString('DELETE FROM wp_adct_pi_event_candidates', $database->queries[1]);
        self::assertSame('COMMIT', $database->queries[2]);
    }

    public function testEventCandidateStorePersistsTheTrimmedSourceSnippet(): void
    {
        $database = new EventCandidateRepositoryDatabase();
        $candidate = new ParseResult();
        $candidate->setField('title', 'Example community supper');
        $candidate->setField('parish_id', 7);
        $candidate->setConfidence(0.82);
        $candidate->setNeedsReprocess(true);
        $candidate->setBlockMetadata(0, 'A short, fictional source excerpt.');
        $outcome = new ParseOutcome([$candidate], [], [], [], $candidate);
        $store = new WordPressEventCandidateStore(new EventCandidateRepository($database));

        $store->replaceDraftCandidatesForMessage(41, $outcome, '2026-09-25 04:10:00');

        $fieldsJson = $database->prepared[4]['arguments'][1];
        $fields = json_decode($fieldsJson, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('A short, fictional source excerpt.', $fields['source_snippet']);
        self::assertSame(true, $fields['reprocess_needed']);
        self::assertSame(7, $database->prepared[4]['arguments'][0]);
    }

    public function testDuplicateBlockIndexesAreRejectedBeforeOpeningATransaction(): void
    {
        $database = new EventCandidateRepositoryDatabase();
        $repository = new EventCandidateRepository($database);

        $this->expectException(InvalidArgumentException::class);
        $repository->replaceDraftCandidatesForMessage(
            41,
            [
                $this->candidate(0, 'First event'),
                $this->candidate(0, 'Duplicate event'),
            ],
            '2026-09-25 04:10:00'
        );
    }

    public function testASecondBulletinLinksToPendingCandidateWithoutCreatingAnotherDraft(): void
    {
        $database = new EventCandidateRepositoryDatabase();
        $database->matchRows = [[
            'id' => '12',
            'parish_id' => '7',
            'fields' => '{"title":"First Friday healing Mass","event_date":"2026-10-02","event_time":"18:00","description":"Healing Mass every first Friday."}',
            'recurrence' => '{"rrule":"FREQ=MONTHLY;BYDAY=1FR"}',
            'match_event_id' => null,
            'status' => 'awaiting_approval',
        ]];
        $repository = new EventCandidateRepository($database);
        $fields = [
            'title' => 'First Friday healing Mass',
            'event_date' => '2026-11-06',
            'event_time' => '18:00',
            'description' => 'Healing Mass every first Friday.',
        ];
        $candidate = $this->candidate(0, $fields['title']);
        $candidate['parish_id'] = 7;
        $candidate['fields'] = json_encode($fields, JSON_THROW_ON_ERROR);
        $candidate['recurrence'] = '{"rrule":"FREQ=MONTHLY;BYDAY=1FR"}';

        $repository->replaceDraftCandidatesForMessage(42, [$candidate], '2026-11-01 10:00:00');
        $insert = array_values(array_filter(
            $database->prepared,
            static fn (array $call): bool => str_contains($call['query'], 'INSERT INTO wp_adct_pi_event_candidates')
        ))[0];
        self::assertContains('duplicate', $insert['arguments']);
        self::assertSame(12, json_decode($insert['arguments'][1], true)['matched_candidate_id']);
        self::assertStringContainsString('SELECT source_id', $database->prepared[0]['query']);
        self::assertStringContainsString('FOR UPDATE', $database->prepared[0]['query']);
        self::assertStringContainsString('SELECT id FROM wp_adct_pi_sources', $database->prepared[1]['query']);
        self::assertStringContainsString('FOR UPDATE', $database->prepared[1]['query']);
        self::assertStringContainsString('LIMIT 201', $database->prepared[2]['query']);
        self::assertSame('COMMIT', end($database->queries));
    }

    public function testChangedPendingCandidateKeepsChangeKindAndManualReviewFlag(): void
    {
        $database = new EventCandidateRepositoryDatabase();
        $database->matchRows = [[
            'id' => '12',
            'parish_id' => '7',
            'fields' => '{"title":"Parish market","event_date":"2026-10-17","event_time":"10:00"}',
            'recurrence' => null,
            'match_event_id' => null,
            'status' => 'awaiting_submitter',
        ]];
        $candidate = $this->candidate(0, 'Parish market');
        $candidate['parish_id'] = 7;
        $candidate['fields'] = json_encode([
            'title' => 'Parish market',
            'event_date' => '2026-10-17',
            'event_time' => '11:00',
        ], JSON_THROW_ON_ERROR);
        (new EventCandidateRepository($database))->replaceDraftCandidatesForMessage(
            42,
            [$candidate],
            '2026-10-17 10:00:00'
        );

        $insert = array_values(array_filter(
            $database->prepared,
            static fn (array $call): bool => str_contains($call['query'], 'INSERT INTO wp_adct_pi_event_candidates')
        ))[0];
        $fields = json_decode($insert['arguments'][1], true, 32, JSON_THROW_ON_ERROR);
        self::assertContains('update', $insert['arguments']);
        self::assertContains('draft', $insert['arguments']);
        self::assertSame(12, $fields['matched_candidate_id']);
        self::assertTrue($fields['match_review_required']);
        self::assertNull($fields['match_event_id'] ?? null);
    }

    public function testPostponementOfPendingCandidateRemainsADraftForReview(): void
    {
        $database = new EventCandidateRepositoryDatabase();
        $database->matchRows = [[
            'id' => '12',
            'parish_id' => '7',
            'fields' => '{"title":"Parish market","event_date":"2026-10-10","event_time":"09:00"}',
            'recurrence' => null,
            'match_event_id' => null,
            'status' => 'awaiting_submitter',
        ]];
        $candidate = $this->candidate(0, 'Postponement: Parish market');
        $candidate['parish_id'] = 7;
        $candidate['fields'] = json_encode([
            'title' => 'Postponement: Parish market',
            'event_date' => '2026-10-17',
            'event_time' => '10:00',
            'source_snippet' => 'The market is postponed to 17 October 2026 at 10:00.',
        ], JSON_THROW_ON_ERROR);

        (new EventCandidateRepository($database))->replaceDraftCandidatesForMessage(
            42,
            [$candidate],
            '2026-10-01 09:00:00'
        );

        $insert = array_values(array_filter(
            $database->prepared,
            static fn (array $call): bool => str_contains($call['query'], 'INSERT INTO wp_adct_pi_event_candidates')
        ))[0];
        $fields = json_decode($insert['arguments'][1], true, 32, JSON_THROW_ON_ERROR);
        self::assertContains('postponement', $insert['arguments']);
        self::assertContains('draft', $insert['arguments']);
        self::assertSame(12, $fields['matched_candidate_id']);
        self::assertTrue($fields['match_review_required']);
        self::assertNotContains(17, $insert['arguments']);
    }

    public function testUnresolvedPublishedChangeDoesNotPersistAnAutomaticEventMatch(): void
    {
        $database = new EventCandidateRepositoryDatabase();
        $database->matchRows = [[
            'id' => '12',
            'parish_id' => '7',
            'fields' => '{"title":"Parish market","event_date":"2026-10-10","event_time":"09:00"}',
            'recurrence' => null,
            'match_event_id' => '17',
            'status' => 'published',
            'live_title' => 'Parish market',
            'live_description' => '',
            'live_start' => '2026-10-10T09:00',
            'live_rule' => '',
        ]];
        $candidate = $this->candidate(0, 'Postponement: Parish market');
        $candidate['parish_id'] = 7;
        $candidate['fields'] = json_encode([
            'title' => 'Postponement: Parish market',
            'event_date' => '2026-10-10',
            'event_time' => '09:00',
            'replacement_schedule_unresolved' => true,
        ], JSON_THROW_ON_ERROR);

        (new EventCandidateRepository($database))->replaceDraftCandidatesForMessage(
            42,
            [$candidate],
            '2026-10-01 09:00:00'
        );

        $insert = array_values(array_filter(
            $database->prepared,
            static fn (array $call): bool => str_contains($call['query'], 'INSERT INTO wp_adct_pi_event_candidates')
        ))[0];
        $fields = json_decode($insert['arguments'][1], true, 32, JSON_THROW_ON_ERROR);
        self::assertContains('new', $insert['arguments']);
        self::assertContains('draft', $insert['arguments']);
        self::assertTrue($fields['match_review_required']);
        self::assertNotContains(17, $insert['arguments']);
        self::assertStringContainsString(
            'manual review',
            implode(' ', array_filter($insert['arguments'], 'is_string'))
        );
    }

    /**
     * @return array{
     *     block_index: int,
     *     parish_id: int|null,
     *     fields: string,
     *     recurrence: string|null,
     *     confidence: float,
     *     parser_version: string,
     *     strategies: string,
     *     notes: string,
     *     ai_used: int,
     *     ai_provider: string|null,
     *     ai_model: string|null
     * }
     */
    private function candidate(int $blockIndex, string $title): array
    {
        return [
            'block_index' => $blockIndex,
            'parish_id' => null,
            'fields' => json_encode(['title' => $title], JSON_THROW_ON_ERROR),
            'recurrence' => null,
            'confidence' => 0.75,
            'parser_version' => '0.1.0',
            'strategies' => '[]',
            'notes' => '[]',
            'ai_used' => 0,
            'ai_provider' => null,
            'ai_model' => null,
        ];
    }
}

final class EventCandidateRepositoryDatabase implements DatabaseConnectionInterface
{
    /**
     * @var list<array{query: string, arguments: array<int, mixed>}>
     */
    public array $prepared = [];

    /** @var list<string> */
    public array $queries = [];

    /** @var list<array<string, mixed>> */
    public array $resultRows = [];

    /** @var list<array<string, mixed>> */
    public array $matchRows = [];

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->prepared[] = ['query' => $query, 'arguments' => $arguments];

        return $query;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;

        return 1;
    }

    public function getRow(string $query): ?array
    {
        if (str_contains($query, 'SELECT source_id')) {
            return ['source_id' => '4'];
        }
        if (str_contains($query, 'SELECT id FROM wp_adct_pi_sources')) {
            return ['id' => '4'];
        }
        return null;
    }

    public function getResults(string $query): array
    {
        if (str_contains($query, 'JOIN wp_adct_pi_inbound_messages')) {
            return $this->matchRows;
        }
        return $this->resultRows;
    }

    public function escapeLike(string $text): string
    {
        return $text;
    }

    public function insertId(): int
    {
        return 92;
    }

    public function charsetCollate(): string
    {
        return '';
    }

    public function clearLastError(): void
    {
    }

    public function lastError(): string
    {
        return '';
    }
}
