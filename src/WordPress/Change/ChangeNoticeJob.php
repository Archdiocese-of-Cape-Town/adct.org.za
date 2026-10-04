<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Change;

use ADCT\ParishIntake\Core\Approval\Approver;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Events\ChangeDiff;
use ADCT\ParishIntake\Core\Jobs\AbstractJob;
use ADCT\ParishIntake\Core\Jobs\JobRunLifecycleInterface;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
use ADCT\ParishIntake\WordPress\Auth\RevertChangeHandler;
use ADCT\ParishIntake\WordPress\Auth\UnpublishEventHandler;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeZone;
use OutOfBoundsException;
use RuntimeException;

/**
 * Tells the people entitled to know that a published event changed, and puts the
 * one-click Revert and Unpublish links in the same message (ADR 0008 point 4).
 *
 * It mirrors ApprovalNoticeJob deliberately rather than sharing its machinery,
 * for two reasons that outrank the duplication:
 *
 * - ApprovalNoticeJob's notice rows are `queued_at`-stamped, which is what makes
 *   its two-phase drain-then-scan resumable. Change notices have no row of their
 *   own to stamp: the `event_changes` row is the subject of the notice, not a
 *   record of having sent it, and `event_changes.notified_at` is written once the
 *   whole recipient set has been offered the message. A crash between the enqueue
 *   and that stamp re-offers the notice, which the mail queue's group key then
 *   collapses into the single message already sitting in the queue.
 * - There is no `change_notices` table and no migration for one. Everything needed
 *   is already in `event_changes` plus the queue's recipient/group-key uniqueness.
 *
 * Two kinds of recorded change are deliberately *not* announced:
 *
 * - `revert` and `unpublish`. Both are an approver's own undo, and a revert or
 *   unpublish link addressed to the approvers who hold authority over that parish
 *   hands them a second, live credential for a change that has already been
 *   undone -- one that, on a trail with a newer change, correctly refuses with
 *   "a newer change has been published". ADR 0008 asks for the links *with the
 *   notice of the change*; the notice of the undo goes to the one person whose
 *   edit it removed, which is RevertChangeHandler::afterCommit(), because only
 *   there is the pressed button known. The rows are still recorded, still scoped
 *   to the parish and still shown in the change history; they are just not mailed.
 *
 * Approval is never assumed. `ApprovalRecipients::roleFor()` is resolved by the
 * token handlers at the moment the button is pressed, so this job decides only
 * who to *address* mail to; it never decides that anybody may act on it.
 */
final class ChangeNoticeJob extends AbstractJob implements JobRunLifecycleInterface
{
    private const BATCH_SIZE = 20;

    /**
     * Digest mail is held back until this hour so that "daily" means one message
     * a day, even when cron happens to run soon after a change.
     */
    public const DEFAULT_DIGEST_HOUR = 7;

    /**
     * Kinds that carry no notice. See the class docblock for why.
     *
     * @var list<string>
     */
    private const UNANNOUNCED = ['revert', 'unpublish'];

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
        parent::__construct('queue_change_notices', 'Queue event change notices and digests', 600);
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
        $after = ctype_digit($checkpoint ?? '') ? (int) $checkpoint : 0;
        $changes = $this->rows($this->database->prepare(
            'SELECT ch.id, ch.event_id, ch.candidate_id, ch.actor, ch.kind,'
            . ' ch.before_payload, ch.after_payload, ch.created_at,'
            . ' ch.notified_at, c.parish_id AS candidate_parish_id,'
            . ' meta.meta_value AS event_parish_id'
            . ' FROM ' . $this->table('adct_pi_event_changes') . ' ch'
            . ' LEFT JOIN ' . $this->table('adct_pi_event_candidates') . ' c ON c.id = ch.candidate_id'
            . ' LEFT JOIN ' . $this->table('postmeta') . ' meta ON meta.post_id = ch.event_id'
            . " AND meta.meta_key = 'parish_id'"
            . ' WHERE ch.id > %d AND ch.notified_at IS NULL'
            . ' ORDER BY ch.id ASC LIMIT %d',
            $after, self::BATCH_SIZE
        ));
        if ($changes === []) {
            return $after === 0 ? null : JobStepResult::completeAt(null);
        }

