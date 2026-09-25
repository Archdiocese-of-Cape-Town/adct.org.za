<?php

namespace ADCT\ParishIntake\Core\Security;

use Closure;

final class SecretResolver
{
    /** @var Closure(string): mixed */
    private Closure $constantReader;

    /** @var Closure(string): mixed */
    private Closure $optionReader;

    public function __construct(callable $constantReader, callable $optionReader)
    {
        $this->constantReader = Closure::fromCallable($constantReader);
        $this->optionReader = Closure::fromCallable($optionReader);
    }

    public function resolve(string $constantName, string $optionName): string
    {
        return $this->readNonEmptyString($this->constantReader, $constantName)
            ?? $this->readNonEmptyString($this->optionReader, $optionName)
            ?? '';
    }

    public function hasConstant(string $constantName): bool
    {
        return $this->readNonEmptyString($this->constantReader, $constantName) !== null;
    }

    public function hasOption(string $optionName): bool
    {
        return $this->readNonEmptyString($this->optionReader, $optionName) !== null;
    }

    public function __debugInfo(): array
    {
        return [];
    }

    private function readNonEmptyString(Closure $reader, string $name): ?string
    {
        $value = $reader($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
