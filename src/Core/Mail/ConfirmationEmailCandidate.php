<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use InvalidArgumentException;

final readonly class ConfirmationEmailCandidate
{
    /**
     * Per-field scoring, as stored in the candidate's `field_confidence` JSON key.
     *
     * Shape: `['score' => float, 'coverage' => float, 'fields' => array<string,
     * array{score: float, origin: string, flags: list<string>}>]`. Any other shape is ignored
     * rather than rejected: a candidate parsed before this field existed must still render, and
     * the renderer falls back to its note-based heuristics in that case.
     *
     * @var array<string, mixed>
     */
    public array $fieldConfidence;

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $recurrence
     * @param list<string> $notes
     * @param array<string, mixed> $fieldConfidence
     */
    public function __construct(
        public int $id,
        public array $fields,
        public array $recurrence,
        public float $confidence,
        public array $notes,
        public string $matchKind = 'new',
        public ?string $matchTitle = null,
        array $fieldConfidence = []
    ) {
        if ($id < 1 || ! is_finite($confidence) || $confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('A confirmation preview candidate has invalid identifiers or confidence.');
        }

        if (! array_is_list($notes)) {
            throw new InvalidArgumentException('Confirmation preview notes must be a list.');
        }

        foreach ($notes as $note) {
            if (! is_string($note)) {
                throw new InvalidArgumentException('Every confirmation preview note must be text.');
            }
        }

        $this->fieldConfidence = $fieldConfidence;
    }

    /**
     * The per-field entries, or an empty list when none were recorded.
     *
     * @return array<string, array{score: float, origin: string, flags: list<string>}>
     */
    public function fieldScores(): array
    {
        $fields = $this->fieldConfidence['fields'] ?? null;

        if (! is_array($fields)) {
            return [];
        }

        $scores = [];

        foreach ($fields as $name => $entry) {
            if (! is_string($name) || ! is_array($entry) || ! isset($entry['score']) || ! is_numeric($entry['score'])) {
                continue;
            }

            $flags = [];

            foreach ((array) ($entry['flags'] ?? []) as $flag) {
                if (is_string($flag)) {
                    $flags[] = $flag;
                }
            }

            $scores[$name] = [
                'score' => (float) $entry['score'],
                'origin' => is_string($entry['origin'] ?? null) ? $entry['origin'] : 'unknown',
                'flags' => $flags,
            ];
        }

        return $scores;
    }
}
