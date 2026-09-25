<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

final readonly class ImapCommandResult
{
    /**
     * @param list<string> $responses Untagged responses received before the tagged completion.
     */
    public function __construct(
        public string $status,
        public array $responses,
    ) {
    }
}