        $localNow = $this->clock->now()->setTimezone(new DateTimeZone('Africa/Johannesburg'));
        $today = $localNow->format('Ymd');
        $deferred = false;
        foreach ($changes as $change) {
            $id = (int) $change['id'];

            // Every run reaches every row once so the id checkpoint keeps moving.
            // A row that is never announced is stamped, so it cannot be offered
            // again on every future run for the rest of its life.
            $announced = $this->announce($change, $localNow, $today, $deferred);

            $after = $id;
            if ($announced) {
                $this->stamp($id);
            }
        }

        return $deferred ? JobStepResult::completeAt(null) : JobStepResult::continueAt((string) $after);
    }

    /**
     * Offer one change to its approvers. Returns whether the change is fully
     * accounted for, and so may be stamped as notified.
     *
     * @param array<string, mixed> $change
     */
    private function announce(array $change, \DateTimeImmutable $localNow, string $today, bool &$deferred): bool
    {
        $id = (int) $change['id'];
        $kind = is_string($change['kind'] ?? null) ? $change['kind'] : '';

        // An undo carries no notice; stamp it and move on.
        if (in_array($kind, self::UNANNOUNCED, true)) {
            return true;
        }

        $parishId = (int) ($change['candidate_parish_id'] ?? 0);
        if ($parishId < 1) {
            $raw = $change['event_parish_id'] ?? null;
            $trimmed = is_string($raw) ? trim($raw) : '';
            $parishId = ctype_digit($trimmed) ? (int) $trimmed : 0;
        }

        try {
            $recipients = $this->recipients->forParish($parishId > 0 ? $parishId : null);
        } catch (OutOfBoundsException) {
            // The parish row went away after the change was published. There is
            // nobody left to notify, so step over this change instead of
            // abandoning the batch and leaving every later change unnotified.
            error_log('[ADCT Parish Intake] Change notice skipped change ' . $id
                . ': its parish no longer exists.');
            return true;
        }

        foreach ($recipients as $email => $recipient) {
            $mode = $recipient['mode'];

            if (isset($this->notifiedThisRun[$email])) {
                // One message per recipient per run (ADR 0011: 500 emails/hour for
                // the whole account). A change left for a later run keeps
                // notified_at NULL, so the next run picks it up.
                $deferred = true;
                continue;
            }

            if ($mode === Approver::NOTIFY_DIGEST && ! $this->digestOpen($localNow)) {
                // Too early for today's digest. Leave the change unnotified so a
                // later run offers it once the digest hour has passed.
                $deferred = true;
                continue;
            }

            $recipientHash = substr(hash('sha256', $email), 0, 24);
            $key = $mode === Approver::NOTIFY_DIGEST
                ? 'change-digest:' . $today . ':' . $recipientHash
                : 'change:' . $id . ':' . $recipientHash;

            // The digest group collapses every change of the day into one
            // message. Per-item mail is keyed on the change, so its key can only
            // collide with itself -- but the check is what makes a re-offer after
            // a crash between the enqueue and the stamp harmless.
            if ($this->queue->findByRecipientAndGroupKey($email, $key) !== null) {
                $this->notifiedThisRun[$email] = true;
                continue;
            }

            $this->send($change, $email, $mode, $key);
            $this->notifiedThisRun[$email] = true;
        }

        return ! $deferred;
    }

    /**
     * Build and enqueue the message for one change and one recipient.
     *
     * @param array<string, mixed> $change
     */
    private function send(array $change, string $email, string $mode, string $key): void
    {
        $id = (int) $change['id'];
        $eventId = (int) ($change['event_id'] ?? 0);
        $digest = $mode === Approver::NOTIFY_DIGEST;

        $actor = is_string($change['actor'] ?? null) ? $change['actor'] : '';
        $paragraphs = [];
        if ($actor !== '') {
            $paragraphs[] = 'Changed by: ' . $actor;
        }
        $paragraphs[] = 'Event ' . $eventId . ' on the events page has been changed.';

        $before = ChangeDiff::decode($change['before_payload'] ?? null);
        $after = ChangeDiff::decode($change['after_payload'] ?? null);
        $summary = ChangeDiff::describe($before['snapshot'], $after['snapshot']);
        if ($summary !== '') {
            $paragraphs[] = 'What changed:';
        }
        foreach (explode("\n", $summary) as $line) {
            $paragraphs[] = $line;
        }
        if (! $before['ok'] || ! $after['ok']) {
            // The trail still says a change happened; only the field-level
            // detail is unreadable. Say so rather than implying nothing changed.
            $paragraphs[] = 'NOTE: the before and after details of this change could not be read.';
        }

        // ADR 0008 point 4: the notice carries both ways out, side by side.
        // Reverting restores the old fields; unpublishing takes the event off
        // the events page, which is the only remedy when the event itself was
        // never ours to publish.
        $links = [
            'Revert this change' => ActionTokenPurpose::REVERT_CHANGE,
            'Unpublish this event' => ActionTokenPurpose::UNPUBLISH_EVENT,
        ];
        $urls = [];
        foreach ($links as $label => $purpose) {
            $urls[$label] = ActionTokenEndpoint::urlForToken($this->tokens->issue(new ActionTokenBinding(
                $purpose, $this->subjectTypeFor($purpose), $id, $email
            ))->token());
        }

        $subject = $digest
            ? 'Your daily event change notices'
            : 'An event you look after has changed';

        $text = implode("\n", $paragraphs) . "\n\n";
        foreach ($urls as $label => $url) {
            $text .= $label . ': ' . $url . "\n";
        }

        $html = '<section>';
        foreach ($paragraphs as $line) {
            $html .= '<p>' . esc_html($line) . '</p>';
        }
        foreach ($urls as $label => $url) {
            $html .= '<p><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></p>';
        }
        $html .= '</section>';

        $this->mailer->enqueue(new OutboundEmail(
            $email, $subject, $html, $text,
            $digest ? MailPriority::REMINDER_OR_DIGEST : MailPriority::APPROVER_OR_CHANGE,
            $key
        ));
    }

    /**
     * The subject type the handler for a purpose reads its row by.
     *
     * Both handlers resolve a change through the same `event_change` subject, so
     * naming it once here keeps a future third remedy from silently minting a
     * token no page can act on.
     */
    private function subjectTypeFor(ActionTokenPurpose $purpose): string
    {
        return $purpose === ActionTokenPurpose::UNPUBLISH_EVENT
            ? UnpublishEventHandler::SUBJECT_TYPE
            : RevertChangeHandler::SUBJECT_TYPE;
    }

    /**
     * Record that this change has been offered to its whole recipient set.
     *
     * Stamped only after enqueue, never before: a crash in between must re-offer
     * the notice, not swallow it, and the group key collapses the duplicate into
     * the message already queued. UTC, because every other timestamp this plugin
     * writes is UTC and the display layer converts.
     */
    private function stamp(int $changeId): void
    {
        $now = $this->utcNow();
        $this->execute($this->database->prepare(
            'UPDATE ' . $this->table('adct_pi_event_changes')
            . ' SET notified_at = %s, updated_at = %s'
            . ' WHERE id = %d AND notified_at IS NULL',
            $now, $now, $changeId
        ));
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
            throw new RuntimeException('Change notice lookup failed.');
        }
        return $rows;
    }

    private function execute(string $sql): void
    {
        $this->database->clearLastError();
        if ($this->database->query($sql) === false || $this->database->lastError() !== '') {
            throw new RuntimeException('A change notice could not be recorded.');
        }
    }

    private function table(string $suffix): string
    {
        $prefix = $this->database->prefix();
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('Invalid change table prefix.');
        }
        return '`' . $prefix . $suffix . '`';
    }
}
