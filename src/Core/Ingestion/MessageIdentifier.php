<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

final class MessageIdentifier
{
    public static function fromMessageId(?string $messageId, int $uidValidity, int $uid): string
    {
        $messageId = preg_replace('/\s+/', '', trim((string) $messageId)) ?? '';

        if ($messageId !== '') {
            if (strlen($messageId) <= 191) {
                return $messageId;
            }

            return 'sha256:' . hash('sha256', $messageId);
        }

        return 'imap:' . $uidValidity . ':' . $uid;
    }
}
