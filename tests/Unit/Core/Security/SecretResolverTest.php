<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Security;

use ADCT\ParishIntake\Core\Security\SecretRegistry;
use ADCT\ParishIntake\Core\Security\SecretResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SecretResolverTest extends TestCase
{
    public function testNonEmptyConstantTakesPrecedenceOverStoredOption(): void
    {
        $resolver = new SecretResolver(
            static fn (string $name): string => $name === 'ADCT_PI_AI_API_KEY' ? 'constant-key' : '',
            static fn (string $name): string => $name === 'adct_parish_intake_openrouter_api_key' ? 'stored-key' : ''
        );

        self::assertSame(
            'constant-key',
            $resolver->resolve('ADCT_PI_AI_API_KEY', 'adct_parish_intake_openrouter_api_key')
        );
    }

    public function testEmptyConstantFallsBackToStoredOption(): void
    {
        $resolver = new SecretResolver(
            static fn (string $name): string => $name === 'ADCT_PI_AI_API_KEY' ? '  ' : '',
            static fn (string $name): string => $name === 'adct_parish_intake_openrouter_api_key' ? 'stored-key' : ''
        );

        self::assertSame(
            'stored-key',
            $resolver->resolve('ADCT_PI_AI_API_KEY', 'adct_parish_intake_openrouter_api_key')
        );
    }

    public function testNonStringValuesAreIgnoredAndEmptyOptionsResolveToEmptyString(): void
    {
        $resolver = new SecretResolver(
            static fn (string $name): mixed => false,
            static fn (string $name): mixed => null
        );

        self::assertSame('', $resolver->resolve('ADCT_PI_AI_API_KEY', 'adct_parish_intake_openrouter_api_key'));
        self::assertFalse($resolver->hasConstant('ADCT_PI_AI_API_KEY'));
        self::assertFalse($resolver->hasOption('adct_parish_intake_openrouter_api_key'));
    }

    public function testSecretValuesDoNotAppearInResolverDebugOrJsonOutput(): void
    {
        $secret = 'sk-test-DO-NOT-ECHO-123';
        $resolver = new SecretResolver(
            static fn (string $name): string => $secret,
            static fn (string $name): string => $secret
        );

        ob_start();
        var_dump($resolver);
        $debugOutput = (string) ob_get_clean();

        self::assertStringNotContainsString($secret, $debugOutput);
        self::assertStringNotContainsString($secret, print_r($resolver, true));
        self::assertSame('{}', json_encode($resolver, JSON_THROW_ON_ERROR));
    }

    public function testRegistrySupportsKnownSecretsAndPerMailboxImapNames(): void
    {
        self::assertSame(
            'ADCT_PI_AI_API_KEY',
            SecretRegistry::constantName(SecretRegistry::AI_API_KEY)
        );
        self::assertSame(
            'ADCT_PI_IMAP_PASSWORD',
            SecretRegistry::constantName(SecretRegistry::IMAP_PASSWORD)
        );
        self::assertSame(
            'ADCT_PI_OCR_API_KEY',
            SecretRegistry::constantName(SecretRegistry::OCR_API_KEY)
        );
        self::assertSame(
            'ADCT_PI_IMAP_PASSWORD_CENTRAL_OFFICE',
            SecretRegistry::constantName(SecretRegistry::IMAP_PASSWORD, 'central-office')
        );
        self::assertSame(
            'adct_parish_intake_imap_password_central_office',
            SecretRegistry::optionName(SecretRegistry::IMAP_PASSWORD, 'central-office')
        );
    }

    public function testInvalidMailboxSlugIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SecretRegistry::mailboxImapPasswordConstantName('central office');
    }

    public function testUnknownSecretIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SecretRegistry::constantName('unknown_secret');
    }
}
