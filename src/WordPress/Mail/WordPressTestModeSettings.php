<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Mail;

use ADCT\ParishIntake\Core\Mail\AllowlistRecipientPolicy;
use InvalidArgumentException;

final class WordPressTestModeSettings
{
    public const TEST_MODE_OPTION = 'adct_pi_test_mode';
    public const ALLOWLIST_OPTION = 'adct_pi_test_allowlist';
    public const MODE_DISABLED = 'disabled';
    public const MODE_ENABLED = 'enabled';

    private function __construct(
        private readonly ?string $mode,
        private readonly array $allowlist,
        private readonly ?AllowlistRecipientPolicy $recipientPolicy,
        private readonly ?string $configurationError
    ) {
    }

    public static function current(): self
    {
        return self::fromValues(
            get_option(self::TEST_MODE_OPTION, self::MODE_DISABLED),
            get_option(self::ALLOWLIST_OPTION, [])
        );
    }

    public static function fromValues(mixed $mode, mixed $allowlist): self
    {
        $displayAllowlist = self::displayableEntries($allowlist);

        if (
            ! is_string($mode)
            || ! in_array($mode, [self::MODE_DISABLED, self::MODE_ENABLED], true)
        ) {
            return new self(
                null,
                $displayAllowlist,
                null,
                'The test-mode setting is invalid. All Parish Intake outbound queue mail is suppressed until it is corrected.'
            );
        }

        if (! is_array($allowlist) || ! array_is_list($allowlist)) {
            return new self(
                $mode,
                [],
                null,
                'The test-mode allow-list is invalid. Use one email address or an exact domain prefixed with "@" per line. All Parish Intake outbound queue mail is suppressed until it is corrected.'
            );
        }

        foreach ($allowlist as $entry) {
            if (! is_string($entry)) {
                return new self(
                    $mode,
                    $displayAllowlist,
                    null,
                    'The test-mode allow-list is invalid. Use one email address or an exact domain prefixed with "@" per line. All Parish Intake outbound queue mail is suppressed until it is corrected.'
                );
            }
        }

        try {
            $recipientPolicy = new AllowlistRecipientPolicy(true, $allowlist);
        } catch (InvalidArgumentException) {
            return new self(
                $mode,
                $displayAllowlist,
                null,
                'The test-mode allow-list is invalid. Use one email address or an exact domain prefixed with "@" per line. All Parish Intake outbound queue mail is suppressed until it is corrected.'
            );
        }

        if ($mode === self::MODE_ENABLED && $allowlist === []) {
            return new self(
                $mode,
                [],
                $recipientPolicy,
                'Test mode is enabled with an empty allow-list. All Parish Intake outbound queue mail is suppressed until at least one email address or exact @domain is added.'
            );
        }

        return new self($mode, $displayAllowlist, $recipientPolicy, null);
    }

    public function isEnabled(): bool
    {
        return $this->mode === self::MODE_ENABLED;
    }

    public function modeValue(): string
    {
        return $this->mode ?? '';
    }

    /**
     * @return list<string>
     */
    public function allowlistForDisplay(): array
    {
        return $this->allowlist;
    }

    public function configurationError(): ?string
    {
        return $this->configurationError;
    }

    public function allowsRecipient(string $normalizedRecipient): bool
    {
        if ($this->configurationError !== null) {
            return false;
        }

        if (! $this->isEnabled()) {
            return true;
        }

        return $this->recipientPolicy?->allows($normalizedRecipient) ?? false;
    }

    /**
     * @return list<string>
     */
    private static function displayableEntries(mixed $allowlist): array
    {
        if (! is_array($allowlist) || ! array_is_list($allowlist)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $entry): string => is_string($entry) ? $entry : '',
            $allowlist
        ));
    }
}
