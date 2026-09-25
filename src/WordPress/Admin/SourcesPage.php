<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Sources\Source;
use ADCT\ParishIntake\Core\Sources\SourceRegistryService;
use ADCT\ParishIntake\Core\Sources\SourceRole;
use ADCT\ParishIntake\Core\Sources\SourceStatus;
use ADCT\ParishIntake\Core\Sources\SourceType;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository;
use DomainException;
use InvalidArgumentException;

final class SourcesPage
{
    private const PAGE_SLUG = 'adct-parish-intake-sources';
    private const PAGE_SIZE = 20;

    public function __construct(
        private SourceRepository $sources,
        private SourceRegistryService $sourceRegistry,
        private ParishRepository $parishes
    ) {
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'adct-parish-intake',
            'Sources',
            'Sources',
            Capabilities::MANAGE_DIRECTORY,
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $this->requireDirectoryCapability();
        $action = sanitize_key($this->getText('action'));

        if ($action === 'add') {
            $this->renderGlobalForm(null);

            return;
        }

        if ($action === 'edit') {
            $source = $this->sources->findSource(absint($this->getText('id')));

            if ($source === null || $source->parishId !== null) {
                wp_die(esc_html__('Only archdiocese-wide sources can be edited here. Edit parish sources from the parish Sources tab.', 'adct-parish-intake'), '', [
                    'response' => 404,
                ]);
            }

            $this->renderGlobalForm($source);

            return;
        }

        $filters = $this->filters();
        $page = max(1, absint($this->getText('paged')));
        $total = $this->sources->countForAdmin($filters);
        $rows = $this->sources->findForAdmin(
            $filters,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE
        );
        $parishes = $this->parishes->findAllForImport();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Sources</h1>
            <a class="page-title-action" href="<?php echo esc_url($this->pageUrl(['action' => 'add'])); ?>">Add archdiocese-wide source</a>
            <hr class="wp-header-end" />

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Source saved.</p></div>
            <?php endif; ?>

            <p>Parish sources are listed here for monitoring and are edited from the parish's Sources tab. Archdiocese-wide sources have no one-official-source limit.</p>

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                <p class="search-box">
                    <label class="screen-reader-text" for="source-search">Search sources</label>
                    <input type="search" id="source-search" name="search" value="<?php echo esc_attr((string) ($filters['search'] ?? '')); ?>" />
                    <input type="submit" class="button" value="Search sources" />
                </p>
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <label class="screen-reader-text" for="source-parish-filter">Filter by parish</label>
                        <select id="source-parish-filter" name="parish_id">
                            <option value="">All parishes</option>
                            <option value="0" <?php selected($filters['parish_id'] ?? null, 0); ?>>Archdiocese-wide</option>
                            <?php foreach ($parishes as $parish) : ?>
                                <option value="<?php echo esc_attr((string) $parish['id']); ?>" <?php selected($filters['parish_id'] ?? null, (int) $parish['id']); ?>>
                                    <?php echo esc_html((string) $parish['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label class="screen-reader-text" for="source-type-filter">Filter by source type</label>
                        <select id="source-type-filter" name="type">
                            <option value="">All types</option>
                            <?php foreach (SourceType::values() as $type) : ?>
                                <option value="<?php echo esc_attr($type); ?>" <?php selected($filters['type'], $type); ?>>
                                    <?php echo esc_html($this->label($type)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label class="screen-reader-text" for="source-role-filter">Filter by role</label>
                        <select id="source-role-filter" name="role">
                            <option value="">All roles</option>
                            <?php foreach (SourceRole::values() as $role) : ?>
                                <option value="<?php echo esc_attr($role); ?>" <?php selected($filters['role'], $role); ?>>
                                    <?php echo esc_html($this->label($role)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label class="screen-reader-text" for="source-status-filter">Filter by status</label>
                        <select id="source-status-filter" name="status">
                            <option value="">All statuses</option>
                            <?php foreach (SourceStatus::values() as $status) : ?>
                                <option value="<?php echo esc_attr($status); ?>" <?php selected($filters['status'], $status); ?>>
                                    <?php echo esc_html($this->label($status)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button">Filter</button>
                    </div>
                    <br class="clear" />
                </div>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col">Scope</th>
                        <th scope="col">Source</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col">Poll interval</th>
                        <th scope="col">Health</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []) : ?>
                        <tr><td colspan="7">No sources match these filters.</td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $row) : ?>
                            <?php
                            $parishId = isset($row['parish_id']) && $row['parish_id'] !== ''
                                ? (int) $row['parish_id']
                                : null;
                            ?>
                            <tr>
                                <td>
                                    <?php if ($parishId === null) : ?>
                                        Archdiocese-wide
                                    <?php else : ?>
                                        <a href="<?php echo esc_url($this->parishSourcesUrl($parishId)); ?>">
                                            <?php echo esc_html((string) ($row['parish_name'] ?? 'Parish')); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($this->label((string) ($row['type'] ?? ''))); ?></strong>
                                    <br /><code><?php echo esc_html((string) ($row['identifier'] ?? '')); ?></code>
                                </td>
                                <td><?php echo esc_html($this->label((string) ($row['role'] ?? ''))); ?></td>
                                <td><?php echo esc_html($this->label((string) ($row['status'] ?? ''))); ?></td>
                                <td><?php echo esc_html($this->pollIntervalLabel(
                                    $row['poll_interval_minutes'] ?? null,
                                    (string) ($row['type'] ?? '')
                                )); ?></td>
                                <td>
                                    <?php $this->renderHealth(
                                        $this->nullableString($row['last_checked_at'] ?? null),
                                        $this->nullableString($row['last_success_at'] ?? null),
                                        $this->nullableString($row['last_item_at'] ?? null),
                                        (int) ($row['consecutive_failures'] ?? 0),
                                        $this->nullableString($row['last_error'] ?? null)
                                    ); ?>
                                </td>
                                <td>
                                    <?php if ($parishId === null) : ?>
                                        <a class="button button-secondary" href="<?php echo esc_url($this->pageUrl([
                                            'action' => 'edit',
                                            'id' => (int) $row['id'],
                                        ])); ?>">Edit</a>
                                    <?php else : ?>
                                        <a class="button button-secondary" href="<?php echo esc_url($this->parishSourcesUrl($parishId)); ?>">Edit in parish</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php $this->renderPagination($filters, $page, $total); ?>
            <p class="description">Health is read-only. Polling jobs will update these fields when source adapters are added.</p>
        </div>
        <?php
    }

