<?php

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseResult;

interface EventRepositoryInterface
{
    /**
     * Provisional: the signature will be refined by its first consumer, candidate/event persistence in E5.3 (#52).
     */
    public function saveCandidate(Message $message, ParseResult $result): ?int;

    /**
     * Provisional: the signature will be refined by its first consumer, candidate/event persistence in E5.3 (#52).
     *
     * @return array{id: int, fields: array<string, mixed>, recurrence?: array<string, mixed>}|null
     */
    public function findCandidate(int $candidateId): ?array;
}
