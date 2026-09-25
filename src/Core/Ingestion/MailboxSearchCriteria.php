<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use DateTimeImmutable;

final readonly class MailboxSearchCriteria
{
    public function __construct(
        public bool $unseen = false,
        public ?DateTimeImmutable $since = null,
        public ?int $afterUid = null,
    ) {
        if ($afterUid !== null && ($afterUid < 0 || $afterUid > MailboxCheckpoint::MAX_UID)) {
            throw new \InvalidArgumentException('A UID search checkpoint must be a valid IMAP UID.');
        }
    }

    public static function all(): self
    {
        return new self();
    }

    public static function unseen(): self
    {
        return new self(unseen: true);
    }

    public static function since(DateTimeImmutable $date): self
    {
        return new self(since: $date);
    }

    public static function afterUid(int $uid): self
    {
        return new self(afterUid: $uid);
    }
}
