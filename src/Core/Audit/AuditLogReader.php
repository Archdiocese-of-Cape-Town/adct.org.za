<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Audit;

use DateTimeImmutable;

/**
 * The audit trail as read back out of storage.
 *
 * This is the port the admin screen and the per-subject History views use. It
 * is deliberately write-free: an audit log that this interface could edit or
 * delete through would no longer be evidence of anything. Appends go through
 * AuditWriter instead, and removal only through the E2.7 retention job.
 */
interface AuditLogReader
{
    /**
     * One page of entries, newest first.
     *
     * @return list<AuditEntry>
     */
    public function entries(AuditQuery $query): array;

    /**
     * How many entries match the query, capped at the hard row ceiling so a
     * COUNT over an unbounded table cannot itself become the timeout.
     */
    public function count(AuditQuery $query): int;
}