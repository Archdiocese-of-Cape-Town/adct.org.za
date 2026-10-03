<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Approval;

use ADCT\ParishIntake\Core\Approval\Approver;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Jobs\AbstractJob;
use ADCT\ParishIntake\Core\Jobs\JobRunLifecycleInterface;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Matching\MatchReviewPolicy;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeZone;
use OutOfBoundsException;
use RuntimeException;

final class ApprovalNoticeJob extends AbstractJob implements JobRunLifecycleInterface
{
    private const BATCH_SIZE = 20;

    /**
     * Digest mail is held back until this hour so that "daily" means one
     * message a day, even when cron happens to run soon after an arrival.
     */
    public const DEFAULT_DIGEST_HOUR = 7;

    private array $notifiedThisRun = [];

    public function __construct(
        private readonly DatabaseConnectionInterface $database,
        private readonly ApprovalRecipients $recipients,
        private readonly ActionTokenService $tokens,
        private readonly MailerInterface $mailer,
        private readonly MailQueueRepositoryInterface $queue,
        private readonly ClockInterface $clock,
        private readonly int $digestHour = self::DEFAULT_DIGEST_HOUR
    ) {
        if ($digestHour < 0 || $digestHour > 23) {
            throw new \InvalidArgumentException('The digest hour must be between 0 and 23.');
        }
        parent::__construct('queue_approval_notices', 'Queue approver decisions and digests', 600);
    }

    /**
     * Whether digest mail may go out at the given local time.
     */
    public function digestOpen(\DateTimeImmutable $localNow): bool
    {
        return (int) $localNow->format('G') >= $this->digestHour;
    }

    public function beginRun(): void
    {
        $this->notifiedThisRun = [];
    }

