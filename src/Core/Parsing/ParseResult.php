<?php

namespace ADCT\ParishIntake\Core\Parsing;

use ADCT\ParishIntake\Core\Parsing\Confidence\FieldEvidence;

final class ParseResult
{
    private string $normalizedText = '';
    private string $classification = 'unknown';
    private array $fields = [];

    /**
     * Why each field is believed, keyed by field name. Populated as the parser sets fields.
     *
     * @var array<string, FieldEvidence>
     */
    private array $fieldEvidence = [];

        /**
         * The computed per-field confidence, attached to `fields` on export.
         *
         * @var array{score: float, coverage: float, fields: array<string, array<string, mixed>>}|null
         */
        private ?array $fieldConfidencePayload = null;

        private array $recurrence = [];
    private float $confidence = 0.0;
    private bool $dateWeekdayMismatch = false;
    private bool $rangeEndBeforeStart = false;
    private bool $nextWeekdayAmbiguous = false;
    private array $notes = [];
    private array $errors = [];
    private array $strategies = [];
    private string $parserVersion = '0.1.0';
    private bool $aiUsed = false;
    private ?string $aiProvider = null;
    private ?string $aiModel = null;
    private array $aiFieldsFilled = [];
    private bool $reprocessNeeded = false;
    private ?int $blockIndex = null;
    private string $sourceSnippet = '';

    public function setNormalizedText(string $text): void
    {
        $this->normalizedText = $text;
    }

    public function getNormalizedText(): string
    {
        return $this->normalizedText;
    }

    public function setClassification(string $classification): void
    {
        $this->classification = $classification;
    }

    public function getClassification(): string
    {
        return $this->classification;
    }

    public function setField(string $key, $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->fields[$key] = $value;
    }

    public function getField(string $key)
    {
        return $this->fields[$key] ?? null;
    }

    public function fields(): array
    {
        return $this->fields;
    }

        /**
         * Record why a field is believed.
         *
         * Last write wins, so a later stage that knows better (such as the directory lookup, which
         * can confirm a parish from a verified sender) may correct or upgrade an earlier guess.
         */
        public function recordFieldEvidence(string $field, FieldEvidence $evidence): void
        {
            $this->fieldEvidence[$field] = $evidence;
    }

        /**
         * @return array<string, FieldEvidence>
         */
        public function fieldEvidence(): array
        {
            return $this->fieldEvidence;
        }

        public function fieldEvidenceFor(string $field): ?FieldEvidence
        {
            return $this->fieldEvidence[$field] ?? null;
        }

        /**
         * Drop the recorded evidence for fields that are no longer present, so a value that was
         * overwritten does not keep scoring the candidate.
         */
        public function pruneFieldEvidence(): void
        {
            foreach (array_keys($this->fieldEvidence) as $field) {
                if (! isset($this->fields[$field])) {
                    unset($this->fieldEvidence[$field]);
                }
            }
        }

        public function mergeFields(array $fields, bool $onlyEmpty = true): void
        {
            foreach ($fields as $key => $value) {
                if ($onlyEmpty && ! empty($this->fields[$key])) {
                    continue;
                }

                $this->setField((string) $key, $value);

                // A merged value replaces whatever produced the old one, so its evidence must move
                // with it or the score would describe a field that is no longer there.
                if (! isset($this->fieldEvidence[$key])) {
                    $this->recordFieldEvidence((string) $key, new FieldEvidence(FieldEvidence::AI));
                }
            }
        }

    public function setRecurrence(array $recurrence): void
    {
        $this->recurrence = $recurrence;
    }

    public function getRecurrence(): array
    {
        return $this->recurrence;
    }

