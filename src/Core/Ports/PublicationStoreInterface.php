<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Publishing\Publication;

interface PublicationStoreInterface
{
    /**
     * Lock the candidate, invoke the policy while holding the lock, and persist the
     * event, change history, occurrences and candidate states in one transaction.
     *
     * @param callable(array<string, mixed>): Publication $prepare
     */
    public function publish(int $candidateId, callable $prepare): int;
}
