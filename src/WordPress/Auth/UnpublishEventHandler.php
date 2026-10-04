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
use WP_Post;

/**
 * Takes one published event off the events page, from the link in a change notice.
 *
 * The other half of ADR 0008 point 4, which puts a Revert link and an Unpublish
 * link side by side in the notice. Reverting is the right answer when a notice
 * moved an event an hour and the old details were right. It is the wrong answer
 * when the change revealed that the event was never ours to publish: a poster for
 * another parish's service that our matching picked up, a hall that turns out to
 * have been booked. No restore brings that event down, so this handler does the one
 * thing nothing else in the trail can.
 *
 * The permissioning is deliberately identical to RevertChangeHandler's. The link
 * is addressed to whoever held authority over the parish when the change was
 * published, which is a fact about the past: a dean can move between deaneries and
 * a reviewer can be de-roled before the link is followed. The token carries no
 * authority of its own and the endpoint cannot see the deanery assignment, so the
 * live relationship is re-resolved through ApprovalRecipients::roleFor() on both the
 * GET preview and the POST. They are separate classes rather than one handler with a
 * flag because they are separate decisions with separate consequences, and a token
 * that could mean either would be a credential for both.
 *
 * Unpublishing is terminal for the post but not for the record. An `unpublish` row
 * is appended to the same trail a revert appends a `revert` row to, holding the
 * state that was live, so "who took this down, and what was on the page" stays
 * answerable from the event history rather than from a post that no longer renders.
 * Nothing here claims the old state comes back: the audit verb is `event_unpublished`,
 * not `change_reverted`, because those two records answer different questions.
 *
 * Because the post no longer renders, the contact who made the change cannot see
 * from the events page that it was taken down, and the trail is read from the admin
 * screen. So the person who pressed the button is told by mail, and the contact is
 * reached by the change notice job reading this row, the same route that tells them
 * about a rejection.
 */
final class UnpublishEventHandler implements AtomicActionTokenHandlerInterface
{
    public const SUBJECT_TYPE = 'event_change';

    /**
     * The post fields WordPressPublicationStore::snapshot() records, so the
     * unpublish row's before_payload is comparable with the change it removes.
     */
    private const SNAPSHOT_META = [
        'parish_id', 'venue_id', 'start_local', 'end_local', 'all_day', 'rrule',
        'exdates', 'rdates', 'featured', 'status_flag', 'source_candidate_id', 'contact',
    ];

    /**
     * A status of its own rather than `trash` or `draft`.
     *
     * `trash` can be emptied by anybody holding the trash capability, which would
     * take the record away from the very history that is supposed to explain the
     * removal. `draft` is not distinguishable from an editor's own draft when the
     * events page is queried. So the status is registered separately and the change
     * history reads the trail, not the post, to answer what happened.
     */
    public const UNPUBLISHED_STATUS = 'adct_unpublished';

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
        return ActionTokenPurpose::UNPUBLISH_EVENT;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        $row = $this->change($binding);
        if ($row === null || $this->role($row, $binding) === null) {
            return null;
        }

        $gone = $this->alreadyUnpublished($row);
        $details = [
            'Event: ' . (string) ($row['event_id'] ?? '0'),
            'Change: ' . (string) ($row['kind'] ?? 'update'),
            'Recorded by: ' . (string) ($row['actor'] ?? 'Unknown')
                . ' on ' . (string) ($row['created_at'] ?? '') . ' UTC',
        ];
        if ($gone !== null) {
            $details[] = $gone;
        }

        return new ActionTokenPreview(
            'Take this event off the events page',
            $gone === null
                ? 'The event stops appearing on the events page and in the calendar feed, '
                    . 'at once. It is not deleted, and re-publishing it is a fresh decision '
                    . 'by an approver -- this link does not put it back.'
                : 'This event is already off the events page. Nothing further is needed.',
            'Take the event off the events page',
            $details,
            $gone === null
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        throw new LogicException('Unpublishing an event requires an atomic token transaction.');
    }

