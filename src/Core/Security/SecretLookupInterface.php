<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Security;

/**
 * The read-only half of secret resolution, as a port.
 *
 * Only the questions an admin screen actually asks — is this secret defined in
 * wp-config.php, and is one also sitting in the options table — are here.
 * Resolving the secret's *value* stays on the WordPress adapter, because a
 * screen must never render one and so has no reason to ask for it.
 */
interface SecretLookupInterface
{
    /**
     * True when wp-config.php defines the constant, which then wins over any
     * stored option.
     */
    public function isConstantConfigured(string $secretId, ?string $scope = null): bool;

    /**
     * True when a non-empty value is stored for this secret.
     */
    public function hasStoredOption(string $secretId, ?string $scope = null): bool;
}