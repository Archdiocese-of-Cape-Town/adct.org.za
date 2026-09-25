<?php

namespace ADCT\ParishIntake\Core\Security;

use InvalidArgumentException;

final class SecretRegistry
{
    public const AI_API_KEY = 'ai_api_key';
    public const IMAP_PASSWORD = 'imap_password';
    public const OCR_API_KEY = 'ocr_api_key';

    /**
     * @var array<string, array{constant: string, option: string}>
     */
    private const DEFINITIONS = [
        self::AI_API_KEY => [
            'constant' => 'ADCT_PI_AI_API_KEY',
            'option' => 'adct_parish_intake_openrouter_api_key',
        ],
        self::IMAP_PASSWORD => [
            'constant' => 'ADCT_PI_IMAP_PASSWORD',
            'option' => 'adct_parish_intake_imap_password',
        ],
        self::OCR_API_KEY => [
            'constant' => 'ADCT_PI_OCR_API_KEY',
            'option' => 'adct_parish_intake_ocr_api_key',
        ],
    ];

    public static function constantName(string $secretId, ?string $scope = null): string
    {
        if ($secretId === self::IMAP_PASSWORD && $scope !== null) {
            return self::mailboxImapPasswordConstantName($scope);
        }

        if ($scope !== null) {
            throw new InvalidArgumentException('Only mailbox passwords support a scoped secret name.');
        }

        return self::definition($secretId)['constant'];
    }

    public static function optionName(string $secretId, ?string $scope = null): string
    {
        $definition = self::definition($secretId);

        if ($scope === null) {
            return $definition['option'];
        }

        if ($secretId !== self::IMAP_PASSWORD) {
            throw new InvalidArgumentException('Only mailbox passwords support a scoped secret name.');
        }

        return 'adct_parish_intake_imap_password_' . strtolower(self::normalizeMailboxSlug($scope));
    }

    public static function mailboxImapPasswordConstantName(string $mailboxSlug): string
    {
        return 'ADCT_PI_IMAP_PASSWORD_' . self::normalizeMailboxSlug($mailboxSlug);
    }

    /**
     * @return array{constant: string, option: string}
     */
    private static function definition(string $secretId): array
    {
        if (! isset(self::DEFINITIONS[$secretId])) {
            throw new InvalidArgumentException('Unknown secret identifier: ' . $secretId);
        }

        return self::DEFINITIONS[$secretId];
    }

    private static function normalizeMailboxSlug(string $mailboxSlug): string
    {
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/i', $mailboxSlug) !== 1) {
            throw new InvalidArgumentException('Mailbox slugs must use letters and numbers separated by single hyphens.');
        }

        return strtoupper(str_replace('-', '_', $mailboxSlug));
    }
}
