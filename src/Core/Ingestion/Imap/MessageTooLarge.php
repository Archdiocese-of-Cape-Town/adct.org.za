<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

final class MessageTooLarge extends MailboxException
{
    public function __construct(
        public readonly int $sizeBytes,
        public readonly int $maxBytes,
    ) {
        parent::__construct(
            sprintf('This message is too large to download; the configured limit is %s.', self::formatLimit($maxBytes))
        );
    }

    private static function formatLimit(int $bytes): string
    {
        if ($bytes >= 1048576) {
            $amount = number_format($bytes / 1048576, 1, '.', '');
            $unit = 'MiB';
        } elseif ($bytes >= 1024) {
            $amount = number_format($bytes / 1024, 1, '.', '');
            $unit = 'KiB';
        } else {
            return $bytes . ' bytes';
        }

        if (str_ends_with($amount, '.0')) {
            $amount = substr($amount, 0, -2);
        }

        return $amount . ' ' . $unit;
    }
}
