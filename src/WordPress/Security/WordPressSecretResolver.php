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
        return $this->resolver->resolve(
            SecretRegistry::constantName($secretId, $scope),
            SecretRegistry::optionName($secretId, $scope)
        );
    }

    public function isConstantConfigured(string $secretId, ?string $scope = null): bool
    {
        return $this->resolver->hasConstant(SecretRegistry::constantName($secretId, $scope));
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
