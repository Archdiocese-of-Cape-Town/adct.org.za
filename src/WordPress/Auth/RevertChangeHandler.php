<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Events\OccurrenceWindow;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\AtomicActionTokenHandlerInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\OccurrenceMaintenanceInterface;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Events\EventListingGeneration;
use ADCT\ParishIntake\WordPress\Events\EventOccurrenceHooks;
use ADCT\ParishIntake\WordPress\Events\EventPostType;
use DateTimeZone;
use DomainException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Restores an event to the snapshot a published change recorded before it amended it.
 *
 * The revert link in a change notice is addressed to whoever held authority over
 * the parish when the change was published. That is a fact about the past, and
 * the recipient can stop being entitled to it before the link is followed: a dean
 * moved between deaneries, a reviewer de-roled, an account deactivated.
 *
 * The endpoint cannot see any of that, and the token carries no authority of its
 * own, so every entry point re-resolves the live relationship through
 * ApprovalRecipients::roleFor() instead of trusting the notice. That is the same
 * seam ApprovalDecisionHandler::role() and ConfirmationDecisionHandler's
 * active-approver check guard, and it is why the role is re-read inside the
 * transaction rather than once up front.
 */
final class RevertChangeHandler implements AtomicActionTokenHandlerInterface
{
    public const SUBJECT_TYPE = 'event_change';

    /**
     * The post fields WordPressPublicationStore::snapshot() records. Restoring
     * anything else would invent a shape the change trail does not have.
     */
    private const SNAPSHOT_META = [
        'parish_id', 'venue_id', 'start_local', 'end_local', 'all_day', 'rrule',
        'exdates', 'rdates', 'featured', 'status_flag', 'source_candidate_id', 'contact',
    ];

    public function __construct(
        private readonly DatabaseConnectionInterface $database,
        private readonly ApprovalRecipients $recipients,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        private readonly OccurrenceMaintenanceInterface $occurrences,
        private readonly EventListingGeneration $listingGeneration,
        private readonly DateTimeZone $timezone
    ) {
    }

    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::REVERT_CHANGE;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        $row = $this->change($binding);
        if ($row === null || $this->role($row, $binding) === null) {
            return null;
        }

        $reverted = $this->reverted($row);
        $details = [
            'Event: ' . (string) ($row['event_id'] ?? '0'),
            'Change: ' . (string) ($row['kind'] ?? 'update'),
            'Recorded by: ' . (string) ($row['actor'] ?? 'Unknown')
                . ' on ' . (string) ($row['created_at'] ?? '') . ' UTC',
        ];
        if ($reverted !== null) {
            $details[] = $reverted;
        }

        return new ActionTokenPreview(
            'Revert this change',
            $reverted === null
                ? 'Put the event back the way it was before this change. '
                    . 'Your button press is what restores it.'
                : 'This change has already been reverted. Nothing further is needed.',
            'Revert the change',
            $details,
            $reverted === null
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        throw new LogicException('Reverting a change requires an atomic token transaction.');
    }

