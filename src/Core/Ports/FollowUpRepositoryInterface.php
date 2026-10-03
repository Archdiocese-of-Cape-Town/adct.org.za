<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

/**
 * The append-only log of outreach the plugin makes to a parish.
 *
 * A reminder is an outreach record as much as a delivery, so writing one is a
 * separate concern from sending it: a job decides that a reminder is owed, the
 * mail queue decides when it is delivered, and this port records what was
 * attempted.
 */
interface FollowUpRepositoryInterface
{
    public const KIND_APPROVAL_REMINDER = 'approval_reminder';
    public const CHANNEL_EMAIL = 'email';

    /**
     * Append one follow-up record.
     *
     * @param int|null $parishId the parish the follow-up relates to, or null
          *                           when there is no parish to attribute it to
          * @param string $kind one of the KIND_* constants
          * @param string $channel one of the CHANNEL_* constants
          * @param string|null $note free text for the operator, never shown to a parish
          * @param string|null $sentAt UTC timestamp when the message left the queue
          * @param string|null $outcome free text for the result, e.g. 'queued'
          *
          * @return bool false when there was no parish to record the follow-up against
          */
         public function record(
             ?int $parishId,
             string $kind,
             string $channel,
             ?string $note = null,
             ?string $sentAt = null,
             ?string $outcome = null
         ): bool;
}