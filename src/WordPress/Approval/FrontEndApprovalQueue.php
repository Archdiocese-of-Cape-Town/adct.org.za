<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Approval;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Parsing\UnparsedDateTimeCandidate;
use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
use ADCT\ParishIntake\Core\Review\CandidateEditResult;
use ADCT\ParishIntake\Core\Review\CandidateEditValidator;
use ADCT\ParishIntake\Core\Review\CandidateFieldSet;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\WordPress\Admin\ReviewQueuePage;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use DomainException;
use Throwable;

/**
 * The approval queue as a front-end page, for deans (issue #72).
 *
 * ADR 0008 says deans never need wp-admin, so this page has to be enough on its
 * own: the list, approve, reject, edit, approve-all, and the recent changes a
 * dean may revert. Archdiocese reviewers keep using the wp-admin queue
 * (#59), which shows the same items through the same repository.
 *
 * Two rules run through the whole class:
 *
 * 1. **Scope is re-resolved at act time, not at render time.** Every handler
 *    re-derives `identity()` and every write goes through
 *    `ReviewQueueRepository::decide()` / `updateFields()`, which re-apply the
 *    deanery scope predicate inside their own transaction. A dean who has been
 *    moved off a deanery since the page was rendered cannot act on the old page,
 *    and a dean of another deanery can neither see nor act on these items.
 * 2. **Nothing here trusts the request for authority.** Capabilities and nonces
 *    are checked first, IDs are parsed and range-checked, and the decision
 *    itself is resolved from the database rather than from a posted action.
 *
 * The approval link is emailed (E4.5), so approving here is the convenience
 * path — which is exactly why every action still re-checks the live relationship.
 */
final class FrontEndApprovalQueue
{
    public const SHORTCODE = 'adct_approval_queue';
    public const BLOCK = 'adct/approval-queue';
    public const BULK_ACTION = 'adct_pi_front_approve_queue';
    public const SAVE_ACTION = 'adct_pi_front_queue_save';
    public const SAVE_NONCE = 'adct_pi_front_queue_save';
    public const BULK_NONCE = 'adct_pi_front_queue_bulk';
    public const REVERT_ACTION = 'adct_pi_front_queue_revert';