    public function performAtomic(
        ActionTokenBinding $binding,
        string $token,
        ActionTokenService $tokens,
        string $reason
    ): ActionTokenOutcome {
        if ($this->isForeign($binding) || $reason !== '') {
            throw new DomainException('This unpublish action is unavailable.');
        }

        $this->execute('START TRANSACTION');
        $eventId = null;

        try {
            $row = $this->change($binding, true);
            if ($row === null) {
                throw new DomainException('That change is no longer available.');
            }

            // Re-resolved inside the transaction, for the same reason as in
            // RevertChangeHandler: the assignment that made this notice
            // addressable may have moved on since the mail was written.
            $role = $this->role($row, $binding);
            if ($role === null) {
                throw new DomainException(
                    'You are no longer an approver for this parish, so this event cannot be unpublished.'
                );
            }

            $gone = $this->alreadyUnpublished($row);
            if ($gone !== null) {
                throw new DomainException($gone);
            }

            $eventId = (int) ($row['event_id'] ?? 0);
            if ($eventId < 1 || ! $this->lockEvent($eventId)) {
                throw new DomainException('The event that was changed no longer exists.');
            }

            // The same hazard as a superseded revert, and worse here. A revert that
            // discarded a later change could be recovered from the trail; an
            // unpublish that fired against a stale notice would take down an event
            // an approver has since looked at and published, and the sender who
            // asked for the change would get a withdrawal they never sent.
            //
            // Deliberately before the token is consumed, so a link that was
            // correctly issued to a real approver stays usable and they can
            // reissue it against the change they mean.
            $this->assertStillCurrent($row);

            if ($tokens->consume($token, $binding)->status !== ActionTokenStatus::CONSUMED) {
                throw new DomainException('The unpublish link was already used or expired.');
            }

            // Read before the post moves, for the same reason the revert handler
            // reads it before the restore: afterwards the two states are
            // indistinguishable and the row would record itself as a no-op pair.
            $removed = $this->snapshot($eventId);
            $this->unpublish($eventId);
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            $changes = $this->table('adct_pi_event_changes');
            // The change that made this event live stays in the trail. Bumping
            // its updated_at is deliberately not what guards a double unpublish: a
            // guarded UPDATE on reverted_at is not reusable here, because an
            // unpublish can follow a revert and a revert can follow an unpublish.
            // The post's status, which only the locked row knows, is the guard.
            $this->execute($this->database->prepare(
                "UPDATE {$changes} SET updated_at = %s WHERE id = %d",
                $now,
                $binding->subjectId
            ));

            $this->execute($this->database->prepare(
                "INSERT INTO {$changes} "
                . '(event_id,candidate_id,actor,kind,before_payload,after_payload,created_at,updated_at) '
                . 'VALUES (%d,%d,%s,%s,%s,%s,%s,%s)',
                $eventId,
                (int) ($row['candidate_id'] ?? 0) ?: null,
                $binding->email,
                'unpublish',
                $this->encode($removed),
                $this->encode(['status' => self::UNPUBLISHED_STATUS]),
                $now,
                $now
            ));

            $this->execute($this->database->prepare(
                'INSERT INTO ' . $this->table('adct_pi_audit_log')
                . ' (actor,action,subject_type,subject_id,details,created_at,updated_at)'
                . ' VALUES (%s,%s,%s,%d,%s,%s,%s)',
                $binding->email, 'event_unpublished', self::SUBJECT_TYPE, $eventId,
                json_encode([
                    'role' => $role,
                    'event_id' => $eventId,
                    'change_id' => $binding->subjectId,
                ], JSON_THROW_ON_ERROR),
                $now, $now
            ));

            $this->execute('COMMIT');
        } catch (Throwable $failure) {
            try {
                $this->execute('ROLLBACK');
            } catch (Throwable $rollbackFailure) {
                throw new RuntimeException(
                    'The unpublish failed and its transaction could not be rolled back: '
                    . $rollbackFailure->getMessage(),
                    0,
                    $failure
                );
            }
            throw $failure;
        }

        $this->afterCommit($eventId, $binding, $row);

        return new ActionTokenOutcome(
            'The event was taken off the events page and its calendar feed.',
            $this->permalink($eventId)
        );
    }

