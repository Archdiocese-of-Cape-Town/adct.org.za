<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Approval;

use ADCT\ParishIntake\Core\Approval\ApprovalReminderSettings;
use ADCT\ParishIntake\Core\Approval\Approver;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Jobs\AbstractJob;
use ADCT\ParishIntake\Core\Jobs\JobRunLifecycleInterface;
use ADCT\ParishIntake\Core\Jobs\JobState;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Matching\MatchReviewPolicy;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\FollowUpRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Nudges approvers about items that have been sitting in a queue.
 *
 * ADR 0008 point 9: after a configurable idle period each queue gets one
 * reminder, switchable globally here and per approver on the approver itself.
 *
 * Two deliberate choices:
 *
 * - A reminder reuses the APPROVE_EVENT, REJECT_EVENT and EDIT tokens, so the
 *   existing handlers apply unchanged. Each of those re-resolves the live
 *   approver relationship at act time, so a dean who has since moved deaneries
 *   cannot act on a reminder minted for them here.
 * - "Never twice per item and approver" is enforced by the mail queue's unique
 *   (recipient, group_key) index rather than by a new table or column. The
 *   group key is derived from the candidate and recipient alone, so a second
 *   daily run finds the queued row and stays quiet.
 */
final class ApprovalReminderJob extends AbstractJob implements JobRunLifecycleInterface
{
    private const BATCH_SIZE = 20;
        private const DEFAULT_INTERVAL_SECONDS = 86400;

        /** @var callable */
        private $settingsProvider;

    public function __construct(
        private readonly DatabaseConnectionInterface $database,
        private readonly ApprovalRecipients $recipients,
        private readonly ActionTokenService $tokens,
        private readonly MailerInterface $mailer,
        private readonly MailQueueRepositoryInterface $queue,
        private readonly FollowUpRepositoryInterface $followUps,
        private readonly ClockInterface $clock,
        callable $settingsProvider
    ) {
        $this->settingsProvider = $settingsProvider;
        parent::__construct('monitoring', 'Send approval reminders', self::DEFAULT_INTERVAL_SECONDS);
    }

    public function isDue(DateTimeImmutable $now, JobState $state): bool
    {
        return $this->settings()->enabled && parent::isDue($now, $state);
    }

    public function beginRun(): void
    {
    }

    public function processNext(?string $checkpoint): ?JobStepResult
    {
        $settings = $this->settings();

        if (! $settings->enabled) {
            return JobStepResult::completeAt(null);
        }

        $after = ctype_digit($checkpoint ?? '') ? (int) $checkpoint : 0;

        // The cutoff is computed from the local clock and handed to the query as
        // a literal parameter, so the index on (status, updated_at) still does
        // the filtering and no per-row date maths happens in SQL.
        $cutoff = $this->localNow()
            ->modify('-' . $settings->days . ' days')
            ->format('Y-m-d H:i:s');

        $candidates = $this->rows($this->database->prepare(
            'SELECT c.id, c.parish_id, c.status, c.decided_at FROM'
            . ' ' . $this->table('adct_pi_event_candidates') . ' c'
            . ' WHERE c.id > %d AND c.status = %s AND c.approved_by IS NULL AND c.decided_at IS NULL'
            . ' AND c.updated_at < %s'
            . ' ORDER BY c.id ASC LIMIT %d',
            $after, 'awaiting_approval', $cutoff, self::BATCH_SIZE
        ));

        if ($candidates === []) {
            return $after === 0 ? null : JobStepResult::completeAt(null);
        }

        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];
            // The row was chosen on the cutoff, but a decision can land between
            // the scan and the send, so re-check before mailing anyone.
            if ((string) ($candidate['status'] ?? '') !== 'awaiting_approval'
                || ($candidate['decided_at'] ?? null) !== null) {
                $after = $id;
                continue;
            }

