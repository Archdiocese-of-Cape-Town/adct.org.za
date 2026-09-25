<?php

namespace ADCT\ParishIntake\Core\Parsing;

final class ParseContext
{
    private array $options;
    private array $notes = [];
    private array $errors = [];
    private array $runtimeValues = [];

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function reset(): void
    {
        $this->notes = [];
        $this->errors = [];
        $this->runtimeValues = [];
    }

    public function getOption(string $key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    public function setRuntimeValue(string $key, $value): void
    {
        $this->runtimeValues[$key] = $value;
    }

    public function getRuntimeValue(string $key, $default = null)
    {
        return $this->runtimeValues[$key] ?? $default;
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
