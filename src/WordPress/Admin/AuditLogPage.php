<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditEntry;
use ADCT\ParishIntake\Core\Audit\AuditLogReader;
use ADCT\ParishIntake\Core\Audit\AuditQuery;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The audit log screen: who approved, refused, edited, published and reverted
 * what, and when.
 *
 * Read-only by design. An audit trail that can be edited or deleted is not
 * evidence of anything, so this screen deliberately offers no write action at
 * all: no nonce field, no POST handler, no form that changes anything. The
 * filters are a GET form because filtering changes nothing.
 *
 * Two limits are structural rather than advisory. The window defaults to three
 * months and can never reach back further than the retention horizon, and the
 * repository caps how many rows one request will read. An unbounded audit table
 * on shared hosting with a 90 second limit is a timeout, not a screen.
 */
final class AuditLogPage
{
    private const PAGE_SLUG = 'adct-parish-intake-audit-log';

    /**
     * Rows per page. Small enough that a page renders well inside the request
     * limit even with the details column expanded.
     */
    private const PAGE_SIZE = 25;

    /**
     * The window shown when the visitor has not asked for one. Three months is
     * long enough to answer "who approved the Palm Sunday notice", short enough
     * that the default view never touches the whole table.
     */
    private const DEFAULT_WINDOW_MONTHS = 3;

    /** The longest window the screen will offer, matching E2.7 retention. */
    private const MAXIMUM_WINDOW_MONTHS = AuditQuery::MAXIMUM_WINDOW_MONTHS;

    /** The windows the date filter offers. */
    private const WINDOW_CHOICES = [1, 3, 6, 12, 24];