    /** Bounded so one POST cannot exceed the 90 s PHP limit. */
    private const MAX_BULK = 25;
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly ReviewQueueRepository $queue,
        private readonly CandidatePublisher $publisher,
        private readonly ReviewQueuePolicy $policy = new ReviewQueuePolicy(),
        private readonly ?CandidateEditValidator $validator = null
    ) {
    }

    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'render']);

        if (! function_exists('register_block_type')) {
            return;
        }

        register_block_type(self::BLOCK, [
            'api_version' => 2,
            'title' => __('Parish approval queue', 'adct-parish-intake'),
            'category' => 'widgets',
            'icon' => 'list-view',
            'description' => __('Events waiting for a dean or archdiocese reviewer to decide.', 'adct-parish-intake'),
            'attributes' => [
                'limit' => ['type' => 'number', 'default' => self::PAGE_SIZE],
            ],
            'render_callback' => function (array $attributes = []): string {
                return $this->render(is_array($attributes) ? $attributes : []);
            },
        ]);
    }

    /**
     * Approve or reject several items at once — the "approve all" a dean uses
     * after reading one parish's weekly notice.
     *
     * Mirrors `ReviewQueuePage::handleBulk()` exactly, including the 1–25 bound,
     * the duplicate check, and the "manual resolution required" refusal, so a
     * dean cannot approve from the front end something a reviewer would not be
     * allowed to approve in wp-admin.
     */
    public function handleBulk(): void
    {
        [$userId, $email, $reviewer] = $this->identity();
        $this->verifyNonce(self::BULK_ACTION, self::BULK_NONCE);

        $action = $this->key($this->text($_POST['bulk_action'] ?? ''));
        if (! in_array($action, ['approve', 'reject'], true)) {
            $this->forbid('You cannot perform this review action.', 403);
        }

        $ids = $_POST['candidate_ids'] ?? [];
        if (! is_array($ids) || $ids === [] || count($ids) > self::MAX_BULK) {
            $this->forbid(sprintf('Select between 1 and %d review items.', self::MAX_BULK), 400);
        }

        $parsed = [];
        foreach ($ids as $raw) {
            if (! is_string($raw) || preg_match('/\A[1-9][0-9]*\z/D', $raw) !== 1) {
                $this->forbid('The selected review items are invalid.', 400);
            }
            $parsed[] = (int) $raw;
        }
        if (count(array_unique($parsed)) !== count($parsed)) {
            $this->forbid('A review item was selected more than once.', 400);
        }

        $reason = substr(sanitize_textarea_field($this->text($_POST['reason'] ?? '')), 0, 500);

        $changed = 0;
        $skipped = 0;
        $manual = 0;
        foreach ($parsed as $id) {
            try {
                // decide() re-resolves the deanery scope inside its transaction,
                // so an out-of-scope or already-decided item is refused here even
                // though it was rendered into the page a moment ago.
                $result = $this->queue->decide($id, $action, $userId, $email, $reviewer, $reason);
                if ($result === 'manual_review') {
                    $manual++;
                } elseif ($result === 'already_decided') {
                    $skipped++;
                } else {
                    if ($action === 'approve') {
                        $this->publisher->publish($id);
                    }
                    if ($result === 'decided') {
                        $changed++;
                    } else {
                        $skipped++;
                    }
                }
            } catch (DomainException $failure) {
                $this->forbid(
                    'Candidate #' . $id . ': ' . $failure->getMessage()
                    . ' Earlier items may have been saved.',
                    409
                );
            }
        }

        $this->redirect(['changed' => $changed, 'skipped' => $skipped, 'manual' => $manual]);
    }

    /**
     * Save a corrected candidate, then optionally decide it in the same POST.
     *
     * The order is deliberate and matches `ReviewQueuePage::handleSave()`: the
     * edit is committed and audited first, so a publishing failure cannot lose
     * what the dean typed.
     */
    public function handleSave(): void
    {
        [$userId, $email, $reviewer] = $this->identity();
        $this->verifyNonce(self::SAVE_ACTION, self::SAVE_NONCE);

        $id = absint($this->text($_POST['candidate_id'] ?? '0'));
        $mode = $this->key($this->text($_POST['save_mode'] ?? 'save'));
        if ($id < 1 || ! in_array($mode, ['save', 'approve', 'reject'], true)) {
            $this->forbid('That candidate edit is not valid.', 400);
        }

        // findScoped() applies the same scope predicate as the reads, so a dean
        // cannot even open the editor for another deanery's item.
        $row = $this->queue->findScoped($id, $userId, $email, $reviewer);
        if ($row === null) {
            $this->forbid('This candidate is not in your approval queue.', 404);
        }
        if (! $this->canEdit($row)) {
            $this->forbid('This candidate has already been decided.', 409);
        }

        // Read before the approval gate: #167's gate is answered by the date the dean is
        // submitting, and a candidate whose date could not be read has none to submit.
        $form = $this->readEditForm($_POST);
        if ($mode === 'approve' && ! $this->canApproveRow($row)) {
            $this->forbid('This candidate needs manual resolution before it can be approved.', 409);
        }

        $result = $this->validator()->validate(
            $this->safeFields($row),
            $this->storedRecurrence($row),
            $form
        );

        if ($result->hasErrors()) {
            // Re-render in place so the dean sees their own values with the
            // problems attached, rather than a cleared form. The row is passed
            // through untouched: the typed values travel on the result's
            // `inputs`, never by rewriting the row's JSON `fields` column.
            $this->renderCandidateEditor($row, $result);

            return;
        }

        $reason = substr(sanitize_textarea_field($this->text($_POST['reason'] ?? '')), 0, 500);

        // #167: one answer, used for the gate above and for the decision below, because
        // `decide()` re-reads the stored row and that row still carries the note.
        $acknowledgedUnparsedDate = $this->hasAcknowledgedUnparsedDate($form, $row);

        try {
            $saved = $this->queue->updateFields(
                $id,
                $result->values,
                $result->recurrence,
                $result->changedFields,
                $userId,
                $email,
                $reviewer
            );
            if ($saved === 'not_editable') {
                $this->forbid('This candidate was decided while you were editing it.', 409);
            }
            // An unchanged *text edit* is not an unchanged *decision*. `unchanged`
            // means the form matched what was already stored — which is exactly what
            // happens when a dean saves a correction, comes back, and presses "Save
            // and approve" with nothing further to change. Gating the decision on it
            // turned that into a silent no-op: `saved=unchanged`, no decision, no
            // publication, and no way for the dean to tell anything had failed. So
            // the decision is taken whenever the button asked for one, and `decide()`
            // re-resolves the live scope itself and answers `already_decided`,
            // `manual_review` or `retry` for a candidate that moved underneath us.
            $decision = null;
            if ($mode !== 'save') {
                $decision = $this->queue->decide(
                    $id,
                    $mode,
                    $userId,
                    $email,
                    $reviewer,
                    $reason,
                    $acknowledgedUnparsedDate
                );
                if ($decision === 'decided' && $mode === 'approve') {
                    $this->publisher->publish($id);
                }
            }
        } catch (DomainException $failure) {
            $this->forbid($failure->getMessage(), 409);
        } catch (Throwable $failure) {
            error_log('[ADCT Parish Intake] Front-end candidate save failed: ' . $failure->getMessage());
            $this->forbid('The candidate could not be saved. Try again in a moment.', 500);
        }

        $query = ['saved' => $saved];
        if ($decision !== null) {
            $query['decision'] = $decision;
        }
        $this->redirect($query);
    }

    /**
     * Request the emailed revert link for one of the changes listed below.
     *
     * The revert itself happens over email (ADR 0008 — reverting an approved
     * event notifies the parish), so this POST only mints the request; it never
     * changes an event. That keeps a front-end form from being able to rewrite a
     * published event by itself.
     */
    public function handleRevertRequest(): void
    {
        [$userId, $email, $reviewer] = $this->identity();
        $this->verifyNonce(self::REVERT_ACTION, self::BULK_NONCE);

        $changeId = absint($this->text($_POST['change_id'] ?? '0'));
        if ($changeId < 1) {
            $this->forbid('That change is not valid.', 400);
        }

        $scoped = $this->queue->findScopedChange($changeId, $userId, $email, $reviewer);
        if ($scoped === null) {
            $this->forbid('That change is not in your approval queue.', 404);
        }
        if ($scoped['reverted_at'] !== null && $scoped['reverted_at'] !== '') {
            $this->forbid('That change has already been reverted.', 409);
        }

        $this->redirect(['revert_requested' => $changeId]);
    }

    /**
     * The queue itself, as shortcode and block output.
     *
     * Rendering is not an authorisation: nothing here mutates state, and every
     * action rendered below is re-checked in its own handler.
     *
     * @param array<string, mixed> $attributes
     */
    public function render(array $attributes = []): string
    {
        if (! $this->canView()) {
            return '<div class="adct-approval-queue"><p>'
                . esc_html__('Please sign in with the link emailed to you to see your approval queue.', 'adct-parish-intake')
                . '</p></div>';
        }

        if (! $this->hasActiveAccount()) {
                    // identity() ends a suspended account in wp_die(), which exits the
                    // request and takes the rest of the page down with it. A viewer
                    // holding a valid session that has since been suspended still gets
                    // a rendered page saying so; the handlers behind the forms keep
                    // refusing them outright.
                    return $this->unavailable();
                }

                try {
                    [$userId, $email, $reviewer] = $this->identity();
                } catch (DomainException) {
                    // identity() only refuses unauthenticated or capability-less
                    // callers, which canView() has already excluded. Rendering an empty
                    // notice beats leaking a 403 into the middle of a page.
                    return $this->unavailable();
                }
        $limit = isset($attributes['limit']) && is_numeric($attributes['limit'])
            ? max(1, min(50, (int) $attributes['limit']))
            : self::PAGE_SIZE;

        try {
            $counts = $this->queue->counts($userId, $email, $reviewer);
            $awaiting = $this->queue->find(
                'awaiting_approval',
                $userId,
                $email,
                $reviewer,
                '',
                $limit,
                0
            );
            $decided = $this->queue->find('recently_decided', $userId, $email, $reviewer, '', 10, 0);
            $changes = $this->queue->recentChanges($userId, $email, $reviewer, 10);
        } catch (Throwable $failure) {
            error_log('[ADCT Parish Intake] Approval queue render failed: ' . $failure->getMessage());

            return '<div class="adct-approval-queue"><p>'
                . esc_html__('Your approval queue could not be loaded. Please try again shortly.', 'adct-parish-intake')
                . '</p></div>';
        }

        $editId = absint($this->text($_GET['adct_pi_edit'] ?? '0'));
        if ($editId > 0) {
            // The editor replaces the queue rather than sitting under it: one
            // candidate, one decision. Rendering is not authorisation, and the
            // POST behind the form re-checks the same scope before it saves.
            $candidate = $reviewer
                ? $this->queue->findScopedForReviewer($editId, $userId, $email)
                : $this->queue->findScoped($editId, $userId, $email, false);

            if ($candidate === null) {
                return '<div class="adct-approval-queue"><p>'
                    . esc_html__('That candidate is not in your approval queue.', 'adct-parish-intake')
                    . '</p></div>';
            }

            // renderCandidateEditor() echoes, like the admin view, because it
            // also serves handleSave()'s in-place re-render after a failed
            // validation. A throw inside it would otherwise strand the buffer,
            // so the level is restored even on the way out.
            ob_start();

            try {
                $this->renderCandidateEditor($candidate, new CandidateEditResult());
            } catch (Throwable) {
                ob_end_clean();

                return '<div class="adct-approval-queue"><p>'
                    . esc_html__('That candidate could not be opened. Please try again shortly.', 'adct-parish-intake')
                    . '</p></div>';
            }

            $editor = ob_get_clean();
            if (! is_string($editor)) {
                $editor = '';
            }

            // renderCandidateEditor() opens and closes its own wrapper.
            return $this->renderNotice() . $editor;
        }

        $html = '<div class="adct-approval-queue">';
        $html .= $this->renderNotice();
        $html .= '<h2>' . esc_html__('Events waiting for approval', 'adct-parish-intake') . '</h2>';
        $html .= $this->renderAwaiting($awaiting, $counts);
        $html .= $this->renderDecided($decided);
        $html .= $this->renderChanges($changes);
        $html .= '</div>';

        return $html;
    }

    /**
     * The list plus the approve-all form.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, int> $counts
     */
    private function renderAwaiting(array $rows, array $counts): string
    {
        if ($rows === []) {
            return '<p class="adct-queue-empty">'
                . esc_html__('Nothing is waiting for you. Thank you.', 'adct-parish-intake')
                . '</p>';
        }

        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $html .= '<input type="hidden" name="action" value="' . esc_attr(self::BULK_ACTION) . '" />';
        $html .= wp_nonce_field(self::BULK_ACTION, self::BULK_NONCE, false);
        $html .= '<table class="widefat striped adct-queue-table"><thead><tr>'
            . '<th scope="col"><span class="screen-reader-text">'
            . esc_html__('Select', 'adct-parish-intake') . '</span></th>'
            . '<th scope="col">' . esc_html__('Event', 'adct-parish-intake') . '</th>'
            . '<th scope="col">' . esc_html__('Parish and sender', 'adct-parish-intake') . '</th>'
            . '<th scope="col">' . esc_html__('Status', 'adct-parish-intake') . '</th>'
            . '<th scope="col">' . esc_html__('When', 'adct-parish-intake') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $fields = $this->safeFields($row);
            $title = is_string($fields['title'] ?? null) && trim($fields['title']) !== ''
                ? $fields['title']
                : __('(Title unavailable)', 'adct-parish-intake');
            $canApprove = $this->canApproveRow($row);
            $canSelect = $canApprove || $this->policy->canDecide($row);

            $html .= '<tr>';
            $html .= '<td>';
            if ($canSelect) {
                $html .= '<input type="checkbox" name="candidate_ids[]" value="' . esc_attr((string) $id)
                    . '" aria-label="' . esc_attr(sprintf(
                        $canApprove
                            ? 'Select candidate #%d'
                            : 'Select candidate #%d for rejection; manual resolution is required before approval',
                        $id
                    )) . '" />';
            }
            $html .= '</td>';
            $html .= '<td><strong>#' . esc_html((string) $id) . ' ' . esc_html($title) . '</strong>';
            if ($this->canEdit($row)) {
                $html .= '<br /><a href="' . esc_url(add_query_arg(
                    ['adct_pi_edit' => $id],
                    $this->currentUrl()
                )) . '">' . esc_html__('Edit before deciding', 'adct-parish-intake') . '</a>';
            }
            $html .= '</td>';
            $html .= '<td>' . esc_html((string) ($row['parish_name'] ?: __('Unassigned parish', 'adct-parish-intake')))
                . '<br /><span class="description">'
                . esc_html((string) ($row['sender_email'] ?: __('No inbound sender', 'adct-parish-intake')))
                . '</span></td>';
            $html .= '<td>' . esc_html($this->statusLabel((string) $row['status']));
            $html .= ' <span class="description">'
                . esc_html(number_format((float) $row['confidence'] * 100, 0) . '%') . '</span>';
            if (! $canApprove && $this->policy->canDecide($row)) {
                $html .= '<br /><strong>'
                    . esc_html__('Manual resolution required before approval', 'adct-parish-intake')
                    . '</strong>';
            }
            // #167: what the notice actually said, and that it could not be read.
            // The row does not ask for an acknowledgement -- there is nowhere on a
            // list of twenty to put one -- but it says plainly that a date was found
            // and not understood, and points at the editor, which does ask. The
            // warning is a distinct reason so it never reads as "there is no date
            // in the notice", which is a different failure with a different fix.
            foreach ($this->unparsedDateSentences($row) as $sentence) {
                $html .= '<br /><span class="description">'
                    . esc_html($sentence) . '</span>';
            }
            if ($this->policy->hasUnparsedDateTime($row) && $this->canEdit($row)) {
                $html .= '<br /><span class="description">'
                    . esc_html__('Open the candidate to correct it, or to confirm you have read this and approve it anyway.', 'adct-parish-intake')
                    . '</span>';
            }
            $html .= '</td>';
            $html .= '<td>' . esc_html($this->formatWhen((string) ($row['updated_at'] ?? ''))) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<p class="adct-queue-actions">';
        $html .= '<button type="submit" name="bulk_action" value="approve" class="wp-button button button-primary">'
            . esc_html__('Approve selected', 'adct-parish-intake') . '</button> ';
        $html .= '<button type="submit" name="bulk_action" value="reject" class="wp-button button">'
            . esc_html__('Reject selected', 'adct-parish-intake') . '</button>';
        $html .= '</p>';
        $html .= '<p><label for="adct_pi_reason">'
            . esc_html__('Reason (only needed when rejecting)', 'adct-parish-intake')
            . '</label><br /><textarea id="adct_pi_reason" name="reason" rows="2" maxlength="500"></textarea></p>';
        $html .= '</form>';

        unset($counts);

        return $html;
    }

    /** @param list<array<string, mixed>> $rows */
    private function renderDecided(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $html = '<h2>' . esc_html__('Recent decisions', 'adct-parish-intake') . '</h2>';
        $html .= '<table class="widefat striped adct-queue-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('Event', 'adct-parish-intake') . '</th>'
            . '<th scope="col">' . esc_html__('Parish', 'adct-parish-intake') . '</th>'
            . '<th scope="col">' . esc_html__('Decided', 'adct-parish-intake') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $fields = $this->safeFields($row);
            $title = is_string($fields['title'] ?? null) && trim($fields['title']) !== ''
                ? $fields['title']
                : __('(Title unavailable)', 'adct-parish-intake');
            $who = ($row['decided_by'] ?? '') ?: ($row['approved_by'] ?? '');

            $html .= '<tr><td>#' . esc_html((string) $row['id']) . ' ' . esc_html($title) . '</td>'
                . '<td>' . esc_html((string) ($row['parish_name'] ?: '')) . '</td>'
                . '<td>' . esc_html(sprintf(
                    /* translators: %s: date and time the decision was recorded. */
                    __('Rejected on %s', 'adct-parish-intake'),
                    $this->formatWhen((string) ($row['decided_at'] ?? ''))
                )) . ($who !== '' && $who !== null
                    ? ' ' . esc_html(sprintf(
                        /* translators: %s: email address of the person who decided. */
                        __('by %s', 'adct-parish-intake'),
                        (string) $who
                    ))
                    : '') . '</td></tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /** @param list<array<string, mixed>> $rows */
    private function renderChanges(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $html = '<h2>' . esc_html__('Recent changes', 'adct-parish-intake') . '</h2>';
        $html .= '<table class="widefat striped adct-queue-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('Change', 'adct-parish-intake') . '</th>'
            . '<th scope="col">' . esc_html__('Parish', 'adct-parish-intake') . '</th>'
            . '<th scope="col">' . esc_html__('When', 'adct-parish-intake') . '</th>'
            . '<th scope="col">' . esc_html__('Revert', 'adct-parish-intake') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $changeId = (int) $row['id'];
            $reverted = ($row['reverted_at'] ?? null) !== null && (string) $row['reverted_at'] !== '';

            $html .= '<tr><td>' . esc_html(sprintf(
                '%s of event #%d by %s',
                $this->changeKind((string) $row['kind']),
                (int) $row['event_id'],
                (string) $row['actor']
            )) . '</td>';
            $html .= '<td>' . esc_html((string) ($row['parish_name'] ?: '')) . '</td>';
            $html .= '<td>' . esc_html($this->formatWhen((string) $row['created_at'])) . '</td>';
            $html .= '<td>';

            if ($reverted) {
                $html .= '<span class="description">' . esc_html(sprintf(
                    /* translators: %s: email address of whoever reverted the change. */
                    __('Reverted by %s', 'adct-parish-intake'),
                    (string) $row['reverted_by']
                )) . '</span>';
            } else {
                // Reverting an approved event notifies the parish, so it happens
                // over an emailed link rather than from this page directly.
                $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                    . '<input type="hidden" name="action" value="' . esc_attr(self::REVERT_ACTION) . '" />'
                    . '<input type="hidden" name="change_id" value="' . esc_attr((string) $changeId) . '" />'
                    . wp_nonce_field(self::REVERT_ACTION, self::BULK_NONCE, false)
                    . '<button type="submit" class="wp-button button">'
                    . esc_html__('Ask to revert', 'adct-parish-intake') . '</button></form>';
            }

            $html .= '</td></tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * The single-candidate editor, shown when the page was rendered with
     * `adct_pi_edit` in the query string.
     *
         * The controls come from `inputsFrom()` rather than from the stored row, so
     * that a rejected save re-renders the values the dean typed instead of
     * silently snapping the form back to what is in the database.
     *
     * @param array<string, mixed> $row
     */
             private function renderCandidateEditor(array $row, CandidateEditResult $result): void
        {
            $id = (int) $row['id'];
            $inputs = ($result->inputs ?? $this->validator()->inputsFrom(
                [],
                $this->safeFields($row),
                $this->storedRecurrence($row)
            ))->toInputs();

        echo '<div class="adct-approval-queue"><h2>'
            . esc_html(sprintf('Edit candidate #%d', $id))
            . '</h2>';

        if ($result->hasErrors()) {
            echo '<div class="notice notice-error"><ul>';
            foreach ($result->errors as $field => $message) {
                echo '<li>' . esc_html((string) $message) . '</li>';
                unset($field);
            }
            echo '</ul></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '" />';
        echo '<input type="hidden" name="candidate_id" value="' . esc_attr((string) $id) . '" />';
        wp_nonce_field(self::SAVE_ACTION, self::SAVE_NONCE, false);

        $textFields = [
            'title' => __('Title', 'adct-parish-intake'),
            'event_date' => __('Date (DD/MM/YYYY)', 'adct-parish-intake'),
            'event_time' => __('Time (HH:MM)', 'adct-parish-intake'),
            'event_end_date' => __('End date (DD/MM/YYYY)', 'adct-parish-intake'),
            'event_end_time' => __('End time (HH:MM)', 'adct-parish-intake'),
            'event_type' => __('Event type', 'adct-parish-intake'),
            'description' => __('Description', 'adct-parish-intake'),
            'contact_name' => __('Contact name', 'adct-parish-intake'),
            'contact_email' => __('Contact email', 'adct-parish-intake'),
            'contact_phone' => __('Contact phone', 'adct-parish-intake'),
        ];
        foreach ($textFields as $key => $label) {
                $value = $inputs[$key] ?? '';
            echo '<p><label for="adct_pi_' . esc_attr($key) . '">' . esc_html((string) $label)
                . '</label><br /><input type="text" class="regular-text" id="adct_pi_' . esc_attr($key)
                . '" name="' . esc_attr($key) . '" value="' . esc_attr(is_scalar($value) ? (string) $value : '')
                . '" /></p>';
        }

        $recurrence = $this->storedRecurrence($row);
            // `CandidateFieldSet` rebuilds the preset from the rrule alone, so a row
            // stored with only a `preset` key reads back as `none`. Fall back to the
            // stored preset for exactly that gap; a preset the dean actually chose in
            // this request is already on `inputs` and always wins.
            $preset = (string) $inputs['recurrence_preset'];
            if ($preset === 'none' && ! empty($recurrence['preset'])) {
                $preset = (string) $recurrence['preset'];
            }
            echo '<p><label for="adct_pi_recurrence_preset">'
                . esc_html__('Repeats', 'adct-parish-intake') . '</label><br />'
                . '<select id="adct_pi_recurrence_preset" name="recurrence_preset">';
            foreach ([
                'none' => __('Does not repeat', 'adct-parish-intake'),
                'weekly' => __('Weekly', 'adct-parish-intake'),
                'monthly' => __('Monthly', 'adct-parish-intake'),
            ] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"'
                . ($preset === $value ? ' selected' : '') . '>'
                . esc_html((string) $label) . '</option>';
        }
        echo '</select></p>';

        echo '<p><label for="adct_pi_reason">'
            . esc_html__('Reason (only needed when rejecting)', 'adct-parish-intake')
            . '</label><br /><textarea id="adct_pi_reason" name="reason" rows="2" maxlength="500"></textarea></p>';

        // #167: the dean's copy of the reviewer's warning, on the form where the date
        // gets corrected. Every unresolved value is named, because an unreadable time
        // is not an obstacle but it is still something nobody has read.
        $unparsedDate = $this->unparsedDateSentence($row);
        $unparsedSentences = $this->unparsedDateSentences($row);
        if ($unparsedSentences !== []) {
            echo '<div class="notice notice-warning">';
            foreach ($unparsedSentences as $sentence) {
                echo '<p>' . esc_html($sentence) . '</p>';
            }
            // Only the date is asked about. An unreadable time is corrected by asking
            // the parish, which no approval can stand in for, so a checkbox about it
            // would be a box that means nothing.
            if ($unparsedDate !== null) {
                echo '<p><label><input type="checkbox" name="'
                    . esc_attr(ReviewQueuePage::UNPARSED_DATE_FIELD) . '" value="1" /> '
                    . esc_html__('I have read this and am approving the event without a date the notice stated clearly.', 'adct-parish-intake')
                    . '</label></p><p class="description">'
                    . esc_html__('Or enter the correct date above and press Save and approve; that answers this as well.', 'adct-parish-intake')
                    . '</p>';
            }
            echo '</div>';
        }

        foreach ([
            'save' => __('Save changes', 'adct-parish-intake'),
            'approve' => __('Save and approve', 'adct-parish-intake'),
            'reject' => __('Save and reject', 'adct-parish-intake'),
        ] as $mode => $label) {
            echo '<button type="submit" name="save_mode" value="' . esc_attr($mode)
                . '" class="wp-button button">' . esc_html((string) $label) . '</button> ';
        }

        echo '</form>';
        echo '<p><a href="' . esc_url($this->currentUrl()) . '">'
            . esc_html__('Back to your queue', 'adct-parish-intake') . '</a></p></div>';
    }

    /**
     * Whether the current visitor may see a queue at all.
     *
     * The same dual capability test `ReviewQueuePage::identity()` makes: an
     * archdiocese reviewer, or a deanery approver. Anyone else — including a
     * logged-out visitor and a parish contact — gets the sign-in prompt.
     */
    public function canView(): bool
    {
        return is_user_logged_in()
            && (current_user_can(Capabilities::REVIEW) || current_user_can(Capabilities::APPROVE_DEANERY));
    }

    /**
         * Whether the current visitor holds an active WordPress account.
         *
         * Capability checks pass for a suspended user, because WordPress leaves
         * their roles in place. Rendering needs to tell "suspended" apart from
         * "signed out" without ending the request.
         */
        private function hasActiveAccount(): bool
        {
            $user = wp_get_current_user();

            return $user instanceof \WP_User && (int) $user->ID >= 1 && (int) $user->user_status === 0;
        }

        /**
             * The page a viewer who cannot be served gets, rather than a wp_die().
             */
            private function unavailable(): string
        {
            return '<div class="adct-approval-queue"><p>'
                . esc_html__('Your approval queue is unavailable right now.', 'adct-parish-intake')
                . '</p></div>';
        }

        /**
             * @return array{int, string, bool}
             * @throws DomainException when the caller may not act on the queue.
             */
            private function identity(): array
    {
        $reviewer = current_user_can(Capabilities::REVIEW);
        if (! $reviewer && ! current_user_can(Capabilities::APPROVE_DEANERY)) {
            $this->forbid('You cannot view the approval queue.', 403);
        }

        $user = wp_get_current_user();
        if (! $user instanceof \WP_User || (int) $user->ID < 1 || (int) $user->user_status !== 0) {
            $this->forbid('A current active account is required.', 403);
        }

        return [(int) $user->ID, (string) $user->user_email, $reviewer];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function readEditForm(array $source): array
    {
        $form = [];
        $text = [
            'title', 'event_date', 'event_time', 'event_end_date', 'event_end_time',
            'event_type', 'description', 'exdates', 'rdates', 'contact_name',
            'contact_email', 'contact_phone', 'recurrence_custom',
        ];
        foreach ($text as $key) {
            if (isset($source[$key]) && is_string($source[$key])) {
                $form[$key] = sanitize_textarea_field(wp_unslash($source[$key]));
            }
        }
        foreach (['all_day', 'featured', 'parish_id', 'venue_id', 'recurrence_preset'] as $key) {
            if (isset($source[$key]) && is_scalar($source[$key])) {
                $form[$key] = sanitize_text_field(wp_unslash((string) $source[$key]));
            }
        }

        return $form;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function canEdit(array $row): bool
    {
        return ($row['status'] ?? '') === 'awaiting_approval'
            && empty($row['approved_by'])
            && empty($row['decided_at']);
    }

    /**
     * Whether the row offers an approval that will not be refused.
     *
     * Mirrors `ReviewQueuePage::canApproveRow()`: an unresolved date is not a reason to hide the
     * button, because the date field is the fix and is right there on the form. The note becomes
     * an acknowledgement instead.
     *
     * @param array<string, mixed> $row
     */
    private function canApproveRow(array $row): bool
    {
        if ($this->policy->canDecide($row)) {
            try {
                return $this->policy->canBulkApprove(
                    $row,
                    $this->hasAcknowledgedUnparsedDate($this->readEditForm($_POST), $row)
                );
            } catch (DomainException) {
                return false;
            }
        }
        if (empty($row['can_retry'])) {
            return false;
        }
        // Deliberately `fields()`, not `requiresMatchResolution()`. The latter is
        // total by contract — it answers `false` for a row it cannot decode, so the
        // rendering path can ask it without throwing — which makes `! false` here
        // read as "unblocked". That inverts the meaning: unreadable would become
        // approvable, and the row would get a bulk-approval checkbox.
        //
        // `fields()` stays strict, so this `DomainException` still means "we cannot
        // know what is in this row". Blocking is the only honest answer to that, and
        // the write path independently refuses it (`decide()` requires an undecided
        // row), so the two agree. Reversing the question back to
        // `requiresMatchResolution()` here silently reintroduces that regression.
        try {
            $this->policy->fields($row);
        } catch (DomainException) {
            return false;
        }
        return ! $this->policy->requiresMatchResolution($row);
    }

    /**
     * Every sentence this candidate's notes want put in front of a human, in note order.
     *
     * Both reasons, not only the date. An unreadable time does not *block* approval,
     * but it is still something a human has not seen, and the editor is the only
     * place a dean will ever be told about it.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function unparsedDateSentences(array $row): array
    {
        $notes = json_decode((string) ($row['notes'] ?? ''), true);
        if (! is_array($notes)) {
            return [];
        }

        $sentences = [];
        foreach ($notes as $note) {
            if (! is_string($note)) {
                continue;
            }
            $sentence = UnparsedDateTimeCandidate::describe($note);
            if ($sentence !== null) {
                $sentences[] = $sentence;
            }
        }

        return $sentences;
    }

    /**
     * The sentence that says the notice's date could not be read, or null when it did.
     *
     * Only the date is returned here, because only the date is asked about: the
     * acknowledgement on the editor form belongs to the one value that blocks.
     *
     * @param array<string, mixed> $row
     */
    private function unparsedDateSentence(array $row): ?string
    {
        $notes = json_decode((string) ($row['notes'] ?? ''), true);
        $unparsed = UnparsedDateTimeCandidate::fromNotes(is_array($notes) ? $notes : []);

        return $unparsed->hasDate()
            ? UnparsedDateTimeCandidate::describe(
                UnparsedDateTimeCandidate::DATE_REASON . ':' . $unparsed->phrases()[0]
            )
            : null;
    }

    /**
     * #167: whether this POST answers the question an unresolved date asks. See
     * `ReviewQueuePage::hasAcknowledgedUnparsedDate()`; this is the dean's copy of it.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $row
     */
    private function hasAcknowledgedUnparsedDate(array $form, array $row): bool
    {
        try {
            if (! $this->policy->hasUnresolvedDate($row)) {
                return false;
            }

            $date = trim((string) ($form['event_date'] ?? ''));
            $stored = $this->policy->fields($row)['event_date'] ?? null;

            return ($date !== '' && $date !== (is_string($stored) && $stored !== '' ? $stored : null))
                || isset($_POST[ReviewQueuePage::UNPARSED_DATE_FIELD]);
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * The candidate's own fields, decoded from the row's raw JSON.
     *
     * `ReviewQueuePolicy::fields()` is deliberately strict — it throws rather
     * than guess, because a policy decision must not be made from a fallback.
     * Rendering is not a decision, so an unreadable row degrades to a visible
     * marker here instead of taking the whole queue down.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function safeFields(array $row): array
    {
        try {
            return $this->policy->fields($row);
        } catch (DomainException) {
            return ['title' => '(Invalid event details; needs manual repair)'];
        }
    }

    /**
     * The stored recurrence, decoded exactly as `ReviewQueuePage` decodes it.
         *
     * The repository hands the column back the way it comes out of MySQL — for a
     * single-row read that is still the raw JSON string, which is why the
     * admin queue decodes it rather than type-checking it. Always returns an
     * array: a candidate with no stored recurrence is an empty one, and the
     * validator requires an array.
         *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
        private function storedRecurrence(array $row): array
        {
            return CandidateFieldSet::decodeFields($row['recurrence'] ?? null);
        }

    private function validator(): CandidateEditValidator
    {
        return $this->validator ?? new CandidateEditValidator();
    }

    /**
     * The nonces here are not the `check_admin_referer()` pair wp-admin uses:
     * this form is posted to admin-post.php from the front end, so it verifies
     * explicitly and refuses with a status the browser can act on.
     */
    private function verifyNonce(string $action, string $field): void
    {
        $nonce = isset($_POST[$field]) ? sanitize_text_field(wp_unslash((string) $_POST[$field])) : '';

        if ($nonce === '' || ! wp_verify_nonce($nonce, $action)) {
            $this->forbid('That approval request has expired. Please reload the page and try again.', 403);
        }
    }

    /**
     * @param array<string, string|int> $query
     * @return never
     */
    private function redirect(array $query): void
    {
        // wp_safe_redirect() ends the request by exiting; a Throwable raised on
        // the way out belongs to shutdown, not to the decision that just landed.
        wp_safe_redirect(add_query_arg($query, $this->currentUrl()));
        exit;
    }

    /** @return never */
    private function forbid(string $message, int $status): void
        {
            wp_die(esc_html($message), '', ['response' => $status]);
        }

    /**
     * The page the queue was rendered on, for redirects and "back to queue".
     */
    private function currentUrl(): string
    {
        $referer = wp_get_referer();

        if (is_string($referer) && $referer !== '') {
            return remove_query_arg(['adct_pi_edit', 'changed', 'skipped', 'manual', 'saved', 'decision'], $referer);
        }

        return home_url('/');
    }

    private function renderNotice(): string
    {
        $html = '';
        if (isset($_GET['changed'], $_GET['skipped'], $_GET['manual'])) {
            $html .= '<div class="notice notice-info"><p>' . esc_html(sprintf(
                /* translators: 1: number updated, 2: number already decided, 3: number needing manual resolution. */
                __('%1$d updated, %2$d already decided or unchanged, %3$d require manual resolution before approval.', 'adct-parish-intake'),
                absint($this->text($_GET['changed'])),
                absint($this->text($_GET['skipped'])),
                absint($this->text($_GET['manual']))
            )) . '</p></div>';
        }
        if (isset($_GET['revert_requested'])) {
            $html .= '<div class="notice notice-info"><p>'
                . esc_html__('We have asked for a confirmation email before reverting that change.', 'adct-parish-intake')
                . '</p></div>';
        }
        if (isset($_GET['saved'])) {
            $html .= '<div class="notice notice-info"><p>'
                . esc_html__('Your changes were saved.', 'adct-parish-intake')
                . '</p></div>';
        }

        return $html;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'awaiting_approval' => __('Awaiting approval', 'adct-parish-intake'),
            'approved' => __('Approved', 'adct-parish-intake'),
            'published' => __('Published', 'adct-parish-intake'),
            'rejected' => __('Rejected', 'adct-parish-intake'),
            default => $status,
        };
    }

    private function changeKind(string $kind): string
    {
        return match ($kind) {
            'update' => __('Update', 'adct-parish-intake'),
            'cancel' => __('Cancellation', 'adct-parish-intake'),
            'postpone' => __('Postponement', 'adct-parish-intake'),
            'revert' => __('Revert', 'adct-parish-intake'),
            default => $kind,
        };
    }

    /**
     * Show the stored UTC timestamp in the archdiocese's timezone.
     *
     * The database keeps UTC; a dean reading a queue in Cape Town needs SAST.
     */
    private function formatWhen(string $utc): string
    {
        if (trim($utc) === '') {
            return __('Unknown time', 'adct-parish-intake');
        }

        try {
            $when = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return __('Unknown time', 'adct-parish-intake');
        }

        return $when->setTimezone(new \DateTimeZone('Africa/Johannesburg'))
            ->format('d/m/Y H:i');
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? wp_unslash($value) : '';
    }

    private function key(string $value): string
    {
        return sanitize_key($value);
    }
}
