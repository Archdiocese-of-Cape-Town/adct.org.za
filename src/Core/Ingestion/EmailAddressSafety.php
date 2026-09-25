<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

final class EmailAddressSafety
{
    public function isSafeConfirmationAddress(?string $email): bool
    {
        if (
            $email === null
            || trim($email) !== $email
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            return false;
        }

        return ! $this->isNoReplyAddress($email);
    }

    public function isNoReplyAddress(?string $email): bool
    {
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $separator = strrpos($email, '@');

        if ($separator === false) {
            return false;
        }

        $localPart = substr(strtolower($email), 0, $separator);

        return preg_match(
            '/(?:^|[._+-])(?:no[-_.]?reply|do[-_.]?not[-_.]?reply|mail(?:er)?[-_.]?daemon|postmaster)(?:$|[._+-])/i',
            $localPart
        ) === 1;
    }
}
