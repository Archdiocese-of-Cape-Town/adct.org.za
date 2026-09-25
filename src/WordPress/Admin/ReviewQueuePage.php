<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use DomainException;

final class ReviewQueuePage
{
    public const PAGE_SLUG = 'adct-parish-intake-review';
    private const ACTION = 'adct_pi_review_bulk';
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly ReviewQueueRepository $queue,
        private readonly CandidatePublisher $publisher,
        private readonly ReviewQueuePolicy $policy = new ReviewQueuePolicy()
    ) {
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
            $candidate = $this->queue->findScoped($detailId, $userId, $email, $reviewer);
            if ($candidate === null) {
                wp_die(esc_html('This candidate is not in your review queue.'), '', ['response' => 404]);
            }
            $this->renderDetail($candidate, $tab, $search);
            return;
        }
        $page = max(1, absint($this->text($_GET['paged'] ?? '1')));
        $total = $counts[$tab];
        $page = min($page, max(1, (int) ceil($total / self::PAGE_SIZE)));
        $rows = $this->queue->find($tab, $userId, $email, $reviewer, $search, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
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
                <p>Instant changes and Revert are not available until the verified-contact change workflow (#71) exists. Ordinary approval updates are not instant changes.</p>
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
                            <button class="button button-primary" name="bulk_action" value="approve" type="submit">Approve selected</button>
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
                                    <?php $this->renderRow($row, $tab, $email); ?>
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
        if (! is_array($ids) || $ids === [] || count($ids) > self::PAGE_SIZE) {
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
    private function renderRow(array $row, string $tab, string $email): void
    {
        $id = (int) $row['id'];
        $fields = $this->safeFields($row);
        $title = is_string($fields['title'] ?? null) ? $fields['title'] : '(Title unavailable)';
        $decided = ($row['decided_by'] ?? null) ?: ($row['approved_by'] ?? null);
        $canSelect = $this->canActOnTab($tab) && (
            $this->policy->canDecide($row)
            || ($row['status'] === 'awaiting_approval' && $decided !== null
                && strcasecmp((string) ($row['approved_by'] ?? ''), $email) === 0)
        );
        $canApprove = false;
        if ($this->policy->canDecide($row)) {
            try {
                $canApprove = $this->policy->canBulkApprove($row);
            } catch (DomainException) {
                // The row stays visible for manual repair, never approval.
            }
        }
        ?>
        <tr>
            <td><?php if ($canSelect) : ?>
                <input type="checkbox" name="candidate_ids[]" value="<?php echo esc_attr((string) $id); ?>"
                    aria-label="<?php echo esc_attr('Select candidate #' . $id); ?>" />
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
                <?php if (($row['status'] ?? null) === 'awaiting_approval'
                    && ! $canApprove && $this->policy->canDecide($row)) : ?>
                    <br /><strong>Manual resolution required before approval</strong>
                <?php endif; ?>
            </td>
            <td><?php echo esc_html(number_format((float) $row['confidence'] * 100, 0) . '%'); ?></td>
            <td><?php echo esc_html((string) $row['updated_at']); ?></td>
            <td><?php echo $decided !== null
                ? esc_html((string) $decided . ' at ' . (string) ($row['decided_at'] ?? $row['approved_at'] ?? 'unknown time'))
                : esc_html('Not decided'); ?></td>
        </tr>
        <?php
    }

    /** @param array<string, mixed> $row */
    private function renderDetail(array $row, string $tab, string $search): void
    {
        $fields = $this->safeFields($row);
        ?>
        <div class="wrap"><h1>Candidate #<?php echo esc_html((string) $row['id']); ?></h1>
            <p><a href="<?php echo esc_url($this->url($tab, $search)); ?>">Back to review queue</a></p>
            <p>This is a read-only preview. Resolve ambiguous matches and edit event details in the future candidate editor (#61); this page does not publish on GET.</p>
            <table class="widefat striped"><tbody>
                <?php foreach ([
                    'title' => 'Title', 'event_date' => 'Date', 'event_time' => 'Time',
                    'venue' => 'Venue', 'description' => 'Description',
                ] as $key => $label) : ?>
                    <tr><th scope="row"><?php echo esc_html($label); ?></th>
                        <td><?php echo esc_html(is_string($fields[$key] ?? null) ? $this->excerpt($fields[$key]) : '—'); ?></td></tr>
                <?php endforeach; ?>
                <tr><th scope="row">Sender</th><td><?php echo esc_html((string) ($row['sender_email'] ?: 'No inbound sender')); ?></td></tr>
                <tr><th scope="row">Parish</th><td><?php echo esc_html((string) ($row['parish_name'] ?: 'Unassigned')); ?></td></tr>
                <tr><th scope="row">Status</th><td><?php echo esc_html((string) $row['status']); ?></td></tr>
                <?php foreach ($this->sectionWarnings($row) as $warning) : ?>
                    <tr><th scope="row">Parser warning</th><td><?php echo esc_html($warning); ?></td></tr>
                <?php endforeach; ?>
                <tr><th scope="row">Decision</th><td><?php echo esc_html((string) ($row['decided_by'] ?: 'Not decided')
                    . ' / ' . (string) ($row['decided_at'] ?: '—')); ?></td></tr>
            </tbody></table>
        </div>
        <?php
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
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
            if (preg_match('/\Apossible_missed_event_after_skipped_section:\s*(\d+)\z/D', $note, $matches) === 1) {
                $warnings[] = 'Possible missed event after ' . (int) $matches[1] . ' skipped sections.';
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
        return add_query_arg(
            ['page' => self::PAGE_SLUG, 'tab' => $tab, 'search' => $search],
            admin_url('admin.php')
        );
    }

    private function renderNotice(): void
    {
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
