<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Sources;

use ADCT\ParishIntake\Core\Directory\EmailAddress;
use InvalidArgumentException;

final class SourceType
{
    public const EMAIL = 'email';
    public const ICS = 'ics';
    public const PDF_URL = 'pdf_url';
    public const FACEBOOK_PAGE = 'facebook_page';
    public const WHATSAPP_FORWARD = 'whatsapp_forward';
    public const RSS = 'rss';
    public const WEB_PAGE = 'web_page';
    public const MANUAL = 'manual';

    private const VALUES = [
        self::EMAIL,
        self::ICS,
        self::PDF_URL,
        self::FACEBOOK_PAGE,
        self::WHATSAPP_FORWARD,
        self::RSS,
        self::WEB_PAGE,
        self::MANUAL,
    ];

    private const URL_TYPES = [
        self::ICS,
        self::PDF_URL,
        self::FACEBOOK_PAGE,
        self::RSS,
        self::WEB_PAGE,
    ];

    private const LABEL_TYPES = [
        self::WHATSAPP_FORWARD,
        self::MANUAL,
    ];

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return self::VALUES;
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::VALUES, true);
    }

    public static function assertValid(string $type): string
    {
        if (! self::isValid($type)) {
            throw new InvalidArgumentException('Choose a supported source type.');
        }

        return $type;
    }

    public static function normalizeIdentifier(string $type, string $identifier): string
    {
        self::assertValid($type);
        $identifier = trim($identifier);

        if ($type === self::EMAIL) {
            return EmailAddress::normalize($identifier);
        }

        if ($identifier === '' || strlen($identifier) > 191) {
            throw new InvalidArgumentException('Enter a source identifier of at most 191 characters.');
        }

        if (in_array($type, self::URL_TYPES, true)) {
            $parts = parse_url($identifier);

            if (
                filter_var($identifier, FILTER_VALIDATE_URL) === false
                || ! is_array($parts)
                || ! isset($parts['scheme'], $parts['host'])
                || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
                || $parts['host'] === ''
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                throw new InvalidArgumentException('This source type requires a valid HTTP or HTTPS URL without embedded credentials.');
            }

            return $identifier;
        }

        if (
            in_array($type, self::LABEL_TYPES, true)
            && preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1
        ) {
            throw new InvalidArgumentException('Source labels cannot contain control characters.');
        }

        return $identifier;
    }
}
