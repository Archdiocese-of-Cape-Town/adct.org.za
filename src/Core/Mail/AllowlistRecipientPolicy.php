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
    private array $allowlist;

    /**
     * @param list<string> $allowlist
     */
    public function __construct(
        private readonly bool $enabled,
        array $allowlist
    ) {
        $normalized = [];

        foreach ($allowlist as $recipient) {
            if (! is_string($recipient)) {
                throw new InvalidArgumentException('Every mail allowlist entry must be an email address.');
            }

            try {
                $normalized[] = (new OutboundEmail(
                    $recipient,
                    'Allowlist validation',
                    ' ',
                    '',
                    MailPriority::REMINDER_OR_DIGEST
                ))->recipient;
            } catch (InvalidArgumentException $failure) {
                throw new InvalidArgumentException('Every mail allowlist entry must be an email address.', 0, $failure);
            }
        }

        $this->allowlist = array_values(array_unique($normalized));
    }

    public function allows(string $normalizedRecipient): bool
    {
        return ! $this->enabled || in_array(strtolower($normalizedRecipient), $this->allowlist, true);
    }
}