    public function performAtomic(
        ActionTokenBinding $binding,
        string $token,
        ActionTokenService $tokens,
        string $reason
    ): ActionTokenOutcome {
        if ($this->isForeign($binding) || $reason !== '') {
            throw new DomainException('This revert action is unavailable.');
        }

        $this->execute('START TRANSACTION');
        $eventId = null;

        try {
            $row = $this->change($binding, true);
            if ($row === null) {
                throw new DomainException('That change is no longer available.');
            }

            // Re-resolved inside the transaction: the assignment that made this
            // notice addressable may have moved on since the mail was written.
            $role = $this->role($row, $binding);
            if ($role === null) {
                throw new DomainException(
                    'You are no longer an approver for this parish, so this change cannot be reverted.'
                );
            }

            $reverted = $this->reverted($row);
            if ($reverted !== null) {
                throw new DomainException($reverted);
            }

            $before = $this->payload($row, 'before_payload');
            if ($before === null) {
                throw new DomainException('That change did not record a state to restore.');
            }

            if ($tokens->consume($token, $binding)->status !== ActionTokenStatus::CONSUMED) {
                throw new DomainException('The revert link was already used or expired.');
            }

            $eventId = (int) ($row['event_id'] ?? 0);
            if ($eventId < 1 || ! $this->lockEvent($eventId)) {
                throw new DomainException('The event that was changed no longer exists.');
            }

            // The state being replaced is read before the restore, not after:
            // once the post is rewritten the two are indistinguishable and the
            // reversal row would record itself as a no-op pair.
            $replaced = $this->snapshot($eventId);
            $restored = $this->restore($eventId, $before);
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            $changes = $this->table('adct_pi_event_changes');
            $updated = $this->execute($this->database->prepare(
                "UPDATE {$changes} SET reverted_by = %s, reverted_at = %s, updated_at = %s"
                . ' WHERE id = %d AND reverted_at IS NULL',
                $binding->email, $now, $now, $binding->subjectId
            ));
            if ($updated !== 1) {
                throw new DomainException('Someone else has already reverted this change.');
            }

            // The reversal is itself a change, so the trail reads forwards: the
            // amended state stays visible in the row it belonged to.
            $this->execute($this->database->prepare(
                "INSERT INTO {$changes} "
                . '(event_id,candidate_id,actor,kind,before_payload,after_payload,created_at,updated_at) '
                . 'VALUES (%d,%d,%s,%s,%s,%s,%s,%s)',
                $eventId,
                (int) ($row['candidate_id'] ?? 0) ?: null,
                $binding->email,
                'revert',
                $this->encode($replaced),
                $this->encode($restored),
                $now,
                $now
            ));

            $this->execute($this->database->prepare(
                'INSERT INTO ' . $this->table('adct_pi_audit_log')
                . ' (actor,action,subject_type,subject_id,details,created_at,updated_at)'
                . ' VALUES (%s,%s,%s,%d,%s,%s,%s)',
                $binding->email, 'change_reverted', self::SUBJECT_TYPE, $binding->subjectId,
                json_encode(['role' => $role, 'event_id' => $eventId], JSON_THROW_ON_ERROR),
                $now, $now
            ));

            $this->execute('COMMIT');
        } catch (Throwable $failure) {
            try {
                $this->execute('ROLLBACK');
            } catch (Throwable $rollbackFailure) {
                throw new RuntimeException(
                    'The revert failed and its transaction could not be rolled back: '
                    . $rollbackFailure->getMessage(),
                    0,
                    $failure
                );
            }
            throw $failure;
        }

        $this->afterCommit($eventId, $binding, $row);

        return new ActionTokenOutcome(
            'The change was reverted and the event restored.',
            $this->permalink($eventId)
        );
    }

    /**
     * A spent link re-opened by its own recipient. Reverting twice would
     * restore a snapshot that is no longer the one that was replaced, so the
     * recovered outcome only reports what happened.
     */
    public function recover(ActionTokenBinding $binding): ActionTokenOutcome
    {
        $row = $this->change($binding);
        if ($row === null || $this->role($row, $binding) === null) {
            throw new DomainException('This link did not revert the change.');
        }
        if (($row['reverted_by'] ?? null) !== $binding->email) {
            throw new DomainException('This link did not revert the change.');
        }
        return new ActionTokenOutcome('This change had already been reverted.');
    }

    /**
     * @param array<string, mixed> $before
     * @return array<string, mixed> the snapshot as restored, for the trail
     */
    private function restore(int $eventId, array $before): array
    {
        $post = get_post($eventId);
        if (! $post instanceof \WP_Post || $post->post_type !== EventPostType::POST_TYPE) {
            throw new DomainException('The event that was changed no longer exists.');
        }

        // The occurrence hooks rebuild from the post on every save, so they have
        // to stand down or they would fire once here and once on our own rebuild.
        EventOccurrenceHooks::setPublishingCandidate(true);
        try {
            $postData = [
                'ID' => $eventId,
                'post_type' => EventPostType::POST_TYPE,
                'post_status' => (string) ($before['status'] ?? 'publish'),
                'post_title' => (string) ($before['title'] ?? ''),
                'post_content' => (string) ($before['content'] ?? ''),
                'post_excerpt' => (string) ($before['excerpt'] ?? ''),
            ];
            $saved = wp_insert_post($postData, true);
            if (is_wp_error($saved) || ! is_int($saved) || $saved !== $eventId) {
                throw new RuntimeException('The event post could not be restored: '
                    . (is_wp_error($saved) ? $saved->get_error_message() : 'the post was not updated'));
            }

            $assigned = wp_set_object_terms(
                $eventId,
                array_map('intval', (array) ($before['event_type_term_ids'] ?? [])),
                EventPostType::TAXONOMY,
                false
            );
            if (is_wp_error($assigned) || ! is_array($assigned)) {
                throw new RuntimeException('The event type could not be restored: '
                    . (is_wp_error($assigned) ? $assigned->get_error_message() : 'invalid result'));
            }

            $meta = (array) ($before['meta'] ?? []);
            foreach (self::SNAPSHOT_META as $key) {
                update_post_meta($eventId, $key, $meta[$key] ?? '');
            }

            $thumbnail = (int) ($before['featured_image_id'] ?? 0);
            if ($thumbnail > 0) {
                set_post_thumbnail($eventId, $thumbnail);
            } else {
                delete_post_thumbnail($eventId);
            }
        } finally {
            EventOccurrenceHooks::setPublishingCandidate(false);
        }

        $this->occurrences->rebuildEvent(
            $eventId,
            OccurrenceWindow::rollingTwelveMonths($this->clock->now(), $this->timezone)
        );

        return $this->snapshot($eventId);
            }