    public function renderParishTab(int $parishId): void
    {
        $sources = $this->sources->findForParish($parishId);
        ?>
        <p>Choose one official source for this parish. Setting another source to Official demotes the previous one to Monitored. Polling health is read-only and will be updated by source jobs when adapters are added.</p>

        <?php if (isset($_GET['source_saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Parish source saved.</p></div>
        <?php endif; ?>

        <?php if ($sources === []) : ?>
            <p>No sources are recorded for this parish yet.</p>
        <?php else : ?>
            <?php foreach ($sources as $source) : ?>
                <section class="postbox" style="padding: 12px 16px; margin-top: 16px;">
                    <h2 class="hndle">
                        <?php echo esc_html($this->label($source->type)); ?>:
                        <code><?php echo esc_html($source->identifier); ?></code>
                    </h2>
                    <p class="description">
                        Role: <?php echo esc_html($this->label($source->role)); ?>
                        · Status: <?php echo esc_html($this->label($source->status)); ?>
                        · Poll interval: <?php echo esc_html($this->pollIntervalLabel(
                            $source->pollIntervalMinutes,
                            $source->type
                        )); ?>
                    </p>
                    <?php $this->renderHealth(
                        $source->lastCheckedAt,
                        $source->lastSuccessAt,
                        $source->lastItemAt,
                        $source->consecutiveFailures,
                        $source->lastError
                    ); ?>
                    <details>
                        <summary>Edit source</summary>
                        <?php $this->renderSourceForm($source, $parishId); ?>
                    </details>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>

        <hr />
        <h2>Add a source</h2>
        <?php $this->renderSourceForm(null, $parishId); ?>
        <?php
    }

    public function handleSaveSource(): void
    {
        $this->requireDirectoryCapability();
        $sourceId = absint($this->postText('source_id'));
        $parishText = trim($this->postText('parish_id'));

        if (preg_match('/^\d+$/D', $parishText) !== 1) {
            wp_die(esc_html__('The source parish selection is invalid.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        $parishValue = (int) $parishText;
        $parishId = $parishValue > 0 ? $parishValue : null;
        check_admin_referer($this->nonceAction($parishId, $sourceId), 'source_nonce');

        if ($parishId !== null && $this->parishes->findById($parishId) === null) {
            wp_die(esc_html__('The parish could not be found.', 'adct-parish-intake'), '', [
                'response' => 404,
            ]);
        }

        $current = $sourceId > 0 ? $this->sources->findSource($sourceId) : null;

        if ($sourceId > 0 && $current === null) {
            wp_die(esc_html__('The source could not be found.', 'adct-parish-intake'), '', [
                'response' => 404,
            ]);
        }

        if ($current !== null && $current->parishId !== $parishId) {
            wp_die(esc_html__('This source can only be edited from its current scope.', 'adct-parish-intake'), '', [
                'response' => 403,
            ]);
        }

        $intervalText = trim($this->postText('poll_interval_minutes'));
        $interval = null;

        if ($intervalText !== '') {
            if (preg_match('/^\d+$/D', $intervalText) !== 1) {
                wp_die(esc_html__('Enter a whole-number poll interval in minutes.', 'adct-parish-intake'), '', [
                    'response' => 400,
                ]);
            }

            $interval = (int) $intervalText;
        }

        try {
            $source = new Source(
                $sourceId,
                $parishId,
                sanitize_key($this->postText('type')),
                sanitize_text_field($this->postText('identifier')),
                sanitize_key($this->postText('role')),
                sanitize_key($this->postText('status')),
                $interval
            );
            $this->sourceRegistry->save($source);
        } catch (DomainException | InvalidArgumentException $failure) {
            wp_die(esc_html($failure->getMessage()), esc_html__('Source update failed', 'adct-parish-intake'), [
                'response' => 400,
            ]);
        }

        if ($parishId === null) {
            wp_safe_redirect($this->pageUrl(['saved' => 1]));
        } else {
            wp_safe_redirect($this->parishSourcesUrl($parishId, ['source_saved' => 1]));
        }

        exit;
    }

    private function renderGlobalForm(?Source $source): void
    {
        ?>
        <div class="wrap">
            <h1><?php echo $source === null ? 'Add archdiocese-wide source' : 'Edit archdiocese-wide source'; ?></h1>
            <p><a href="<?php echo esc_url($this->pageUrl()); ?>">&larr; Back to sources</a></p>
            <p>Archdiocese-wide sources are not tied to a parish. Their official/monitored roles do not have a one-official-source limit.</p>
            <?php $this->renderSourceForm($source, null); ?>
        </div>
        <?php
    }

    private function renderSourceForm(?Source $source, ?int $parishId): void
    {
        $sourceId = $source?->id ?? 0;
        $type = $source?->type ?? SourceType::EMAIL;
        $role = $source?->role ?? SourceRole::MONITORED;
        $status = $source?->status ?? SourceStatus::ACTIVE;
        $pollInterval = $source?->pollIntervalMinutes
            ?? ($type === SourceType::MANUAL ? null : Source::DEFAULT_POLL_INTERVAL_MINUTES);
        $prefix = 'source-' . ($sourceId > 0 ? (string) $sourceId : ($parishId === null ? 'new-global' : 'new-parish-' . $parishId));
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adct_pi_save_source" />
            <input type="hidden" name="source_id" value="<?php echo esc_attr((string) $sourceId); ?>" />
            <input type="hidden" name="parish_id" value="<?php echo esc_attr((string) ($parishId ?? 0)); ?>" />
            <?php wp_nonce_field($this->nonceAction($parishId, $sourceId), 'source_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-type">Type</label></th>
                    <td>
                        <select id="<?php echo esc_attr($prefix); ?>-type" name="type" required>
                            <?php foreach (SourceType::values() as $value) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($type, $value); ?>>
                                    <?php echo esc_html($this->label($value)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-identifier">Identifier</label></th>
                    <td>
                        <input id="<?php echo esc_attr($prefix); ?>-identifier" class="regular-text" name="identifier" type="text" maxlength="191" required value="<?php echo esc_attr($source?->identifier ?? ''); ?>" />
                        <p class="description">Email sources use an email address; ICS, PDF, Facebook, RSS and web sources require an HTTP or HTTPS URL; forwarded WhatsApp and manual sources use a label.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-role">Role</label></th>
                    <td>
                        <select id="<?php echo esc_attr($prefix); ?>-role" name="role" required>
                            <?php foreach (SourceRole::values() as $value) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($role, $value); ?>>
                                    <?php echo esc_html($this->label($value)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-status">Status</label></th>
                    <td>
                        <select id="<?php echo esc_attr($prefix); ?>-status" name="status" required>
                            <?php foreach (SourceStatus::values() as $value) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>>
                                    <?php echo esc_html($this->label($value)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Five consecutive failures mark a source Unreliable unless it is Paused or Disabled. Status can also be changed here.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-poll-interval">Poll interval (minutes)</label></th>
                    <td>
                        <input id="<?php echo esc_attr($prefix); ?>-poll-interval" name="poll_interval_minutes" type="number" min="<?php echo esc_attr((string) Source::MINIMUM_POLL_INTERVAL_MINUTES); ?>" max="<?php echo esc_attr((string) Source::MAXIMUM_POLL_INTERVAL_MINUTES); ?>" step="1" value="<?php echo esc_attr($pollInterval === null ? '' : (string) $pollInterval); ?>" />
                        <p class="description">Pollable sources default to 1,440 minutes (24 hours); the minimum is 10 minutes. These limits are provisional. Manual sources are not polled and keep this field blank.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button($source === null ? 'Add source' : 'Save source', 'primary', 'submit', false); ?>
        </form>
        <?php
    }

    private function renderHealth(
        ?string $lastCheckedAt,
        ?string $lastSuccessAt,
        ?string $lastItemAt,
        int $consecutiveFailures,
        ?string $lastError
    ): void {
        ?>
        <dl class="source-health">
            <dt>Last checked</dt><dd><?php echo esc_html($this->timestampLabel($lastCheckedAt)); ?></dd>
            <dt>Last success</dt><dd><?php echo esc_html($this->timestampLabel($lastSuccessAt)); ?></dd>
            <dt>Last item</dt><dd><?php echo esc_html($this->timestampLabel($lastItemAt)); ?></dd>
            <dt>Consecutive failures</dt><dd><?php echo esc_html((string) $consecutiveFailures); ?></dd>
            <dt>Last error</dt><dd><?php echo esc_html($lastError ?? '—'); ?></dd>
        </dl>
        <?php
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function renderPagination(array $filters, int $page, int $total): void
    {
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));

        if ($pages < 2) {
            return;
        }
        ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html((string) $total); ?> sources</span>
                <?php if ($page > 1) : ?>
                    <a class="button" href="<?php echo esc_url($this->pageUrl($this->paginationArguments($filters, $page - 1))); ?>">Previous</a>
                <?php endif; ?>
                <span>Page <?php echo esc_html((string) $page); ?> of <?php echo esc_html((string) $pages); ?></span>
                <?php if ($page < $pages) : ?>
                    <a class="button" href="<?php echo esc_url($this->pageUrl($this->paginationArguments($filters, $page + 1))); ?>">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, scalar>
     */
    private function paginationArguments(array $filters, int $page): array
    {
        $arguments = ['paged' => $page];

        foreach ($filters as $key => $value) {
            if ($value !== null && $value !== '') {
                $arguments[$key] = $value;
            }
        }

        return $arguments;
    }

    /**
     * @return array{search: string, parish_id: int|null, type: string, role: string, status: string}
     */
    private function filters(): array
    {
        $parishText = $this->getText('parish_id');
        $parishId = null;

        if ($parishText !== '' && preg_match('/^\d+$/D', $parishText) === 1) {
            $parishId = (int) $parishText;
        }

        $type = sanitize_key($this->getText('type'));
        $role = sanitize_key($this->getText('role'));
        $status = sanitize_key($this->getText('status'));

        return [
            'search' => sanitize_text_field($this->getText('search')),
            'parish_id' => $parishId,
            'type' => SourceType::isValid($type) ? $type : '',
            'role' => in_array($role, SourceRole::values(), true) ? $role : '',
            'status' => in_array($status, SourceStatus::values(), true) ? $status : '',
        ];
    }

    private function parishSourcesUrl(int $parishId, array $arguments = []): string
    {
        return add_query_arg(
            array_merge([
                'page' => 'adct-parish-intake-parishes',
                'action' => 'edit',
                'id' => $parishId,
                'tab' => 'sources',
            ], $arguments),
            admin_url('admin.php')
        );
    }

    private function pageUrl(array $arguments = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::PAGE_SLUG], $arguments),
            admin_url('admin.php')
        );
    }

    private function nonceAction(?int $parishId, int $sourceId): string
    {
        return 'adct_pi_source_save_' . ($parishId ?? 0) . '_' . $sourceId;
    }

    private function requireDirectoryCapability(): void
    {
        if (! current_user_can(Capabilities::MANAGE_DIRECTORY)) {
            wp_die(esc_html__('You do not have permission to manage sources.', 'adct-parish-intake'), '', [
                'response' => 403,
            ]);
        }
    }

    private function pollIntervalLabel(mixed $value, ?string $type = null): string
    {
        if ($value === null || $value === '') {
            return $type === SourceType::MANUAL
                ? 'Not polled'
                : (string) Source::DEFAULT_POLL_INTERVAL_MINUTES . ' minutes (default)';
        }

        return (string) ((int) $value) . ' minutes';
    }

    private function timestampLabel(?string $timestamp): string
    {
        return $timestamp === null ? 'Not yet' : $timestamp . ' UTC';
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function label(string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private function getText(string $key): string
    {
        $value = $_GET[$key] ?? '';

        return is_scalar($value) ? (string) wp_unslash((string) $value) : '';
    }

    private function postText(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_scalar($value) ? (string) wp_unslash((string) $value) : '';
    }
}
