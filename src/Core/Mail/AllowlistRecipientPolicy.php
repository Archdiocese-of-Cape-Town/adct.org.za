<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use ADCT\ParishIntake\Core\Ports\RecipientPolicyInterface;
use InvalidArgumentException;

final class AllowlistRecipientPolicy implements RecipientPolicyInterface
{
    /**
     * @var list<string>
     */
    private array $emailAllowlist;

    /**
     * @var list<string>
     */
    private array $domainAllowlist;

    /**
     * @param list<string> $allowlist Email addresses or exact domains prefixed with "@"
     */
    public function __construct(
        private readonly bool $enabled,
        array $allowlist
    ) {
        $emails = [];
        $domains = [];

        foreach ($allowlist as $entry) {
            if (! is_string($entry)) {
                throw new InvalidArgumentException(
                    'Every mail allowlist entry must be an email address or an exact domain prefixed with "@".'
                );
            }

            $entry = strtolower(trim($entry));

            if (str_starts_with($entry, '@')) {
                $domain = substr($entry, 1);

                if (! $this->isValidDomain($domain)) {
                    throw new InvalidArgumentException(
                        'Every mail allowlist entry must be an email address or an exact domain prefixed with "@".'
                    );
                }

                $domains[] = $domain;

                continue;
            }

            try {
                $emails[] = (new OutboundEmail(
                    $entry,
                    'Allowlist validation',
                    ' ',
                    '',
                    MailPriority::REMINDER_OR_DIGEST
                ))->recipient;
            } catch (InvalidArgumentException $failure) {
                throw new InvalidArgumentException(
                    'Every mail allowlist entry must be an email address or an exact domain prefixed with "@".',
                    0,
                    $failure
                );
            }
        }

        $this->emailAllowlist = array_values(array_unique($emails));
        $this->domainAllowlist = array_values(array_unique($domains));
    }

    public function allows(string $normalizedRecipient): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $recipient = strtolower(trim($normalizedRecipient));

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        if (in_array($recipient, $this->emailAllowlist, true)) {
            return true;
        }

        $separator = strrpos($recipient, '@');

        return $separator !== false
            && in_array(substr($recipient, $separator + 1), $this->domainAllowlist, true);
    }

    private function isValidDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && str_contains($domain, '.')
            && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
