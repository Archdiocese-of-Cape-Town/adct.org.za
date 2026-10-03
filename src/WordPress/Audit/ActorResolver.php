<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Audit;

/**
 * The identity recorded as the actor of an action taken in a browser session.
 *
 * The audit log's actor column holds an email address at every write site, so
 * an actor is an address and never a user id. One interface keeps that
 * resolution in a single place: the repository writes it, the screens read it
 * back as a filter, and a test can stand in a fixed address without loading
 * WordPress.
 */
interface ActorResolver
{
    /**
     * The actor to record, or the system actor when no user is attributable.
     *
     * An audit trail cannot answer "who" with a blank, so an implementation
     * returns AuditLogRepository::SYSTEM_ACTOR rather than an empty string when
     * there is no signed-in user or the user has no address on record.
     */
    public function actor(): string;
}