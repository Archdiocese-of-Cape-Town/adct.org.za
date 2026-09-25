<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\AtomicActionTokenHandlerInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Approval\ApprovalPreviewText;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeZone;
use DomainException;
use LogicException;
use RuntimeException;
use Throwable;

final class ApprovalDecisionHandler implements AtomicActionTokenHandlerInterface
{
    public function __construct(
        private readonly ActionTokenPurpose $action,
        private readonly DatabaseConnectionInterface $database,
        private readonly ApprovalRecipients $recipients,
        private readonly CandidatePublisher $publisher,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock
    ) {
        if (! in_array($action, [ActionTokenPurpose::APPROVE_EVENT, ActionTokenPurpose::REJECT_EVENT], true)) {
            throw new LogicException('An approval handler needs an approval or rejection purpose.');
        }
    }

    public function purpose(): ActionTokenPurpose
    {
        return $this->action;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        $row = $this->candidate($binding);
        if ($row === null || $this->role($row, $binding) === null || ! $this->deliverable($binding)) {
            return null;
        }
        $fields = json_decode((string) ($row['fields'] ?? ''), true);
        if (! is_array($fields)) {
            throw new RuntimeException('The approval preview is invalid.');
        }
        $details = [];
        if ((int) ($row['parish_id'] ?? 0) > 0) {
            $parish = $this->read($this->database->prepare(
                'SELECT name FROM ' . $this->table('adct_pi_parishes') . ' WHERE id = %d',
                (int) $row['parish_id']
            ));
            if (is_string($parish['name'] ?? null) && $parish['name'] !== '') {
                $details[] = 'Parish: ' . $parish['name'];
            }
        }
        foreach (['title' => 'Event', 'event_date' => 'Date', 'event_time' => 'Time',
            'venue' => 'Venue', 'event_type' => 'Event type',
            'description' => 'Description'] as $key => $label) {
            if (is_string($fields[$key] ?? null) && trim($fields[$key]) !== '') {
                $details[] = $label . ': ' . ApprovalPreviewText::shorten(
                    $key === 'event_date' ? ApprovalPreviewText::date($fields[$key]) : $fields[$key]
                );
            }
        }
        $message = $this->message($row);
        if ($message !== null) {
            $details[] = 'Submitted by: ' . (string) ($message['sender_email'] ?? 'Unknown');
        }
        $notes = json_decode((string) ($row['notes'] ?? '[]'), true);
        if (is_array($notes) && in_array('unknown_sender', $notes, true)) {
            $details[] = 'Warning: unknown sender. Check the parish before approving.';
        }
        if (is_array($notes) && in_array('dmarc_fail', $notes, true)) {
            $details[] = 'Warning: reported DMARC failure. Verify the sender before approving.';
        }
        $decided = $this->decision($row);
        return new ActionTokenPreview(
            $decided === null ? 'Review event' : 'Event already decided',
            $decided ?? 'Check this event before making a decision. Only your button press records it.',
            $this->action === ActionTokenPurpose::APPROVE_EVENT ? 'Approve and publish' : 'Reject event',
            $details,
            $decided === null || ($row['status'] === 'awaiting_approval'
                && $row['decided_by'] === $binding->email
                && $this->action === ActionTokenPurpose::APPROVE_EVENT)
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        throw new LogicException('Approver decisions require an atomic token transaction.');
    }

    public function performAtomic(
        ActionTokenBinding $binding,
        string $token,
        ActionTokenService $tokens,
        string $reason
    ): ActionTokenOutcome {
        if ($binding->purpose !== $this->action || $binding->subjectType !== 'event_candidate'
            || ($this->action === ActionTokenPurpose::APPROVE_EVENT && $reason !== '')) {
            throw new DomainException('This approval action is unavailable.');
        }

        $this->execute('START TRANSACTION');
        try {
            $row = $this->candidate($binding, true);
            if ($row === null || $this->role($row, $binding) === null || ! $this->deliverable($binding)) {
                throw new DomainException('This approver is no longer assigned.');
            }
            if ($this->decision($row) !== null || $row['status'] !== 'awaiting_approval') {
                throw new DomainException($this->decision($row) ?? 'This event is not awaiting approval.');
            }
            $fields = json_decode((string) ($row['fields'] ?? ''), true);
            if (! is_array($fields) || ! empty($fields['match_review_required'])
                || ! empty($row['match_review_required']) || (int) ($row['matched_candidate_id'] ?? 0) > 0
                || ($row['match_kind'] ?? null) !== 'new') {
                throw new DomainException('A matched or ambiguous event needs manual review.');
            }
            if ($tokens->consume($token, $binding)->status !== ActionTokenStatus::CONSUMED) {
                throw new DomainException('The approval link was already used or expired.');
            }
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $table = $this->table('adct_pi_event_candidates');
            $approve = $this->action === ActionTokenPurpose::APPROVE_EVENT;
            $role = $this->role($row, $binding);
            $query = $approve
                ? $this->database->prepare(
                    "UPDATE {$table} SET approved_by = %s, approved_at = %s, approved_via = %s,"
                    . ' decided_by = %s, decided_at = %s, updated_at = %s'
                    . " WHERE id = %d AND status = %s AND approved_by IS NULL AND decided_at IS NULL",
                    $binding->email, $now, $role, $binding->email, $now, $now,
                    $binding->subjectId, 'awaiting_approval'
                )
                : $this->database->prepare(
                    "UPDATE {$table} SET status = %s, decided_by = %s, decided_at = %s,"
                    . ' decision_note = %s, updated_at = %s'
                    . " WHERE id = %d AND status = %s AND approved_by IS NULL AND decided_at IS NULL",
                    'rejected', $binding->email, $now, $reason, $now,
                    $binding->subjectId, 'awaiting_approval'
                );
            if ($this->execute($query) !== 1) {
                throw new DomainException('Someone else has already decided this event.');
            }
            $audit = $this->table('adct_pi_audit_log');
            $this->execute($this->database->prepare(
                "INSERT INTO {$audit} (actor,action,subject_type,subject_id,details,created_at,updated_at)"
                . ' VALUES (%s,%s,%s,%d,%s,%s,%s)',
                $binding->email, $approve ? 'approver_approved' : 'approver_rejected',
                'event_candidate', $binding->subjectId,
                json_encode(['role' => $role, 'reason' => $approve ? null : $reason], JSON_THROW_ON_ERROR),
                $now, $now
            ));
            $this->execute('COMMIT');
        } catch (Throwable $failure) {
            $this->execute('ROLLBACK');
            throw $failure;
        }
        return $this->complete($binding);
    }

    public function recover(ActionTokenBinding $binding): ActionTokenOutcome
    {
        $row = $this->candidate($binding);
        if ($row === null || $this->role($row, $binding) === null || ! $this->deliverable($binding)
            || ($row['decided_by'] ?? null) !== $binding->email
            || ($this->action === ActionTokenPurpose::APPROVE_EVENT
                ? ! in_array($row['status'], ['awaiting_approval', 'published'], true)
                    || ! in_array($row['approved_via'], ['dean', 'reviewer'], true)
                : $row['status'] !== 'rejected')) {
            throw new DomainException('This link did not decide the event.');
        }
        return $this->complete($binding);
    }

    private function complete(ActionTokenBinding $binding): ActionTokenOutcome
    {
        $row = $this->candidate($binding);
        if ($row === null) {
            throw new RuntimeException('The decided candidate could not be read.');
        }
        $submitter = $this->submitter($row);
        if ($this->action === ActionTokenPurpose::REJECT_EVENT) {
            if ($submitter !== null) {
                $reason = trim((string) ($row['decision_note'] ?? ''));
                $text = 'Your event was not published.' . ($reason === '' ? '' : "\nReason: " . $reason);
                $this->mailer->enqueue(new OutboundEmail(
                    $submitter, 'Your event was not published', nl2br(esc_html($text)),
                    $text, MailPriority::APPROVER_OR_CHANGE, 'approval-rejected:' . $binding->subjectId
                ));
            }
            return new ActionTokenOutcome('The event was rejected. Thank you.');
        }
        try {
            $eventId = $this->publisher->publish($binding->subjectId);
        } catch (DomainException $failure) {
            throw new RuntimeException('Approval was recorded, but publication needs operator attention.', 0, $failure);
        }
        $url = get_permalink($eventId);
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('The published event permalink is unavailable.');
        }
        if ($submitter !== null) {
            $text = "Your event is now live:\n" . $url;
            $this->mailer->enqueue(new OutboundEmail(
                $submitter, 'Your event is now live',
                '<p>Your event is now live: <a href="' . esc_url($url) . '">View the event</a></p>',
                $text, MailPriority::APPROVER_OR_CHANGE, 'approval-live:' . $binding->subjectId
            ));
        }
        return new ActionTokenOutcome('The event was approved and published.', [$url]);
    }

