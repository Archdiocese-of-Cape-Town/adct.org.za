<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Matching\MatchReviewPolicy;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendCooldownException;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeImmutable;
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

    /**
     * The note that marks a candidate a person typed in rather than one the
     * parser read.
     *
     * It is deliberately not a parser warning: `unknown_sender` in particular
     * would file the candidate under Unknown senders and read as "this needs a
     * parish lookup", which is the opposite of what happened.
     */
    public const MANUAL_NOTE = 'manual_entry';

    private readonly string $candidates;
    private readonly string $messages;
    private readonly string $parishes;
    private readonly string $deaneries;
    private readonly string $approvers;
    private readonly string $contacts;
    private readonly string $venues;
    private readonly string $audit;
    private readonly string $changes;
    private readonly string $postmeta;

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
        $this->changes = "`{$prefix}adct_pi_event_changes`";
        // WordPress names its post meta table from the same prefix, and the
        // connection interface deliberately exposes nothing site-specific, so
        // it is derived here rather than added as another port method.
        $this->postmeta = "`{$prefix}postmeta`";
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
        [$retryEligibility, $retryArgs] = $this->retryEligibility($userId, $email);
        $select = "SELECT c.*, m.sender_email, p.name AS parish_name, "
            . "{$category} AS category, {$retryEligibility} FROM {$this->candidates} c "
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
            [...$retryArgs, ...$args]
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
     * Changes to already-published events, scoped the way the queue is.
     *
     * The front-end queue lists these so a dean can see what happened to their
     * deaneries' events, and so an out-of-scope change ID posted at the revert
     * form resolves to nothing rather than to somebody else's history.
     *
     * @return list<array<string, mixed>>
     */
    public function recentChanges(int $userId, string $email, bool $reviewer, int $limit = 25): array
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('The reviewer ID must be positive.');
        }
        [$scope, $args] = $this->changeScope($userId, $email, $reviewer);
        $parish = self::changeParish();

        return $this->rows($this->prepared(
            'SELECT ch.id, ch.event_id, ch.candidate_id, ch.actor, ch.kind, '
            . 'ch.notified_at, ch.reverted_by, ch.reverted_at, ch.created_at, '
            . "{$parish} AS parish_id, p.name AS parish_name "
            . "FROM {$this->changes} ch "
            . "LEFT JOIN {$this->candidates} c ON c.id = ch.candidate_id "
            . "LEFT JOIN {$this->postmeta} meta ON meta.post_id = ch.event_id "
            . " AND meta.meta_key = 'parish_id' "
            . "LEFT JOIN {$this->parishes} p ON p.id = {$parish} "
            . "WHERE {$parish} IS NOT NULL AND ({$scope}) "
            . 'ORDER BY ch.created_at DESC, ch.id DESC LIMIT %d',
            [...$args, max(1, min(100, $limit))]
        ));
    }

    /**
     * One change, or null when it is not this reviewer's to see or revert.
     *
     * The read-only counterpart to {@see recentChanges()}: the front end calls
     * this before asking for a revert link, so a change ID posted from another
     * deanery resolves to null rather than to somebody else's history.
     *
     * @return array<string, mixed>|null
     */
    public function findScopedChange(int $changeId, int $userId, string $email, bool $reviewer): ?array
    {
        if ($changeId < 1) {
            throw new InvalidArgumentException('The change ID must be positive.');
        }
        [$scope, $args] = $this->changeScope($userId, $email, $reviewer);
        $parish = self::changeParish();

        return $this->row($this->prepared(
            'SELECT ch.id, ch.event_id, ch.candidate_id, ch.actor, ch.kind, ch.notified_at, '
            . 'ch.reverted_by, ch.reverted_at, ch.created_at, '
            . "{$parish} AS parish_id, p.name AS parish_name "
            . "FROM {$this->changes} ch "
            . "LEFT JOIN {$this->candidates} c ON c.id = ch.candidate_id "
            . "LEFT JOIN {$this->postmeta} meta ON meta.post_id = ch.event_id "
            . " AND meta.meta_key = 'parish_id' "
            . "LEFT JOIN {$this->parishes} p ON p.id = {$parish} "
            . "WHERE ch.id = %d AND {$parish} IS NOT NULL AND ({$scope})",
            [$changeId, ...$args]
        ));
    }

    /**
     * The parish a recorded change belongs to.
     *
     * The change row itself has no parish column, so it inherits the candidate
     * that produced it and falls back to the event's `parish_id` post meta,
     * exactly as RevertChangeHandler reads it. Named once here so the list and
     * the single-row lookup cannot disagree about who a change belongs to.
     */
    private static function changeParish(): string
    {
        return "COALESCE(c.parish_id, NULLIF(TRIM(meta.meta_value), ''))";
    }

    /**
     * The same scoping rule as {@see findScoped()}, stated explicitly for a user
     * who holds the archdiocese-wide review capability.
     *
     * `findScoped()` takes the caller's `bool` at face value, so naming the case
     * here keeps a reviewer's authority from depending on how the flag was set.
     */
    public function findScopedForReviewer(int $id, int $userId, string $email): ?array
    {
        return $this->findScoped($id, $userId, $email, true);
    }

    /**
     * The message a candidate was extracted from.
     *
     * Only for confirming that a stored attachment belongs to the candidate a
     * reviewer has already opened; the detail screen and the save handler both
     * authorise through `findScoped()` first.
     */
    public function findMessageOf(int $id): ?int
    {
        if ($id < 1) {
            throw new InvalidArgumentException('The candidate ID must be positive.');
        }
        $row = $this->row($this->database->prepare(
            "SELECT message_id FROM {$this->candidates} WHERE id = %d",
            $id
        ));

        return $row === null || ! isset($row['message_id']) ? null : (int) $row['message_id'];
    }

    /**
     * The published event this candidate was published as, or null (issue #172).
     *
     * The review queue's promote control names a *candidate*, because that is what
     * the screen is about, so the target event has to be derived rather than posted.
     * The link is the `source_candidate_id` post meta the publish step already
     * writes, so no new column and no new join between the two tables is needed.
     *
     * Three things are deliberate:
     *
     * - **Only a published event.** A draft or trashed event whose meta still names
     *   this candidate is not a legitimate target: the file would be copied into a
     *   public uploads directory and stay fetchable even though no page shows it.
     * - **Scoped by the post type.** `adct_event` only, so a stray page carrying the
     *   same meta cannot be named.
     * - **The candidate id is bound**, and compared with `CAST(c.id AS CHAR)` the way
     *   `EventCandidateRepository` already does, because post meta is a string column.
     *
     * The `post_status` check is repeated on the row in PHP as well as in the SQL.
     * That is not belt-and-braces for its own sake: it means a future edit to the
     * WHERE clause cannot silently widen what this method returns, and the media
     * library copy is expensive enough to be worth a second cheap guard.
     */
    public function findPublishedEventForCandidate(int $candidateId): ?int
    {
        if ($candidateId < 1) {
            throw new InvalidArgumentException('The candidate ID must be positive.');
        }

        $row = $this->row($this->prepared(
            "SELECT p.ID, p.post_status FROM {$this->postmeta} meta "
            . 'INNER JOIN {$this->postsTable()} p ON p.ID = meta.post_id '
            . "WHERE meta.meta_key = 'source_candidate_id' AND meta.meta_value = CAST(%d AS CHAR) "
            . "AND p.post_type = 'adct_event' AND p.post_status = 'publish' "
            . 'ORDER BY p.ID DESC',
            [$candidateId]
        ));

        if ($row === null || (string) ($row['post_status'] ?? '') !== 'publish') {
            return null;
        }

        $eventId = (int) ($row['ID'] ?? 0);

        return $eventId > 0 ? $eventId : null;
    }

    /**
     * The `wp_posts` table, quoted.
     *
     * Named once here because the promote lookup is the only statement in this
     * repository that reaches for it, and a table name is not something to
     * interpolate from a call site.
     */
    private function postsTable(): string
    {
        return '`' . $this->database->prefix() . 'posts`';
    }

    /**
     * The audit entries recorded against one candidate, newest first.
     *
     * The detail screen shows these so a reviewer can see who has already
     * touched a candidate and what they changed.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $id, int $limit = 25): array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('The candidate ID must be positive.');
        }

        return $this->rows($this->database->prepare(
            "SELECT actor, action, details, created_at FROM {$this->audit} "
            . "WHERE subject_type = %s AND subject_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
            'event_candidate',
            $id,
            max(1, min(100, $limit))
        ));
    }

    /**
     * An empty candidate for an event a person is typing in by hand.
     *
     * A scanned poster usually yields nothing the parser can use, so the
     * reviewer starts a blank candidate beside it and fills in the ordinary
     * edit form. The new row deliberately carries nothing that would let it
     * reach the publisher: it is `awaiting_approval` with no approver recorded,
     * so the one approval route — `decide()` followed by
     * `CandidatePublisher::publish()` — stays the only way an event is
     * published, however it was entered.
     *
     * The message and parish are taken from the candidate the reviewer was
     * already looking at, inside the lock, and never from the request. That way
     * the new event routes to the same dean or reviewer a parsed one would, and
     * a row that has moved since the page was rendered cannot be used as a
     * starting point for someone else's event.
     *
     * @param string $actor  the person creating it, for the audit trail
     * @param bool   $reviewer whether they hold the archdiocese-wide capability
     * @param int|null $attachmentId the poster this event is being typed from,
     *        recorded for the audit trail only. The caller verifies it belongs to
     *        the same message; nothing here trusts it.
     */
    public function createManualCandidate(
        int $sourceCandidateId,
        string $actor,
        bool $reviewer,
        ?int $attachmentId = null
    ): int {
        if ($sourceCandidateId < 1) {
            throw new InvalidArgumentException('A manual entry needs a candidate to start from.');
        }

        $this->execute('START TRANSACTION');
        try {
            $source = $this->row($this->database->prepare(
                "SELECT id, message_id, parish_id FROM {$this->candidates} WHERE id = %d FOR UPDATE",
                $sourceCandidateId
            ));
            if ($source === null) {
                throw new RuntimeException('The candidate this event is being typed from is no longer available.');
            }
            $messageId = (int) ($source['message_id'] ?? 0);
            $parishId = (int) ($source['parish_id'] ?? 0);
            if ($messageId < 1) {
                throw new RuntimeException('The candidate this event is being typed from has no stored email.');
            }
            $blockIndex = $this->nextBlockIndex($messageId);
            $now = $this->timestamp();

            $inserted = $this->execute($this->database->prepare(
                "INSERT INTO {$this->candidates}"
                . ' (`message_id`, `block_index`, `parish_id`, `fields`, `confidence`,'
                . ' `parser_version`, `notes`, `match_kind`, `status`, `created_at`, `updated_at`)'
                . ' VALUES (%d, %d, %d, %s, %f, %s, %s, %s, %s, %s, %s)',
                $messageId,
                $blockIndex,
                $parishId,
                // '{}' rather than '[]' or '': the review queue decodes `fields`
                // and insists on an object, so a blank candidate has to be one.
                '{}',
                0.0,
                '',
                json_encode([self::MANUAL_NOTE], JSON_THROW_ON_ERROR),
                'new',
                'awaiting_approval',
                $now,
                $now
            ));
            $id = $inserted === 1 ? $this->database->insertId() : 0;
            if ($id < 1) {
                throw new RuntimeException('The manual event could not be saved: ' . $this->database->lastError());
            }

            $this->audit(
                $actor,
                'candidate_created_by_hand',
                $id,
                [
                    'role' => $reviewer ? 'reviewer' : 'dean',
                    'source_candidate_id' => $sourceCandidateId,
                ] + ($attachmentId === null ? [] : ['attachment_id' => $attachmentId]),
                $now
            );
            $this->execute('COMMIT');

            return $id;
        } catch (Throwable $failure) {
            $this->rollback($failure);
            throw $failure;
        }
    }

    /**
     * The next free block on a message.
     *
     * `block_index` is unique per message, so a second event typed from the same
     * email has to land after the blocks already there. The read is locked for
     * the same reason the parser locks it: two people working the same notice
     * must not both decide that block five is free.
     */
    private function nextBlockIndex(int $messageId): int
    {
        $rows = $this->rows($this->database->prepare(
            "SELECT block_index FROM {$this->candidates} WHERE message_id = %d"
            . ' ORDER BY block_index ASC FOR UPDATE',
            $messageId
        ));
        $highest = -1;
        foreach ($rows as $row) {
            $index = filter_var(
                $row['block_index'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]
            );
            if (! is_int($index)) {
                throw new RuntimeException('An existing event candidate has an invalid block index.');
            }
            $highest = max($highest, $index);
        }

        return $highest + 1;
    }

    /**
     * How many items are still awaiting approval past the reminder period.
     *
     * The health screen shows this so an administrator can tell at a glance
     * whether reminders are reaching approvers or whether a queue has gone
     * stale. The caller supplies the cutoff rather than the repository, so the
     * period stays owned by ApprovalReminderSettings and the injected clock.
     */
    public function countOverdueApprovals(string $cutoff): int
    {
        $row = $this->row($this->database->prepare(
            "SELECT COUNT(*) AS total FROM {$this->candidates}"
            . " WHERE status = %s AND approved_by IS NULL AND decided_at IS NULL AND updated_at < %s",
            'awaiting_approval',
            $cutoff
        ));

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param bool $acknowledgedUnparsedDate #167. The approver has seen that the notice's date
     *        could not be read and is approving anyway. Carried here rather than re-read from the
     *        request because this re-reads the stored row inside the transaction, and a date the
     *        approver just corrected is only in the write that ran a moment ago -- the note itself
     *        outlives that write.
     * @return 'decided'|'already_decided'|'manual_review'|'retry'
     */
    public function decide(
        int $id,
        string $action,
        int $userId,
        string $email,
        bool $reviewer,
        string $reason = '',
        bool $acknowledgedUnparsedDate = false
    ): string {
        if ($id < 1 || ! in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Invalid review decision.');
        }
        $this->execute('START TRANSACTION');
        try {
            $candidate = $this->lockedCandidate($id, $userId, $email, $reviewer);
            if ($candidate === null) {
                throw new DomainException('This candidate is outside your review queue.');
            }
            if ($action === 'approve'
                && in_array($candidate['status'], ['awaiting_approval', 'duplicate'], true)
                && $this->policy->requiresMatchResolution($candidate)) {
                $this->execute('COMMIT');
                return 'manual_review';
            }
            if (! $this->policy->canDecide($candidate)) {
                $this->execute('COMMIT');
                return $action === 'approve' && ! empty($candidate['can_retry'])
                    ? 'retry' : 'already_decided';
            }
            if ($action === 'approve' && ! $this->policy->canBulkApprove($candidate, $acknowledgedUnparsedDate)) {
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

    /**
     * Assign, clear or leave a candidate's parish alone, and — for an ambiguous
     * match — clear the two `fields` keys that block approval (issue #177).
     *
     * One method for both routes, deliberately. The queue's bulk assignment and
     * the candidate detail screen's resolution panel are the same act, and a
     * second implementation would be a second thing to keep in step with the
     * scope predicate, the lock and the audit row.
     *
     * The defaults are the bulk form's behaviour exactly: a reviewer, no venue
     * change and no ambiguity keys in play. So `handleBulk()` and the
     * `candidate_parish_assigned` row it has always written are untouched.
     *
     * @param int|null $venueId the venue this candidate should point at, or null
     *        to keep the stored one. Only meaningful on the resolution route,
     *        where the venue is often the reason the match was ambiguous.
     * @param bool $resolveMatch clear `match_review_required` and
     *        `matched_candidate_id` in the same transaction as the parish
     *        change. The keys are *removed*, not set to false:
     *        {@see \ADCT\ParishIntake\Core\Matching\MatchReviewPolicy::requiresManualReview()}
     *        decides on key presence, so a falsified key would still ask for
     *        manual review forever.
     *
     *        It also widens the status this route accepts from `awaiting_approval`
     *        alone to `awaiting_approval` or `duplicate` (issue #218), and writes
     *        `awaiting_approval` when the row was a duplicate. One flag, because
     *        the status is only ever widened where a person is naming the match:
     *        the bulk form, which passes `false`, still refuses a duplicate.
     */
    public function assignParish(
        int $id,
        int $parishId,
        int $userId,
        string $email,
        bool $reviewer = true,
        ?int $venueId = null,
        bool $resolveMatch = false
    ): bool {
        if ($id < 1) {
            throw new InvalidArgumentException('The candidate ID must be positive.');
        }
        if ($parishId < 1 && ! $resolveMatch) {
            // Only the resolution route may deliberately clear a parish. The
            // bulk form posts `parish_id = 0` when no parish was chosen, and it
            // is refused before it ever reaches here.
            throw new InvalidArgumentException('Select an active parish.');
        }
        $this->execute('START TRANSACTION');
        try {
            $candidate = $this->lockedCandidate($id, $userId, $email, $reviewer);
            // Issue #218. The resolution route accepts a `duplicate` as well as an
            // `awaiting_approval`, because those are the only two statuses
            // {@see \ADCT\ParishIntake\Core\Review\ReviewQueuePolicy::canDecide()}
            // lets a reviewer act on (#217), and #177 gave each of them a panel
            // that then refused the write. Resolving one writes it back to
            // `awaiting_approval`: a duplicate a person has just shown is
            // unresolvable has stopped being a duplicate, and a row that kept
            // the status would drop out of {@see where()} — that listing keys off
            // the `fields` keys the resolution removes — and never be seen again.
            //
            // The bulk form is a different act and keeps its narrower rule: it has
            // no panel, so it cannot say which match was wrong, and letting it
            // clear a duplicate's block would strand the row.
            $resolvable = $resolveMatch ? ['awaiting_approval', 'duplicate'] : ['awaiting_approval'];
            if ($candidate === null || ! in_array($candidate['status'], $resolvable, true)
                || ! empty($candidate['approved_by']) || ! empty($candidate['decided_at'])) {
                throw new DomainException('Only an undecided candidate can be assigned a parish.');
            }
            if ($parishId > 0 && $this->row($this->database->prepare(
                "SELECT id FROM {$this->parishes} WHERE id = %d AND status = %s",
                $parishId, 'active'
            )) === null) {
                throw new DomainException('Select an active parish.');
            }
            $fields = $this->policy->fields($candidate);
            $storedVenueId = (int) ($fields['venue_id'] ?? 0);
            if ($venueId !== null) {
                $storedVenueId = $venueId;
            }
            if ($storedVenueId > 0 && $parishId > 0 && $this->row($this->database->prepare(
                "SELECT id FROM {$this->venues} WHERE id = %d AND parish_id = %d",
                $storedVenueId, $parishId
            )) === null) {
                // On the resolution route the venue is the thing being fixed, so
                // say so plainly instead of telling them to do the very thing they
                // are already doing.
                throw new DomainException(
                    $resolveMatch
                        ? 'That venue belongs to another parish. Choose a venue of the parish you selected, '
                          . 'or leave the venue blank.'
                        : 'The existing venue belongs to another parish; resolve the venue first.'
                );
            }
            $previous = (int) ($candidate['parish_id'] ?? 0);
            $previousReview = MatchReviewPolicy::requiresManualReview($fields);
            $unchanged = ($previous === $parishId)
                && ($venueId === null || $venueId === (int) ($fields['venue_id'] ?? 0))
                // Resolving something that is not ambiguous has nothing to do, and
                // writing an audit row claiming a block was cleared that never
                // existed would be worse than doing nothing.
                && (! $resolveMatch || ! $previousReview);
            if ($unchanged) {
                $this->execute('COMMIT');
                return false;
            }
            if ($parishId > 0) {
                $fields['parish_id'] = $parishId;
            } else {
                unset($fields['parish_id']);
            }
            if ($venueId !== null) {
                if ($venueId > 0) {
                    $fields['venue_id'] = $venueId;
                } else {
                    unset($fields['venue_id']);
                }
            }
            if ($resolveMatch) {
                unset($fields['match_review_required'], $fields['matched_candidate_id']);
            }
            $now = $this->timestamp();
            // The status the row was locked under goes into the WHERE clause, as
            // the parish does: two reviewers opening the same candidate and
            // resolving it differently must not both succeed. It is a parameter,
            // not a literal, so the statement still names the status it was read
            // under and cannot write a row its own guard would never match.
            $lockedStatus = (string) $candidate['status'];
            $previousGuard = $candidate['parish_id'] === null ? 'parish_id IS NULL' : 'parish_id = %d';
            // A cleared parish is written as a real SQL NULL, not 0: the column
            // is `bigint unsigned`, so 0 would name a parish that does not
            // exist. Same reason as {@see updateFields()}.
            //
            // `status` is written only when it can actually change. A resolution of
            // an ordinary ambiguous candidate leaves `awaiting_approval` alone, so
            // the column is not touched and `updated_at` still means what it says.
            $sets = "SET parish_id = NULLIF(%s, ''), fields = %s";
            $setValues = [
                $parishId > 0 ? (string) $parishId : '',
                json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ];
            if ($resolveMatch && $lockedStatus !== 'awaiting_approval') {
                $sets .= ', status = %s';
                $setValues[] = 'awaiting_approval';
            }
            $setValues[] = $now;
            $setValues[] = $id;
            $setValues[] = $lockedStatus;
            $setValues = [...$setValues, ...($candidate['parish_id'] === null ? [] : [(int) $candidate['parish_id']])];
            $updated = $this->execute($this->database->prepare(
                "UPDATE {$this->candidates} {$sets}, updated_at = %s "
                . 'WHERE id = %d AND status = %s AND approved_by IS NULL AND decided_at IS NULL'
                . " AND {$previousGuard}",
                ...$setValues
            ));
            if ($updated !== 1) {
                $this->execute('ROLLBACK');
                return false;
            }
            $details = [
                'role' => $reviewer ? 'reviewer' : 'dean',
                'from_parish_id' => $previous ?: null,
                'to_parish_id' => $parishId > 0 ? $parishId : null,
                'venue_id' => $venueId,
                'left_unassigned' => $parishId < 1,
                'sender_trust_changed' => false,
                'match_review_cleared' => $resolveMatch ? $previousReview : null,
            ];
            if ($resolveMatch && $lockedStatus !== 'awaiting_approval') {
                // A reader of the trail has to be able to tell a duplicate coming
                // back into the approval queue from an ordinary unblock: the
                // second is invisible in the history, the first is the reason the
                // row is there at all. The plain assignment moves no status, so it
                // records none.
                $details['from_status'] = $lockedStatus;
                $details['to_status'] = 'awaiting_approval';
            }
            $this->audit($email, $resolveMatch ? 'candidate_match_resolved' : 'candidate_parish_assigned', $id, $details, $now);
            $this->execute('COMMIT');
            return true;
        } catch (Throwable $failure) {
            $this->rollback($failure);
            throw $failure;
        }
    }

    /**
     * When this candidate's confirmation preview was last resent, or null if never.
     *
     * Read from the audit trail rather than from a dedicated column, so there is
     * one record of the resend instead of two that could disagree. It is the same
     * query {@see history()} runs, narrowed to the one action, because the detail
     * screen asks this on every render and a cooldown that queried the whole trail
     * would grow without bound.
     */
    public function lastConfirmationResentAt(int $id): ?DateTimeImmutable
    {
        if ($id < 1) {
            throw new InvalidArgumentException('The candidate ID must be positive.');
        }

        $row = $this->row($this->database->prepare(
            "SELECT created_at FROM {$this->audit} WHERE subject_type = %s AND subject_id = %d AND action = %s "
            . 'ORDER BY created_at DESC, id DESC LIMIT 1',
            'event_candidate',
            $id,
            AuditAction::CANDIDATE_CONFIRMATION_RESENT->value
        ));

        if ($row === null || ! is_string($row['created_at'] ?? null)) {
            return null;
        }

        // `created_at` is stored in UTC by {@see timestamp()}; the plugin works in
        // Africa/Johannesburg, and the cooldown has to be compared in absolute
        // terms, so it is returned as an instant rather than a local wall time.
        $resentAt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $row['created_at'],
            new DateTimeZone('UTC')
        );

        return $resentAt === false ? null : $resentAt;
    }

    /**
     * Claim the right to resend this candidate's confirmation preview, or refuse.
     *
     * The cooldown is enforced here rather than in the admin page, and it is the
     * same transaction that writes the audit row. Two consequences, both the
     * point of the exercise:
     *
     * - A hand-crafted POST cannot bypass it. The page's own check is only
     *   presentation; this one runs on every accepted request, inside a lock.
     * - Two presses arriving at once cannot both win. The candidate row is locked
     *   first, so the second reader sees the first one's audit row and is refused.
     *
     * The audit row is written even if rendering or queuing afterwards fails. A
     * reviewer pressing "Resend" is an outbound message to a parish the moment they
     * ask for it, and a failed send is exactly the case somebody will later want to
     * explain. Rolling the row back to keep the trail tidy would erase the only
     * evidence that the request was made.
     *
     * @param string $actor the reviewer's email, for the audit trail
     * @return int the id of the inbound message to render from
     * @throws \DomainException when the candidate is outside the reviewer's scope,
     *         or has no saved fields to render
     * @throws ConfirmationEmailResendCooldownException when the previous resend was
     *         inside $cooldownSeconds
     */
    public function bookConfirmationResend(
        int $id,
        int $userId,
        string $email,
        bool $reviewer,
        DateTimeImmutable $requestedAt,
        int $cooldownSeconds
    ): int {
        if ($id < 1) {
            throw new InvalidArgumentException('The candidate ID must be positive.');
        }
        if ($cooldownSeconds < 0) {
            throw new InvalidArgumentException('The resend cooldown cannot be negative.');
        }

        $this->execute('START TRANSACTION');
        try {
            $candidate = $this->lockedCandidate($id, $userId, $email, $reviewer);

            if ($candidate === null) {
                throw new DomainException('That event is no longer available to you.');
            }

            $lastResentAt = $this->lastConfirmationResentAtLocked($id);

            if ($lastResentAt !== null) {
                $retryAfter = $lastResentAt->modify('+' . $cooldownSeconds . ' seconds');

                if ($requestedAt < $retryAfter) {
                    throw new ConfirmationEmailResendCooldownException($lastResentAt, $retryAfter, $id);
                }
            }

            $messageId = (int) ($candidate['message_id'] ?? 0);

            if ($messageId < 1) {
                throw new DomainException('This event has no message to resend a confirmation for.');
            }

            $this->audit(
                $email,
                AuditAction::CANDIDATE_CONFIRMATION_RESENT->value,
                $id,
                [
                                    'role' => $reviewer ? 'reviewer' : 'dean',
                                    'message_id' => $messageId,
                                    'cooldown_seconds' => $cooldownSeconds,
                                    'next_allowed_at' => $requestedAt
                                        ->modify('+' . $cooldownSeconds . ' seconds')
                                        ->setTimezone(new DateTimeZone('UTC'))
                                        ->format('Y-m-d H:i:s'),
                                ],
                $requestedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            );
            $this->execute('COMMIT');

            return $messageId;
        } catch (Throwable $failure) {
            $this->rollback($failure);
            throw $failure;
        }
    }

    /**
     * The last resend, read inside the booking transaction.
     *
     * Separate from {@see lastConfirmationResentAt()} because that one clears the
     * last error and throws a read failure, which inside the transaction would turn
     * a routine empty result into a rollback. Here a lookup failure must leave the
     * claim unproven and the transaction closed.
     */
    private function lastConfirmationResentAtLocked(int $id): ?DateTimeImmutable
    {
        $this->database->clearLastError();
        $row = $this->database->getRow($this->database->prepare(
            "SELECT created_at FROM {$this->audit} WHERE subject_type = %s AND subject_id = %d AND action = %s "
            . 'ORDER BY created_at DESC, id DESC LIMIT 1',
            'event_candidate',
            $id,
            AuditAction::CANDIDATE_CONFIRMATION_RESENT->value
        ));

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The confirmation resend history could not be read.');
        }

        if ($row === null || ! is_string($row['created_at'] ?? null)) {
            return null;
        }

        $resentAt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $row['created_at'],
            new DateTimeZone('UTC')
        );

        return $resentAt === false ? null : $resentAt;
    }

    /**
         * Writes a reviewer's corrected `fields` and `recurrence` back to a
         * candidate.
         *
         * Only an undecided candidate inside the caller's review scope can be
         * edited, and the row is locked for the duration so a decision made in
         * another tab cannot be overwritten by an edit that started earlier. The
         * parish column is realigned with the JSON, because {@see assignParish()}
                  * keeps the two in step; see {@see self::parishIdFor()} for why an edit that
                  * carries no `parish_id` preserves the stored one.
         *
         * Returns `saved` when the candidate was rewritten, `unchanged` when the
         * reviewer's form matched what was already stored, and `not_editable` when
         * the candidate moved out of reach before the write landed.
         *
         * @param array<string, mixed> $fields
         * @param array<string, mixed> $recurrence
         * @param list<string>         $changedFields keys the reviewer actually altered
         * @return 'saved'|'unchanged'|'not_editable'
         */
        public function updateFields(
            int $id,
            array $fields,
            array $recurrence,
            array $changedFields,
            int $userId,
            string $email,
            bool $reviewer
        ): string {
            if ($id < 1) {
                throw new InvalidArgumentException('The candidate ID must be positive.');
            }
            $this->execute('START TRANSACTION');
            try {
                $candidate = $this->lockedCandidate($id, $userId, $email, $reviewer);
                if ($candidate === null) {
                    throw new DomainException('This candidate is outside your review queue.');
                }
                if (! $this->isEditable($candidate)) {
                    $this->execute('COMMIT');

                    return 'not_editable';
                }
                if ($changedFields === []) {
                    $this->execute('COMMIT');

                    return 'unchanged';
                }
                $now = $this->timestamp();
                                $parishId = $this->parishIdFor($fields, $candidate);
                                $updated = $this->execute($this->database->prepare(
                                    "UPDATE {$this->candidates} SET fields = %s, recurrence = %s, parish_id = NULLIF(%s, ''), updated_at = %s "
                                    . 'WHERE id = %d AND status = %s AND approved_by IS NULL AND decided_at IS NULL',
                                    json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                                    $this->encodeRecurrence($recurrence),
                                    $parishId === null ? '' : (string) $parishId,
                                    $now,
                                    $id,
                                    'awaiting_approval'
                                ));
                if ($updated !== 1) {
                    $this->execute('ROLLBACK');

                    return 'not_editable';
                }
                $this->audit($email, 'candidate_edited', $id, [
                    'role' => $reviewer ? 'reviewer' : 'dean',
                    'changed_fields' => array_values($changedFields),
                ], $now);
                $this->execute('COMMIT');

                return 'saved';
            } catch (Throwable $failure) {
                $this->rollback($failure);
                throw $failure;
            }
        }

        /**
                 * The parish an edit leaves on the candidate.
                 *
                 * `fields['parish_id']` and the `parish_id` column are two statements of one
                 * fact, and {@see assignParish()} is the only supported way to change the
                  * fact. The wp-admin candidate editor (#61) does render a `parish_id`
                  * control, but its posted key goes through `updateFields()` only; the
                  * front-end approval editor (#72) still posts none, so treating "absent"
                  * as "clear" would write the column as SQL NULL on every ordinary text
                  * correction there. A candidate with no parish matches no deanery scope
                  * predicate, so the editor who fixed the title lost sight of the item and
                  * so did every other approver — the row became unapprovable while still
                  * sitting in `awaiting_approval`.
                 *
                 * So the key is honoured only when it is actually present. A zero or
                 * negative value is not a parish and would write the same broken NULL, so it
                 * is ignored too; clearing the parish is `assignParish()`'s decision, audited
                 * as its own action.
                 *
                 * A genuinely parish-less candidate is written as `NULLIF(%s, '')` rather
                 * than a plain `%s`. `parish_id` is `bigint unsigned`, and passing PHP's
                 * `null` to `%s` stores the empty string, which MySQL coerces to `0` — a
                 * parish that does not exist, the very failure this method exists to prevent.
                 * Writing a real SQL NULL keeps "no parish" meaning "no parish" on both
                 * MySQL 8 and MariaDB 10.11, in strict mode and without it.
                 *
                 * @param array<string, mixed> $fields
                 * @param array<string, mixed> $candidate
                 */
            private function parishIdFor(array $fields, array $candidate): ?int
            {
                if (isset($fields['parish_id']) && (int) $fields['parish_id'] > 0) {
                    return (int) $fields['parish_id'];
                }

                $stored = $candidate['parish_id'] ?? null;

                return $stored === null ? null : (int) $stored;
            }

            /**
                 * The recurrence JSON, or SQL NULL when the reviewer cleared the rule.
         *
         * A NULL is kept distinct from `[]` because the parser writes NULL for a
         * single event and {@see CandidatePublisher} treats the two differently.
         *
         * @param array<string, mixed> $recurrence
         */
        private function encodeRecurrence(array $recurrence): ?string
        {
            return $recurrence === []
                ? null
                : json_encode($recurrence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        /**
         * A candidate may be edited only while it is still awaiting a decision.
         *
         * @param array<string, mixed> $candidate
         */
        private function isEditable(array $candidate): bool
        {
            return $candidate['status'] === 'awaiting_approval'
                && empty($candidate['approved_by'])
                && empty($candidate['decided_at']);
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
        [$retryEligibility, $retryArgs] = $this->retryEligibility($userId, $email);
        return $this->row($this->prepared(
            "SELECT c.*, {$retryEligibility} FROM {$this->candidates} c "
            . "WHERE c.id = %d AND {$scope} FOR UPDATE",
            [...$retryArgs, $id, ...$args]
        ));
    }

    /** @return array{string, list<int|string>} */
    private function retryEligibility(int $userId, string $email): array
    {
        return [
            "CASE WHEN c.status = 'awaiting_approval' AND c.approved_by IS NOT NULL "
            . "AND TRIM(c.approved_by) <> '' AND c.decided_at IS NOT NULL "
            . "AND (LOWER(c.approved_by) = LOWER(%s) "
            . "OR (c.approved_via IN ('dean', 'self') AND EXISTS ("
            . "SELECT 1 FROM {$this->parishes} retry_parish "
            . "INNER JOIN {$this->deaneries} retry_deanery "
            . "ON retry_deanery.id = retry_parish.deanery_id AND retry_deanery.status = 'active' "
            . "INNER JOIN {$this->approvers} retry_approver "
            . "ON retry_approver.deanery_id = retry_deanery.id AND retry_approver.active = 1 "
            . "AND retry_approver.wp_user_id = %d "
            . "WHERE retry_parish.id = c.parish_id "
            . "AND LOWER(retry_approver.email) = LOWER(c.approved_by)))) THEN 1 ELSE 0 END AS can_retry",
            [$email, $userId],
        ];
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

    /**
     * The scoping rule for recorded changes, in its own right.
     *
     * Kept apart from {@see where()} on purpose: `where()` filters on
     * `c.parish_id`, which is the candidate's parish, whereas a change belongs
     * to {@see changeParish()} — the candidate's parish or, failing that, the
     * event's `parish_id` post meta. An event edited after publication carries
     * no candidate link, so a dean must still see the changes to their own
     * events. Collapsing the two rules would hide those from every dean.
     *
     * @return array{string, list<int|string>}
     */
    private function changeScope(int $userId, string $email, bool $reviewer): array
    {
        if ($reviewer) {
            return ['1 = 1', []];
        }
        $parish = self::changeParish();
        return [
            "EXISTS (SELECT 1 FROM {$this->parishes} scoped_parish "
            . "INNER JOIN {$this->deaneries} d ON d.id = scoped_parish.deanery_id AND d.status = 'active' "
            . "INNER JOIN {$this->approvers} a ON a.deanery_id = d.id AND a.active = 1 "
            . "WHERE scoped_parish.id = {$parish} AND a.wp_user_id = %d)",
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
