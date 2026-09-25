<?php

namespace ADCT\ParishIntake\Core\Parsing;

final class ParseResult
{
    private string $normalizedText = '';
    private string $classification = 'unknown';
    private array $fields = [];
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

    public function mergeFields(array $fields, bool $onlyEmpty = true): void
    {
        foreach ($fields as $key => $value) {
            if ($onlyEmpty && ! empty($this->fields[$key])) {
                continue;
            }

            $this->setField((string) $key, $value);
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

    public function toArray(): array
    {
        return [
            'normalized_text' => $this->normalizedText,
            'classification' => $this->classification,
            'block_index' => $this->blockIndex,
            'source_snippet' => $this->sourceSnippet,
            'fields' => $this->fields,
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
