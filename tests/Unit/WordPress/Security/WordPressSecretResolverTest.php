<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Security;

use ADCT\ParishIntake\Core\Security\SecretRegistry;
use ADCT\ParishIntake\Core\Security\SecretResolver;
use ADCT\ParishIntake\WordPress\Security\WordPressSecretResolver;
use PHPUnit\Framework\TestCase;

final class WordPressSecretResolverTest extends TestCase
{
    public function testMailboxConstantTakesPrecedenceOverItsStoredPassword(): void
    {
        $resolver = $this->resolver(
            [
                'ADCT_PI_IMAP_PASSWORD_MAILBOX_4' => 'scoped-constant',
            ],
            [
                'adct_parish_intake_imap_password_mailbox_4' => 'stored-password',
            ]
        );

        self::assertSame(
            'scoped-constant',
            $resolver->resolve(SecretRegistry::IMAP_PASSWORD, 'mailbox-4')
        );
        self::assertSame(
            'ADCT_PI_IMAP_PASSWORD_MAILBOX_4',
            $resolver->configuredConstantName(SecretRegistry::IMAP_PASSWORD, 'mailbox-4')
        );
    }

    public function testDefaultMailboxConstantTakesPrecedenceOverAStoredMailboxPassword(): void
    {
        $resolver = $this->resolver(
            [
                'ADCT_PI_IMAP_PASSWORD' => 'default-constant',
            ],
            [
                'adct_parish_intake_imap_password_mailbox_4' => 'stored-password',
            ]
        );

        self::assertSame(
            'default-constant',
            $resolver->resolve(SecretRegistry::IMAP_PASSWORD, 'mailbox-4')
        );
        self::assertSame(
            'ADCT_PI_IMAP_PASSWORD',
            $resolver->configuredConstantName(SecretRegistry::IMAP_PASSWORD, 'mailbox-4')
        );
    }

    public function testStoredMailboxPasswordIsUsedWhenNoConstantIsConfigured(): void
    {
        $resolver = $this->resolver(
            [],
            [
                'adct_parish_intake_imap_password_mailbox_4' => 'stored-password',
            ]
        );

        self::assertSame(
            'stored-password',
            $resolver->resolve(SecretRegistry::IMAP_PASSWORD, 'mailbox-4')
        );
        self::assertNull(
            $resolver->configuredConstantName(SecretRegistry::IMAP_PASSWORD, 'mailbox-4')
        );
        self::assertTrue($resolver->hasStoredOption(SecretRegistry::IMAP_PASSWORD, 'mailbox-4'));
    }

    /**
     * @param array<string, string> $constants
     * @param array<string, string> $options
     */
    private function resolver(array $constants, array $options): WordPressSecretResolver
    {
        return new WordPressSecretResolver(new SecretResolver(
            static fn (string $name): ?string => $constants[$name] ?? null,
            static fn (string $name): ?string => $options[$name] ?? null
        ));
    }
}