    /**
     * @param array<string, mixed> $row
     */
    private function afterCommit(int $eventId, ActionTokenBinding $binding, array $row): void
    {
        try {
            clean_post_cache($eventId);
            $this->listingGeneration->bump();
        } catch (Throwable $failure) {
            throw new RuntimeException(
                'Event ' . $eventId . ' was reverted, but its listing cache could not be refreshed. '
                . 'Revert change ' . $binding->subjectId . ' from the event history to try again.',
                0,
                $failure
            );
        }

        // Only the person who pressed the button is told, and only once: the
        // contact that made the change is reached through the change notice job.
        $this->mailer->enqueue(new OutboundEmail(
            $binding->email,
            'Your change was reverted',
            '<p>The change you reverted on event ' . $eventId . ' has been undone.</p>',
            "The change you reverted on event {$eventId} has been undone.",
            MailPriority::APPROVER_OR_CHANGE,
            'change-reverted:' . $binding->subjectId
        ));
    }

    /**
     * The live relationship, uncached. Returns the role this recipient holds now
     * over the parish the change belongs to, or null when they hold none.
     *
     * @param array<string, mixed> $row
     */
    private function role(array $row, ActionTokenBinding $binding): ?string
    {
        return $this->recipients->roleFor(
            (int) ($row['parish_id'] ?? 0) ?: null,
            $binding->email
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reverted(array $row): ?string
    {
        $by = $row['reverted_by'] ?? null;

        if (! is_string($by) || $by === '' || empty($row['reverted_at'])) {
            return null;
        }

        return 'This change has already been reverted by ' . $by
            . ' at ' . (string) $row['reverted_at'] . ' UTC.';
    }

    private function isForeign(ActionTokenBinding $binding): bool
    {
        return $binding->purpose !== $this->purpose()
            || $binding->subjectType !== self::SUBJECT_TYPE;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function change(ActionTokenBinding $binding, bool $lock = false): ?array
    {
        if ($this->isForeign($binding)) {
            return null;
        }

        return $this->read($this->database->prepare(
            'SELECT c.*, parish_meta.meta_value AS parish_id'
            . ' FROM ' . $this->table('adct_pi_event_changes') . ' c'
            . ' LEFT JOIN ' . $this->table('postmeta') . ' parish_meta ON parish_meta.post_id = c.event_id'
            . " AND parish_meta.meta_key = 'parish_id'"
            . ' WHERE c.id = %d' . ($lock ? ' FOR UPDATE' : ''),
            $binding->subjectId
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payload(array $row, string $column): ?array
    {
        $raw = $row[$column] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function lockEvent(int $eventId): bool
    {
        return $this->read($this->database->prepare(
            'SELECT ID FROM ' . $this->table('posts') . ' WHERE ID = %d FOR UPDATE',
            $eventId
        )) !== null;
    }

        /**
         * The same shape WordPressPublicationStore::snapshot() records, so the
         * reversal's after_payload is comparable with the change it reverses.
         *
         * @return array<string, mixed>
         */
        private function snapshot(int $eventId): array
    {
        $post = get_post($eventId);
        if (! $post instanceof \WP_Post) {
            throw new RuntimeException('The event could not be read for change history.');
        }
        $meta = [];
        foreach (self::SNAPSHOT_META as $key) {
            $meta[$key] = get_post_meta($eventId, $key, true);
        }
        $terms = wp_get_object_terms($eventId, EventPostType::TAXONOMY, ['fields' => 'ids']);
        if (is_wp_error($terms) || ! is_array($terms)) {
            throw new RuntimeException('The event type could not be read for change history: '
                . (is_wp_error($terms) ? $terms->get_error_message() : 'invalid result'));
        }
        return [
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'status' => $post->post_status,
            'event_type_term_ids' => array_map('intval', $terms),
            'featured_image_id' => get_post_thumbnail_id($eventId),
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function encode(array $snapshot): string
    {
        return json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>
     */
    private function permalink(int $eventId): array
    {
        $url = get_permalink($eventId);
        if (! is_string($url) || $url === '') {
            return [];
        }

        return [$url];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $sql): ?array
    {
        $this->database->clearLastError();
        $row = $this->database->getRow($sql);
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The revert database read failed.');
        }
        return $row;
    }

    private function execute(string $sql): int
    {
        $this->database->clearLastError();
        $result = $this->database->query($sql);
        if ($result === false || $this->database->lastError() !== '') {
            throw new RuntimeException('The revert database write failed.');
        }
        return $result;
    }

    private function table(string $suffix): string
    {
        $prefix = $this->database->prefix();
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The revert database prefix is invalid.');
        }
        return '`' . $prefix . $suffix . '`';
    }
}