    public function setConfidence(float $confidence): void
    {
        $this->confidence = max(0.0, min(1.0, $confidence));
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function markDateWeekdayMismatch(): void
    {
        $this->dateWeekdayMismatch = true;
    }

    public function hasDateWeekdayMismatch(): bool
    {
        return $this->dateWeekdayMismatch;
    }

    public function markRangeEndBeforeStart(): void
    {
        $this->rangeEndBeforeStart = true;
    }

    public function hasRangeEndBeforeStart(): bool
    {
        return $this->rangeEndBeforeStart;
    }

    public function markNextWeekdayAmbiguous(): void
    {
        $this->nextWeekdayAmbiguous = true;
    }

    public function hasAmbiguousNextWeekday(): bool
    {
        return $this->nextWeekdayAmbiguous;
    }

    public function addNote(string $note): void
    {
        $this->notes[] = $note;
    }

    public function getNotes(): array
    {
        return $this->notes;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function addStrategy(string $strategy): void
    {
        if (! in_array($strategy, $this->strategies, true)) {
            $this->strategies[] = $strategy;
        }
    }

    public function getStrategies(): array
    {
        return $this->strategies;
    }

    public function setParserVersion(string $parserVersion): void
    {
        $this->parserVersion = $parserVersion;
    }

    public function getParserVersion(): string
    {
        return $this->parserVersion;
    }

    public function markAiUsed(?string $provider, ?string $model = null, array $fieldsFilled = []): void
    {
        $this->aiUsed = true;
        $this->aiProvider = $provider;
        $this->aiModel = $model;
        $this->aiFieldsFilled = $fieldsFilled;
    }

    public function usedAi(): bool
    {
        return $this->aiUsed;
    }

    public function getAiProvider(): ?string
    {
        return $this->aiProvider;
    }

    public function getAiModel(): ?string
    {
        return $this->aiModel;
    }

    public function getAiFieldsFilled(): array
    {
        return $this->aiFieldsFilled;
    }

    public function setNeedsReprocess(bool $needsReprocess): void
    {
        $this->reprocessNeeded = $needsReprocess;
    }

    public function needsReprocess(): bool
    {
        return $this->reprocessNeeded;
    }

    public function setBlockMetadata(int $blockIndex, string $sourceText): void
    {
        $this->blockIndex = max(0, $blockIndex);
        $sourceText = trim($sourceText);
        $this->sourceSnippet = function_exists('mb_substr')
            ? mb_substr($sourceText, 0, 2000, 'UTF-8')
            : substr($sourceText, 0, 2000);
    }

    public function getBlockIndex(): ?int
    {
        return $this->blockIndex;
    }

    public function getSourceSnippet(): string
    {
        return $this->sourceSnippet;
    }

        /**
         * Per-field confidence, published inside `fields` so it rides along in the existing
         * longtext column. No schema migration is needed for this, the same way `parish_match`
         * and `venue_match` were added.
         *
         * @return array{score: float, coverage: float, fields: array<string, array<string, mixed>>}|null
         */
        public function fieldConfidence(): ?array
        {
            return $this->fieldConfidencePayload;
        }

        /**
         * @param array{score: float, coverage: float, fields: array<string, array<string, mixed>>}|null $payload
         */
        public function setFieldConfidence(?array $payload): void
        {
            $this->fieldConfidencePayload = $payload;
        }

        /**
         * The fields as persisted, with the per-field confidence payload merged in.
         *
         * @return array<string, mixed>
         */
        private function fieldsWithFieldConfidence(): array
        {
            if ($this->fieldConfidencePayload === null) {
                return $this->fields;
            }

            return ['field_confidence' => $this->fieldConfidencePayload] + $this->fields;
        }

        public function toArray(): array
    {
        return [
            'normalized_text' => $this->normalizedText,
            'classification' => $this->classification,
            'block_index' => $this->blockIndex,
            'source_snippet' => $this->sourceSnippet,
                'fields' => $this->fieldsWithFieldConfidence(),
            'recurrence' => $this->recurrence,
            'confidence' => $this->confidence,
            'notes' => $this->notes,
            'errors' => $this->errors,
            'strategies' => $this->strategies,
            'parser_version' => $this->parserVersion,
            'ai_used' => $this->aiUsed,
            'ai_provider' => $this->aiProvider,
            'ai_model' => $this->aiModel,
            'ai_fields_filled' => $this->aiFieldsFilled,
            'reprocess_needed' => $this->reprocessNeeded,
        ];
    }
}
