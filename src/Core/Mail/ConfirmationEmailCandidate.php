<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use InvalidArgumentException;

final readonly class ConfirmationEmailCandidate
{
    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $recurrence
     * @param list<string> $notes
     */
    public function __construct(
        public int $id,
        public array $fields,
        public array $recurrence,
        public float $confidence,
        public array $notes,
        public string $matchKind = 'new',
        public ?string $matchTitle = null
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
    }
}