    private function decision(array $row): ?string
    {
        if (empty($row['decided_at']) || empty($row['decided_by'])) {
            return null;
        }
        return 'Already ' . ($row['status'] === 'rejected' ? 'rejected' : 'approved')
            . ' by ' . $row['decided_by'] . ' at ' . $row['decided_at'] . ' UTC.';
    }

    private function role(array $row, ActionTokenBinding $binding): ?string
    {
        return $this->recipients->roleFor(
            (int) ($row['parish_id'] ?? 0) ?: null,
            $binding->email
        );
    }

    private function submitter(array $row): ?string
    {
        $email = $row['confirmed_by'] ?? null;
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? strtolower($email) : null;
    }

    private function deliverable(ActionTokenBinding $binding): bool
    {
        $row = $this->read($this->database->prepare(
            'SELECT q.status FROM ' . $this->table('adct_pi_approval_notices') . ' n'
            . ' JOIN ' . $this->table('adct_pi_mail_queue') . ' q'
            . ' ON q.group_key = n.group_key AND q.recipient = n.recipient'
            . ' WHERE n.candidate_id = %d AND n.recipient = %s LIMIT 1',
            $binding->subjectId, $binding->email
        ));
        return $row !== null && in_array($row['status'], ['queued', 'sending', 'sent'], true);
    }

