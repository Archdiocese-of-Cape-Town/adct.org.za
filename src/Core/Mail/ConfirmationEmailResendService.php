<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\ConfirmationEmailResendBookingInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Re-sends a candidate's confirmation email, at a reviewer's request.
 *
 * Two things distinguish this from {@see ConfirmationEmailPreviewService} and both
 * are deliberate:
 *
 * - It renders from the candidate's **current** stored fields, not from what the
 *   reviewer happened to have on screen. A resend is a statement about the event
 *   as it now stands, so the email and the candidate can never disagree.
 * - It is not idempotent. Every press produces a new email under a fresh group
 *   key, because the whole point is to hand the parish working, single-use action
 *   links that supersede the ones in the email they already have.
 *
 * The hourly cooldown lives in the booking port, not here. That keeps it enforced
 * by the same transaction that writes the audit row, so a hand-crafted POST racing
 * a real one cannot slip past it — the view merely reports what the booking
 * decided.
 */
final class ConfirmationEmailResendService
{
    /** Between two resends of the same candidate. */
    public const COOLDOWN_SECONDS = 3600;

    private const GROUP_KEY_PREFIX = 'confirmation-resend';

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly MailerInterface $mailer,
        private readonly ConfirmationEmailComposer $composer,
        private readonly MailQueueRepositoryInterface $queue,
        private readonly ConfirmationEmailResendBookingInterface $booking,
        private readonly DateTimeZone $displayTimezone
    ) {
    }

    /**
     * Queue a fresh confirmation email for the candidate behind $candidateId.
     *
     * The booking is taken *before* anything is rendered or queued, so two
     * concurrent presses cannot both pass the cooldown: the second loses the
     * transaction and is told when it may retry.
     *
     * @throws ConfirmationEmailResendCooldownException when the candidate was
     *         resent within the last hour
     * @throws \DomainException when the candidate cannot be resent at all
     *         (never saved, or no safe recipient)
     */
    public function resend(int $candidateId, string $actor): ConfirmationEmailResendResult
    {
        $requestedAt = $this->clock->now();
        $resendable = $this->booking->claimResendSlot($candidateId, $actor, $requestedAt, self::COOLDOWN_SECONDS);

        $batch = $resendable->batch;

        if ($batch->candidates === []) {
            throw new \DomainException('This candidate has no confirmation email to resend.');
        }

        $recipient = $this->composer->recipient($batch);

        if ($recipient === null) {
            throw new \DomainException('This candidate has no safe email address to resend a confirmation to.');
        }

        $recipient = ActionTokenBinding::normalizeEmailAddress($recipient);
        $threadHeaders = $this->composer->threadHeaders($batch);
        $payloadFingerprint = $this->composer->payloadFingerprint($batch, $recipient, $threadHeaders);
        $content = $this->composer->render($batch, $recipient);
        $email = new OutboundEmail(
            $recipient,
            $content->subject,
            $content->html,
            $content->text,
            MailPriority::LOGIN_OR_CONFIRMATION,
            $this->groupKey($candidateId, $requestedAt),
            $threadHeaders,
            $payloadFingerprint
        );

        // Never sent inline (ADR 0011): enqueue() owns delivery, including the
        // test-mode suppression and the 500-per-hour account budget.
        $result = $this->mailer->enqueue($email);

        if ($result->duplicate) {
            throw new RuntimeException('A confirmation resend group key was already used.');
        }

        $queued = $this->queue->findByRecipientAndGroupKey($recipient, $email->groupKey);

        if ($queued === null || $queued->id !== $result->id) {
            throw new RuntimeException('The newly queued confirmation resend could not be verified.');
        }

        return new ConfirmationEmailResendResult(
            ConfirmationEmailResendOutcome::fromQueueStatus($queued->status),
            $candidateId,
            $recipient,
            $queued->id,
            $resendable->lastResentAt,
            $requestedAt->modify('+' . self::COOLDOWN_SECONDS . ' seconds'),
            $requestedAt
        );
    }

    /**
     * The current instant, from the injected clock.
     *
     * Exposed so the admin screen can decide whether to grey the button out
     * without reading "now" itself — the screen must be testable against a fixed
     * clock, and a stray `new DateTimeImmutable()` in the view would quietly make
     * every time-dependent assertion a race.
     */
    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * The earliest moment another resend of this candidate would be accepted.
     *
     * Null when it has never been resent, so the view can offer the button
     * unconditionally rather than guessing.
     */
    public function nextAllowedAt(int $candidateId): ?DateTimeImmutable
    {
        $last = $this->booking->lastResentAt($candidateId);

        return $last?->modify('+' . self::COOLDOWN_SECONDS . ' seconds');
    }

    /**
     * The last time this candidate's confirmation was resent, or null if never.
     */
    public function lastResentAt(int $candidateId): ?DateTimeImmutable
    {
        return $this->booking->lastResentAt($candidateId);
    }

    /**
     * A group key unique to this press.
     *
     * Fresh on purpose: the queue treats a repeated key as the same email, and a
     * resend that silently reused `confirmation:<message>` would be dropped as a
     * duplicate instead of reaching the parish.
     */
    private function groupKey(int $candidateId, DateTimeImmutable $requestedAt): string
    {
        return self::GROUP_KEY_PREFIX . ':' . $candidateId . ':' . $requestedAt->format('YmdHis');
    }
}
