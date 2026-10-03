<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Audit;

use DateTimeImmutable;
use JsonException;

/**
 * One audit row, as the screen sees it.
 *
 * `details` is deliberately kept as the raw stored JSON string rather than a
 * decoded array. Two reasons: the column is longtext, so a hand-edited or
 * legacy row is not guaranteed to hold JSON at all; and the screen must escape
 * whatever it prints, so it should never be handed a structure that invites
 * being trusted as one. decoded() returns null when the column is not JSON.
 */
final class AuditEntry
{
    public function __construct(
        public readonly int $id,
        public readonly string $actor,
        public readonly string $action,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly ?string $details,
        public readonly DateTimeImmutable $createdAt
    ) {
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws JsonException only when the caller asks for a strict decode.
     */
    public function decoded(): ?array
    {
        if ($this->details === null || trim($this->details) === '') {
            return null;
        }

        $decoded = json_decode($this->details, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The label for this row's action, falling back to the stored value so a
     * row written before an action was renamed still reads as something.
     */
    public function actionLabel(): string
    {
        return AuditAction::labels()[$this->action] ?? $this->action;
    }

    public function subjectLabel(): string
    {
        $labels = AuditSubjectType::labels();

        return isset($labels[$this->subjectType]) ? $labels[$this->subjectType] : $this->subjectType;
    }

    /**
     * "Candidate #12" style subject reference, or just the type when the row
     * has no subject id (a settings change, for instance).
     */
    public function subjectLabelWithId(): string
    {
        if ($this->subjectId < 1) {
            return $this->subjectLabel();
        }

        return sprintf('%s #%d', $this->subjectLabel(), $this->subjectId);
    }
}