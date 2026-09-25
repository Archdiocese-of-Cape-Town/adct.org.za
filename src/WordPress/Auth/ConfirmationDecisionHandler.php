<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Ingestion\AuthenticationResults;
use ADCT\ParishIntake\Core\Ports\AtomicActionTokenHandlerInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeZone;
use DomainException;
use LogicException;
use RuntimeException;
use Throwable;

final class ConfirmationDecisionHandler implements AtomicActionTokenHandlerInterface
{
    public function __construct(
        private readonly ActionTokenPurpose $action,
        private readonly DatabaseConnectionInterface $database,
        private readonly ApprovalRouteResolver $routes,
        private readonly CandidatePublisher $publisher,
        private readonly ClockInterface $clock
    ) {
        if (! in_array($action, [ActionTokenPurpose::CONFIRM, ActionTokenPurpose::DENY], true)) {
            throw new LogicException('Only confirmation and denial can use this handler.');
        }
    }

    public function purpose(): ActionTokenPurpose
    {
        return $this->action;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        if (! $this->supports($binding)) {
            return null;
        }

        $rows = $this->candidates($binding);
        if ($rows === []) {
            return null;
        }

        $details = [];
        foreach ($rows as $row) {
            $fields = json_decode((string) ($row['fields'] ?? ''), true, 32);
            if (! is_array($fields)) {
                $details[] = 'Event details unavailable';
                continue;
            }
            $parts = [];
            foreach ([
                'title' => 'Event',
                'event_date' => 'Date',
                'event_time' => 'Time',
                'venue' => 'Venue',
                'description' => 'Description',
            ] as $key => $label) {
                $value = $fields[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $parts[] = $label . ': ' . $this->truncate($value);
                }
            }
            $details[] = $parts === [] ? 'Event details unavailable' : implode(' | ', $parts);
        }

        return new ActionTokenPreview(
            $this->action === ActionTokenPurpose::DENY ? 'Deny event' : 'Confirm event preview',
            $this->action === ActionTokenPurpose::DENY
                ? 'Please check the event below. Denial will reject it.'
                : 'Please check the event below. Confirmation sends it to an approver; an eligible approver may self-approve.',
            $this->action === ActionTokenPurpose::DENY ? 'Deny event' : 'Confirm preview',
            $details
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        throw new LogicException('A confirmation decision requires an atomic token transaction.');
    }

    public function recover(ActionTokenBinding $binding): ActionTokenOutcome
    {
        if ($this->action !== ActionTokenPurpose::CONFIRM || ! $this->supports($binding)) {
            throw new DomainException('There is no self-approval to complete.');
        }
        $rows = $this->candidates($binding);
        $pending = [];
        foreach ($rows as $row) {
            if (($row['approved_via'] ?? null) === 'self'
                && ($row['approved_by'] ?? null) === $binding->email
                && ($row['confirmed_by'] ?? null) === $binding->email
                && ($row['status'] ?? null) === 'awaiting_approval') {
                $pending[] = (int) $row['id'];
            }
        }
        if ($pending === []) {
            throw new DomainException('This confirmation has already been completed.');
        }
        $urls = [];
        foreach ($pending as $id) {
            $urls[] = $this->eventUrl($this->publisher->publish($id));
        }
        return new ActionTokenOutcome('Thank you. Your event has been approved and published.', $urls);
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= 1000) {
            return $value;
        }

        $value = substr($value, 0, 1000);
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }

        return $value . '…';
    }

    public function performAtomic(
        ActionTokenBinding $binding,
        string $token,
        ActionTokenService $tokens,
        string $reason
    ): ActionTokenOutcome {
        if (! $this->supports($binding) || ($this->action !== ActionTokenPurpose::DENY && $reason !== '')) {
            throw new DomainException('The confirmation action is not available.');
        }

        $this->execute('START TRANSACTION');
        $selfApproved = [];
        try {
            $rows = $this->candidates($binding, true);
            if ($rows === []) {
                throw new DomainException('No undecided candidates remain.');
            }
            $drafts = array_values(array_filter(
                $rows,
                static fn (array $row): bool => ($row['status'] ?? null) === 'draft'
            ));
            if ($drafts === [] || ($binding->subjectType === 'event_candidate' && count($drafts) !== 1)) {
                throw new DomainException('An event was already decided.');
            }
            foreach ($drafts as $row) {
                $this->assertEligible($row, $binding);
            }
            $consumption = $tokens->consume($token, $binding);
            if ($consumption->status !== ActionTokenStatus::CONSUMED) {
                throw new DomainException('The confirmation link has already been used or has expired.');
            }

            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $table = $this->table('adct_pi_event_candidates');
            foreach ($drafts as $row) {
                $id = (int) $row['id'];
                $self = $this->action === ActionTokenPurpose::CONFIRM && $this->isActiveApprover($row, $binding);
                $flags = $this->flags($row, $binding);
                if ($this->action === ActionTokenPurpose::DENY) {
                    $query = $this->database->prepare(
                        "UPDATE {$table} SET status = %s, decided_by = %s, decided_at = %s,"
                        . ' decision_note = %s, updated_at = %s WHERE id = %d AND status = %s',
                        'rejected', $binding->email, $now, $reason, $now, $id, 'draft'
                    );
                } else {
                    $notes = json_decode((string) ($row['notes'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR);
                    if (! is_array($notes) || ! array_is_list($notes)) {
                        throw new RuntimeException('Candidate review notes are invalid.');
                    }
                    foreach (['unknown_sender', 'dmarc_fail'] as $flag) {
                        if ($flags[$flag] && ! in_array($flag, $notes, true)) {
                            $notes[] = $flag;
                        }
                    }
                    $query = $this->database->prepare(
                        "UPDATE {$table} SET status = %s, confirmed_by = %s, confirmed_at = %s,"
                        . ' approved_by = ' . ($self ? '%s' : 'NULL')
                        . ', approved_at = ' . ($self ? '%s' : 'NULL')
                        . ', approved_via = ' . ($self ? '%s' : 'NULL')
                        . ', notes = %s, updated_at = %s WHERE id = %d AND status = %s',
                        ...($self
                            ? ['awaiting_approval', $binding->email, $now, $binding->email, $now, 'self', json_encode($notes, JSON_THROW_ON_ERROR), $now, $id, 'draft']
                            : ['awaiting_approval', $binding->email, $now, json_encode($notes, JSON_THROW_ON_ERROR), $now, $id, 'draft'])
                    );
                }
                if ($this->execute($query) !== 1) {
                    throw new RuntimeException('The candidate decision was not saved.');
                }
                $audit = $this->table('adct_pi_audit_log');
                $this->execute($this->database->prepare(
                    "INSERT INTO {$audit} (actor, action, subject_type, subject_id, details, created_at, updated_at)"
                    . ' VALUES (%s, %s, %s, %d, %s, %s, %s)',
                    $binding->email,
                    $this->action === ActionTokenPurpose::DENY ? 'submitter_denied' : 'submitter_confirmed',
                    'event_candidate',
                    $id,
                    json_encode([
                        'approved_via' => $self ? 'self' : null,
                        'unknown_sender' => $flags['unknown_sender'],
                        'dmarc_fail' => $flags['dmarc_fail'],
                        'reason' => $this->action === ActionTokenPurpose::DENY ? $reason : null,
                    ], JSON_THROW_ON_ERROR),
                    $now,
                    $now
                ));
                if ($self) {
                    $selfApproved[] = $id;
                }
            }
            $this->execute('COMMIT');
        } catch (Throwable $failure) {
            try {
                $this->execute('ROLLBACK');
            } catch (Throwable $rollbackFailure) {
                throw new RuntimeException('A confirmation decision failed and rollback failed.', 0, $failure);
            }
            throw $failure;
        }

        $eventUrls = [];
        foreach ($selfApproved as $id) {
            $eventId = $this->publisher->publish($id);
            $eventUrls[] = $this->eventUrl($eventId);
        }

        if ($this->action === ActionTokenPurpose::DENY) {
            return new ActionTokenOutcome('Your decision has been recorded. Thank you.');
        }
        if ($selfApproved !== []) {
            return new ActionTokenOutcome('Thank you. Your event has been approved and published.', $eventUrls);
        }
        return new ActionTokenOutcome('Thank you, your dean or the archdiocese will approve it shortly.');
    }

    private function supports(ActionTokenBinding $binding): bool
    {
        return $binding->purpose === $this->action
            && ($binding->subjectType === 'event_candidate'
                || ($this->action === ActionTokenPurpose::CONFIRM && $binding->subjectType === 'inbound_message'));
    }

    private function eventUrl(int $eventId): string
    {
        $url = get_permalink($eventId);
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('The published event permalink is unavailable.');
        }
        return $url;
    }

    /** @return list<array<string, mixed>> */
    private function candidates(ActionTokenBinding $binding, bool $lock = false): array
    {
        $table = $this->table('adct_pi_event_candidates');
        $column = $binding->subjectType === 'inbound_message' ? 'message_id' : 'id';
        $query = $this->database->prepare(
            "SELECT * FROM {$table} WHERE {$column} = %d ORDER BY id ASC"
            . ($lock ? ' FOR UPDATE' : ''),
            $binding->subjectId
        );
        $this->database->clearLastError();
        $rows = $this->database->getResults($query);
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The confirmation candidates could not be read.');
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function assertEligible(array $row, ActionTokenBinding $binding): void
    {
        $messageId = (int) ($row['message_id'] ?? 0);
        if ($messageId < 1) {
            throw new DomainException('A confirmation needs its original inbound message.');
        }
        $messages = $this->table('adct_pi_inbound_messages');
        $queue = $this->table('adct_pi_mail_queue');
        $message = $this->row($this->database->prepare(
            "SELECT confirmation_status, sender_email, auth_results FROM {$messages} WHERE id = %d",
            $messageId
        ));
        $this->database->clearLastError();
        $mails = $this->database->getResults($this->database->prepare(
            "SELECT status, recipient FROM {$queue} WHERE group_key = %s LIMIT 2",
            'confirmation:' . $messageId
        ));
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The confirmation queue item could not be read.');
        }
        if (
            ! in_array($message['confirmation_status'] ?? null, ['queued', 'sent'], true)
            || count($mails) !== 1
            || strcasecmp((string) ($mails[0]['recipient'] ?? ''), $binding->email) !== 0
            || ! in_array($mails[0]['status'] ?? null, ['queued', 'sending', 'sent'], true)
            || ! in_array($row['match_kind'] ?? null, ['new', 'update', 'cancellation', 'postponement'], true)
        ) {
            throw new DomainException('This event does not have a deliverable confirmation preview.');
        }
        $fields = json_decode((string) ($row['fields'] ?? ''), true, 32);
        if (! is_array($fields)
            || (isset($fields['parish_id'])
                && (int) $fields['parish_id'] !== (int) ($row['parish_id'] ?? 0))) {
            throw new DomainException('The parish in the preview does not match the candidate.');
        }
    }

    /** @param array<string, mixed> $row */
    private function flags(array $row, ActionTokenBinding $binding): array
    {
        $messages = $this->table('adct_pi_inbound_messages');
        $contacts = $this->table('adct_pi_parish_contacts');
        $message = $this->row($this->database->prepare(
            "SELECT sender_email, auth_results FROM {$messages} WHERE id = %d",
            (int) $row['message_id']
        ));
        $sender = strtolower(trim((string) ($message['sender_email'] ?? '')));
        $contact = $this->row($this->database->prepare(
            "SELECT id FROM {$contacts} WHERE email = %s AND parish_id = %d AND trust = %s LIMIT 1",
            $sender, (int) ($row['parish_id'] ?? 0), 'verified'
        ));
        $auth = $message['auth_results'] ?? null;
        return [
            'unknown_sender' => $contact === null,
            'dmarc_fail' => is_string($auth) && $auth !== ''
                && AuthenticationResults::fromJson($auth)->hasReportedDmarcFailure(),
        ];
    }

    /** @param array<string, mixed> $row */
    private function isActiveApprover(array $row, ActionTokenBinding $binding): bool
    {
        if (($row['match_kind'] ?? null) !== 'new') {
            return false;
        }
        $fields = json_decode((string) ($row['fields'] ?? ''), true, 32);
        if (! is_array($fields) || ! empty($fields['match_review_required'])) {
            return false;
        }
        $messages = $this->table('adct_pi_inbound_messages');
        $message = $this->row($this->database->prepare(
            "SELECT sender_email, auth_results FROM {$messages} WHERE id = %d", (int) $row['message_id']
        ));
        if (strcasecmp((string) ($message['sender_email'] ?? ''), $binding->email) !== 0) {
            return false;
        }
        $auth = $message['auth_results'] ?? null;
        if (is_string($auth) && $auth !== ''
            && AuthenticationResults::fromJson($auth)->hasReportedDmarcFailure()) {
            return false;
        }
        $contacts = $this->table('adct_pi_parish_contacts');
        if ($this->row($this->database->prepare(
            "SELECT id FROM {$contacts} WHERE email = %s AND trust = %s LIMIT 1",
            $binding->email, 'blocked'
        )) !== null) {
            return false;
        }
        $user = get_user_by('email', $binding->email);
        if (! $user instanceof \WP_User || (int) $user->user_status !== 0) {
            return false;
        }
        if (user_can($user, Capabilities::REVIEW)) {
            return true;
        }
        $parishId = (int) ($row['parish_id'] ?? 0);
        if ($parishId < 1 || ! user_can($user, Capabilities::APPROVE_DEANERY)) {
            return false;
        }
        foreach ($this->routes->forParish($parishId)->approvers as $approver) {
            if ($approver->wpUserId === (int) $user->ID
                && strcasecmp($approver->email, $binding->email) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed>|null */
    private function row(string $sql): ?array
    {
        $this->database->clearLastError();
        $row = $this->database->getRow($sql);
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The confirmation decision database read failed.');
        }
        return $row;
    }

    private function execute(string $sql): int
    {
        $this->database->clearLastError();
        $result = $this->database->query($sql);
        if ($result === false || $this->database->lastError() !== '') {
            throw new RuntimeException('The confirmation decision database write failed.');
        }
        return $result;
    }

    private function table(string $suffix): string
    {
        $prefix = $this->database->prefix();
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The confirmation database prefix is invalid.');
        }
        return '`' . $prefix . $suffix . '`';
    }
}