    /**
     * A spent link re-opened by its own recipient. Unpublishing twice would write a
     * second trail row recording a removal that had already happened, so the
     * recovered outcome only reports what happened.
     */
    public function recover(ActionTokenBinding $binding): ActionTokenOutcome
    {
        $row = $this->change($binding);
        if ($row === null || $this->role($row, $binding) === null) {
            throw new DomainException('This link did not unpublish the event.');
        }
        if ($this->alreadyUnpublished($row) === null) {
            throw new DomainException('This link did not unpublish the event.');
        }

        return new ActionTokenOutcome('This event had already been taken off the events page.');
    }

    /**
     * Move the event out of the front end and off the calendar feed.
     *
     * The occurrences are rebuilt rather than merely hidden: the ICS feed is
     * generated from the occurrence rows, so leaving them behind would keep
     * publishing an event the approver has just taken down, and ADR 0008 point 5
     * is about the feed being honest about what is on.
     */
    private function unpublish(int $eventId): void
    {
        $post = get_post($eventId);
        if (! $post instanceof WP_Post || $post->post_type !== EventPostType::POST_TYPE) {
            throw new DomainException('The event that was changed no longer exists.');
        }

        // The occurrence hooks rebuild from the post on every save, so they have
        // to stand down or the same window would be built twice.
        EventOccurrenceHooks::setPublishingCandidate(true);
        try {
            $saved = wp_insert_post([
                'ID' => $eventId,
                'post_type' => EventPostType::POST_TYPE,
                'post_status' => self::UNPUBLISHED_STATUS,
            ], true);
            if (is_wp_error($saved) || ! is_int($saved) || $saved !== $eventId) {
                throw new RuntimeException('The event could not be unpublished: '
                    . (is_wp_error($saved) ? $saved->get_error_message() : 'the post was not updated'));
            }
        } finally {
            EventOccurrenceHooks::setPublishingCandidate(false);
        }

        $this->occurrences->rebuildEvent(
            $eventId,
            OccurrenceWindow::rollingTwelveMonths($this->clock->now(), $this->timezone)
        );
    }

    /**
     * Whether anything was recorded against this event after the change under
     * unpublish. The trail is the authority here, not the post: a later
     * publication appends a row whether or not it changed the fields this would
     * touch, and re-reading the post cannot tell a later publication from a hand
     * edit.
     *
     * @param array<string, mixed> $row
     */
    private function supersededBy(array $row): ?int
    {
        $changeId = (int) ($row['id'] ?? 0);
        $eventId = (int) ($row['event_id'] ?? 0);
        if ($changeId < 1 || $eventId < 1) {
            return null;
        }

        $newer = $this->read($this->database->prepare(
            'SELECT id FROM ' . $this->table('adct_pi_event_changes')
            . ' WHERE event_id = %d AND id > %d ORDER BY id ASC LIMIT 1',
            $eventId, $changeId
        ));

        if ($newer === null) {
            return null;
        }

        $newerId = (int) ($newer['id'] ?? 0);

        return $newerId > 0 ? $newerId : null;
    }

