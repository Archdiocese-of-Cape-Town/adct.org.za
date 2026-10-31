<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use RuntimeException;

final class MimeParserClassResolver
{
    private const MIME_PARSER_CLASSES = [
        'ADCT\\ParishIntake\\Dependencies\\ZBateson\\MailMimeParser\\MailMimeParser',
        // Plain vendor/ is used in development, before the release prefixing step.
        'ZBateson\\MailMimeParser\\MailMimeParser',
    ];

    public static function resolve(): string
    {
        foreach (self::MIME_PARSER_CLASSES as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        throw new RuntimeException('The configured MIME parser dependency is not available.');
    }
}
