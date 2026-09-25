<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageStoreResult;

interface InboundMessageStoreInterface
{
    public function findDuplicate(int $sourceId, string $externalId, ?string $contentHash): ?int;

    public function store(InboundMessageRecord $message, string $timestamp): InboundMessageStoreResult;
}
