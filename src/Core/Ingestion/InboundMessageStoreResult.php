<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use InvalidArgumentException;

final readonly class InboundMessageStoreResult
{
    public function __construct(
        public int $messageId,
        public bool $duplicate
    ) {
        if ($messageId < 1) {
            throw new InvalidArgumentException('A stored inbound message needs a positive ID.');
        }
    }
}