    public function processNext(?string $checkpoint): ?JobStepResult
    {
        $pending = $this->rows(
            'SELECT group_key, recipient FROM ' . $this->table('adct_pi_approval_notices')
            . ' WHERE queued_at IS NULL ORDER BY id ASC LIMIT 1'
        );
        if ($pending !== []) {
            if (isset($this->notifiedThisRun[$pending[0]['recipient']])) {
                return JobStepResult::completeAt(null);
            }
            $this->deliver($pending[0]['group_key'], $pending[0]['recipient']);
            $this->notifiedThisRun[$pending[0]['recipient']] = true;
            return JobStepResult::continueAt($checkpoint);
        }

        $after = ctype_digit($checkpoint ?? '') ? (int) $checkpoint : 0;
        $candidates = $this->rows($this->database->prepare(
            'SELECT c.* FROM ' . $this->table('adct_pi_event_candidates') . ' c'
            . ' WHERE c.id > %d AND c.status = %s AND c.approved_by IS NULL AND c.match_kind = %s'
            . ' ORDER BY c.id ASC LIMIT %d',
            $after, 'awaiting_approval', 'new', self::BATCH_SIZE
        ));
        if ($candidates === []) {
            return $after === 0 ? null : JobStepResult::completeAt(null);
        }
        $grouped = [];
        $deferred = false;
        $localNow = $this->clock->now()->setTimezone(new DateTimeZone('Africa/Johannesburg'));
        $today = $localNow->format('Ymd');
        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];
            $fields = json_decode((string) ($candidate['fields'] ?? ''), true);
            if (! is_array($fields)) {
                throw new RuntimeException('An approval candidate has invalid preview fields.');
            }
            if (MatchReviewPolicy::requiresManualReview($fields)
                || ! empty($candidate['match_review_required'])
                || (int) ($candidate['matched_candidate_id'] ?? 0) > 0) {
                $after = $id;
                continue;
            }
            try {
                $recipients = $this->recipients->forParish(
                    $candidate['parish_id'] === null ? null : (int) $candidate['parish_id']
                );
            } catch (OutOfBoundsException) {
                // The parish row went away after intake. There is nobody left to
                // notify, so step over this candidate instead of abandoning the
                // batch and leaving every later candidate unnotified.
                error_log('[ADCT Parish Intake] Approval notice skipped candidate ' . $id
                    . ': its parish no longer exists.');
                $after = $id;
                continue;
            }
            foreach ($recipients as $email => $recipient) {
                if ($this->noticeExists($id, $email)) {
                    continue;
                }
                if (isset($this->notifiedThisRun[$email])) {
                    $deferred = true;
                    continue;
                }
                $mode = $recipient['mode'];
                if ($mode === Approver::NOTIFY_DIGEST && ! $this->digestOpen($localNow)) {
                    // Too early for today's digest. Leave the candidate unnotified
                    // so a later run picks it up once the digest hour has passed.
                    $deferred = true;
                    continue;
                }
                $recipientHash = substr(hash('sha256', $email), 0, 24);
                $key = $mode === Approver::NOTIFY_DIGEST
                    ? 'approval-digest:' . $today . ':' . $recipientHash
                    : ($grouped[$email]['key'] ?? 'approval:' . $id . ':' . $recipientHash);
                if ($mode === Approver::NOTIFY_DIGEST && $this->queue->findByRecipientAndGroupKey($email, $key) !== null) {
                    continue;
                }
                $grouped[$email] = ['key' => $key, 'mode' => $mode];
                $this->execute($this->database->prepare(
                    'INSERT INTO ' . $this->table('adct_pi_approval_notices')
                    . ' (candidate_id,recipient,group_key,notify_mode,created_at,updated_at)'
                    . ' VALUES (%d,%s,%s,%s,%s,%s)',
                    $id, $email, $key, $mode, $this->utcNow(), $this->utcNow()
                ));
            }
            $after = $id;
        }
        foreach ($grouped as $email => $group) {
            $this->deliver($group['key'], $email);
            $this->notifiedThisRun[$email] = true;
        }
        return $deferred ? JobStepResult::completeAt(null) : JobStepResult::continueAt((string) $after);
    }

    private function deliver(string $key, string $email): void
    {
        $notices = $this->rows($this->database->prepare(
            'SELECT n.candidate_id, n.notify_mode, c.fields, c.notes, c.message_id,'
            . ' m.sender_email, m.sender_name, p.name AS parish_name'
            . ' FROM ' . $this->table('adct_pi_approval_notices') . ' n'
            . ' JOIN ' . $this->table('adct_pi_event_candidates') . ' c ON c.id = n.candidate_id'
            . ' LEFT JOIN ' . $this->table('adct_pi_inbound_messages') . ' m ON m.id = c.message_id'
            . ' LEFT JOIN ' . $this->table('adct_pi_parishes') . ' p ON p.id = c.parish_id'
            . ' WHERE n.group_key = %s AND n.recipient = %s AND n.queued_at IS NULL'
            . ' ORDER BY n.candidate_id ASC LIMIT %d',
            $key, $email, self::BATCH_SIZE
        ));
        if ($notices === []) {
            return;
        }
        if ($this->queue->findByRecipientAndGroupKey($email, $key) === null) {
            $text = "Events awaiting your decision:\n\n";
            $html = '<p>Events awaiting your decision:</p>';
            foreach ($notices as $notice) {
                $fields = json_decode((string) $notice['fields'], true);
                if (! is_array($fields)) {
                    throw new RuntimeException('An approval candidate has invalid preview fields.');
                }
                $id = (int) $notice['candidate_id'];
                $title = is_string($fields['title'] ?? null) ? $fields['title'] : 'Untitled event';
                $sender = (string) ($notice['sender_email'] ?? 'Unknown');
                $notes = json_decode((string) ($notice['notes'] ?? '[]'), true);
                $warning = is_array($notes) && in_array('unknown_sender', $notes, true)
                    ? 'WARNING: Unknown sender. Verify the parish before approving.' : '';
                if (is_array($notes) && in_array('dmarc_fail', $notes, true)) {
                    $warning .= ' WARNING: Reported DMARC failure.';
                }
                $preview = [];
                if (is_string($notice['parish_name'] ?? null) && $notice['parish_name'] !== '') {
                    $preview[] = 'Parish: ' . $notice['parish_name'];
                }
                foreach (['title' => 'Event', 'event_date' => 'Date', 'event_time' => 'Time',
                    'venue' => 'Venue', 'event_type' => 'Event type',
                    'description' => 'Description'] as $field => $label) {
                    if (is_string($fields[$field] ?? null) && trim($fields[$field]) !== '') {
                        $preview[] = $label . ': ' . ApprovalPreviewText::shorten(
                            $field === 'event_date'
                                ? ApprovalPreviewText::date($fields[$field])
                                : $fields[$field]
                        );
                    }
                }
                $preview[] = 'Submitted by: ' . $sender;
                if ($warning !== '') {
                    $preview[] = $warning;
                }
                $links = [];
                foreach ([
                    'Approve' => ActionTokenPurpose::APPROVE_EVENT,
                    'Reject' => ActionTokenPurpose::REJECT_EVENT,
                ] as $label => $purpose) {
                    $links[$label] = ActionTokenEndpoint::urlForToken(
                        $this->tokens->issue(new ActionTokenBinding($purpose, 'event_candidate', $id, $email))->token()
                    );
                }
                $links['Edit'] = ActionTokenEndpoint::urlForToken(
                    $this->tokens->issue(new ActionTokenBinding(
                        ActionTokenPurpose::EDIT, 'event_candidate', $id, $email
                    ))->token()
                );
                $text .= implode("\n", $preview) . "\n";
                $html .= '<section><h2>' . esc_html($title) . '</h2>';
                foreach ($preview as $line) {
                    $html .= '<p>' . esc_html($line) . '</p>';
                }
                foreach ($links as $label => $url) {
                    $text .= $label . ': ' . $url . "\n";
                    $html .= '<p><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></p>';
                }
                $text .= "\n";
                $html .= '</section>';
            }
            $digest = $notices[0]['notify_mode'] === Approver::NOTIFY_DIGEST;
            $this->mailer->enqueue(new OutboundEmail(
                $email, $digest ? 'Your daily event approvals' : 'Events awaiting your approval',
                $html, $text,
                $digest ? MailPriority::REMINDER_OR_DIGEST : MailPriority::APPROVER_OR_CHANGE,
                $key
            ));
        }
        $this->execute($this->database->prepare(
            'UPDATE ' . $this->table('adct_pi_approval_notices')
            . ' SET queued_at = %s, updated_at = %s'
            . ' WHERE group_key = %s AND recipient = %s AND queued_at IS NULL',
            $this->utcNow(), $this->utcNow(), $key, $email
        ));
    }

    private function noticeExists(int $id, string $email): bool
    {
        return $this->rows($this->database->prepare(
            'SELECT id FROM ' . $this->table('adct_pi_approval_notices')
            . ' WHERE candidate_id = %d AND recipient = %s LIMIT 1',
            $id, $email
        )) !== [];
    }

    private function utcNow(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function rows(string $sql): array
    {
        $this->database->clearLastError();
        $rows = $this->database->getResults($sql);
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('Approval notice lookup failed.');
        }
        return $rows;
    }

    private function execute(string $sql): void
    {
        $this->database->clearLastError();
        if ($this->database->query($sql) === false || $this->database->lastError() !== '') {
            throw new RuntimeException('An approval notice could not be recorded.');
        }
    }

    private function table(string $suffix): string
    {
        $prefix = $this->database->prefix();
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('Invalid approval table prefix.');
        }
        return '`' . $prefix . $suffix . '`';
    }
}
