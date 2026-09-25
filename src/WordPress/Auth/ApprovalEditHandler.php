<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Approval\ApprovalPreviewText;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use LogicException;
use RuntimeException;
use Throwable;

final class ApprovalEditHandler implements ActionTokenActionHandlerInterface
{
    public function __construct(
        private readonly DatabaseConnectionInterface $database,
        private readonly ApprovalRecipients $recipients,
        private readonly ClockInterface $clock
    ) {
    }

    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::EDIT;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        $row = $this->candidate($binding);
        if ($row === null || $this->role($row, $binding) === null || ! $this->deliverable($binding)) {
            return null;
        }
        $fields = json_decode((string) ($row['fields'] ?? ''), true);
        if (! is_array($fields)) {
            throw new RuntimeException('The editable candidate fields are invalid.');
        }
        $available = $row['status'] === 'awaiting_approval' && empty($row['decided_at'])
            && $row['match_kind'] === 'new' && empty($fields['match_review_required'])
            && empty($row['match_review_required']) && (int) ($row['matched_candidate_id'] ?? 0) === 0;
        return new ActionTokenPreview(
            'Correct event preview',
            $available
                ? 'Make corrections, then save. This does not publish or approve the event.'
                : 'This event has already been decided or needs manual review.',
            'Save corrections',
            ['Venue and parish cannot be changed through this link. Contact the intake office if either is wrong.'],
            $available,
            $available ? [
                'title' => (string) ($fields['title'] ?? ''),
                'event_date' => ApprovalPreviewText::editDate((string) ($fields['event_date'] ?? '')),
                'event_time' => (string) ($fields['event_time'] ?? '09:00'),
                'description' => (string) ($fields['description'] ?? ''),
            ] : []
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        throw new LogicException('An edit requires a nonce-protected transactional POST.');
    }

    public function save(
        ActionTokenBinding $binding,
        string $secret,
        ActionTokenService $tokens,
        array $edits
    ): ActionTokenOutcome {
        $title = trim((string) ($edits['title'] ?? ''));
        $date = trim((string) ($edits['event_date'] ?? ''));
        $time = trim((string) ($edits['event_time'] ?? ''));
        $description = trim((string) ($edits['description'] ?? ''));
        $parsed = DateTimeImmutable::createFromFormat('!d/m/Y', $date, new DateTimeZone('Africa/Johannesburg'));
        if ($title === '' || strlen($title) > 255 || strlen($description) > 2000
            || preg_match('//u', $title) !== 1 || preg_match('//u', $description) !== 1
            || $parsed === false || $parsed->format('d/m/Y') !== $date
            || preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $time) !== 1) {
            throw new DomainException('Enter a title, valid day-first date (DD/MM/YYYY), time (HH:MM), and a short description.');
        }
        $this->execute('START TRANSACTION');
        try {
            $row = $this->candidate($binding, true);
            if ($row === null || $this->role($row, $binding) === null || ! $this->deliverable($binding)
                || $row['status'] !== 'awaiting_approval' || ! empty($row['decided_at'])
                || $row['match_kind'] !== 'new' || ! empty($row['match_review_required'])
                || (int) ($row['matched_candidate_id'] ?? 0) > 0) {
                throw new DomainException('This event is already decided or you are no longer an approver.');
            }
            $fields = json_decode((string) ($row['fields'] ?? ''), true);
            if (! is_array($fields) || ! empty($fields['match_review_required'])) {
                throw new DomainException('Ambiguous matches require manual review.');
            }
            if (($parsed->format('Y-m-d') !== ($fields['event_date'] ?? null)
                || $time !== ($fields['event_time'] ?? null))
                && (isset($fields['event_end_date']) || isset($fields['event_end_time']))) {
                throw new DomainException('For a time range or multi-day event, please contact the intake office to change its dates.');
            }
            if ($tokens->consume($secret, $binding)->status !== ActionTokenStatus::CONSUMED) {
                throw new DomainException('This correction link has already been used or expired.');
            }
            $before = array_intersect_key($fields, array_flip([
                'title', 'event_date', 'event_time', 'description',
            ]));
            $fields['title'] = $title;
            $fields['event_date'] = $parsed->format('Y-m-d');
            $fields['event_time'] = $time;
            $fields['description'] = $description;
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $this->execute($this->database->prepare(
                'UPDATE ' . $this->table('adct_pi_event_candidates')
                . ' SET fields = %s, updated_at = %s WHERE id = %d AND status = %s AND decided_at IS NULL',
                json_encode($fields, JSON_THROW_ON_ERROR), $now, $binding->subjectId, 'awaiting_approval'
            ));
            $this->execute($this->database->prepare(
                'INSERT INTO ' . $this->table('adct_pi_audit_log')
                . ' (actor,action,subject_type,subject_id,details,created_at,updated_at)'
                . ' VALUES (%s,%s,%s,%d,%s,%s,%s)',
                $binding->email, 'approver_edited', 'event_candidate', $binding->subjectId,
                json_encode(['before' => $before, 'after' => array_intersect_key($fields, array_flip([
                    'title', 'event_date', 'event_time', 'description',
                ]))], JSON_THROW_ON_ERROR),
                $now, $now
            ));
            $this->execute('COMMIT');
        } catch (Throwable $failure) {
            $this->execute('ROLLBACK');
            throw $failure;
        }
        return new ActionTokenOutcome('Corrections saved. The event is still awaiting approval; use the approval link to review it again.');
    }

    private function role(array $row, ActionTokenBinding $binding): ?string
    {
        return $this->recipients->roleFor((int) ($row['parish_id'] ?? 0) ?: null, $binding->email);
    }

    private function deliverable(ActionTokenBinding $binding): bool
    {
        $this->database->clearLastError();
        $row = $this->database->getRow($this->database->prepare(
            'SELECT q.status FROM ' . $this->table('adct_pi_approval_notices') . ' n'
            . ' JOIN ' . $this->table('adct_pi_mail_queue') . ' q'
            . ' ON q.group_key = n.group_key AND q.recipient = n.recipient'
            . ' WHERE n.candidate_id = %d AND n.recipient = %s LIMIT 1',
            $binding->subjectId, $binding->email
        ));
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The correction email could not be checked.');
        }
        return $row !== null && in_array($row['status'], ['queued', 'sending', 'sent'], true);
    }

    private function candidate(ActionTokenBinding $binding, bool $lock = false): ?array
    {
        if ($binding->purpose !== ActionTokenPurpose::EDIT || $binding->subjectType !== 'event_candidate') {
            return null;
        }
        $this->database->clearLastError();
        $row = $this->database->getRow($this->database->prepare(
            'SELECT * FROM ' . $this->table('adct_pi_event_candidates')
            . ' WHERE id = %d' . ($lock ? ' FOR UPDATE' : ''), $binding->subjectId
        ));
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The approval edit could not read the candidate.');
        }
        return $row;
    }

    private function execute(string $sql): void
    {
        $this->database->clearLastError();
        if ($this->database->query($sql) === false || $this->database->lastError() !== '') {
            throw new RuntimeException('The approval edit could not be saved.');
        }
    }

    private function table(string $suffix): string
    {
        $prefix = $this->database->prefix();
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('Invalid approval edit table prefix.');
        }
        return '`' . $prefix . $suffix . '`';
    }
}
