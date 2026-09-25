<?php

namespace ADCT\ParishIntake\WordPress\Security;

use ADCT\ParishIntake\Core\Security\SecretRegistry;
use ADCT\ParishIntake\Core\Security\SecretResolver;

final class WordPressSecretResolver
{
    private SecretResolver $resolver;

    public function __construct(?SecretResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new SecretResolver(
            static fn (string $name): mixed => defined($name) ? constant($name) : null,
            static fn (string $name): mixed => function_exists('get_option') ? get_option($name, '') : null
        );
    }

    public function resolve(string $secretId, ?string $scope = null): string
    {
        if ($secretId === SecretRegistry::IMAP_PASSWORD && $scope !== null) {
            $mailboxConstant = SecretRegistry::constantName($secretId, $scope);
            $mailboxOption = SecretRegistry::optionName($secretId, $scope);
            $defaultConstant = SecretRegistry::constantName($secretId);

            if ($this->resolver->hasConstant($mailboxConstant)) {
                return $this->resolver->resolve($mailboxConstant, $mailboxOption);
            }

            if ($this->resolver->hasConstant($defaultConstant)) {
                return $this->resolver->resolve($defaultConstant, $mailboxOption);
            }

            return $this->resolver->resolve($mailboxConstant, $mailboxOption);
        }

        return $this->resolver->resolve(
            SecretRegistry::constantName($secretId, $scope),
            SecretRegistry::optionName($secretId, $scope)
        );
    }

    public function isConstantConfigured(string $secretId, ?string $scope = null): bool
    {
        return $this->configuredConstantName($secretId, $scope) !== null;
    }

    public function configuredConstantName(string $secretId, ?string $scope = null): ?string
    {
        $constantName = SecretRegistry::constantName($secretId, $scope);

        if ($this->resolver->hasConstant($constantName)) {
            return $constantName;
        }

        if ($secretId === SecretRegistry::IMAP_PASSWORD && $scope !== null) {
            $defaultConstant = SecretRegistry::constantName($secretId);

            if ($this->resolver->hasConstant($defaultConstant)) {
                return $defaultConstant;
            }
        }

        return null;
    }

    public function hasStoredOption(string $secretId, ?string $scope = null): bool
    {
        return $this->resolver->hasOption(SecretRegistry::optionName($secretId, $scope));
    }

    public function __debugInfo(): array
    {
        return [];
    }
}