    /**
     * An unpublish issued against one change must not take down an event that a
     * later change has since amended. A notice says what was true when it was
     * written, and the link outlives that by design, because the notice is the only
     * copy of the before and after the approver needs to judge.
     *
     * @param array<string, mixed> $row
     */
    private function assertStillCurrent(array $row): void
    {
        $newer = $this->supersededBy($row);
        if ($newer === null) {
            return;
        }

        throw new DomainException(
            'A newer change (change ' . $newer . ') has been published on this event since'
                . ' this one, so unpublishing would remove the newer change instead. Unpublish'
                . ' from the newer change instead.'
        );
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
                'Event ' . $eventId . ' was unpublished, but its listing cache could not be refreshed. '
                . 'Unpublish from the event history to try again.',
                0,
                $failure
            );
        }

        // The person who pressed the button is told what they just did. The contact
                // whose event went off the page is told separately, below.
                $this->mailer->enqueue(new OutboundEmail(
                    $binding->email,
                    'Your unpublish took effect',
                    '<p>The event you took off the events page is no longer listed.</p>',
                    "The event you took off the events page is no longer listed.",
                    MailPriority::APPROVER_OR_CHANGE,
                    'event-unpublished:' . $eventId . ':' . $binding->subjectId
                ));

                $this->tellTheContact($eventId, $binding, $row);
            }

            /**
             * The contact who wrote the change, told that their event is off the page.
             *
             * ChangeNoticeJob will not reach them. It skips `revert` rows on purpose --
             * a notice carrying a one-click revert link is a live credential for an undo
             * -- and `unpublish` rows are silent for the same reason: this row records
             * an undo already performed, and the remedies were all taken. So the
             * handler is the only place both the outcome and the contact's address are
             * in hand.
             *
             * The actor column is that address: CandidatePublisher builds the
             * Publication from approved_by, so it is a deliverable address and not a
             * login name.
             *
             * Suppressed when the actor is the presser, so one person gets one message
             * on an account capped at 500 emails an hour for the whole site (ADR
             * 0011), and when the column is blank, which a contact-published change
             * never has but a hand-written row need not.
             *
             * @param array<string, mixed> $row
             */
            private function tellTheContact(int $eventId, ActionTokenBinding $binding, array $row): void
            {
                $actor = $row['actor'] ?? null;

                if (! is_string($actor)) {
                    return;
                }

                $actor = trim($actor);

                if ($actor === '' || strcasecmp($actor, $binding->email) === 0) {
                    return;
                }

                $this->mailer->enqueue(new OutboundEmail(
                    $actor,
                    'An event you asked for is no longer listed',
                    '<p>An event you asked to have changed on the Archdiocese website has been'
                        . ' taken off the events page and its calendar feed. It is not deleted, and'
                        . ' an archdiocesan reviewer can put it back.</p>',
                    "An event you asked to have changed on the Archdiocese website has been taken off"
                        . " the events page and its calendar feed.\n"
                        . "It is not deleted, and an archdiocesan reviewer can put it back.",
                    MailPriority::APPROVER_OR_CHANGE,
                    'event-unpublished-for-contact:' . $eventId . ':' . $binding->subjectId
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
    private function alreadyUnpublished(array $row): ?string
    {
        $eventId = (int) ($row['event_id'] ?? 0);
        $post = $eventId > 0 ? get_post($eventId) : null;
        if (! $post instanceof WP_Post || $post->post_status !== self::UNPUBLISHED_STATUS) {
            return null;
        }

        return 'This event is already off the events page. Nothing further is needed.';
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
     * The same shape WordPressPublicationStore::snapshot() records, so the
     * unpublish row's before_payload is comparable with the change it removes.
     *
     * @return array<string, mixed>
     */
    private function snapshot(int $eventId): array
    {
        $post = get_post($eventId);
        if (! $post instanceof WP_Post) {
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

    private function lockEvent(int $eventId): bool
    {
        return $this->read($this->database->prepare(
            'SELECT ID FROM ' . $this->table('posts') . ' WHERE ID = %d FOR UPDATE',
            $eventId
        )) !== null;
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
            throw new RuntimeException('The unpublish database read failed.');
        }
        return $row;
    }

    private function execute(string $sql): int
    {
        $this->database->clearLastError();
        $result = $this->database->query($sql);
        if ($result === false || $this->database->lastError() !== '') {
            throw new RuntimeException('The unpublish database write failed.');
        }
        return $result;
    }

    private function table(string $suffix): string
    {
        $prefix = $this->database->prefix();
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The unpublish database prefix is invalid.');
        }
        return '`' . $prefix . $suffix . '`';
    }
}
