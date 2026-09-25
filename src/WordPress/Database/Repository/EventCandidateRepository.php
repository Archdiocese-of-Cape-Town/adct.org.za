<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Matching\EventNoticeMatcher;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class EventCandidateRepository extends AbstractRepository
{
    protected const TABLE_SUFFIX = 'adct_pi_event_candidates';

    protected const FIELD_FORMATS = [
        'message_id' => '%d',
        'block_index' => '%d',
        'parish_id' => '%d',
        'fields' => '%s',
        'recurrence' => '%s',
        'confidence' => '%f',
        'parser_version' => '%s',
        'strategies' => '%s',
        'notes' => '%s',
        'ai_used' => '%d',
        'ai_provider' => '%s',
        'ai_model' => '%s',
        'match_event_id' => '%d',
        'match_kind' => '%s',
        'status' => '%s',
        'confirmed_by' => '%s',
        'confirmed_at' => '%s',
        'approved_by' => '%s',
        'approved_at' => '%s',
        'approved_via' => '%s',
        'decided_by' => '%s',
        'decided_at' => '%s',
        'decision_note' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];

    /**
     * Replace only unreviewed candidates for a stored message.
     *
     * @param list<array{
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
     * }> $candidates
     */
    public function replaceDraftCandidatesForMessage(
        int $messageId,
        array $candidates,
        string $timestamp
    ): void {
        if ($messageId < 1) {
            throw new InvalidArgumentException('An event candidate message ID must be positive.');
        }

        if (count($candidates) > 50) {
            throw new InvalidArgumentException('A message cannot have more than 50 event candidates.');
        }

        $candidatesByBlock = [];

        foreach ($candidates as $candidate) {
            $blockIndex = $candidate['block_index'] ?? null;

            if (! is_int($blockIndex) || $blockIndex < 0) {
                throw new InvalidArgumentException('Every event candidate needs a non-negative block index.');
            }

            if (isset($candidatesByBlock[$blockIndex])) {
                throw new InvalidArgumentException('A message cannot have duplicate event candidate block indexes.');
            }

            $candidateValues = $candidate;
            unset($candidateValues['block_index']);
            $candidatesByBlock[$blockIndex] = $candidateValues;
        }

        $this->executeTransactionQuery('START TRANSACTION');

        try {
            $matched = [];
            if (array_filter($candidatesByBlock, static fn (array $row): bool => $row['parish_id'] !== null) !== []) {
                $messages = $this->database->prefix() . 'adct_pi_inbound_messages';
                $source = $this->fetchRow($this->database->prepare(
                    "SELECT source_id FROM {$messages} WHERE id = %d FOR UPDATE",
                    $messageId
                ));
                if ($source === null || (int) ($source['source_id'] ?? 0) < 1) {
                    throw new RuntimeException('The event candidate source could not be locked for matching.');
                }
                $sources = $this->database->prefix() . 'adct_pi_sources';
                $posts = $this->database->prefix() . 'posts';
                $postmeta = $this->database->prefix() . 'postmeta';
                if ($this->fetchRow($this->database->prepare(
                    "SELECT id FROM {$sources} WHERE id = %d FOR UPDATE",
                    (int) $source['source_id']
                )) === null) {
                    throw new RuntimeException('The event candidate source no longer exists.');
                }
                $matcher = new EventNoticeMatcher();
                foreach ($candidatesByBlock as $blockIndex => $values) {
                    if ($values['parish_id'] === null) {
                        continue;
                    }
                    $rows = $this->fetchRows($this->database->prepare(
                        'SELECT c.id, c.parish_id, c.fields, c.recurrence, c.match_event_id, c.status,'
                        . ' p.post_title AS live_title, p.post_content AS live_description,'
                        . ' start_meta.meta_value AS live_start, rule_meta.meta_value AS live_rule'
                        . ' FROM ' . $this->tableName() . ' c'
                        . " JOIN {$messages} m ON m.id = c.message_id"
                        . " LEFT JOIN {$posts} p ON p.ID = c.match_event_id"
                        . " AND p.post_type = 'adct_event' AND p.post_status = 'publish'"
                        . " LEFT JOIN {$postmeta} source_meta ON source_meta.post_id = p.ID"
                        . " AND source_meta.meta_key = 'source_candidate_id'"
                        . " LEFT JOIN {$postmeta} parish_meta ON parish_meta.post_id = p.ID"
                        . " AND parish_meta.meta_key = 'parish_id'"
                        . " LEFT JOIN {$postmeta} start_meta ON start_meta.post_id = p.ID"
                        . " AND start_meta.meta_key = 'start_local'"
                        . " LEFT JOIN {$postmeta} rule_meta ON rule_meta.post_id = p.ID"
                        . " AND rule_meta.meta_key = 'rrule'"
                        . ' WHERE m.source_id = %d AND c.parish_id = %d AND c.message_id <> %d'
                        . " AND c.status IN ('draft','awaiting_submitter','awaiting_approval','approved','published')"
                        . " AND (c.status <> 'published' OR (p.ID IS NOT NULL"
                        . ' AND source_meta.meta_value = CAST(c.id AS CHAR)'
                        . ' AND parish_meta.meta_value = CAST(c.parish_id AS CHAR)))'
                        . " AND (c.status <> 'draft' OR c.match_event_id IS NULL)"
                        . ' ORDER BY c.id DESC LIMIT 201',
                        (int) $source['source_id'],
                        $values['parish_id'],
                        $messageId
                    ));
                    if (count($rows) > 200) {
                        $matched[$blockIndex] = [
                            'kind' => 'new', 'event_id' => null, 'candidate_id' => null,
                            'score' => 0.0, 'note' => 'Too many prior notices; match requires manual review.',
                        ];
                        continue;
                    }
                    $existing = [];
                    foreach ($rows as $row) {
                        $fields = json_decode((string) $row['fields'], true, 32, JSON_THROW_ON_ERROR);
                        $recurrence = $row['recurrence'] === null
                            ? [] : json_decode((string) $row['recurrence'], true, 32, JSON_THROW_ON_ERROR);
                        if (! is_array($fields) || ! is_array($recurrence)) {
                            throw new RuntimeException('A stored candidate has invalid match data.');
                        }
                        $eventId = $row['status'] === 'published' ? (int) $row['match_event_id'] : null;
                        if ($row['status'] === 'published' && $eventId < 1) {
                            throw new RuntimeException('A published candidate has no matching event.');
                        }
                        if ($eventId !== null && (
                            ($row['live_title'] ?? null) !== ($fields['title'] ?? null)
                            || ($row['live_description'] ?? null) !== ($fields['description'] ?? '')
                            || ($row['live_start'] ?? null) !==
                                (($fields['event_date'] ?? '') . 'T' . ($fields['event_time'] ?? '00:00'))
                            || ($row['live_rule'] ?? null) !== ($recurrence['rrule'] ?? '')
                        )) {
                            continue;
                        }
                        $existing[] = [
                            'id' => (int) $row['id'],
                            'parish_id' => (int) $row['parish_id'],
                            'fields' => $fields,
                            'recurrence' => $recurrence,
                            'event_id' => $eventId,
                        ];
                    }
                    $matched[$blockIndex] = $matcher->match([
                        'parish_id' => $values['parish_id'],
                        'fields' => json_decode($values['fields'], true, 32, JSON_THROW_ON_ERROR),
                        'recurrence' => $values['recurrence'] === null
                            ? [] : json_decode($values['recurrence'], true, 32, JSON_THROW_ON_ERROR),
                    ], $existing);
                    if ($matched[$blockIndex]['event_id'] !== null) {
                        foreach ($existing as $prior) {
                            if ($prior['event_id'] === $matched[$blockIndex]['event_id']) {
                                $matched[$blockIndex]['baseline'] = $prior;
                                break;
                            }
                        }
                    }
                }
            }
            $existingRows = $this->fetchRows($this->database->prepare(
                'SELECT `id`, `block_index`, `status` FROM ' . $this->tableName()
                . ' WHERE `message_id` = %d ORDER BY `block_index` ASC FOR UPDATE',
                $messageId
            ));

            foreach ($existingRows as $existingRow) {
                $candidateId = filter_var(
                    $existingRow['id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                $blockIndex = filter_var(
                    $existingRow['block_index'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 0]]
                );

                if (! is_int($candidateId) || ! is_int($blockIndex)) {
                    throw new RuntimeException('An existing event candidate has invalid identity data.');
                }

                if (($existingRow['status'] ?? null) !== 'draft') {
                    unset($candidatesByBlock[$blockIndex]);
                    continue;
                }

                if (! isset($candidatesByBlock[$blockIndex])) {
                    if ($this->delete($candidateId) < 1) {
                        throw new RuntimeException('A stale draft event candidate could not be removed.');
                    }

                    continue;
                }

                $candidateValues = $this->withMatch($candidatesByBlock[$blockIndex], $matched[$blockIndex] ?? null);
                $values = array_merge(
                    $candidateValues,
                    [
                        'updated_at' => $timestamp,
                    ]
                );
                $this->update($candidateId, $values);
                unset($candidatesByBlock[$blockIndex]);
            }

            foreach ($candidatesByBlock as $blockIndex => $values) {
                $this->insert(array_merge(
                    $this->withMatch($values, $matched[$blockIndex] ?? null),
                    [
                        'message_id' => $messageId,
                        'block_index' => $blockIndex,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]
                ));
            }

            $this->executeTransactionQuery('COMMIT');
        } catch (Throwable $failure) {
            $this->rollbackTransaction($failure);
            throw $failure;
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array{kind: string, event_id: int|null, candidate_id: int|null, score: float, note: string}|null $match
     * @return array<string, mixed>
     */
    private function withMatch(array $values, ?array $match): array
    {
        $match ??= ['kind' => 'new', 'event_id' => null, 'candidate_id' => null, 'score' => 0.0, 'note' => ''];
        $fields = json_decode($values['fields'], true, 32, JSON_THROW_ON_ERROR);
        $notes = json_decode($values['notes'], true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($fields) || ! is_array($notes)) {
            throw new RuntimeException('Candidate match data is invalid.');
        }
        if ($match['candidate_id'] !== null) {
            $fields['matched_candidate_id'] = $match['candidate_id'];
        }
        if ($match['event_id'] !== null && isset($match['baseline'])) {
            foreach (['event_date', 'event_time', 'event_end_date', 'event_end_time'] as $key) {
                if (! isset($fields[$key]) && isset($match['baseline']['fields'][$key])) {
                    $fields[$key] = $match['baseline']['fields'][$key];
                }
            }
            if ($values['recurrence'] === null && $match['baseline']['recurrence'] !== []) {
                $values['recurrence'] = json_encode($match['baseline']['recurrence'], JSON_THROW_ON_ERROR);
            }
        }
        if ($match['note'] !== '') {
            $notes[] = $match['note'];
            $fields['match_review_required'] = true;
        }
        $fields['match_score'] = $match['score'];
        $values['fields'] = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $values['notes'] = json_encode($notes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $values['match_event_id'] = $match['event_id'];
        $values['match_kind'] = $match['kind'];
        $values['status'] = $match['kind'] === 'duplicate' ? 'duplicate' : 'draft';
        return $values;
    }

    private function executeTransactionQuery(string $query): void
    {
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false) {
            throw new RuntimeException(
                'The event candidate transaction could not be completed: ' . $this->database->lastError()
            );
        }
    }

    private function rollbackTransaction(Throwable $originalFailure): void
    {
        $this->database->clearLastError();
        $result = $this->database->query('ROLLBACK');

        if ($result === false) {
            throw new RuntimeException(
                'The event candidate transaction could not be rolled back.',
                0,
                $originalFailure
            );
        }
    }
}