    public function __construct(
        private AuditLogReader $audit,
        private ClockInterface $clock,
        private DateTimeZone $timezone
    ) {
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'adct-parish-intake',
            'Audit log',
            'Audit log',
            Capabilities::VIEW_REPORTS,
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $this->requireReportsCapability();

        $now = $this->currentTime();
        $filters = $this->filters($now);
        $query = $this->query($filters);
        $entries = [];
        $total = 0;

        try {
            // Both reads are guarded: a count that fails is the same database
            // problem as a page that fails, and either one must not escape as
            // a fatal on a shared host with a 90 second limit.
            $total = $this->audit->count($query);
            $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));
            $page = min($this->queryPage(), $totalPages);
            $entries = $this->audit->entries(
                $query->withPaging(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE)
            );
        } catch (Throwable $failure) {
            // The filters have already been validated, so a failure here is a
            // database problem rather than bad input. Say so plainly instead of
            // rendering an empty table that reads as "nothing happened".
            error_log(
                '[ADCT Parish Intake] The audit log could not be read ('
                . get_class($failure) . ').'
            );
            $entries = [];
            $total = 0;
            $totalPages = 1;
            $page = 1;
        }
        ?>
        <div class="wrap">
            <h1>Audit log</h1>
            <hr class="wp-header-end" />

            <p>
                Every approval, refusal, edit, assignment, publication, trust change and revert, with who did it and when.
                Entries are added, never changed or removed, and are cleared only by the retention schedule on the
                <a href="<?php echo esc_url($this->pageUrl(['window' => self::MAXIMUM_WINDOW_MONTHS])); ?>">Scheduled jobs</a>
                screen after <?php echo esc_html((string) self::MAXIMUM_WINDOW_MONTHS); ?> months.
            </p>

            <?php $this->renderFilterNotice($filters['invalid']); ?>
            <?php $this->renderFilters($filters); ?>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Action</th>
                        <th scope="col">Subject</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($entries === []) : ?>
                        <tr><td colspan="5">No audit entries match this filter.</td></tr>
                    <?php else : ?>
                        <?php foreach ($entries as $entry) : ?>
                            <?php $this->renderRow($entry); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php $this->renderPagination($page, $totalPages, $total, $filters); ?>
        </div>
        <?php
    }

    private function renderRow(AuditEntry $entry): void
    {
        ?>
        <tr>
            <td><?php echo esc_html($this->formatTimestamp($entry->createdAt)); ?></td>
            <td><?php echo esc_html($entry->actor); ?></td>
            <td><?php echo esc_html($entry->actionLabel()); ?></td>
            <td><?php echo esc_html($entry->subjectLabelWithId()); ?></td>
            <td><?php echo esc_html($this->formatDetails($entry)); ?></td>
        </tr>
        <?php
    }

    /**
     * The details column as one line of text.
     *
     * The column holds whatever the write site put there, including text that
     * came out of a parsed email body, so it is printed as a single escaped
     * string and never as markup. An entry whose details column is not valid
     * JSON is shown raw rather than hidden, because a row that cannot be read
     * is exactly the row someone reading an audit log is looking for.
     */
    private function formatDetails(AuditEntry $entry): string
    {
        if ($entry->details === null || trim($entry->details) === '') {
            return '—';
        }

        $decoded = $entry->decoded();

        if ($decoded === null) {
            return $entry->details;
        }

        $changed = $decoded['changed'] ?? null;

        if (is_array($changed)) {
            return $this->formatSettingChanges($changed);
        }

        $parts = [];

        foreach ($decoded as $key => $value) {
            $parts[] = $key . ': ' . $this->describeValue($value);
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    /**
     * @param array<mixed> $changes
     */
    private function formatSettingChanges(array $changes): string
    {
        $parts = [];

        foreach ($changes as $change) {
            if (! is_array($change)) {
                continue;
            }

            $option = is_scalar($change['option'] ?? null) ? (string) $change['option'] : '';
            $before = is_scalar($change['before'] ?? null) ? (string) $change['before'] : '';
            $after = is_scalar($change['after'] ?? null) ? (string) $change['after'] : '';

            if ($option === '') {
                continue;
            }

            $parts[] = sprintf('%s: %s → %s', $option, $before, $after);
        }

        return $parts === [] ? '—' : implode('; ', $parts);
    }

    private function describeValue(mixed $value): string
    {
        if ($value === null) {
            return 'none';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return sprintf('%d item(s)', count($value));
        }

        return 'value';
    }

    /**
     * The validated filters as a query.
     *
     * Built once and paged by cloning, so the count and the page of rows are
     * always filtered identically and cannot drift apart.
     *
     * @param array{
     *     action: string,
     *     subjectType: string,
     *     subjectId: int,
     *     actor: string,
     *     since: DateTimeImmutable,
     *     until: DateTimeImmutable|null
     * } $filters
     */
    private function query(array $filters): AuditQuery
    {
        return new AuditQuery(
            $filters['action'],
            $filters['subjectType'],
            $filters['subjectId'],
            $filters['actor'],
            $filters['since'],
            $filters['until']
        );
    }

    /**
     * The filters as validated values.
     *
     * Anything unrecognised is dropped rather than rejected: a stale bookmark
     * or a hand-edited query string should still produce a usable screen. The
     * `invalid` flag is what lets the page say so out loud.
     *
     * @return array{
     *     action: string,
     *     subjectType: string,
     *     subjectId: int,
     *     actor: string,
     *     window: int,
     *     since: DateTimeImmutable,
     *     until: DateTimeImmutable|null,
     *     invalid: bool
     * }
     */
    private function filters(DateTimeImmutable $now): array
    {
        $action = $this->text('action');
        $invalid = false;

        if ($action !== '' && ! in_array($action, AuditAction::values(), true)) {
            $action = '';
            $invalid = true;
        }

        $subjectType = $this->text('subject_type');

        if ($subjectType !== '' && ! in_array($subjectType, AuditSubjectType::values(), true)) {
            $subjectType = '';
            $invalid = true;
        }

        $subjectId = $this->subjectId();

        if ($subjectId < 0) {
            $subjectId = 0;
            $invalid = true;
        }

        $windowText = $this->text('window');
        $window = $windowText === '' ? self::DEFAULT_WINDOW_MONTHS : absint($windowText);

        if ($window < 1 || $window > self::MAXIMUM_WINDOW_MONTHS) {
            $window = self::DEFAULT_WINDOW_MONTHS;
            $invalid = $windowText !== '';
        }

        $actor = sanitize_text_field($this->text('actor'));

        return [
            'action' => $action,
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
            'actor' => $actor,
            'window' => $window,
            'since' => $now->modify(sprintf('-%d months', $window)),
            'until' => null,
            'invalid' => $invalid,
        ];
    }

    /**
     * @param array{
     *     action: string,
     *     subjectType: string,
     *     subjectId: int,
     *     actor: string,
     *     window: int,
     *     invalid: bool
     * } $filters
     */
    private function renderFilterNotice(bool $invalid): void
    {
        if (! $invalid) {
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p>Part of that filter was not recognised, so it was ignored. Showing the recent entries instead.</p>
        </div>
        <?php
    }

    /**
     * @param array{
     *     action: string,
     *     subjectType: string,
     *     subjectId: int,
     *     actor: string,
     *     window: int
     * } $filters
     */
    private function renderFilters(array $filters): void
    {
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
            <p class="search-box">
                <label class="screen-reader-text" for="adct-pi-audit-actor">Filter by actor</label>
                <input
                    type="search"
                    id="adct-pi-audit-actor"
                    name="actor"
                    value="<?php echo esc_attr($filters['actor']); ?>"
                    placeholder="Actor email address"
                />
            </p>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <label class="screen-reader-text" for="adct-pi-audit-action">Filter by action</label>
                    <select id="adct-pi-audit-action" name="action">
                        <option value="">All actions</option>
                        <?php foreach (AuditAction::labels() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['action'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label class="screen-reader-text" for="adct-pi-audit-subject-type">Filter by subject type</label>
                    <select id="adct-pi-audit-subject-type" name="subject_type">
                        <option value="">All subjects</option>
                        <?php foreach (AuditSubjectType::labels() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['subjectType'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label class="screen-reader-text" for="adct-pi-audit-subject-id">Filter by subject ID</label>
                    <input
                        type="number"
                        min="0"
                        step="1"
                        size="6"
                        id="adct-pi-audit-subject-id"
                        name="subject_id"
                        value="<?php echo $filters['subjectId'] > 0 ? esc_attr((string) $filters['subjectId']) : ''; ?>"
                    />

                    <label class="screen-reader-text" for="adct-pi-audit-window">Filter by period</label>
                    <select id="adct-pi-audit-window" name="window">
                        <?php foreach (self::WINDOW_CHOICES as $choice) : ?>
                            <option value="<?php echo esc_attr((string) $choice); ?>" <?php selected($filters['window'], $choice); ?>>
                                <?php echo esc_html(sprintf('Last %d month(s)', $choice)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="button">Filter</button>
                </div>
                <br class="clear" />
            </div>
        </form>
        <?php
    }

    /**
     * @param array{
     *     action: string,
     *     subjectType: string,
     *     subjectId: int,
     *     actor: string,
     *     window: int,
     *     since: DateTimeImmutable,
     *     until: DateTimeImmutable|null,
     *     invalid: bool
     * } $filters
     */
    private function renderPagination(int $page, int $totalPages, int $total, array $filters): void
    {
        if ($totalPages <= 1) {
            return;
        }

        $arguments = [
            'action' => $filters['action'],
            'subject_type' => $filters['subjectType'],
            'actor' => $filters['actor'],
            'window' => (string) $filters['window'],
        ];

        if ($filters['subjectId'] > 0) {
            $arguments['subject_id'] = (string) $filters['subjectId'];
        }
        ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html(sprintf('%d entries', $total)); ?></span>
                <span class="pagination-links">
                    <?php if ($page > 1) : ?>
                        <a class="prev-page" href="<?php echo esc_url($this->pageUrl($arguments + ['paged' => $page - 1])); ?>">Previous</a>
                    <?php else : ?>
                        <span class="tablenav-pages-navspan">Previous</span>
                    <?php endif; ?>
                    <span class="paging-input"><?php echo esc_html(sprintf('Page %d of %d', $page, $totalPages)); ?></span>
                    <?php if ($page < $totalPages) : ?>
                        <a class="next-page" href="<?php echo esc_url($this->pageUrl($arguments + ['paged' => $page + 1])); ?>">Next</a>
                    <?php else : ?>
                        <span class="tablenav-pages-navspan">Next</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php
    }

    private function formatTimestamp(DateTimeImmutable $moment): string
    {
        return $moment
            ->setTimezone($this->timezone)
            ->format('d/m/Y H:i');
    }

    private function currentTime(): DateTimeImmutable
    {
        // wp_timezone() is the site's configured zone, which is not necessarily
        // this plugin's. Falling back keeps the window correct either way.
        $zone = function_exists('wp_timezone') ? wp_timezone() : $this->timezone;

        return (new DateTimeImmutable('@' . $this->clock->now()->getTimestamp()))
            ->setTimezone($zone instanceof DateTimeZone ? $zone : $this->timezone);
    }

    private function queryPage(): int
    {
        if (! isset($_GET['paged']) || ! is_string($_GET['paged'])) {
            return 1;
        }

        return max(1, absint(wp_unslash($_GET['paged'])));
    }

    /**
     * @return array<string, string>
     */
    private function pageUrl(array $arguments = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::PAGE_SLUG], $arguments),
            admin_url('admin.php')
        );
    }

    private function requireReportsCapability(): void
    {
        if (! current_user_can(Capabilities::VIEW_REPORTS)) {
            // No markup is echoed: the message is escaped by wp_die() as text
            // when no response flag asks for markup, and escaping it first
            // would show the entities to an administrator reading the screen.
            wp_die(
                esc_html__('You do not have permission to view the audit log.', 'adct-parish-intake'),
                '',
                ['response' => 403]
            );
        }
    }

    private function text(string $key): string
    {
        $value = $_GET[$key] ?? '';

        return is_scalar($value) ? (string) wp_unslash((string) $value) : '';
    }

    /**
     * The subject id filter, or -1 when the query string held something that is
     * not a row identifier.
     */
    private function subjectId(): int
    {
        $value = $this->text('subject_id');

        if ($value === '') {
            return 0;
        }

        if (preg_match('/\A\d{1,18}\z/', $value) !== 1) {
            return -1;
        }

        return (int) $value;
    }
}
