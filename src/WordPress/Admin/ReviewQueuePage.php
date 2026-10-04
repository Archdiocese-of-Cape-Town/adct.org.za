<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Attachments\PreviewableImage;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendCooldownException;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendOutcome;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendService;
use ADCT\ParishIntake\Core\Parsing\Stages\ConfidenceScoringStage;
use ADCT\ParishIntake\Core\Parsing\UnparsedDateTimeCandidate;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
use ADCT\ParishIntake\Core\Review\CandidateEditResult;
use ADCT\ParishIntake\Core\Review\CandidateEditValidator;
use ADCT\ParishIntake\Core\Review\CandidateFieldSet;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\WordPress\Attachments\AttachmentImageEndpoint;
use ADCT\ParishIntake\WordPress\Attachments\OcrControl;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class ReviewQueuePage
{
    public const PAGE_SLUG = 'adct-parish-intake-review';
    private const ACTION = 'adct_pi_review_bulk';

    /**
     * The POST action and nonce names for the single-candidate editor.
     *
     * They are public because the editor, the source panel and `Plugin.php` all
     * have to name the same action, and a mismatch would fail silently.
     */
    public const SAVE_ACTION = 'adct_pi_candidate_save';
    public const SAVE_NONCE = 'review_nonce';
    public const RAW_MESSAGE_ACTION = 'adct_pi_candidate_raw_message';
    public const ATTACHMENT_ACTION = 'adct_pi_candidate_attachment';

    /**
     * The POST action and nonce for starting a hand-typed event beside a poster.
     *
     * Deliberately its own action rather than a mode on `SAVE_ACTION`: the only
     * thing it does is open a blank candidate, so a stray press of it cannot be
     * mistaken for a save or an approval. Nothing is published by pressing it.
     */
    public const CREATE_MANUAL_ACTION = 'adct_pi_candidate_create_manual';
    public const CREATE_MANUAL_NONCE = 'manual_nonce';

    /**
     * The POST action and nonce for resolving an ambiguous match in place
     * (issue #177).
     *
     * Its own action, its own nonce and its own form, for the same reason the
     * manual-entry button has its own: pressing Enter in a text field on this
     * screen must never be able to clear a block, and a form that can clear a
     * block must never be a mode on a form that only edits text.
     */
    public const RESOLVE_MATCH_ACTION = 'adct_pi_candidate_resolve_match';
    public const RESOLVE_MATCH_NONCE = 'resolve_match_nonce';

    /**
     * The POST action and nonce for resending a confirmation preview
     * (issue #176).
     *
     * Its own action and nonce for the same reason as the two above: this one
     * sends an email to somebody outside the archdiocese, so it must not be
     * reachable by pressing Enter in a text field, and it must not be a mode on
     * a form whose job is editing text. It is also the only action here that
     * costs mail against the account's 500-per-hour budget, which is why the
     * view says plainly that it queues rather than sends.
     */
    public const RESEND_CONFIRMATION_ACTION = 'adct_pi_candidate_resend_confirmation';
    public const RESEND_CONFIRMATION_NONCE = 'resend_confirmation_nonce';

    /**
     * One nonce name for both download actions. The file a reviewer is allowed to
     * see is decided by the candidate they came from, not by the button, so
     * there is nothing to vary.
     */
    public const SOURCE_NONCE = 'source_nonce';

    /** Serving a stored file is bounded so a large poster cannot exhaust the 90 s limit. */
    private const MAX_DOWNLOAD_BYTES = 10485760;

    private const PAGE_SIZE = 25;

    /**
     * The shared OCR assets' cache-busting version, matching the other screen
     * that enqueues them so a browser only ever holds one copy.
     */
    private const ASSET_VERSION = '1.0.0';

    /**
     * The post field that carries #167's acknowledgement.
     *
     * An approver has to be able to say they mean it: a notice whose date was found and
     * could not be read publishes as an event with no date, and a date nobody supplied
     * is not a decision an approver should be able to make by accident. Ticking this says
     * they have seen the phrase and still mean to approve. It is the only way to
     * acknowledge a date that cannot be corrected from the form above, which is why it
     * has a name of its own rather than riding on the save mode.
     *
     * Public because `FrontEndApprovalQueue` renders the same checkbox from its own form
    * and must post the same name, or the dean's acknowledgement would be silently dropped.
    */
    public const UNPARSED_DATE_FIELD = 'adct_pi_unparsed_date_acknowledged';

    /**
     * @param string $pluginFile the plugin's main file, so assets resolve and cache-bust
     * @param OcrControl|null $ocr the shared client-side reader for a poster preview (ADR 0018)
     * @param AttachmentImageEndpoint|null $imageEndpoint builds the nonce-bound URL that
     *        serves one stored poster to an already-authorised reviewer
     * @param (callable(): ConfirmationEmailResendService)|null $resendConfirmation builds
     *        the resend service on first use, because it needs the mail queue and the
     *        token service, which are themselves built after this page
     */
    public function __construct(
        private readonly ReviewQueueRepository $queue,
        private readonly CandidatePublisher $publisher,
        private readonly ReviewQueuePolicy $policy = new ReviewQueuePolicy(),
        private readonly ?InboundMessageRepository $messages = null,
        private readonly ?AttachmentRepository $attachments = null,
        private readonly ?InboundMailStorageReaderInterface $storage = null,
        private readonly ?CandidateEditValidator $validator = null,
        private readonly string $pluginFile = '',
        private readonly ?OcrControl $ocr = null,
        private readonly ?AttachmentImageEndpoint $imageEndpoint = null,
        private readonly mixed $resendConfirmation = null
    ) {
    }

    /**
     * The resend service, resolved on first use.
     *
     * Null when the plugin was wired without one. That is not a failure: the
     * resend panel simply does not render, which is how a fresh install that has
     * not finished its build order behaves. It is deliberately not an error here,
     * because {@see renderPage()} must never die on a screen that is otherwise
     * perfectly usable.
     */
    private function resendService(): ?ConfirmationEmailResendService
    {
        if ($this->resendConfirmation === null) {
            return null;
        }

            // Accepts either a ready service or a factory for one. The factory is what
            // `Plugin.php` passes, because the mail queue and token service are built
            // after this page and no constructor may call a WordPress function; a ready
            // instance is accepted too so a caller that already has one — a check
            // harness, or a site wiring it by hand — is not forced to wrap it.

            if ($this->resendConfirmation instanceof ConfirmationEmailResendService) {
                return $this->resendConfirmation;
            }

            if (! is_callable($this->resendConfirmation)) {
                return null;
            }

            $service = ($this->resendConfirmation)();

            return $service instanceof ConfirmationEmailResendService ? $service : null;
        }

    /**
     * Load the detail screen's stylesheet and script, and only on this page.
     *
     * The script is a progressive enhancement: it hides the time fields for an
     * all-day event and reveals the custom rule box. The form is complete and
     * correct without it, so nothing here gates a control's behaviour.
     */
    public function enqueueDetailAssets(string $hookSuffix): void
    {
        if ($this->pluginFile === '' || ! str_contains($hookSuffix, self::PAGE_SLUG)) {
            return;
        }
        // Bumped by hand when these files change, matching the public listing.
        $version = '1.1.0';
        wp_enqueue_style(
            'adct-parish-intake-candidate-detail',
            plugins_url('assets/candidate-detail.css', $this->pluginFile),
            [],
            $version
        );
        wp_enqueue_script(
            'adct-parish-intake-candidate-detail',
            plugins_url('assets/candidate-detail.js', $this->pluginFile),
            [],
            $version,
            true
        );
    }

    /**
     * Add the client-side OCR module to the detail screen.
     *
     * Only when a poster preview is actually on the page, so a reviewer who is
     * not looking at an image never loads a parser (ADR 0018).
     */
    public function enqueueDetailOcrAssets(string $hookSuffix): void
    {
        if ($this->ocr === null || $this->pluginFile === '' || ! str_contains($hookSuffix, self::PAGE_SLUG)) {
            return;
        }

        if (! $this->detailShowsPoster()) {
            return;
        }

        wp_enqueue_style(
            'adct-parish-intake-ocr',
            plugins_url('assets/ocr.css', $this->pluginFile),
            [],
            self::ASSET_VERSION
        );
        wp_enqueue_script(
            'adct-parish-intake-ocr',
            plugins_url('assets/ocr.js', $this->pluginFile),
            [],
            self::ASSET_VERSION,
            true
        );
        wp_enqueue_script(
            'adct-parish-intake-ocr-settings',
            plugins_url('assets/ocr-settings.js', $this->pluginFile),
            [],
            self::ASSET_VERSION,
            true
        );
    }

    /**
     * Whether this request is a detail screen that would show a stored poster.
     *
     * A plain check of the query, deliberately cheap and deliberately not a
     * query: the enqueue runs on every admin request, so it must not touch the
     * database. A screen that answers yes but turns out to be empty simply loads
     * a module with nothing to bind to, which costs nothing.
     */
    private function detailShowsPoster(): bool
    {
        if ($this->attachments === null || $this->imageEndpoint === null || ! is_admin()) {
            return false;
        }

        $screen = get_current_screen();
        if ($screen === null || $screen->base !== self::PAGE_SLUG) {
            return false;
        }

        return absint($this->text($_GET['candidate'] ?? '0')) > 0;
    }

    public function registerMenu(): void
    {
        if (! current_user_can(Capabilities::REVIEW) && ! current_user_can(Capabilities::APPROVE_DEANERY)) {
            return;
        }
        $reviewer = current_user_can(Capabilities::REVIEW);
        $badge = $this->queue->counts(
            get_current_user_id(),
            wp_get_current_user()->user_email,
            $reviewer
        )['awaiting_approval'];
        $label = 'Review queue' . ($badge > 0
            ? ' <span class="awaiting-mod count-' . (int) $badge . '"><span class="pending-count">'
                . (int) $badge . '</span></span>'
            : '');
        if ($reviewer) {
            add_submenu_page(
                'adct-parish-intake',
                'Parish Intake Review queue',
                $label,
                Capabilities::REVIEW,
                self::PAGE_SLUG,
                [$this, 'renderPage']
            );
        } else {
            add_menu_page(
                'Parish Intake Review queue',
                $label,
                Capabilities::APPROVE_DEANERY,
                self::PAGE_SLUG,
                [$this, 'renderPage'],
                'dashicons-yes-alt',
                58
            );
        }
    }

    public function renderPage(): void
    {
        [$userId, $email, $reviewer] = $this->identity();
        $tab = $this->tab($this->text($_GET['tab'] ?? 'awaiting_approval'));
        $search = substr(sanitize_text_field($this->text($_GET['search'] ?? '')), 0, 100);
        $counts = $this->queue->counts($userId, $email, $reviewer, $search);
        $detailId = absint($this->text($_GET['candidate'] ?? '0'));
        if ($detailId > 0) {
            $candidate = $this->scopedCandidate($detailId, $userId, $email, $reviewer);
            if ($candidate === null) {
                wp_die(esc_html('This candidate is not in your review queue.'), '', ['response' => 404]);
            }
            $this->renderDetail($candidate, $tab, $search, $reviewer);
            return;
        }
        $page = max(1, absint($this->text($_GET['paged'] ?? '1')));
        $total = $counts[$tab];
        $page = min($page, max(1, (int) ceil($total / self::PAGE_SIZE)));
        $rows = $this->queue->find($tab, $userId, $email, $reviewer, $search, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
        $hasApprovableRows = false;
        foreach ($rows as $row) {
            if ($this->canApproveRow($row)) {
                $hasApprovableRows = true;
                break;
            }
        }
        ?>
        <div class="wrap">
            <h1>Review queue</h1>
            <p>Awaiting approval shows every scoped approval item, including low-confidence and unknown-sender items. The other pending tabs show each candidate in one primary category. An unknown sender is not verified by assigning a parish.</p>
            <?php $this->renderNotice(); ?>
            <nav class="nav-tab-wrapper" aria-label="Review queue tabs">
                <?php foreach (ReviewQueueRepository::TABS as $key => $label) : ?>
                    <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"
                        href="<?php echo esc_url($this->url($key, $search)); ?>">
                        <?php echo esc_html($label . ' (' . $counts[$key] . ')'); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php if ($tab === 'recent_changes') : ?>
                <p>Instant changes and Revert are not available until the verified-contact change workflow (#71) exists. Ordinary approval updates are not instant changes. Deans see the changes to their own deaneries&rsquo; events on the front-end queue (<code>[adct_pi_approval_queue]</code>, issue #72) and can ask for the emailed revert link from there.</p>
            <?php else : ?>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />
                    <p class="search-box">
                        <label class="screen-reader-text" for="adct-pi-review-search">Search sender, parish or title</label>
                        <input type="search" id="adct-pi-review-search" name="search" value="<?php echo esc_attr($search); ?>" />
                        <button type="submit" class="button">Search</button>
                    </p>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />
                    <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>" />
                    <?php wp_nonce_field(self::ACTION, 'review_nonce'); ?>
                    <?php if ($this->canActOnTab($tab)) : ?>
                        <p>
                            <?php if ($hasApprovableRows) : ?>
                                <button class="button button-primary" name="bulk_action" value="approve" type="submit">Approve selected</button>
                            <?php endif; ?>
                            <button class="button" name="bulk_action" value="reject" type="submit">Reject selected</button>
                            <label for="adct-pi-reason">Reason for rejection (optional)</label>
                            <input id="adct-pi-reason" type="text" name="reason" maxlength="500" />
                            <?php if ($reviewer) : ?>
                                <label for="adct-pi-assign-parish">Assign parish</label>
                                <select id="adct-pi-assign-parish" name="parish_id">
                                    <option value="">Select an active parish</option>
                                    <?php foreach ($this->queue->activeParishes() as $parish) : ?>
                                        <option value="<?php echo esc_attr((string) $parish['id']); ?>">
                                            <?php echo esc_html((string) $parish['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="button" name="bulk_action" value="assign" type="submit">Assign to selected</button>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <table class="widefat striped">
                        <thead><tr>
                            <th scope="col"><span class="screen-reader-text">Select</span></th>
                            <th scope="col">Event</th><th scope="col">Sender / parish</th>
                            <th scope="col">Status / category</th><th scope="col">Confidence</th>
                            <th scope="col">Updated (UTC)</th><th scope="col">Decision</th>
                        </tr></thead>
                        <tbody>
                            <?php if ($rows === []) : ?>
                                <tr><td colspan="7">No candidates match this view.</td></tr>
                            <?php else : ?>
                                <?php foreach ($rows as $row) : ?>
                                    <?php $this->renderRow($row, $tab); ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </form>
                <?php if ($total > self::PAGE_SIZE) : ?>
                    <p class="tablenav-pages">
                        <?php for ($index = 1; $index <= (int) ceil($total / self::PAGE_SIZE); $index++) : ?>
                            <a class="button <?php echo $index === $page ? 'button-primary' : ''; ?>"
                                href="<?php echo esc_url(add_query_arg('paged', $index, $this->url($tab, $search))); ?>">
                                <?php echo esc_html((string) $index); ?>
                            </a>
                        <?php endfor; ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handleBulk(): void
    {
        [$userId, $email, $reviewer] = $this->identity();
        check_admin_referer(self::ACTION, 'review_nonce');
        $action = sanitize_key($this->text($_POST['bulk_action'] ?? ''));
        if (! in_array($action, ['approve', 'reject', 'assign'], true) || ($action === 'assign' && ! $reviewer)) {
            wp_die(esc_html('You cannot perform this review action.'), '', ['response' => 403]);
        }
        $tab = $this->tab($this->text($_POST['tab'] ?? 'awaiting_approval'));
        if (! $this->canActOnTab($tab)) {
            wp_die(esc_html('This view is read-only.'), '', ['response' => 403]);
        }
        $ids = $_POST['candidate_ids'] ?? [];
        $manualReviewIds = $_POST['manual_review_candidate_ids'] ?? [];
        if (! is_array($ids) || ! is_array($manualReviewIds)) {
            wp_die(esc_html('The selected review items are invalid.'), '', ['response' => 400]);
        }
        if ($action === 'approve' && $manualReviewIds !== []) {
            wp_die(
                esc_html('One or more selected candidates need manual resolution before they can be approved.'),
                '',
                ['response' => 409]
            );
        }
        if ($action !== 'approve') {
            $ids = array_merge($ids, $manualReviewIds);
        }
        if ($ids === [] || count($ids) > self::PAGE_SIZE) {
            wp_die(esc_html('Select between 1 and 25 review items.'), '', ['response' => 400]);
        }
        $parsed = [];
        foreach ($ids as $raw) {
            if (! is_string($raw) || preg_match('/\A[1-9][0-9]*\z/D', $raw) !== 1) {
                wp_die(esc_html('The selected review items are invalid.'), '', ['response' => 400]);
            }
            $parsed[] = (int) $raw;
        }
        if (count(array_unique($parsed)) !== count($parsed)) {
            wp_die(esc_html('A review item was selected more than once.'), '', ['response' => 400]);
        }
        $parishId = absint($this->text($_POST['parish_id'] ?? '0'));
        $reason = sanitize_textarea_field($this->text($_POST['reason'] ?? ''));
        if (strlen($reason) > 500 || ($action === 'assign' && $parishId < 1)) {
            wp_die(esc_html('Select an active parish or shorten the rejection reason.'), '', ['response' => 400]);
        }
        $changed = 0;
        $skipped = 0;
        $manual = 0;
        foreach ($parsed as $id) {
            try {
                if ($action === 'assign') {
                    $changed += (int) $this->queue->assignParish($id, $parishId, $userId, $email);
                    continue;
                }
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
                wp_die(esc_html('Candidate #' . $id . ': ' . $failure->getMessage()
                    . ' Earlier items may have been saved.'), '', ['response' => 409]);
            }
        }
        $search = substr(sanitize_text_field($this->text($_POST['search'] ?? '')), 0, 100);
        wp_safe_redirect(add_query_arg([
            'changed' => $changed, 'skipped' => $skipped, 'manual' => $manual,
        ], $this->url($tab, $search)));
        exit;
    }

    /** @param array<string, mixed> $row */
    private function renderRow(array $row, string $tab): void
    {
        $id = (int) $row['id'];
        $fields = $this->safeFields($row);
        $title = is_string($fields['title'] ?? null) ? $fields['title'] : '(Title unavailable)';
        $decided = ($row['decided_by'] ?? null) ?: ($row['approved_by'] ?? null);
        $canApprove = $this->canApproveRow($row);
        $canSelect = $this->canActOnTab($tab) && (
            $this->policy->canDecide($row)
            || $canApprove
        );
        $selectionName = $canApprove ? 'candidate_ids[]' : 'manual_review_candidate_ids[]';
        ?>
        <tr>
            <td><?php if ($canSelect) : ?>
                <input type="checkbox" name="<?php echo esc_attr($selectionName); ?>" value="<?php echo esc_attr((string) $id); ?>"
                    aria-label="<?php echo esc_attr($canApprove
                        ? 'Select candidate #' . $id
                        : 'Select candidate #' . $id . ' for rejection or assignment; manual resolution is required before approval'); ?>" />
                <?php endif; ?></td>
            <td><a href="<?php echo esc_url(add_query_arg('candidate', $id, $this->url($tab, ''))); ?>">
                <?php echo esc_html('#' . $id . ' ' . $title); ?></a></td>
            <td><?php echo esc_html((string) ($row['sender_email'] ?: 'No inbound sender')); ?><br />
                <?php echo esc_html((string) ($row['parish_name'] ?: 'Unassigned parish')); ?></td>
            <td><?php echo esc_html((string) $row['status']); ?><br />
                <span class="description"><?php echo esc_html((string) $row['category']); ?></span>
                <?php foreach ($this->sectionWarnings($row) as $warning) : ?>
                    <br /><strong><?php echo esc_html($warning); ?></strong>
                <?php endforeach; ?>
                <?php if (in_array($row['status'] ?? null, ['awaiting_approval', 'duplicate'], true)
                                    && ! $canApprove && $this->policy->canDecide($row)) : ?>
                                    <br /><strong><?php echo esc_html(
                                        // Same words for both statuses, because it is the same problem:
                                        // #217 made a duplicate decidable and #218 made it resolvable, but
                                        // until a person resolves it there is nothing to approve.
                                        'Manual resolution required before approval'
                                    ); ?></strong>
                                <?php endif; ?>
            </td>
            <td><?php echo esc_html(number_format((float) $row['confidence'] * 100, 0) . '%');
                $uncertain = $this->uncertainFieldCount($fields);

                if ($uncertain > 0) : ?>
                    <br /><span class="description"><?php echo esc_html(
                        $uncertain . ($uncertain === 1 ? ' field needs checking' : ' fields need checking')
                    ); ?></span>
                <?php endif; ?></td>
            <td><?php echo esc_html((string) $row['updated_at']); ?></td>
            <td><?php echo $decided !== null
                ? esc_html((string) $decided . ' at ' . (string) ($row['decided_at'] ?? $row['approved_at'] ?? 'unknown time'))
                : esc_html('Not decided'); ?></td>
        </tr>
        <?php
    }

    /**
     * Whether the row offers an approval that will not be refused.
     *
     * This drives the button, so it must never say no for a reason the reviewer cannot act on. An
     * unresolved date is a reason they *can* act on -- the date field is right there above the
     * buttons, and typing the real date is the fix -- so the button is rendered and the note is
     * turned into an acknowledgement instead. The checks that stay here are the ones about the
     * candidate rather than about what the reviewer types.
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
     * Whether the reviewer has answered #167's question: they have seen that the notice's date
     * could not be read, and they mean it.
     *
     * The checkbox is the acknowledgement for a notice that cannot be corrected from the form. A
     * date the reviewer has typed is its own acknowledgement, and does not also ask them to tick a
     * box about a problem they have just fixed. A form that posted nothing -- a GET render, or a
     * bulk action -- acknowledges nothing.
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

            return ($date !== '' && $date !== $this->storedEventDate($row))
                || isset($_POST[self::UNPARSED_DATE_FIELD]);
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * The candidate's own event date as stored, or null when it has none.
     *
     * @param array<string, mixed> $row
     */
    private function storedEventDate(array $row): ?string
    {
        $date = $this->policy->fields($row)['event_date'] ?? null;

        return is_string($date) && $date !== '' ? $date : null;
    }

    /**
     * Whether this candidate's stored `fields` can no longer be decoded.
     *
     * A rendering path must not throw on data it merely displays, so this screen
     * never lets the policy's strict `fields()` decide anything. But swallowing
     * the throw and calling the row "fine" would be its own lie, so the condition
     * is detected here, once, and named.
     *
     * What an unreadable row then gets is decided, not accidental:
     *
     * - No resolve control. A correct resolve *rewrites* `fields`, so offering the
     *   form would overwrite the very event we failed to parse.
     * - No approval. {@see canApproveRow()} already refuses it, on its own
     *   `fields()`, and the reviewer is told why.
     * - A visible notice, because a row that silently offers nothing looks like a
     *   row that simply has no match problem.
     *
     * @param array<string, mixed> $row
     */
    private function detailsAreUnreadable(array $row): bool
    {
        try {
            $this->policy->fields($row);

            return false;
        } catch (DomainException) {
            return true;
        }
    }

    /**
     * The per-field evidence behind the event score, as a read-only table.
     *
     * This is what a reviewer needs in order to judge the notice: not just "72%" but *which*
     * detail is unsupported. Values the parser invented are labelled as such and come first.
     *
     * @param array<string, mixed> $fields
     */
    private function renderFieldConfidence(array $fields): void
        {
            $stored = $fields['field_confidence'] ?? null;

            if (! is_array($stored) || ! is_array($stored['fields'] ?? null)) {
                return;
            }

            $labels = [
                'title' => 'Title',
                'event_date' => 'Date',
                'event_time' => 'Time',
                'parish_name' => 'Parish',
                'venue' => 'Venue',
                'event_type' => 'Event type',
                'contact' => 'Contact',
                'description' => 'Description',
                'recurrence' => 'Recurrence',
            ];
            $entries = [];

            foreach ($stored['fields'] as $name => $entry) {
                if (! is_string($name) || ! is_array($entry) || ! isset($entry['score']) || ! is_numeric($entry['score'])) {
                    continue;
                }

                $flags = array_values(array_filter(
                    (array) ($entry['flags'] ?? []),
                    static fn ($flag): bool => is_string($flag)
                ));

                $entries[$name] = [
                    'label' => $labels[$name] ?? $name,
                    'score' => (float) $entry['score'],
                    'origin' => is_string($entry['origin'] ?? null) ? $entry['origin'] : 'unknown',
                    'flags' => $flags,
                ];
            }

            if ($entries === []) {
                return;
            }

            $order = ['title', 'event_date', 'event_time', 'parish_name', 'venue', 'event_type', 'contact', 'description', 'recurrence'];
            usort($entries, static function (array $a, array $b) use ($order): int {
                return array_search($a['label'], $order, true) <=> array_search($b['label'], $order, true);
            });

            $coverage = isset($stored['coverage']) && is_numeric($stored['coverage'])
                ? (float) $stored['coverage']
                : null;
            ?>
            <tr><th scope="row">Field confidence</th><td>
                <p class="description" style="margin-top:0;">
                    Each detail is scored on the evidence behind it. <?php if ($coverage !== null) : ?>
                        The notice supports <?php echo esc_html(number_format($coverage * 100, 0) . '%'); ?> of the fields this event needs.
                    <?php endif; ?>
                </p>
                <table class="widefat striped" style="margin:0;">
                    <thead><tr>
                        <th scope="col">Field</th><th scope="col">Score</th>
                        <th scope="col">Evidence</th><th scope="col">Notes</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($entries as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html($entry['label']); ?></td>
                            <td><?php echo esc_html(number_format($entry['score'] * 100, 0) . '%'); ?></td>
                            <td><?php echo esc_html(str_replace('_', ' ', $entry['origin'])); ?></td>
                            <td><?php
                                echo esc_html($this->fieldConfidenceNote($entry));
                                                    ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </td></tr>
            <?php
        }

        /**
     * The reviewer-facing explanation of one field's evidence.
     *
     * @param array{label: string, score: float, origin: string, flags: list<string>} $entry
     */
    private function fieldConfidenceNote(array $entry): string
    {
        if ($entry['origin'] === 'unsupported') {
            return 'Not stated in the notice; the parser fell back to a guess.';
        }

        if ($entry['flags'] === []) {
            return '—';
        }

        return implode(', ', array_map(
            fn (string $flag): string => str_replace('_', ' ', $flag),
            $entry['flags']
        ));
    }

    /**
     * How many scored fields fall below the field threshold, for the queue list.
     *
     * @param array<string, mixed> $fields
     */
    private function uncertainFieldCount(array $fields): int
        {
            $stored = $fields['field_confidence'] ?? null;

            if (! is_array($stored) || ! is_array($stored['fields'] ?? null)) {
                return 0;
            }

            $threshold = (float) get_option(
                'adct_parish_intake_field_confidence_threshold',
                (string) ConfidenceScoringStage::DEFAULT_FIELD_THRESHOLD
            );
            $count = 0;

            foreach ($stored['fields'] as $entry) {
                if (is_array($entry) && isset($entry['score']) && is_numeric($entry['score']) && (float) $entry['score'] < $threshold) {
                    $count++;
                }
            }

            return $count;
        }

    /**
     * The single-candidate screen: the source email, its attachments, every
     * extracted field, and the edit/approve form.
     *
     * A GET only ever renders. Nothing here writes, so a prefetched or
     * bookmarked URL cannot change a candidate.
     *
     * @param array<string, mixed> $row
     */
    private function renderDetail(
        array $row,
        string $tab,
        string $search,
        bool $reviewer,
        ?CandidateEditResult $attempt = null
    ): void {
        $id = (int) $row['id'];
        $messageId = (int) ($row['message_id'] ?? 0);
        $message = $messageId > 0 && $this->messages !== null
            ? $this->messages->findById($messageId)
            : null;
        $attachments = $messageId > 0 && $this->attachments !== null
            ? $this->attachments->findByMessageId($messageId)
            : [];
        $editable = $this->canEdit($row);
        $canApprove = $editable && $this->canApproveRow($row);
        $poster = $this->posterImageFor($messageId);

        // Issue #177: the screen already names the ambiguity; this decides whether
        // to also offer the control that clears it. Deliberately driven by the
        // same policy the publisher and the decide path trust, so a candidate is
        // never shown a resolve button that would then be refused.
        //
        // `requiresMatchResolution()` reads `fields` and used to throw on JSON that
        // is not an object. That is right for the decide and publish routes, which
        // must refuse a candidate they cannot understand; it is fatal here, because
        // a screen that only displays a row has no licence to die on it — the
        // re-render path after a rejected save reaches exactly such a candidate.
        // The policy now answers either way, and this screen names the difference.
        $unreadable = $this->detailsAreUnreadable($row);
        // `canResolveMatch()`, not `$editable`: a duplicate can be resolved and
        // cannot be edited (#218).
        $needsResolution = ! $unreadable
            && $this->canResolveMatch($row)
            && $this->policy->requiresMatchResolution($row);

        // Issue #176: the resend panel is offered only for a candidate with saved
        // fields, because that is all a resend can render from. The cooldown state
        // is read here purely to explain the button; the service is what decides.
        $resend = $this->resendPanelState($row);

        $view = new CandidateDetailView();
        $view->render(
            $row,
            $message,
            $attachments,
            $this->queue->activeParishes(),
            $tab,
            $search,
            $editable,
            $canApprove,
            $attempt,
            $this->isDownloadable(...),
            $this->renderFieldConfidence(...),
            $this->posterPanel($poster),
            $editable && $messageId > 0,
            $needsResolution,
            $unreadable,
            $resend
        );
        $view->renderAuditTrail($this->queue->history($id));

                // Issue #176: on the detail path this is the only notice, because that
                // branch returns before the list screen's `renderNotice()` above. It is
                // rendered after the view — the view flushes the audit trail — so the
                // outcome lands at the top of the card rather than the foot of the page.
                $this->renderResendNotice();
            }

    /**
     * What the resend panel needs to draw itself for this candidate.
     *
     * Returned as a plain array rather than a view object so this screen — which
     * already speaks in arrays from the repository — does not have to learn
     * another type, and so a candidate with nothing to resend is one empty
     * array rather than a branch here.
     *
     * @param array<string, mixed> $row
     * @return array{available: bool, reason: string, lastResentAt: ?DateTimeImmutable, nextAllowedAt: ?DateTimeImmutable, now?: DateTimeImmutable}
     */
    private function resendPanelState(array $row): array
    {
        $unavailable = ['available' => false, 'reason' => '', 'lastResentAt' => null, 'nextAllowedAt' => null];
        $service = $this->resendService();

        if ($service === null) {
            $unavailable['reason'] = 'Resending a confirmation is not available on this site.';

            return $unavailable;
        }

        // "Saved" is the requirement, not "editable": a resend renders stored
        // fields and changes nothing, so it stays available after a decision,
        // which is exactly when a parish most often needs the preview again.
        if ($this->detailsAreUnreadable($row)) {
            $unavailable['reason'] = 'These event details cannot be read, so there is nothing to send.';

            return $unavailable;
        }

        $fields = $row['fields'] ?? null;
        $hasFields = is_string($fields) ? trim($fields) !== '' && trim($fields) !== '{}' : $fields !== null;

        if (! $hasFields || (int) ($row['message_id'] ?? 0) < 1) {
            $unavailable['reason'] = 'This event has no saved details yet, so there is nothing to send. '
                . 'Save the event details first, then resend the confirmation.';

            return $unavailable;
        }

        $id = (int) $row['id'];

        try {
            $lastResentAt = $service->lastResentAt($id);
        } catch (Throwable $failure) {
            error_log(
                '[ADCT Parish Intake] Could not read the confirmation resend history (' . get_class($failure) . ').'
            );

            return $unavailable;
        }

        return [
            'available' => true,
            'reason' => '',
            'lastResentAt' => $lastResentAt,
            'nextAllowedAt' => $lastResentAt === null
                ? null
                : $lastResentAt->modify('+' . ConfirmationEmailResendService::COOLDOWN_SECONDS . ' seconds'),
                    // Supplied rather than read by the view, so the panel's greyed-out
                    // state is decided by the same clock the cooldown is enforced on.
                    'now' => $service->now(),
                ];
            }

    /**
     * The resend outcome, on the detail screen.
     *
     * The detail branch of {@see renderPage()} returns before the list screen's
     * {@see renderNotice()}, so it needs its own. Every value here arrives from
     * this screen's own redirect and is re-parsed strictly; the recipient is only
     * ever shown back through `esc_html()`.
     */
    private function renderResendNotice(): void
    {
        $outcome = $this->text($_GET['resent'] ?? '');

        if ($outcome === '') {
            return;
        }

        $timezone = new DateTimeZone('Africa/Johannesburg');
        $next = $this->resentTimestamp('resent_next', $timezone);

        if ($outcome === 'cooldown') {
            $previous = $this->resentTimestamp('resent_at', $timezone);
            ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html(sprintf(
                'The confirmation preview for this event was already resent at %s. You may resend it again after %s.',
                $previous?->format('d/m/Y H:i') ?? 'an earlier time',
                $next?->format('d/m/Y H:i') ?? 'an hour later'
            )); ?></p></div>
            <?php
            return;
        }

        if ($outcome === 'failed') {
            ?>
            <div class="notice notice-error inline"><p><?php echo esc_html(
                'The confirmation preview could not be sent. Check the mail queue for details; the event itself '
                . 'is unchanged and nothing has been published.'
            ); ?></p></div>
            <?php
            return;
        }

        $sent = self::resendOutcome($outcome);

        if ($sent === null) {
            return;
        }

        $recipient = sanitize_email($this->text($_GET['resent_to'] ?? ''));
        $when = $this->resentTimestamp('resent_at', $timezone);
        $tail = $next === null
            ? ''
            : sprintf(' The next resend may be sent after %s.', $next->format('d/m/Y H:i'));

        if ($sent === ConfirmationEmailResendOutcome::SUPPRESSED) {
            $message = 'Test mode is on, so the confirmation preview was not sent. '
                . 'Nothing was emailed and the event is unchanged.';
        } elseif ($sent === ConfirmationEmailResendOutcome::FAILED) {
            $message = 'The confirmation preview could not be delivered. Check the mail queue for details.';
        } elseif ($sent === ConfirmationEmailResendOutcome::QUEUED) {
            $message = sprintf(
                'The confirmation preview was queued for %s%s. It goes out with the next mail batch.',
                $recipient,
                $when === null ? '' : ' at ' . $when->format('d/m/Y H:i')
            ) . $tail;
        } else {
            $message = sprintf(
                'The confirmation preview was sent to %s%s.',
                $recipient,
                $when === null ? '' : ' at ' . $when->format('d/m/Y H:i')
            ) . $tail;
        }

        $class = match ($sent) {
            ConfirmationEmailResendOutcome::SUPPRESSED,
            ConfirmationEmailResendOutcome::FAILED => 'notice-warning',
            default => 'notice-success',
        };
        ?>
        <div class="notice <?php echo esc_attr($class); ?> inline"><p><?php echo esc_html($message); ?></p></div>
        <?php
    }

    /**
     * The outcome named in a query argument, or null when it names none.
     *
     * Matched case-insensitively so the redirect can carry a lower-cased name
     * alongside its own `cooldown` and `failed` sentinels. A pure enum has no
     * `tryFrom()`, so the lookup is a loop over the known cases: an unknown name
     * must produce no notice at all rather than a fatal or an echoed guess.
     */
    private static function resendOutcome(string $name): ?ConfirmationEmailResendOutcome
    {
        $wanted = strtoupper($name);

        foreach (ConfirmationEmailResendOutcome::cases() as $case) {
            if ($case->name === $wanted) {
                return $case;
            }
        }

        return null;
    }

    /**
     * A timestamp carried back in a query argument, as an instant in the site's
     * timezone.
     *
     * Returns null for anything that is not a plain integer timestamp. The
     * arguments are attacker-reachable and are only ever a courtesy for the
     * notice, so an unreadable one must produce a vaguer sentence rather than a
     * fatal error on a screen that is otherwise fine.
     */
    private function resentTimestamp(string $key, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $raw = $this->text($_GET[$key] ?? '');

        if ($raw === '' || preg_match('/\A-?\d{1,11}\z/D', $raw) !== 1) {
            return null;
        }

        return (new DateTimeImmutable('@' . (int) $raw))->setTimezone($timezone);
    }

    /**
     * The first browser-readable poster on a candidate's message, or null.
     *
     * Only one poster is previewed: the point is to read the event beside the
     * form, and a notice with six photographs is not six events. Every
     * attachment is still listed and downloadable below, so nothing is hidden.
     */
    private function posterImageFor(int $messageId): ?PreviewableImage
    {
        if ($messageId < 1 || $this->attachments === null) {
            return null;
        }

        try {
            $rows = $this->attachments->findStoredImagesForMessage($messageId);
        } catch (Throwable $failure) {
            error_log(
                '[ADCT Parish Intake] Could not look for a poster preview (' . get_class($failure) . ').'
            );

            return null;
        }

        foreach ($rows as $row) {
            try {
                return new PreviewableImage(
                    (int) ($row['id'] ?? 0),
                    (int) ($row['message_id'] ?? 0),
                    (string) ($row['filename'] ?? ''),
                    (string) ($row['storage_path'] ?? ''),
                    (string) ($row['mime_type'] ?? ''),
                    (int) ($row['size_bytes'] ?? 0)
                );
            } catch (InvalidArgumentException) {
                // Not a browser-readable image, or not one this plugin stored.
                // Skip to the next rather than offering a preview that fails.
                continue;
            }
        }

        return null;
    }

    /**
     * The poster preview panel for the side column, as ready-to-print markup.
     *
     * Empty when there is nothing safe to show. The recognised text is put into
     * the description box so a secretary can correct a paragraph rather than
     * retype it; it is never posted back as OCR output (ADR 0018).
     *
     * The inline preview is scaled down to fit the column, but a poster is often
     * a photographed A4 sheet whose dates are unreadable at that size. The
     * sentence under the heading therefore offers the same bytes at their natural
     * size in a new tab: a plain link to the identical, already-authorised URL,
     * so there is no lightbox, no JavaScript and nothing new to authenticate.
     */
    private function posterPanel(?PreviewableImage $poster): string
    {
        if ($poster === null || $this->ocr === null || $this->imageEndpoint === null) {
            return '';
        }

        $imageUrl = $this->imageEndpoint->imageUrl($poster->attachmentId);

        return sprintf(
            '<div class="adct-pi-card">'
            . '<h2>Poster</h2>'
            . '<p class="description">Check the details against the poster before approving. '
            . 'You can also read the words off it in your own browser and correct them here. '
            . '<a href="%1$s" target="_blank" rel="noopener">Open the poster at full size</a> '
            . 'if the words here are too small.'
            . '</p>%2$s</div>',
            esc_url($imageUrl),
            $this->ocr->render(
                $imageUrl,
                'adct-pi-field-description',
                __('Read the text on this poster', 'adct-parish-intake')
            )
        );
    }

    /**
     * Whether a stored name still resolves to a real file on disk.
     *
     * A retention run can remove a file while the row survives, so the view
     * must be able to tell "no path recorded" apart from "the file is gone";
     * only the first case is worth a Download button.
     */
    private function isDownloadable(string $path): bool
    {
        $storage = $this->storage;
        if ($storage === null) {
            return false;
        }

        try {
            return is_file($storage->resolveAttachmentPath($path));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the editor may change this candidate at all.
     *
     * Anything already decided is read-only, so a later screen cannot rewrite an
     * approved event behind the publisher's back.
     *
     * @param array<string, mixed> $row
     */
    private function canEdit(array $row): bool
    {
        return ($row['status'] ?? '') === 'awaiting_approval'
            && empty($row['approved_by'])
            && empty($row['decided_at']);
    }

    /**
     * Whether the match-resolution panel may be shown and its action accepted.
     *
     * Wider than {@see canEdit()} on purpose, and by exactly one status. A
     * `duplicate` candidate is an unchanged notice that still needs a person to
     * say which match was wrong, and #217 already widened `canDecide()` to accept
     * it — so the queue links to the row and offers rejection, while `canEdit()`
     * refused it and the panel that #177 built for exactly these rows could never
     * act. That is the dead end issue #218 reports.
     *
     * The two must not be merged. `canEdit()` also gates the editor form and
     * `handleSave()` behind it, and `updateFields()` still refuses a duplicate's
     * status, so widening it would offer a form whose POST is rejected. The panel
     * is the only route a duplicate needs, and {@see assignParish()} is the only
     * write that accepts its status.
     *
     * @param array<string, mixed> $row
     */
    private function canResolveMatch(array $row): bool
    {
        return in_array($row['status'] ?? '', ['awaiting_approval', 'duplicate'], true)
            && empty($row['approved_by'])
            && empty($row['decided_at']);
    }

    private function validator(): CandidateEditValidator
    {
        return $this->validator ?? new CandidateEditValidator();
    }

    /**
     * The candidate editor's POST input, sanitised.
     *
     * Only the keys the validator reads are collected, so nothing the form does
     * not own can reach it.
     *
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
        $keys = [
            'all_day', 'featured', 'parish_id', 'venue_id', 'status_flag',
            'recurrence_preset', 'recurrence_weekday', 'recurrence_ordinal',
            'recurrence_month_day',
        ];
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_scalar($source[$key])) {
                $form[$key] = sanitize_text_field(wp_unslash((string) $source[$key]));
            }
        }

        return $form;
    }

    /**
     * The editor's POST handler: save, then optionally approve or reject.
     *
     * The order matters. The edit is committed and audited first, so a failure
     * to publish cannot lose what the reviewer typed; the decision then goes
     * through the same `decide()` the bulk form uses, keeping one code path for
     * approving a candidate.
     */
    public function handleSave(): void
    {
        [$userId, $email, $reviewer] = $this->identity();
        check_admin_referer(self::SAVE_ACTION, self::SAVE_NONCE);

        $id = absint($this->text($_POST['candidate_id'] ?? '0'));
        $tab = $this->tab($this->text($_POST['tab'] ?? 'awaiting_approval'));
        $search = substr(sanitize_text_field($this->text($_POST['search'] ?? '')), 0, 100);
        $mode = sanitize_key($this->text($_POST['save_mode'] ?? 'save'));
        if ($id < 1 || ! in_array($mode, ['save', 'approve', 'reject'], true)) {
            wp_die(esc_html('That candidate edit is not valid.'), '', ['response' => 400]);
        }
        $row = $this->scopedCandidate($id, $userId, $email, $reviewer);
        if ($row === null) {
            wp_die(esc_html('This candidate is not in your review queue.'), '', ['response' => 404]);
        }
        if (! $this->canEdit($row)) {
            wp_die(esc_html('This candidate has already been decided.'), '', ['response' => 409]);
        }
        // #167: read the posted form *before* the approval gate, not after it. The gate
        // decides on the *stored* row, but the row is only fixed by the save below, so
        // gating first would refuse the one approval that solves the problem: the
        // reviewer who types the real date and approves in the same submission. The
        // read is pure, so moving it earlier changes no other ordering.
        $form = $this->readEditForm($_POST);
        if ($mode === 'approve' && ! $this->canApproveRow($row)) {
            wp_die(
                esc_html('This candidate needs manual resolution before it can be approved.'),
                '',
                ['response' => 409]
            );
        }

        $result = $this->validator()->validate(
            $this->safeFields($row),
            $this->storedRecurrence($row),
            $form
        );
        if ($result->hasErrors()) {
            // Re-render rather than redirect: the point is to show the reviewer
            // their own values with the problems attached.
            $this->renderDetail(
                array_merge($row, $this->fieldsFrom($result)),
                $tab,
                $search,
                $reviewer,
                $result
            );

            return;
        }

        // The redirect is deliberately outside the try block. `wp_safe_redirect()`
                // ends the request by exiting, and any Throwable raised on the way out of
                // it belongs to the shutdown path, not to the save. Catching it here would
                // turn a finished save into a "could not be saved" error page.
                $reason = substr(sanitize_textarea_field($this->text($_POST['reason'] ?? '')), 0, 500);

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
                        wp_die(esc_html('This candidate was decided while you were editing it.'), '', ['response' => 409]);
                    }
                    // An unchanged *text edit* is not an unchanged *decision*: see the same
                                        // branch in `FrontEndApprovalQueue::handleSave()`. Gating the
                                        // decision on the save reporting a change meant a reviewer who
                                        // pressed "Save and approve" on an already-correct candidate was
                                        // redirected with `saved=unchanged` and no decision at all.
                                        // `decide()` re-resolves the live scope and returns
                                        // `already_decided`, `manual_review` or `retry` on its own.
                                        $decision = null;
                                        if ($mode !== 'save') {
                                            $decision = $this->queue->decide($id, $mode, $userId, $email, $reviewer, $reason);
                                            if ($decision === 'decided' && $mode === 'approve') {
                                                $this->publisher->publish($id);
                                            }
                                        }
                } catch (DomainException $failure) {
                    wp_die(esc_html($failure->getMessage()), '', ['response' => 409]);
                } catch (Throwable $failure) {
                    error_log('[ADCT Parish Intake] Candidate save failed: ' . $failure->getMessage());
                    wp_die(esc_html('The candidate could not be saved. Try again in a moment.'), '', ['response' => 500]);
                }

                $query = ['candidate' => $id, 'tab' => $tab, 'search' => $search, 'saved' => $saved];
                if ($decision !== null) {
                    $query['decision'] = $decision;
                }

                wp_safe_redirect(add_query_arg($query, $this->url($tab, $search)));
                exit;
            }

    /**
     * Open a blank candidate to type an event in by hand, beside a stored poster.
     *
     * This only ever creates the empty row. Everything after that is the
     * ordinary editor and the ordinary approval route, so a hand-typed event is
     * not a second way into the publisher: the new row records no approver, and
     * `handleSave()` is the only thing that can give it one.
     *
     * The live relationship is re-resolved here rather than trusted from the
     * form. The candidate the reviewer names decides the message, the parish and
     * the routing; the attachment is then checked against that same message
     * server-side, so a crafted POST cannot start an event from somebody else's
     * poster.
     */
    public function handleCreateManual(): void
    {
        [$userId, $email, $reviewer] = $this->identity();
        check_admin_referer(self::CREATE_MANUAL_ACTION, self::CREATE_MANUAL_NONCE);

        $candidateId = absint($this->text($_POST['candidate'] ?? '0'));
        $attachmentId = absint($this->text($_POST['attachment_id'] ?? '0'));
        $tab = $this->tab($this->text($_POST['tab'] ?? 'awaiting_approval'));
        $search = substr(sanitize_text_field($this->text($_POST['search'] ?? '')), 0, 100);

        if ($candidateId < 1) {
            wp_die(esc_html('That candidate is not valid.'), '', ['response' => 400]);
        }
        $source = $this->scopedCandidate($candidateId, $userId, $email, $reviewer);
        if ($source === null) {
            wp_die(esc_html('This candidate is not in your review queue.'), '', ['response' => 404]);
        }
        // The source must still be open. Copying details from a candidate that
        // has already been approved would produce a second event nobody approved.
        if (! $this->canEdit($source)) {
            wp_die(esc_html('This candidate has already been decided.'), '', ['response' => 409]);
        }

        $messageId = $this->queue->findMessageOf($candidateId);
        $attachment = null;
        if ($attachmentId > 0) {
            $attachment = $this->attachmentRecord($attachmentId);
            if ($attachment === null || $messageId !== (int) ($attachment['message_id'] ?? 0)) {
                wp_die(
                    esc_html('That file is not attached to this email.'),
                    '',
                    ['response' => 404]
                );
            }
        }

        try {
            $created = $this->queue->createManualCandidate(
                $candidateId,
                $email,
                $reviewer,
                $attachmentId > 0 ? $attachmentId : null
            );
        } catch (DomainException $failure) {
            wp_die(esc_html($failure->getMessage()), '', ['response' => 409]);
        } catch (Throwable $failure) {
            error_log('[ADCT Parish Intake] Manual candidate create failed: ' . $failure->getMessage());
            wp_die(esc_html('The new event could not be started. Try again in a moment.'), '', [
                'response' => 500,
            ]);
        }

        wp_safe_redirect(add_query_arg(
            [
                'candidate' => $created,
                'tab' => $tab,
                'search' => $search,
                'created' => 1,
            ],
            self::queueUrl($tab, $search)
        ));
        exit;
    }

    /**
     * A stored attachment row, when it is one this plugin still holds.
     *
     * Null means "no such stored attachment", which is what the callers above
     * turn into a 404. Deliberately returns the row rather than a file path: the
     * caller only ever needs to compare which message it belongs to.
     *
     * @return array<string, mixed>|null
     */
    private function attachmentRecord(int $attachmentId): ?array
    {
        if ($attachmentId < 1 || $this->attachments === null) {
            return null;
        }

        try {
            $row = $this->attachments->find($attachmentId);
        } catch (Throwable $failure) {
            error_log(
                '[ADCT Parish Intake] Could not read attachment ' . $attachmentId
                . ' (' . get_class($failure) . ').'
            );

            return null;
        }

        return $row === null ? null : $row;
    }

    /**
     * Serve the stored original message for a candidate.
     *
     * The reviewer proves which candidate they are looking at by posting its ID;
     * the message is then chosen from the database, never from the request. That
     * keeps a stored path out of any URL and stops one reviewer from walking the
     * message table.
     */
    public function handleRawMessage(): void
    {
        [$userId, $email, $reviewer] = $this->identity();
        check_admin_referer(self::RAW_MESSAGE_ACTION, self::SOURCE_NONCE);
        $candidateId = absint($this->text($_POST['candidate'] ?? '0'));
        if ($candidateId < 1 || $this->scopedCandidate($candidateId, $userId, $email, $reviewer) === null) {
            wp_die(esc_html('This candidate is not in your review queue.'), '', ['response' => 404]);
        }
        $this->sendFile($this->rawMessageFor($candidateId));
    }

    /**
     * The stored raw message for a candidate, or null when it is not available.
     *
     * Split out from the handler so the authorization is a plain return value
     * and can be exercised without ending the request.
     */
    private function rawMessageFor(int $candidateId): ?array
    {
        $messageId = $this->queue->findMessageOf($candidateId);
        $message = $messageId === null || $this->messages === null
            ? null
            : $this->messages->findById($messageId);
        $path = $message === null ? null : $this->nullableString($message['raw_path'] ?? null);
        if ($path === null) {
            return null;
        }

        return [
            'path' => $path,
            'mime_type' => 'message/rfc822',
            'filename' => 'message-' . $messageId . '.eml',
        ];
    }

    /**
     * Serve one stored attachment belonging to a candidate's message.
     *
     * The attachment is looked up by its own ID and its message is checked
     * against the candidate the reviewer was viewing, so one reviewer cannot
     * walk the attachment table.
     */
    public function handleAttachment(): void
    {
        [$userId, $email, $reviewer] = $this->identity();
        check_admin_referer(self::ATTACHMENT_ACTION, self::SOURCE_NONCE);
        $attachmentId = absint($this->text($_POST['attachment_id'] ?? '0'));
        $candidateId = absint($this->text($_POST['candidate'] ?? '0'));
        if ($attachmentId < 1 || $candidateId < 1) {
            wp_die(esc_html('That attachment is not available.'), '', ['response' => 404]);
        }
        if ($this->scopedCandidate($candidateId, $userId, $email, $reviewer) === null) {
            wp_die(esc_html('This candidate is not in your review queue.'), '', ['response' => 404]);
        }
        $this->sendFile($this->attachmentFor($attachmentId, $candidateId));
    }

    /**
     * Resolve an ambiguous match from the candidate's own screen (issue #177).
     *
     * The candidate detail screen already said *why* a match was ambiguous —
     * {@see CandidateDetailView::renderProvenance()} shows the match kind and the
     * parish — but until now it offered nothing to do about it, and the reviewer
     * had to find the queue's separate assignment control. This route is that
     * control, scoped exactly like deciding and routed through exactly the same
     * repository method, so there is one code path and one audit row.
     *
     * A reviewer may pick a different parish and venue, or deliberately leave the
     * candidate unassigned — an unassigned candidate matches no dean, so it is
     * the archdiocese's to approve, and saying so is a real answer rather than a
     * shrug. Both outcomes clear the two `fields` keys that block approval, in the
     * same transaction as the parish change, which is what makes the candidate
     * approvable afterwards.
     */
    public function handleResolveMatch(): void
    {
        [$userId, $email, $reviewer] = $this->identity();
        check_admin_referer(self::RESOLVE_MATCH_ACTION, self::RESOLVE_MATCH_NONCE);

        $id = absint($this->text($_POST['candidate_id'] ?? '0'));
        $tab = $this->tab($this->text($_POST['tab'] ?? 'awaiting_approval'));
        $search = substr(sanitize_text_field($this->text($_POST['search'] ?? '')), 0, 100);
        if ($id < 1) {
            wp_die(esc_html('That candidate is not valid.'), '', ['response' => 400]);
        }

        // Re-resolved on every POST, never trusted from the form: a dean can only
        // resolve a candidate in their own scope, exactly as when deciding it.
        $row = $this->scopedCandidate($id, $userId, $email, $reviewer);
        if ($row === null) {
            wp_die(esc_html('This candidate is not in your review queue.'), '', ['response' => 404]);
        }
        if (! $this->canResolveMatch($row)) {
            wp_die(esc_html('This candidate has already been decided.'), '', ['response' => 409]);
        }

        // An empty `parish_id` is the deliberate "leave it unassigned" choice, so it
        // is not a validation failure. `CandidateFieldSet::intOrNull()` maps 0,
        // negatives and non-numeric input to null, so a crafted POST cannot smuggle a
        // negative or non-numeric id through.
        $parishId = CandidateFieldSet::intOrNull($this->text($_POST['parish_id'] ?? '')) ?? 0;
        $venueId = CandidateFieldSet::intOrNull($this->text($_POST['venue_id'] ?? ''));
        if ($venueId !== null && $venueId < 1) {
            wp_die(esc_html('That venue is not valid.'), '', ['response' => 400]);
        }
        if ($parishId < 1 && $venueId !== null) {
            wp_die(
                esc_html('Choose a parish before selecting a venue, or leave the venue blank.'),
                '',
                ['response' => 400]
            );
        }

        try {
            $this->queue->assignParish($id, $parishId, $userId, $email, $reviewer, $venueId, true);
        } catch (DomainException $failure) {
            wp_die(esc_html($failure->getMessage()), '', ['response' => 409]);
        } catch (Throwable $failure) {
            error_log('[ADCT Parish Intake] Match resolution failed: ' . $failure->getMessage());
            wp_die(esc_html('The match could not be resolved. Try again in a moment.'), '', [
                'response' => 500,
            ]);
        }

        // Outside the try, so a success redirect can never become a 500.
        wp_safe_redirect(add_query_arg(
            ['candidate' => $id, 'tab' => $tab, 'search' => $search, 'resolved' => 1],
            self::queueUrl($tab, $search)
        ));
        exit;
    }
    /**
     * Queue a fresh copy of this candidate's confirmation preview for the parish.
     *
     * The cooldown is *not* checked here. It is checked by the booking the service
     * calls, inside the transaction that writes the audit row, which is the only
     * place a hand-crafted POST cannot get past. This handler only has to make
     * sure the request is legitimate and tell the reviewer what happened.
     */
    public function handleResendConfirmation(): void
    {
        [$userId, $email, $reviewer] = $this->identity();
        check_admin_referer(self::RESEND_CONFIRMATION_ACTION, self::RESEND_CONFIRMATION_NONCE);

        $id = absint($this->text($_POST['candidate_id'] ?? '0'));
        $tab = $this->tab($this->text($_POST['tab'] ?? 'awaiting_approval'));
        $search = substr(sanitize_text_field($this->text($_POST['search'] ?? '')), 0, 100);
        if ($id < 1) {
            wp_die(esc_html('That candidate is not valid.'), '', ['response' => 400]);
        }

        $service = $this->resendService();

        if ($service === null) {
            wp_die(esc_html('Resending a confirmation is not available on this site.'), '', [
                'response' => 400,
            ]);
        }

        // Re-resolved on every POST, never trusted from the form: a dean can only
        // resend for a candidate in their own scope, exactly as when editing it.
        $row = $this->scopedCandidate($id, $userId, $email, $reviewer);
        if ($row === null) {
            wp_die(esc_html('This candidate is not in your review queue.'), '', ['response' => 404]);
        }

        try {
            $result = $service->resend($id, $email);
        } catch (ConfirmationEmailResendCooldownException $failure) {
            // Not a 409 and not an error: the reviewer did exactly the right thing
            // and only the hour is against them. Say when, and say when they may
            // come back, then put them back on the same candidate.
            wp_safe_redirect(add_query_arg(
                [
                    'candidate' => $id,
                    'tab' => $tab,
                    'search' => $search,
                    'resent' => 'cooldown',
                    'resent_at' => $failure->lastResentAt->getTimestamp(),
                    'resent_next' => $failure->retryAfter->getTimestamp(),
                ],
                self::queueUrl($tab, $search)
            ));
            exit;
        } catch (DomainException $failure) {
            wp_die(esc_html($failure->getMessage()), '', ['response' => 409]);
        } catch (Throwable $failure) {
            error_log('[ADCT Parish Intake] Confirmation resend failed: ' . $failure->getMessage());
            wp_safe_redirect(add_query_arg(
                [
                    'candidate' => $id,
                    'tab' => $tab,
                    'search' => $search,
                    'resent' => 'failed',
                ],
                self::queueUrl($tab, $search)
            ));
            exit;
        }

        // Outside the try, so a success redirect can never become a 500.
        //
        // The notice is rebuilt from these values rather than stashed in a
        // transient, so it cannot outlive the redirect or be read by anyone but
        // the reviewer who just pressed the button on this candidate. They are
        // all re-parsed strictly on the way back in, and escaped on the way out.
        wp_safe_redirect(add_query_arg(
            [
                'candidate' => $id,
                'tab' => $tab,
                'search' => $search,
                // Lower-cased to match the sentinels the notice uses for its own two
                    // outcomes, so one query argument carries one shape rather
                    // than a mix of `cooldown` and `QUEUED`.
                    'resent' => strtolower($result->outcome->name),
                'resent_at' => $result->sentAt->getTimestamp(),
                'resent_next' => $result->nextAllowedAt->getTimestamp(),
                'resent_to' => $result->recipient,
            ],
            self::queueUrl($tab, $search)
        ));
        exit;
    }

    /**
     * The candidate, but only if this reviewer is allowed to see it.
     *
     * A reviewer with the archdiocese-wide capability sees every candidate; a
     * deanery approver only sees candidates from their own deaneries. The two
     * are different questions, so they are asked separately rather than through
     * one boolean.
     */
    private function scopedCandidate(int $candidateId, int $userId, string $email, bool $reviewer): ?array
    {
        return $reviewer
            ? $this->queue->findScopedForReviewer($candidateId, $userId, $email)
            : $this->queue->findScoped($candidateId, $userId, $email, false);
    }

    /**
     * The stored file for an attachment, when it belongs to the candidate.
     *
     * The message is compared rather than trusted from the request, so a
     * reviewer cannot enumerate the attachment table through this screen. Null
     * means "no such attachment on that candidate"; a missing file is a
     * separate answer, so the panel can say so rather than showing a button
     * that fails.
     *
     * @return array{path: string, mime_type: string, filename: string}|null
     */
    private function attachmentFor(int $attachmentId, int $candidateId): ?array
    {
        if ($attachmentId < 1 || $this->attachments === null) {
            return null;
        }
        $attachment = $this->attachments->find($attachmentId);
        if ($attachment === null) {
            return null;
        }
        if ($this->queue->findMessageOf($candidateId) !== (int) ($attachment['message_id'] ?? 0)) {
            return null;
        }
        $path = $this->nullableString($attachment['storage_path'] ?? null);
        if ($path === null) {
            return null;
        }

        return [
            'path' => $path,
            'mime_type' => $this->downloadMimeType($this->nullableString($attachment['mime_type'] ?? null)),
            'filename' => $this->nullableString($attachment['filename'] ?? null) ?? 'attachment-' . $attachmentId,
        ];
    }

    /**
     * A MIME type recorded from a parish email is untrusted input, and it is
     * about to become a response header value. Only a well-formed type is
     * allowed through; anything else is served as an opaque byte stream.
     */
    private function downloadMimeType(?string $mimeType): string
    {
        if ($mimeType === null) {
            return 'application/octet-stream';
        }
        $clean = strtolower(trim($mimeType));
        if (preg_match('#^application/[a-z0-9.+-]+$#', $clean) === 1) {
            return $clean;
        }
        if (preg_match('#^(image|audio|video|message)/[a-z0-9.+-]+$#', $clean) === 1) {
            return $clean;
        }
        if ($clean === 'text/plain' || $clean === 'text/csv' || $clean === 'text/calendar') {
            return $clean;
        }

        return 'application/octet-stream';
    }

    /**
     * Stream a stored file, or fail loudly.
     *
     * The path is validated and resolved by the storage adapter, which rejects
     * anything that is not a stored name and refuses symbolic links, so this
     * cannot be turned into a directory traversal.
     *
     * @param array{path: string, mime_type: string, filename: string}|null $file
     */
    private function sendFile(?array $file): void
    {
        if ($file === null) {
            wp_die(esc_html('That file is not available.'), '', ['response' => 404]);
        }
        $storage = $this->storage;
        if ($storage === null) {
            wp_die(esc_html('That file is not available.'), '', ['response' => 404]);
        }
        try {
            $path = $storage->resolveAttachmentPath($file['path']);
        } catch (Throwable $unavailable) {
            wp_die(esc_html('That file is not available.'), '', ['response' => 404]);
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_DOWNLOAD_BYTES) {
            wp_die(esc_html('That file is too large to download here.'), '', ['response' => 413]);
        }
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            wp_die(esc_html('That file could not be read.'), '', ['response' => 500]);
        }
        // A stored filename is parish-supplied, so it is reduced to a harmless
        // token and never sent back as a header value.
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['filename']) ?? 'attachment';
        nocache_headers();
        header('Content-Type: ' . $this->downloadMimeType($file['mime_type']));
        header('Content-Length: ' . (string) strlen($contents));
        header("Content-Disposition: attachment; filename=\"{$safeName}\"");
        header('X-Content-Type-Options: nosniff');
        echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary download.
        exit;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * The candidate's stored recurrence JSON.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function storedRecurrence(array $row): array
    {
        return CandidateFieldSet::decodeFields($row['recurrence'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     * @param CandidateEditResult $result
     * @return array<string, mixed>
     */
    private function fieldsFrom(CandidateEditResult $result): array
    {
        return ['fields' => json_encode($result->values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function safeFields(array $row): array
    {
        try {
            return $this->policy->fields($row);
        } catch (DomainException) {
            return ['title' => '(Invalid event details; needs manual repair)'];
        }
    }

    private function excerpt(string $value): string
    {
        $value = substr($value, 0, 2000);
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }
        return $value;
    }

    /** @param array<string, mixed> $row
     *  @return list<string>
     */
    private function sectionWarnings(array $row): array
    {
        $notes = json_decode((string) ($row['notes'] ?? ''), true);
        if (! is_array($notes) || ! array_is_list($notes)) {
            return [];
        }
        $warnings = [];
        foreach ($notes as $note) {
            if (! is_string($note)) {
                continue;
            }
            if ($note === ReviewQueueRepository::MANUAL_NOTE) {
                // Not a parser problem: this row exists because a person is
                // typing the event in. Say so plainly, and do not imply the
                // parser had a go at the poster and gave up.
                $warnings[] = 'A person entered this event by hand from the email.';
                continue;
            }
            if (preg_match('/\Apossible_missed_event_after_skipped_section:\s*(\d+)\z/D', $note, $matches) === 1) {
                $warnings[] = 'Possible missed event after ' . (int) $matches[1] . ' skipped sections.';
            } elseif (UnparsedDateTimeCandidate::describe($note) !== null) {
                // #167: the queue row is a summary, but it must not be a summary that
                // hides the reason a field is empty. Say it in words here too, so a
                // reviewer who never opens the detail screen still learns that the
                // notice carried a date the parser could not read.
                $warnings[] = UnparsedDateTimeCandidate::describe($note);
            } elseif (str_starts_with($note, 'skipped_sections:')) {
                $warnings[] = 'The parser skipped sections of this message.';
            }
        }
        return array_values(array_unique($warnings));
    }

    private function canActOnTab(string $tab): bool
    {
        return in_array($tab, ['awaiting_approval', 'unknown_senders', 'low_confidence', 'failed'], true);
    }

    /** @return array{int, string, bool} */
    private function identity(): array
    {
        $reviewer = current_user_can(Capabilities::REVIEW);
        if (! $reviewer && ! current_user_can(Capabilities::APPROVE_DEANERY)) {
            wp_die(esc_html('You cannot view the review queue.'), '', ['response' => 403]);
        }
        $user = wp_get_current_user();
        if (! $user instanceof \WP_User || (int) $user->ID < 1 || (int) $user->user_status !== 0) {
            wp_die(esc_html('A current active account is required.'), '', ['response' => 403]);
        }
        return [(int) $user->ID, (string) $user->user_email, $reviewer];
    }

    private function tab(string $value): string
    {
        return array_key_exists($value, ReviewQueueRepository::TABS) ? $value : 'awaiting_approval';
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? wp_unslash($value) : '';
    }

    private function url(string $tab, string $search): string
    {
        return self::queueUrl($tab, $search);
    }

    /**
     * The queue URL without the detail parameter, for the "back" link.
     */
    public static function queueUrl(string $tab, string $search): string
    {
        return add_query_arg(
            ['page' => self::PAGE_SLUG, 'tab' => $tab, 'search' => $search],
            admin_url('admin.php')
        );
    }

    private function renderNotice(): void
    {
        if (isset($_GET['resolved'])) {
            ?>
            <div class="notice notice-success"><p><?php echo esc_html(
                'The ambiguous match is resolved and recorded in the history below. The event details are '
                . 'still yours to check before you approve it; nothing has been published.'
            ); ?></p></div>
            <?php
            return;
        }

        if (isset($_GET['created'])) {
            ?>
            <div class="notice notice-success"><p><?php echo esc_html(
                'A blank event has been created below, beside the poster. Fill in what the poster says, '
                . 'check it against the poster, then choose Approve. Nothing is published until you do.'
            ); ?></p></div>
            <?php
            return;
        }
        if (! isset($_GET['changed'], $_GET['skipped'], $_GET['manual'])) {
            return;
        }
        $changed = absint($this->text($_GET['changed']));
        $skipped = absint($this->text($_GET['skipped']));
        $manual = absint($this->text($_GET['manual']));
        ?>
        <div class="notice notice-info"><p><?php echo esc_html(sprintf(
            '%d updated, %d already decided or unchanged, %d require manual resolution before approval.',
            $changed, $skipped, $manual
        )); ?></p></div>
        <?php
    }
}