    private function message(array $row): ?array
    {
        if ((int) ($row['message_id'] ?? 0) < 1) {
            return null;
        }
        return $this->read($this->database->prepare(
            'SELECT sender_email FROM ' . $this->table('adct_pi_inbound_messages') . ' WHERE id = %d',
            (int) $row['message_id']
        ));
    }

    private function candidate(ActionTokenBinding $binding, bool $lock = false): ?array
    {
        if ($binding->purpose !== $this->action || $binding->subjectType !== 'event_candidate') {
            return null;
        }
        return $this->read($this->database->prepare(
            'SELECT * FROM ' . $this->table('adct_pi_event_candidates')
            . ' WHERE id = %d' . ($lock ? ' FOR UPDATE' : ''),
            $binding->subjectId
        ));
    }

    private function read(string $sql): ?array
    {
        $this->database->clearLastError();
        $row = $this->database->getRow($sql);
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The approval database read failed.');
        }
        return $row;
    }

    private function execute(string $sql): int
    {
        $this->database->clearLastError();
        $result = $this->database->query($sql);
        if ($result === false || $this->database->lastError() !== '') {
            throw new RuntimeException('The approval database write failed.');
        }
        return $result;
    }

    private function table(string $suffix): string
    {
        $prefix = $this->database->prefix();
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The approval database prefix is invalid.');
        }
        return '`' . $prefix . $suffix . '`';
    }
}
