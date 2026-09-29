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
        public ?int $beforeUid = null,
        public ?DateTimeImmutable $before = null,
        public ?string $folder = null,
    ) {
        if ($afterUid !== null && ($afterUid < 0 || $afterUid > MailboxCheckpoint::MAX_UID)) {
            throw new \InvalidArgumentException('A UID search checkpoint must be a valid IMAP UID.');
        }

        if ($beforeUid !== null && ($beforeUid < 1 || $beforeUid > MailboxCheckpoint::MAX_UID)) {
            throw new \InvalidArgumentException('A UID search range must be a valid IMAP UID.');
        }

        if ($afterUid !== null && $beforeUid !== null && $beforeUid < $afterUid) {
            throw new \InvalidArgumentException('A UID search range must be ascending.');
        }

        if ($folder !== null && trim($folder) === '') {
            throw new \InvalidArgumentException('A mailbox folder name cannot be empty.');
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

    public static function before(DateTimeImmutable $date, ?string $folder = null, ?int $afterUid = null): self
    {
        return new self(before: $date, afterUid: $afterUid, folder: $folder);
    }

    public static function afterUid(int $uid): self
    {
        return new self(afterUid: $uid);
    }
}
