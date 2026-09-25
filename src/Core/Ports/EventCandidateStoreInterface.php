<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Parsing\ParseOutcome;

interface EventCandidateStoreInterface
{
    public function replaceDraftCandidatesForMessage(
        int $messageId,
        ParseOutcome $outcome,
        string $timestamp
    ): void;

    public function discardDraftCandidatesForMessage(int $messageId, string $timestamp): void;
}