            $this->remind($id, (int) ($candidate['parish_id'] ?? 0) ?: null, $settings);
            $after = $id;
        }

        return JobStepResult::continueAt((string) $after);
    }

    private function remind(int $candidateId, ?int $parishId, ApprovalReminderSettings $settings): void
    {
        $event = $this->event($candidateId);

        if ($event === null) {
            return;
        }

        $fields = json_decode((string) $event['fields'], true);

        if (! is_array($fields)) {
            throw new RuntimeException('An approval candidate has invalid preview fields.');
        }

        // The same guard the notice job applies: an item still waiting on a
        // manual match decision is not ready for an approver.
        if (MatchReviewPolicy::requiresManualReview($fields)
            || (int) ($event['matched_candidate_id'] ?? 0) > 0) {
            return;
        }

        $title = is_string($fields['title'] ?? null) && trim($fields['title']) !== ''
            ? $fields['title']
            : 'Untitled event';

                // A reminder is always about one item and one approver, so it goes straight
                        // out whatever the approver's original notify mode was. There is no digest
                        // to fold it into: the group key is per item and per approver either way,
                        // and a digest would only obscure the nudge.
                        $line = $this->summary($event, $fields);

                        foreach ($this->recipients->forParish($parishId) as $email => $recipient) {
                            if (! $recipient['reminders']) {
                                continue;
                            }

                            $key = 'approval-reminder:' . $candidateId . ':' . substr(hash('sha256', $email), 0, 24);

                            if ($this->queue->findByRecipientAndGroupKey($email, $key) !== null) {
                                continue;
                            }

                            $this->send(
                                $email,
                                $key,
                                $title,
                                [$line],
                                $this->links($candidateId, $email),
                                $settings->days
                            );
            $this->followUps->record(
                                $parishId,
                FollowUpRepositoryInterface::KIND_APPROVAL_REMINDER,
                FollowUpRepositoryInterface::CHANNEL_EMAIL,
                'candidate ' . $candidateId,
                null,
                'queued'
            );
        }
    }

    /**
     * @param list<string> $lines
     * @param array<string, string> $links
     */
    private function send(
        string $email,
        string $key,
        string $title,
        array $lines,
                        array $links,
                        int $waitingDays
                    ): void {
                        $intro = 'This event has been waiting for a decision for '
                            . $waitingDays . ' days.';

        $text = $title . "\n\n" . $intro . "\n\n" . implode("\n", $lines) . "\n";
        $html = '<p>' . esc_html($intro) . '</p><h2>' . esc_html($title) . '</h2>';

        foreach ($lines as $line) {
            $html .= '<p>' . esc_html($line) . '</p>';
        }
        foreach ($links as $label => $url) {
            $text .= $label . ': ' . $url . "\n";
            $html .= '<p><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></p>';
        }

        $this->mailer->enqueue(new OutboundEmail(
            $email,
            'Still waiting for your approval: ' . $title,
            $html,
            $text,
            MailPriority::REMINDER_OR_DIGEST,
            $key
        ));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function summary(array $event, array $fields): string
    {
        $summary = [];

        if (is_string($event['parish_name'] ?? null) && $event['parish_name'] !== '') {
            $summary[] = 'Parish: ' . $event['parish_name'];
        }

        foreach ([
            'event_date' => 'Date',
            'event_time' => 'Time',
            'venue' => 'Venue',
        ] as $field => $label) {
            if (is_string($fields[$field] ?? null) && trim($fields[$field]) !== '') {
                $summary[] = $label . ': ' . ApprovalPreviewText::shorten(
                    $field === 'event_date'
                        ? ApprovalPreviewText::date($fields[$field])
                        : $fields[$field]
                );
            }
        }

        $summary[] = 'Submitted by: ' . (string) ($event['sender_email'] ?? 'Unknown');

        return implode("\n", $summary);
    }

    /**
     * @return array<string, string>
     */
    private function links(int $candidateId, string $email): array
    {
        $links = [];

        foreach ([
            'Approve' => ActionTokenPurpose::APPROVE_EVENT,
            'Reject' => ActionTokenPurpose::REJECT_EVENT,
            'Edit' => ActionTokenPurpose::EDIT,
        ] as $label => $purpose) {
            $links[$label] = ActionTokenEndpoint::urlForToken(
                $this->tokens->issue(
                    new ActionTokenBinding($purpose, 'event_candidate', $candidateId, $email)
                )->token()
            );
        }

        return $links;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function event(int $candidateId): ?array
    {
        $rows = $this->rows($this->database->prepare(
            'SELECT c.id, c.fields, c.notes, c.message_id, c.matched_candidate_id,'
            . ' m.sender_email, m.sender_name, p.name AS parish_name'
            . ' FROM ' . $this->table('adct_pi_event_candidates') . ' c'
            . ' LEFT JOIN ' . $this->table('adct_pi_inbound_messages') . ' m ON m.id = c.message_id'
            . ' LEFT JOIN ' . $this->table('adct_pi_parishes') . ' p ON p.id = c.parish_id'
            . ' WHERE c.id = %d LIMIT 1',
            $candidateId
        ));

        return $rows[0] ?? null;
    }

    private function settings(): ApprovalReminderSettings
    {
        $settings = ($this->settingsProvider)();

        if (! $settings instanceof ApprovalReminderSettings) {
            throw new RuntimeException('The approval reminder settings provider returned an invalid value.');
        }

        return $settings;
    }

    private function localNow(): DateTimeImmutable
        {
            return $this->clock->now()->setTimezone(new DateTimeZone('Africa/Johannesburg'));
        }

        /**
         * @return list<array<string, mixed>>
         */
        private function rows(string $sql): array
        {
            $this->database->clearLastError();
            $rows = $this->database->getResults($sql);
            if ($this->database->lastError() !== '') {
                throw new RuntimeException('The approval reminder lookup failed.');
            }

            return array_values($rows);
        }

    private function table(string $suffix): string
    {
        $prefix = $this->database->prefix();
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('Invalid approval reminder table prefix.');
        }
        return '`' . $prefix . $suffix . '`';
    }
}