<?php

namespace ADCT\ParishIntake\Core\Parsing;

final class ParseContext
{
    private array $options;
    private array $notes = [];
    private array $errors = [];

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function reset(): void
    {
        $this->notes = [];
        $this->errors = [];
    }

    public function getOption(string $key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    public function addNote(string $note): void
    {
        $this->notes[] = $note;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function notes(): array
    {
        return $this->notes;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
