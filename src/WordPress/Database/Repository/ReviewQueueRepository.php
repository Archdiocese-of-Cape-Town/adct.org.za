<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ReviewQueueRepository
{
    public const TABS = [
        'awaiting_approval' => 'Awaiting approval',
        'unknown_senders' => 'Unknown senders',
        'low_confidence' => 'Low confidence',
        'failed' => 'Failed',
        'awaiting_submitter' => 'Awaiting submitter',
        'recently_published' => 'Recently published',
        'recently_decided' => 'Recent decisions',
        'recent_changes' => 'Recent changes',
    ];

    private readonly string $candidates;
    private readonly string $messages;
    private readonly string $parishes;
    private readonly string $deaneries;
    private readonly string $approvers;
    private readonly string $contacts;
    private readonly string $venues;
    private readonly string $audit;

    public function __construct(
        private readonly DatabaseConnectionInterface $database,
        private readonly ClockInterface $clock,
        private readonly ReviewQueuePolicy $policy = new ReviewQueuePolicy(),
        private readonly float $confidenceThreshold = 0.55
    ) {
        if ($confidenceThreshold < 0 || $confidenceThreshold > 1) {
            throw new InvalidArgumentException('The confidence threshold must be between zero and one.');
        }
        $prefix = $database->prefix();
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new InvalidArgumentException('The database prefix is invalid.');
        }
        $this->candidates = "`{$prefix}adct_pi_event_candidates`";
        $this->messages = "`{$prefix}adct_pi_inbound_messages`";
        $this->parishes = "`{$prefix}adct_pi_parishes`";
        $this->deaneries = "`{$prefix}adct_pi_deaneries`";
        $this->approvers = "`{$prefix}adct_pi_deanery_approvers`";
        $this->contacts = "`{$prefix}adct_pi_parish_contacts`";
        $this->venues = "`{$prefix}adct_pi_venues`";
        $this->audit = "`{$prefix}adct_pi_audit_log`";
    }

    /**
     * @return array<string, int>
     */
    public function counts(int $userId, string $email, bool $reviewer, string $search = ''): array
    {
        $counts = array_fill_keys(array_keys(self::TABS), 0);
        $counts['primary_approval'] = 0;
        [$where, $args] = $this->where($userId, $email, $reviewer, $search);
        $category = $this->category();
        $query = "SELECT category, COUNT(*) AS total, SUM(status = 'awaiting_approval') AS awaiting "
            . "FROM (SELECT {$category} AS category, c.status FROM {$this->candidates} c "
            . "LEFT JOIN {$this->messages} m ON m.id = c.message_id "
            . "LEFT JOIN {$this->parishes} p ON p.id = c.parish_id {$where}) items GROUP BY category";
        foreach ($this->rows($this->prepared($query, $args)) as $row) {
            $key = (string) $row['category'];
            if ($key === 'approval') {
                $counts['primary_approval'] = (int) $row['total'];
            } elseif (array_key_exists($key, $counts)) {
                $counts[$key] = (int) $row['total'];
            } else {
                throw new RuntimeException('A review queue category was not recognized.');
            }
            $counts['awaiting_approval'] += (int) $row['awaiting'];
        }
        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function find(
        string $tab,
        int $userId,
        string $email,
        bool $reviewer,
        string $search,
        int $limit,
        int $offset
    ): array {
        $this->assertTab($tab);
        if ($tab === 'recent_changes') {
            return [];
        }
        [$where, $args] = $this->where($userId, $email, $reviewer, $search);
        $category = $this->category();
        $select = "SELECT c.*, m.sender_email, p.name AS parish_name, "
            . "{$category} AS category FROM {$this->candidates} c "
            . "LEFT JOIN {$this->messages} m ON m.id = c.message_id "
            . "LEFT JOIN {$this->parishes} p ON p.id = c.parish_id {$where}";
        $select .= $tab === 'awaiting_approval'
            ? " AND c.status = 'awaiting_approval'"
            : ' AND ' . $category . ' = %s';
        if ($tab !== 'awaiting_approval') {
            $args[] = $tab === 'recently_decided' ? 'recently_decided' : $tab;
        }
        $args[] = max(1, min(50, $limit));
        $args[] = max(0, $offset);
        return $this->rows($this->prepared(
            $select . ' ORDER BY c.updated_at DESC, c.id DESC LIMIT %d OFFSET %d',
            $args
        ));
    }

    /** @return array<string, mixed>|null */
    public function findScoped(int $id, int $userId, string $email, bool $reviewer): ?array
    {
        [$where, $args] = $this->where($userId, $email, $reviewer, '');
        $args[] = $id;
        return $this->row($this->prepared(
            "SELECT c.*, m.sender_email, p.name AS parish_name FROM {$this->candidates} c "
            . "LEFT JOIN {$this->messages} m ON m.id = c.message_id "
            . "LEFT JOIN {$this->parishes} p ON p.id = c.parish_id {$where} AND c.id = %d",
            $args
        ));
    }

    /**
     * @return 'decided'|'already_decided'|'manual_review'|'retry'
     */
    public function decide(int $id, string $action, int $userId, string $email, bool $reviewer, string $reason = ''): string
    {
        if ($id < 1 || ! in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Invalid review decision.');
        }
        $this->execute('START TRANSACTION');
        try {
            $candidate = $this->lockedCandidate($id, $userId, $email, $reviewer);
            if ($candidate === null) {
                throw new DomainException('This candidate is outside your review queue.');
            }
            if (! $this->policy->canDecide($candidate)) {
                $this->execute('COMMIT');
                if ($action === 'approve' && $candidate['status'] === 'duplicate'
                    && $this->policy->requiresMatchResolution($candidate)) {
                    return 'manual_review';
                }
                return $action === 'approve'
                    && $candidate['status'] === 'awaiting_approval'
                    && strcasecmp((string) ($candidate['approved_by'] ?? ''), $email) === 0
                    && ! empty($candidate['decided_at']) ? 'retry' : 'already_decided';
            }
            if ($action === 'approve' && ! $this->policy->canBulkApprove($candidate)) {
                $this->execute('COMMIT');
                return 'manual_review';
            }
            $now = $this->timestamp();
            [$scope, $scopeArgs] = $this->scope($userId, $email, $reviewer);
            $set = $action === 'approve'
                ? 'approved_by = %s, approved_at = %s, approved_via = %s, decided_by = %s, decided_at = %s, updated_at = %s'
                : 'status = %s, decided_by = %s, decided_at = %s, decision_note = %s, updated_at = %s';
            $values = $action === 'approve'
                ? [$email, $now, $reviewer ? 'reviewer' : 'dean', $email, $now, $now]
                : ['rejected', $email, $now, $reason, $now];
            $updated = $this->execute($this->prepared(
                "UPDATE {$this->candidates} c SET {$set} WHERE c.id = %d AND c.status = %s "
                . "AND c.approved_by IS NULL AND c.decided_at IS NULL AND {$scope}",
                [...$values, $id, 'awaiting_approval', ...$scopeArgs]
            ));
            if ($updated !== 1) {
                $this->execute('ROLLBACK');
                return 'already_decided';
            }
            $this->audit($email, $action === 'approve' ? 'approver_approved' : 'approver_rejected', $id, [
                'role' => $reviewer ? 'reviewer' : 'dean',
                'reason' => $action === 'reject' ? $reason : null,
            ], $now);
            $this->execute('COMMIT');
            return 'decided';
        } catch (Throwable $failure) {
            $this->rollback($failure);
            throw $failure;
        }
    }

    public function assignParish(int $id, int $parishId, int $userId, string $email): bool
    {
        if ($id < 1 || $parishId < 1) {
            throw new InvalidArgumentException('Candidate and parish IDs must be positive.');
        }
        $this->execute('START TRANSACTION');
        try {
            $candidate = $this->lockedCandidate($id, $userId, $email, true);
            if ($candidate === null || $candidate['status'] !== 'awaiting_approval'
                || ! empty($candidate['approved_by']) || ! empty($candidate['decided_at'])) {
                throw new DomainException('Only an undecided candidate can be assigned a parish.');
            }
            if ($this->row($this->database->prepare(
                "SELECT id FROM {$this->parishes} WHERE id = %d AND status = %s",
                $parishId, 'active'
            )) === null) {
                throw new DomainException('Select an active parish.');
            }
            $fields = $this->policy->fields($candidate);
            $venueId = (int) ($fields['venue_id'] ?? 0);
            if ($venueId > 0 && $this->row($this->database->prepare(
                "SELECT id FROM {$this->venues} WHERE id = %d AND parish_id = %d",
                $venueId, $parishId
            )) === null) {
                throw new DomainException('The existing venue belongs to another parish; resolve the venue first.');
            }
            $previous = (int) ($candidate['parish_id'] ?? 0);
            if ($previous === $parishId) {
                $this->execute('COMMIT');
                return false;
            }
            $fields['parish_id'] = $parishId;
            $now = $this->timestamp();
            $previousParish = $candidate['parish_id'] === null
                ? 'parish_id IS NULL'
                : 'parish_id = %d';
            $updated = $this->execute($this->database->prepare(
                "UPDATE {$this->candidates} SET parish_id = %d, fields = %s, updated_at = %s "
                . "WHERE id = %d AND status = %s AND {$previousParish} AND approved_by IS NULL AND decided_at IS NULL",
                $parishId, json_encode($fields, JSON_THROW_ON_ERROR), $now,
                ...($candidate['parish_id'] === null
                    ? [$id, $candidate['status']]
                    : [$id, $candidate['status'], (int) $candidate['parish_id']])
            ));
            if ($updated !== 1) {
                $this->execute('ROLLBACK');
                return false;
            }
            $this->audit($email, 'candidate_parish_assigned', $id, [
                'from_parish_id' => $previous ?: null,
                'to_parish_id' => $parishId,
                'sender_trust_changed' => false,
            ], $now);
            $this->execute('COMMIT');
            return true;
        } catch (Throwable $failure) {
            $this->rollback($failure);
            throw $failure;
        }
    }

    /** @return list<array<string, mixed>> */
    public function activeParishes(): array
    {
        return $this->rows($this->database->prepare(
            "SELECT id, name FROM {$this->parishes} WHERE status = %s ORDER BY name ASC",
            'active'
        ));
    }

    /** @return array<string, mixed>|null */
    private function lockedCandidate(int $id, int $userId, string $email, bool $reviewer): ?array
    {
        [$scope, $args] = $this->scope($userId, $email, $reviewer);
        return $this->row($this->prepared(
            "SELECT c.* FROM {$this->candidates} c WHERE c.id = %d AND {$scope} FOR UPDATE",
            [$id, ...$args]
        ));
    }

    /** @return array{string, list<int|string>} */
    private function scope(int $userId, string $email, bool $reviewer): array
    {
        if ($reviewer) {
            return ['1 = 1', []];
        }
        return [
            "EXISTS (SELECT 1 FROM {$this->parishes} scoped_parish "
            . "INNER JOIN {$this->deaneries} d ON d.id = scoped_parish.deanery_id AND d.status = 'active' "
            . "INNER JOIN {$this->approvers} a ON a.deanery_id = d.id AND a.active = 1 "
            . "WHERE scoped_parish.id = c.parish_id AND a.wp_user_id = %d)",
            [$userId],
        ];
    }

    /** @return array{string, list<int|string>} */
    private function where(int $userId, string $email, bool $reviewer, string $search): array
    {
        [$scope, $args] = $this->scope($userId, $email, $reviewer);
        $where = "WHERE {$scope} AND c.status != 'superseded' "
            . "AND (c.status != 'duplicate' OR CASE WHEN JSON_VALID(c.fields) THEN "
            . "JSON_EXTRACT(c.fields, '$.matched_candidate_id') > 0 "
            . "OR JSON_UNQUOTE(JSON_EXTRACT(c.fields, '$.match_review_required')) = 'true' "
            . "ELSE 0 END) "
            . "AND (c.status NOT IN ('published','rejected') OR c.updated_at >= %s)";
        $args[] = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))
            ->modify('-30 days')->format('Y-m-d H:i:s');
        if ($search !== '') {
            $like = '%' . $this->database->escapeLike($search) . '%';
            $where .= " AND (m.sender_email LIKE %s OR p.name LIKE %s OR "
                . "CASE WHEN JSON_VALID(c.fields) THEN JSON_UNQUOTE(JSON_EXTRACT(c.fields, '$.title')) "
                . "ELSE '' END LIKE %s)";
            array_push($args, $like, $like, $like);
        }
        return [$where, $args];
    }

    private function category(): string
    {
        $unknown = "(c.parish_id IS NULL OR m.sender_email IS NULL "
            . "OR CASE WHEN JSON_VALID(c.notes) THEN JSON_CONTAINS(c.notes, '\"unknown_sender\"') ELSE 0 END "
            . "OR NOT EXISTS (SELECT 1 FROM {$this->contacts} contact "
            . "WHERE contact.parish_id = c.parish_id AND contact.email = m.sender_email "
            . "AND contact.trust = 'verified'))";
        $threshold = number_format($this->confidenceThreshold, 3, '.', '');
        return "CASE WHEN c.status = 'published' THEN 'recently_published' "
            . "WHEN c.status = 'rejected' THEN 'recently_decided' "
            . "WHEN c.status = 'awaiting_approval' AND (c.approved_by IS NOT NULL OR c.decided_at IS NOT NULL) THEN 'failed' "
            . "WHEN c.status IN ('failed','expired','approved') THEN 'failed' "
            . "WHEN c.status = 'draft' AND m.confirmation_status IN ('failed','suppressed') THEN 'failed' "
            . "WHEN c.status IN ('draft','awaiting_submitter') THEN 'awaiting_submitter' "
            . "WHEN c.status = 'awaiting_approval' AND {$unknown} THEN 'unknown_senders' "
            . "WHEN c.status = 'awaiting_approval' AND c.confidence < {$threshold} THEN 'low_confidence' "
            . "WHEN c.status = 'awaiting_approval' THEN 'approval' ELSE 'failed' END";
    }

    /** @param array<string, mixed> $details */
    private function audit(string $actor, string $action, int $id, array $details, string $now): void
    {
        $inserted = $this->execute($this->database->prepare(
            "INSERT INTO {$this->audit} (actor, action, subject_type, subject_id, details, created_at, updated_at)"
            . ' VALUES (%s, %s, %s, %d, %s, %s, %s)',
            $actor, $action, 'event_candidate', $id,
            json_encode($details, JSON_THROW_ON_ERROR), $now, $now
        ));
        if ($inserted !== 1) {
            throw new RuntimeException('The review audit record could not be saved.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function assertTab(string $tab): void
    {
        if (! array_key_exists($tab, self::TABS)) {
            throw new InvalidArgumentException('Unknown review queue tab.');
        }
    }

    /** @param list<int|string> $args */
    private function prepared(string $query, array $args): string
    {
        return $args === [] ? $query : $this->database->prepare($query, ...$args);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $query): array
    {
        $this->database->clearLastError();
        $rows = $this->database->getResults($query);
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The review queue could not be read: ' . $this->database->lastError());
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function row(string $query): ?array
    {
        $this->database->clearLastError();
        $row = $this->database->getRow($query);
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The review item could not be read: ' . $this->database->lastError());
        }
        return $row;
    }

    private function execute(string $query): int
    {
        $this->database->clearLastError();
        $result = $this->database->query($query);
        if ($result === false || $this->database->lastError() !== '') {
            throw new RuntimeException('The review decision could not be saved: ' . $this->database->lastError());
        }
        return $result;
    }

    private function rollback(Throwable $failure): void
    {
        try {
            $this->execute('ROLLBACK');
        } catch (Throwable $rollbackFailure) {
            throw new RuntimeException('Review rollback failed.', 0, $failure);
        }
    }
}
