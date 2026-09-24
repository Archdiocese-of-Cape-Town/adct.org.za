<?php

namespace ADCT\ParishIntake\Parsing;

final class ParseResult
{
    private string $normalizedText = '';
    private string $classification = 'unknown';
    private array $fields = [];
    private array $recurrence = [];
    private float $confidence = 0.0;
    private array $notes = [];
    private array $errors = [];
    private array $strategies = [];
    private string $parserVersion = '0.1.0';
    private bool $aiUsed = false;
    private ?string $aiProvider = null;
    private bool $reprocessNeeded = false;

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

    public function markAiUsed(?string $provider): void
    {
        $this->aiUsed = true;
        $this->aiProvider = $provider;
    }

    public function usedAi(): bool
    {
        return $this->aiUsed;
    }

    public function getAiProvider(): ?string
    {
        return $this->aiProvider;
    }

    public function setNeedsReprocess(bool $needsReprocess): void
    {
        $this->reprocessNeeded = $needsReprocess;
    }

    public function needsReprocess(): bool
    {
        return $this->reprocessNeeded;
    }

    public function toArray(): array
    {
        return [
            'normalized_text' => $this->normalizedText,
            'classification' => $this->classification,
            'fields' => $this->fields,
            'recurrence' => $this->recurrence,
            'confidence' => $this->confidence,
            'notes' => $this->notes,
            'errors' => $this->errors,
            'strategies' => $this->strategies,
            'parser_version' => $this->parserVersion,
            'ai_used' => $this->aiUsed,
            'ai_provider' => $this->aiProvider,
            'reprocess_needed' => $this->reprocessNeeded,
        ];
    }
}
