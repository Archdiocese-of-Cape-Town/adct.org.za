<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

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

                $values = array_merge(
                    $candidatesByBlock[$blockIndex],
                    [
                        'match_kind' => 'new',
                        'updated_at' => $timestamp,
                    ]
                );
                $this->update($candidateId, $values);
                unset($candidatesByBlock[$blockIndex]);
            }

            foreach ($candidatesByBlock as $blockIndex => $values) {
                $this->insert(array_merge(
                    $values,
                    [
                        'message_id' => $messageId,
                        'block_index' => $blockIndex,
                        'match_kind' => 'new',
                        'status' => 'draft',
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
