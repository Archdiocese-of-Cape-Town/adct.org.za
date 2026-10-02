<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Review;

/**
 * The outcome of validating a reviewer's candidate edit. The serialised
 * `fields` and `recurrence` JSON are only present when the edit is valid.
 */
final readonly class CandidateEditResult
{
    /**
     * @param array<string, mixed> $values         the serialised `fields` JSON
     * @param array<string, mixed> $recurrence     the serialised `recurrence` JSON, or []
     * @param array<string, string> $errors        field name to reviewer-facing message
     * @param list<string> $changedFields          the editable keys whose value changed
     * @param CandidateFieldSet|null $inputs       the submitted values, so a failed
     *                                              save can re-render the reviewer's form
     */
    public function __construct(
        public array $values = [],
        public array $recurrence = [],
        public array $errors = [],
        public array $changedFields = [],
        public ?CandidateFieldSet $inputs = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function errorFor(string $field): ?string
    {
        $error = $this->errors[$field] ?? null;

        return is_string($error) ? $error : null;
    }

    /**
     * The inputs the reviewer submitted, so a failed save can re-render the form
     * with their own values rather than the stored ones.
     *
     * @return array<string, mixed>
     */
    public function submittedInputs(): array
    {
        return $this->inputs?->toInputs() ?? [];
    }
}
