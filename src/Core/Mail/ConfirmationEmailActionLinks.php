<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use InvalidArgumentException;

final readonly class ConfirmationEmailActionLinks
{
    /**
     * @param array<int, array{approve: string, deny: string, edit: string}> $candidateLinks
     */
    public function __construct(
        public string $approveAll,
        public array $candidateLinks
    ) {
        self::assertWebUrl($approveAll);

        foreach ($candidateLinks as $candidateId => $links) {
            if (
                ! is_int($candidateId)
                || $candidateId < 1
                || array_keys($links) !== ['approve', 'deny', 'edit']
            ) {
                throw new InvalidArgumentException('Confirmation preview action links have an invalid shape.');
            }

            foreach ($links as $url) {
                self::assertWebUrl($url);
            }
        }
    }

    /**
     * @return array{approve: string, deny: string, edit: string}
     */
    public function forCandidate(int $candidateId): array
    {
        $links = $this->candidateLinks[$candidateId] ?? null;

        if (! is_array($links)) {
            throw new InvalidArgumentException('A confirmation preview candidate has no action links.');
        }

        return $links;
    }

    private static function assertWebUrl(string $url): void
    {
        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1
        ) {
            throw new InvalidArgumentException('A confirmation preview action URL is invalid.');
        }
    }
}
