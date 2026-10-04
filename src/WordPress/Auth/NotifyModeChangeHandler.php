<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Approval\Approver;
use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryApproverRepository;
use DateTimeZone;
use DomainException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Lets a deanery approver choose between per-item notices and a daily digest
 * from the email itself (issue #169).
 *
 * A deanery approver is, by ADR 0007 and ADR 0008, a person who authenticates by
 * emailed link and never needs a WordPress account, so the only surface that has
 * ever let a dean set this is the Deaneries admin screen — which they cannot
 * reach. Until now their `notify_mode` could only be changed by an administrator
 * on their behalf. Archdiocese reviewers were never affected: they have a
 * wp-admin account for the review queue and set the same choice on their own
 * profile page.
 *
 * The token carries no authority. It names a WordPress user and an email, and
 * both entry points re-resolve the live account and the live assignments before
 * anything happens, exactly as LoginHandler does and for the same reason: the
 * link may sit in a mailbox for the seven days it is valid, and the recipient
 * can stop being an approver on any day of those seven. Pressing "Save" changes
 * their own notification frequency and nothing else — it can never approve,
 * deny, correct or publish an event.
 *
 * `perform()` throws unconditionally, as ApprovalEditHandler::perform() does, so
 * the change can only be committed through the endpoint's nonce-protected,
 * transactional POST. A GET renders the form and writes nothing.
 *
 * ## Multi-deanery behaviour
 *
 * `notify_mode` is stored per deanery assignment, not per person, so a person
 * who approves for two deaneries has two rows. This handler applies the choice
 * to **every assignment that is live at the moment the button is pressed** — the
 * assignment row is active and its deanery is still active. That set is read
 * inside the transaction, not taken from the token, so a dean moved to a single
 * deanery between receiving the mail and pressing the button changes only the
 * one assignment they still hold. Per-deanery modes remain available to an
 * administrator on the Deaneries screen for anyone who needs them to differ.
 */
final class NotifyModeChangeHandler implements ActionTokenActionHandlerInterface
{
    /**
     * The binding's subject type. There is no single row this change is about:
     * the subject id is the WordPress user id, and every live assignment that
     * user holds is what the write touches.
     */
    public const SUBJECT_TYPE = 'approval_preference';

    /** The two values the stored column accepts, and the only ones this link offers. */
    public const MODES = [
        Approver::NOTIFY_DIGEST => 'One email a day with everything waiting for me',
        Approver::NOTIFY_EACH => 'An email each time something needs me',
    ];

    public function __construct(
        private readonly DatabaseConnectionInterface $database,
        private readonly DeaneryApproverRepository $approvers,
        private readonly ClockInterface $clock
    ) {
    }

    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::CHANGE_NOTIFY_MODE;
    }

    /**
     * The confirmation page, or null when the recipient is no longer entitled to
     * one. Refusing here rather than at the POST means a dean who has lost the
     * assignment is never shown a form that would fail.
     */
    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        if ($this->isForeign($binding)) {
            return null;
        }

        if ($this->resolve($binding) === null) {
            return null;
        }

        $assignments = $this->assignments($binding->subjectId);

        if ($assignments === []) {
            return null;
        }

        return new ActionTokenPreview(
            __('How often should we email you?', 'adct-parish-intake'),
            __(
                'Choose how often you hear about events waiting for your approval. '
                . 'This changes your own notifications only — it does not approve anything. '
                . 'Your next waiting event uses the new setting.',
                'adct-parish-intake'
            ),
            __('Save my choice', 'adct-parish-intake'),
            $this->details($assignments),
            true,
            [NotifyModeField::FORM_FIELD => $this->storedMode($assignments)]
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        throw new LogicException(
            'Changing a notification mode requires a nonce-protected transactional POST.'
        );
    }

    /**
     * Save the chosen mode on every live assignment of the bound WordPress user.
     *
     * The submitted mode is validated before the transaction opens, so a
     * malformed submission cannot hold a database transaction open for a
     * request that was never going to do work. Everything else is re-read
     * inside it.
     */
    public function save(
        ActionTokenBinding $binding,
        string $secret,
        ActionTokenService $tokens,
        string $submittedMode
    ): ActionTokenOutcome {
        $mode = trim($submittedMode);

        if (! array_key_exists($mode, self::MODES)) {
            throw new DomainException(
                'Please choose either an email for each event or one email a day.'
            );
        }

        $this->execute('START TRANSACTION');

        try {
            if ($this->isForeign($binding) || $this->resolve($binding) === null) {
                throw new DomainException(
                    'You are no longer an approver, so this preference cannot be changed.'
                );
            }

            // Re-read inside the transaction: the set of deaneries this person
            // is live in at act time is the set that changes, not the set that
            // was true when the notice was mailed.
            $assignments = $this->assignments($binding->subjectId);

            if ($assignments === []) {
                throw new DomainException(
                    'You are no longer an approver, so this preference cannot be changed.'
                );
            }

            if ($tokens->consume($secret, $binding)->status !== ActionTokenStatus::CONSUMED) {
                throw new DomainException('This link has already been used or expired.');
            }

            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $changed = 0;

            foreach ($assignments as $assignment) {
                $this->approvers->update((int) $assignment['id'], [
                    'notify_mode' => $mode,
                    'updated_at' => $now,
                ]);
                if ($assignment['notify_mode'] !== $mode) {
                    $changed++;
                }
            }

            $this->audit($binding, $mode, $assignments, $changed, $now);
            $this->execute('COMMIT');
        } catch (Throwable $failure) {
            $this->execute('ROLLBACK');
            throw $failure;
        }

        return new ActionTokenOutcome(
            $mode === Approver::NOTIFY_DIGEST
                ? __('Saved. You will get one email a day listing everything waiting for you. '
                    . 'It applies from the next event that needs you.', 'adct-parish-intake')
                : __('Saved. You will get an email each time something needs you. '
                    . 'It applies from the next event that needs you.', 'adct-parish-intake')
        );
    }

    /**
     * @param list<array{id: int, deanery_id: int, notify_mode: string}> $assignments
     */
    private function audit(
        ActionTokenBinding $binding,
        string $mode,
        array $assignments,
        int $changed,
        string $now
    ): void {
        $this->execute($this->database->prepare(
            'INSERT INTO ' . $this->table('adct_pi_audit_log')
            . ' (actor,action,subject_type,subject_id,details,created_at,updated_at)'
            . ' VALUES (%s,%s,%s,%d,%s,%s,%s)',
            $binding->email,
                        AuditAction::APPROVER_NOTIFY_MODE_CHANGED->value,
            self::SUBJECT_TYPE,
            $binding->subjectId,
            (string) json_encode([
                'wp_user_id' => $binding->subjectId,
                'from' => $this->storedMode($assignments),
                'to' => $mode,
                'deanery_ids' => array_map(
                                static fn (array $assignment): int => (int) $assignment['deanery_id'],
                                $assignments
                            ),
                'changed' => $changed,
            ], JSON_THROW_ON_ERROR),
            $now,
            $now
        ));
    }

    /**
     * The live WordPress account behind this binding, or null when it may not
     * change its own notification frequency.
     *
     * Uncached, and checked on every entry point, so a link minted for a dean
     * who has since been deactivated, stripped of APPROVE_DEANERY, or had their
     * account email changed stops working at once. The email check matters as
     * much as the capability check: the mail is sent to an address, and if that
     * address is later reassigned to a different person they must not inherit
     * the link.
     *
     * @return \WP_User|null
     */
    private function resolve(ActionTokenBinding $binding): ?\WP_User
    {
        $user = get_user_by('email', $binding->email);

        if (! $user instanceof \WP_User) {
            return null;
        }

        if ((int) $user->ID !== $binding->subjectId) {
            return null;
        }

        if ((int) $user->user_status !== 0) {
            return null;
        }

        if (strtolower(trim((string) $user->user_email)) !== strtolower(trim($binding->email))) {
            return null;
        }

        if (! user_can($user, Capabilities::APPROVE_DEANERY)) {
            return null;
        }

        return $user;
    }

    /**
     * @return list<array{id: int, deanery_id: int, notify_mode: string}>
     */
    private function assignments(int $wpUserId): array
    {
        return $this->approvers->findLiveAssignmentsForUser($wpUserId);
    }

    private function isForeign(ActionTokenBinding $binding): bool
    {
        return $binding->purpose !== $this->purpose()
            || $binding->subjectType !== self::SUBJECT_TYPE;
    }

    /**
     * @param list<array{id: int, deanery_id: int, notify_mode: string}> $assignments
     */
    private function storedMode(array $assignments): string
    {
        $first = $assignments[0]['notify_mode'] ?? Approver::NOTIFY_EACH;

        return array_key_exists($first, self::MODES) ? $first : Approver::NOTIFY_EACH;
    }

    /**
     * @param list<array{id: int, deanery_id: int, notify_mode: string}> $assignments
     * @return list<string>
     */
    private function details(array $assignments): array
    {
        $deaneries = count($assignments);
        $details = [
            sprintf(
                /* translators: %d: the number of deaneries this person approves for. */
                __('This applies to every deanery you approve for (%d in total).', 'adct-parish-intake'),
                $deaneries
            ),
        ];

        if ($this->isMixed($assignments)) {
            $details[] = __(
                'Your deaneries are currently set differently, so this will make them all the same. '
                . 'An administrator can set them individually on the Deaneries screen.',
                'adct-parish-intake'
            );
        }

        return $details;
    }

    /**
     * @param list<array{id: int, deanery_id: int, notify_mode: string}> $assignments
     */
    private function isMixed(array $assignments): bool
    {
        $first = $assignments[0]['notify_mode'] ?? null;

        foreach ($assignments as $assignment) {
            if ($assignment['notify_mode'] !== $first) {
                return true;
            }
        }

        return false;
    }

    private function execute(string $sql): int
    {
        $this->database->clearLastError();
        $result = $this->database->query($sql);

        if ($result === false || $this->database->lastError() !== '') {
            throw new RuntimeException('The notification preference could not be saved.');
        }

        return $result;
    }

    private function table(string $suffix): string
    {
        $prefix = $this->database->prefix();

        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The notification preference table prefix is invalid.');
        }

        return '`' . $prefix . $suffix . '`';
    }
}