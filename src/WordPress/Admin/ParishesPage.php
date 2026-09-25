<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Approval\ApprovalRoute;
use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Directory\ContactService;
use ADCT\ParishIntake\Core\Directory\CsvFormulaGuard;
use ADCT\ParishIntake\Core\Directory\EmailAddress;
use ADCT\ParishIntake\Core\Directory\ImportPlan;
use ADCT\ParishIntake\Core\Directory\ImportRow;
use ADCT\ParishIntake\Core\Directory\ParishCsvImporter;
use ADCT\ParishIntake\Core\Directory\ParishDataValidator;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Directory\Venue;
use ADCT\ParishIntake\Core\Directory\VenueAdministrationService;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishContactRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
use ADCT\ParishIntake\WordPress\Directory\DirectoryImportService;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

final class ParishesPage
{
    private const PAGE_SLUG = 'adct-parish-intake-parishes';
    private const IMPORT_TRANSIENT_PREFIX = 'adct_pi_directory_import_';
    private const MAXIMUM_UPLOAD_BYTES = 1048576;
    private const IMPORT_PREVIEW_TTL = 900;
    private const PAGE_SIZE = 20;

    public function __construct(
        private ParishRepository $parishes,
        private DeaneryRepository $deaneries,
        private ParishContactRepository $contacts,
        private ContactService $contactService,
        private DirectoryImportService $importService,
        private ApprovalRouteResolver $approvalRouteResolver,
        private VenueRepository $venues,
        private VenueAdministrationService $venueService,
        private ClockInterface $clock,
        private SourcesPage $sourcesPage
    ) {
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'adct-parish-intake',
            'Parishes',
            'Parishes',
            Capabilities::MANAGE_DIRECTORY,
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $this->requireDirectoryCapability();

        $deaneries = $this->deaneries->findAll();
        $parishRows = $this->parishes->findAllForImport();
        $action = sanitize_key($this->getText('action'));

        if ($action === 'add') {
            $this->renderForm(null, $deaneries, $parishRows);

            return;
        }

        if ($action === 'edit') {
            $id = absint($this->getText('id'));
            $parish = $this->parishes->findWithRelations($id);

            if ($parish === null) {
                wp_die(esc_html__('The parish could not be found.', 'adct-parish-intake'), '', [
                    'response' => 404,
                ]);
            }

            $tab = sanitize_key($this->getText('tab'));

            if ($tab === 'venues') {
                $this->renderVenueTab($parish);

                return;
            }

            if ($tab === 'sources') {
                $this->renderSourcesTab($parish);

                return;
            }

            $this->renderForm($parish, $deaneries, $parishRows);

            return;
        }

        $filters = [
            'search' => sanitize_text_field($this->getText('search')),
            'kind' => sanitize_key($this->getText('kind')),
            'deanery_id' => absint($this->getText('deanery_id')),
            'status' => sanitize_key($this->getText('status')),
        ];
        $page = max(1, absint($this->getText('paged')));
        $total = $this->parishes->countForDirectory($filters);
        $rows = $this->parishes->findForDirectory(
            $filters,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Parishes</h1>
            <a class="page-title-action" href="<?php echo esc_url($this->pageUrl(['action' => 'add'])); ?>">Add parish</a>
            <hr class="wp-header-end" />

            <?php $this->renderNotices(); ?>

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                <p class="search-box">
                    <label class="screen-reader-text" for="parish-search">Search parishes</label>
                    <input type="search" id="parish-search" name="search" value="<?php echo esc_attr($filters['search']); ?>" />
                    <input type="submit" class="button" value="Search parishes" />
                </p>
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <label class="screen-reader-text" for="parish-kind-filter">Filter by kind</label>
                        <select id="parish-kind-filter" name="kind">
                            <option value="">All kinds</option>
                            <?php foreach (ParishDataValidator::KINDS as $kind) : ?>
                                <option value="<?php echo esc_attr($kind); ?>" <?php selected($filters['kind'], $kind); ?>>
                                    <?php echo esc_html($this->label($kind)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label class="screen-reader-text" for="parish-deanery-filter">Filter by deanery</label>
                        <select id="parish-deanery-filter" name="deanery_id">
                            <option value="0">All deaneries</option>
                            <?php foreach ($deaneries as $deanery) : ?>
                                <option value="<?php echo esc_attr((string) $deanery['id']); ?>" <?php selected($filters['deanery_id'], (int) $deanery['id']); ?>>
                                    <?php echo esc_html((string) $deanery['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label class="screen-reader-text" for="parish-status-filter">Filter by status</label>
                        <select id="parish-status-filter" name="status">
                            <option value="">All statuses</option>
                            <option value="active" <?php selected($filters['status'], 'active'); ?>>Active</option>
                            <option value="inactive" <?php selected($filters['status'], 'inactive'); ?>>Inactive</option>
                        </select>
                        <button type="submit" class="button">Filter</button>
                    </div>
                    <br class="clear" />
                </div>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="adct_pi_bulk_assign_parishes" />
                <?php wp_nonce_field('adct_pi_bulk_assign_parishes', 'bulk_assign_nonce'); ?>
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <label for="parish-bulk-deanery">Assign selected parishes to</label>
                        <select id="parish-bulk-deanery" name="deanery_id">
                            <option value="0">No deanery (reviewers only)</option>
                            <?php foreach ($deaneries as $deanery) : ?>
                                <option value="<?php echo esc_attr((string) $deanery['id']); ?>">
                                    <?php echo esc_html((string) $deanery['name']); ?>
                                    <?php echo (string) ($deanery['status'] ?? 'active') === 'inactive' ? ' (inactive)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="button" type="submit">Assign to selected parishes</button>
                    </div>
                    <br class="clear" />
                </div>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><span class="screen-reader-text">Select parish</span></th>
                            <th scope="col">Name</th>
                            <th scope="col">Kind</th>
                            <th scope="col">Deanery / approval route</th>
                            <th scope="col">Area / suburb</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []) : ?>
                            <tr><td colspan="6">No parishes match these filters.</td></tr>
                        <?php else : ?>
                            <?php foreach ($rows as $row) : ?>
                                <?php $route = $this->approvalRouteResolver->forParish((int) $row['id']); ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="parish_ids[]" value="<?php echo esc_attr((string) $row['id']); ?>" aria-label="<?php echo esc_attr('Select ' . (string) $row['name']); ?>" />
                                    </th>
                                    <td>
                                        <strong><a href="<?php echo esc_url($this->pageUrl([
                                            'action' => 'edit',
                                            'id' => (int) $row['id'],
                                        ])); ?>"><?php echo esc_html((string) $row['name']); ?></a></strong>
                                        <br /><code><?php echo esc_html((string) $row['slug']); ?></code>
                                    </td>
                                    <td><?php echo esc_html($this->label((string) $row['kind'])); ?></td>
                                    <td>
                                        <?php if ($route->reviewersOnly) : ?>
                                            <strong>Reviewers only</strong>
                                            <br /><span class="description"><?php echo esc_html($this->routeReason($route)); ?></span>
                                        <?php else : ?>
                                            <?php echo esc_html((string) ($row['deanery_name'] ?? '')); ?>
                                            <br /><span class="description"><?php echo esc_html(count($route->approvers) . ' active approver(s) plus reviewers'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        echo esc_html(implode(' / ', array_filter([
                                            (string) ($row['area'] ?? ''),
                                            (string) ($row['suburb'] ?? ''),
                                        ], static fn (string $value): bool => $value !== '')));
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($this->label((string) $row['status'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>

            <?php $this->renderPagination($filters, $page, $total); ?>

            <?php $this->renderImportSection(); ?>

            <?php $this->renderImportPreview(); ?>
        </div>
        <?php
    }

    public function handleSaveParish(): void
    {
        $this->requireDirectoryCapability();
        check_admin_referer('adct_pi_save_parish', 'adct_pi_save_parish_nonce');

        $id = absint($this->postText('parish_id'));
        $deaneryId = absint($this->postText('deanery_id'));
        $parentId = absint($this->postText('parent_parish_id'));
        $deaneries = $this->deaneries->findAll();
        $parishRows = $this->parishes->findAllForImport();
        $deaneryById = $this->indexById($deaneries);
        $parishById = $this->indexById($parishRows);
        $errors = [];

        if ($deaneryId > 0 && ! isset($deaneryById[$deaneryId])) {
            $errors[] = 'Select a deanery from the list.';
        }

        if ($parentId > 0 && ! isset($parishById[$parentId])) {
            $errors[] = 'Select a parent parish from the list.';
        }

        if ($id > 0 && $parentId === $id) {
            $errors[] = 'A parish cannot be its own parent.';
        }

        $current = $id > 0 ? $this->parishes->findWithRelations($id) : null;

        if ($id > 0 && $current === null) {
            wp_die(esc_html__('The parish could not be found.', 'adct-parish-intake'), '', [
                'response' => 404,
            ]);
        }

        $input = [
            'slug' => sanitize_title($this->postText('slug')),
            'name' => sanitize_text_field($this->postText('name')),
            'area' => sanitize_text_field($this->postText('area')),
            'church' => sanitize_text_field($this->postText('church')),
            'kind' => sanitize_key($this->postText('kind')),
            'is_mother_parish' => $parentId > 0 ? 'no' : 'yes',
            'parent_slug' => $parentId > 0 ? (string) $parishById[$parentId]['slug'] : '',
            'deanery_slug' => $deaneryId > 0 ? (string) $deaneryById[$deaneryId]['slug'] : '',
            'address' => sanitize_textarea_field($this->postText('address')),
            'suburb' => sanitize_text_field($this->postText('suburb')),
            'latitude' => sanitize_text_field($this->postText('latitude')),
            'longitude' => sanitize_text_field($this->postText('longitude')),
            'website' => esc_url_raw($this->postText('website')),
            'phone' => sanitize_text_field($this->postText('phone')),
            'expected_cadence_days' => sanitize_text_field($this->postText('expected_cadence_days')),
            'reminders_enabled' => $this->postText('reminders_enabled') === '1' ? '1' : '0',
            'status' => sanitize_key($this->postText('status')),
            'notes' => sanitize_textarea_field($this->postText('notes')),
        ];
        $validation = (new ParishDataValidator())->validate(
            $input,
            $deaneries,
            $parishRows
        );
        $errors = array_merge($errors, $validation->errors);

        if ($errors !== []) {
            wp_die(esc_html(implode(' ', $errors)), 'Invalid parish details', [
                'response' => 400,
            ]);
        }

        foreach ($parishRows as $parish) {
            if (
                strtolower((string) $parish['slug']) === $validation->values['slug']
                && (int) $parish['id'] !== $id
            ) {
                wp_die(esc_html__('A parish with that slug already exists.', 'adct-parish-intake'), '', [
                    'response' => 400,
                ]);
            }
        }

        $timestamp = $this->timestamp();
        $values = [
            'name' => $validation->values['name'],
            'slug' => $validation->values['slug'],
            'area' => $validation->values['area'],
            'church' => $validation->values['church'],
            'kind' => $validation->values['kind'],
            'parent_parish_id' => $parentId > 0 ? $parentId : null,
            'deanery_id' => $deaneryId > 0 ? $deaneryId : null,
            'address' => $validation->values['address'],
            'suburb' => $validation->values['suburb'],
            'latitude' => $validation->values['latitude'],
            'longitude' => $validation->values['longitude'],
            'website' => $validation->values['website'],
            'phone' => $validation->values['phone'],
            'expected_cadence_days' => $validation->values['expected_cadence_days'],
            'reminders_enabled' => $validation->values['reminders_enabled'],
            'status' => $validation->values['status'],
            'notes' => $validation->values['notes'],
            'updated_at' => $timestamp,
        ];

        if ($id > 0) {
            $this->parishes->update($id, $values);
        } else {
            $values['created_at'] = $timestamp;
            $this->parishes->insert($values);
        }

        wp_safe_redirect($this->pageUrl(['saved' => 1]));
        exit;
    }

    public function handleBulkAssign(): void
    {
        $this->requireDirectoryCapability();
        check_admin_referer('adct_pi_bulk_assign_parishes', 'bulk_assign_nonce');

        $rawIds = $_POST['parish_ids'] ?? null;

        if (! is_array($rawIds) || $rawIds === [] || count($rawIds) > 100) {
            wp_die(esc_html__('Select between 1 and 100 parishes to assign.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        $parishIds = [];

        foreach ($rawIds as $rawId) {
            if (! is_scalar($rawId) || preg_match('/^[1-9][0-9]*$/D', (string) $rawId) !== 1) {
                wp_die(esc_html__('The selected parish list is invalid. Select the parishes again.', 'adct-parish-intake'), '', [
                    'response' => 400,
                ]);
            }

            $parishIds[] = (int) $rawId;
        }

        $parishIds = array_values(array_unique($parishIds));
        $deaneryId = absint($this->postText('deanery_id'));

        if ($deaneryId > 0 && $this->deaneries->findById($deaneryId) === null) {
            wp_die(esc_html__('Choose a deanery from the list.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        foreach ($parishIds as $parishId) {
            if ($this->parishes->findById($parishId) === null) {
                wp_die(esc_html__('One of the selected parishes could not be found. Select them again.', 'adct-parish-intake'), '', [
                    'response' => 400,
                ]);
            }
        }

        $this->parishes->updateDeaneryForParishes(
            $parishIds,
            $deaneryId > 0 ? $deaneryId : null,
            $this->timestamp()
        );

        wp_safe_redirect($this->pageUrl(['bulk_assigned' => count($parishIds)]));
        exit;
    }

    public function handleVenueAction(): void
    {
        $this->requireDirectoryCapability();
        $parishId = absint($this->postText('parish_id'));
        $venueId = absint($this->postText('venue_id'));
        $action = sanitize_key($this->postText('venue_action'));

        if (! in_array($action, ['save', 'deactivate', 'reactivate'], true)) {
            wp_die(esc_html__('Choose a valid venue action.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        check_admin_referer($this->venueNonceAction($action, $parishId, $venueId));

        if ($parishId < 1 || $this->parishes->findWithRelations($parishId) === null) {
            wp_die(esc_html__('The parish could not be found.', 'adct-parish-intake'), '', [
                'response' => 404,
            ]);
        }

        try {
            if ($action === 'save') {
                $this->venueService->save($parishId, $venueId, [
                    'name' => sanitize_text_field($this->postText('name')),
                    'aliases' => sanitize_textarea_field($this->postText('aliases')),
                    'address' => sanitize_textarea_field($this->postText('address')),
                    'suburb' => sanitize_text_field($this->postText('suburb')),
                    'latitude' => sanitize_text_field($this->postText('latitude')),
                    'longitude' => sanitize_text_field($this->postText('longitude')),
                    'is_default' => $this->postText('is_default') === '1' ? '1' : '0',
                ]);
            } elseif ($action === 'deactivate') {
                $this->venueService->deactivate($parishId, $venueId);
            } else {
                $this->venueService->reactivate($parishId, $venueId);
            }
        } catch (DomainException | InvalidArgumentException $failure) {
            wp_die(esc_html($failure->getMessage()), esc_html__('Venue update failed', 'adct-parish-intake'), [
                'response' => 400,
            ]);
        }

        wp_safe_redirect($this->pageUrl([
            'action' => 'edit',
            'id' => $parishId,
            'tab' => 'venues',
            'venue_saved' => 1,
        ]));
        exit;
    }

    public function handleVenueBackfill(): void
    {
        $this->requireDirectoryCapability();
        check_admin_referer('adct_pi_venue_backfill');
        $created = $this->importService->ensureVenuesFromDirectory();

        wp_safe_redirect($this->pageUrl([
            'venues_seeded' => $created,
        ]));
        exit;
    }

    public function handleContactAction(): void
    {
        $this->requireDirectoryCapability();
        $parishId = absint($this->postText('parish_id'));
        $contactId = absint($this->postText('contact_id'));
        $action = sanitize_key($this->postText('contact_action'));

        if (! in_array($action, ['save', 'remove', 'verify', 'block', 'unblock'], true)) {
            wp_die(esc_html__('Choose a valid contact action.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        check_admin_referer($this->contactNonceAction($action, $parishId, $contactId));

        if ($parishId < 1 || $this->parishes->findWithRelations($parishId) === null) {
            wp_die(esc_html__('The parish could not be found.', 'adct-parish-intake'), '', [
                'response' => 404,
            ]);
        }

        try {
            if ($action === 'save') {
                $email = $this->postText('email');
                $displayName = sanitize_text_field($this->postText('display_name'));
                $roleLabel = sanitize_text_field($this->postText('role_label'));
                $receivesReminders = $this->postText('receives_reminders') === '1';

                if ($contactId > 0) {
                    $this->contactService->updateLink(
                        $contactId,
                        $parishId,
                        $email,
                        $displayName,
                        $roleLabel,
                        $receivesReminders
                    );
                } else {
                    $this->contactService->link(
                        $parishId,
                        $email,
                        $displayName,
                        $roleLabel,
                        $receivesReminders
                    );
                }
            } elseif ($action === 'remove') {
                $this->contactService->removeLink($contactId, $parishId);
            } else {
                $contact = $this->contacts->findLink($contactId, $parishId);

                if ($contact === null) {
                    wp_die(esc_html__('The parish contact link could not be found.', 'adct-parish-intake'), '', [
                        'response' => 404,
                    ]);
                }

                $email = EmailAddress::normalize((string) ($contact['email'] ?? ''));

                if ($action === 'verify') {
                    $this->contactService->verify($email);
                } elseif ($action === 'block') {
                    $this->contactService->block($email);
                } else {
                    $this->contactService->unblock($email);
                }
            }
        } catch (DomainException | InvalidArgumentException $failure) {
            wp_die(esc_html($failure->getMessage()), esc_html__('Contact update failed', 'adct-parish-intake'), [
                'response' => 400,
            ]);
        }

        wp_safe_redirect($this->pageUrl([
            'action' => 'edit',
            'id' => $parishId,
            'contact_saved' => 1,
        ]));
        exit;
    }

    public function handleImportPreview(): void
    {
        $this->requireDirectoryCapability();
        check_admin_referer(
            'adct_pi_directory_import_preview',
            'adct_pi_directory_import_nonce'
        );

        $type = sanitize_key($this->postText('import_type'));

        if (! in_array($type, ['parishes', 'deaneries'], true)) {
            wp_die(esc_html__('Choose a valid CSV import type.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        $file = $_FILES['csv_file'] ?? null;

        if (
            ! is_array($file)
            || ! isset($file['error'], $file['tmp_name'])
            || (! is_int($file['error']) && ! (is_string($file['error']) && ctype_digit($file['error'])))
        ) {
            wp_die(esc_html__('Choose a CSV file to preview.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK || ! is_string($file['tmp_name']) || ! is_uploaded_file($file['tmp_name'])) {
            wp_die(esc_html__('The CSV upload could not be read. Please choose the file again.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        $fileSize = filesize($file['tmp_name']);

        if ($fileSize === false || $fileSize > self::MAXIMUM_UPLOAD_BYTES) {
            wp_die(esc_html__('CSV files must be no larger than 1 MB.', 'adct-parish-intake'), '', [
                'response' => 413,
            ]);
        }

        $csv = file_get_contents($file['tmp_name']);

        if (! is_string($csv)) {
            wp_die(esc_html__('The CSV upload could not be read. Please choose the file again.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        $plan = $type === 'parishes'
            ? $this->importService->previewParishes($csv)
            : $this->importService->previewDeaneries($csv);
        $nonce = $this->postText('adct_pi_directory_import_nonce');
        $userId = get_current_user_id();
        $importId = hash('sha256', $userId . ':' . $nonce . ':' . wp_generate_uuid4());
        $transientKey = self::IMPORT_TRANSIENT_PREFIX . $importId;
        $stored = [
            'user_id' => $userId,
            'type' => $type,
            'csv' => $csv,
            'plan' => $plan,
        ];

        if (! set_transient($transientKey, $stored, self::IMPORT_PREVIEW_TTL)) {
            wp_die(esc_html__('The CSV preview could not be saved. Please upload it again.', 'adct-parish-intake'), '', [
                'response' => 500,
            ]);
        }

        wp_safe_redirect($this->pageUrl(['import_preview' => $importId]));
        exit;
    }

    public function handleImportConfirm(): void
    {
        $this->requireDirectoryCapability();
        $importId = sanitize_text_field($this->postText('import_id'));

        if (preg_match('/^[a-f0-9]{64}$/D', $importId) !== 1) {
            wp_die(esc_html__('The CSV preview has expired. Upload the file again.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        check_admin_referer(
            'adct_pi_directory_import_confirm_' . $importId,
            'adct_pi_directory_import_confirm_nonce'
        );

        $transientKey = self::IMPORT_TRANSIENT_PREFIX . $importId;
        $stored = get_transient($transientKey);

        if (
            ! is_array($stored)
            || (int) ($stored['user_id'] ?? 0) !== get_current_user_id()
            || ! in_array($stored['type'] ?? '', ['parishes', 'deaneries'], true)
            || ! is_string($stored['csv'] ?? null)
        ) {
            wp_die(esc_html__('The CSV preview has expired. Upload the file again.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        $type = (string) $stored['type'];
        $plan = $type === 'parishes'
            ? $this->importService->previewParishes($stored['csv'])
            : $this->importService->previewDeaneries($stored['csv']);

        if (! $plan->canImport()) {
            $stored['plan'] = $plan;
            set_transient($transientKey, $stored, self::IMPORT_PREVIEW_TTL);
            wp_safe_redirect($this->pageUrl([
                'import_preview' => $importId,
                'import_error' => 1,
            ]));
            exit;
        }

        $plan = $type === 'parishes'
            ? $this->importService->importParishes($stored['csv'])
            : $this->importService->importDeaneries($stored['csv']);
        delete_transient($transientKey);
        $counts = $plan->counts();

        wp_safe_redirect($this->pageUrl([
            'imported' => 1,
            'import_type' => $type,
            'created' => $counts[ImportRow::CREATE],
            'updated' => $counts[ImportRow::UPDATE],
            'unchanged' => $counts[ImportRow::UNCHANGED],
        ]));
        exit;
    }

    public function handleExport(): void
    {
        $this->requireDirectoryCapability();
        check_admin_referer('adct_pi_directory_export');

        $template = $this->getText('template') === '1';
        $rows = $template ? [] : $this->parishes->findAllForImport();
        $filename = $template ? 'parishes-template.csv' : 'parishes.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        $stream = fopen('php://output', 'wb');

        if (! is_resource($stream)) {
            wp_die(esc_html__('The CSV export could not be started.', 'adct-parish-intake'), '', [
                'response' => 500,
            ]);
        }

        if (fputcsv($stream, ParishCsvImporter::HEADERS, ',', '"', '') === false) {
            wp_die(esc_html__('The CSV export could not be written.', 'adct-parish-intake'), '', [
                'response' => 500,
            ]);
        }

        foreach ($rows as $row) {
            if (fputcsv($stream, $this->exportRow($row), ',', '"', '') === false) {
                wp_die(esc_html__('The CSV export could not be written.', 'adct-parish-intake'), '', [
                    'response' => 500,
                ]);
            }
        }

        fclose($stream);
        exit;
    }

    private function renderForm(?array $record, array $deaneries, array $parishes): void
    {
        $isNew = $record === null;
        $record = $record ?? [];
        $id = (int) ($record['id'] ?? 0);
        $mapLink = $this->mapLink($record);
        ?>
        <div class="wrap">
            <h1><?php echo $isNew ? 'Add parish' : 'Edit parish'; ?></h1>
            <p><a href="<?php echo esc_url($this->pageUrl()); ?>">&larr; Back to parishes</a></p>

            <?php if (! $isNew) : ?>
                <?php $this->renderEditTabs($id, 'details'); ?>
            <?php endif; ?>

            <?php $this->renderApprovalRouteStatus($isNew ? null : $record); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('adct_pi_save_parish', 'adct_pi_save_parish_nonce'); ?>
                <input type="hidden" name="action" value="adct_pi_save_parish" />
                <input type="hidden" name="parish_id" value="<?php echo esc_attr((string) $id); ?>" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="parish-name">Name</label></th>
                        <td><input id="parish-name" class="regular-text" name="name" type="text" required value="<?php echo esc_attr((string) ($record['name'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-slug">Slug</label></th>
                        <td><input id="parish-slug" class="regular-text" name="slug" type="text" required value="<?php echo esc_attr((string) ($record['slug'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-kind">Kind</label></th>
                        <td>
                            <select id="parish-kind" name="kind" required>
                                <?php foreach (ParishDataValidator::KINDS as $kind) : ?>
                                    <option value="<?php echo esc_attr($kind); ?>" <?php selected((string) ($record['kind'] ?? 'parish'), $kind); ?>>
                                        <?php echo esc_html($this->label($kind)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-area">Area</label></th>
                        <td><input id="parish-area" class="regular-text" name="area" type="text" value="<?php echo esc_attr((string) ($record['area'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-church">Church</label></th>
                        <td><input id="parish-church" class="regular-text" name="church" type="text" value="<?php echo esc_attr((string) ($record['church'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-deanery">Deanery</label></th>
                        <td>
                            <select id="parish-deanery" name="deanery_id">
                                <option value="0">No deanery</option>
                                <?php foreach ($deaneries as $deanery) : ?>
                                    <option value="<?php echo esc_attr((string) $deanery['id']); ?>" <?php selected((int) ($record['deanery_id'] ?? 0), (int) $deanery['id']); ?>>
                                        <?php echo esc_html((string) $deanery['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-parent">Parent parish</label></th>
                        <td>
                            <select id="parish-parent" name="parent_parish_id">
                                <option value="0">No parent</option>
                                <?php foreach ($parishes as $parish) : ?>
                                    <?php if ((int) $parish['id'] === $id) : ?>
                                        <?php continue; ?>
                                    <?php endif; ?>
                                    <option value="<?php echo esc_attr((string) $parish['id']); ?>" <?php selected((int) ($record['parent_parish_id'] ?? 0), (int) $parish['id']); ?>>
                                        <?php echo esc_html((string) $parish['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-address">Address</label></th>
                        <td><textarea id="parish-address" class="large-text" rows="3" name="address"><?php echo esc_textarea((string) ($record['address'] ?? '')); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-suburb">Suburb</label></th>
                        <td><input id="parish-suburb" class="regular-text" name="suburb" type="text" value="<?php echo esc_attr((string) ($record['suburb'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-latitude">Latitude</label></th>
                        <td><input id="parish-latitude" name="latitude" type="number" step="any" min="-90" max="90" value="<?php echo esc_attr((string) ($record['latitude'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-longitude">Longitude</label></th>
                        <td><input id="parish-longitude" name="longitude" type="number" step="any" min="-180" max="180" value="<?php echo esc_attr((string) ($record['longitude'] ?? '')); ?>" /></td>
                    </tr>
                    <?php if ($mapLink !== null) : ?>
                        <tr>
                            <th scope="row">Map helper</th>
                            <td><a href="<?php echo esc_url($mapLink['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($mapLink['label']); ?></a><p class="description">Copy the coordinates from the map and paste them into the fields above.</p></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><label for="parish-website">Website</label></th>
                        <td><input id="parish-website" class="regular-text" name="website" type="url" value="<?php echo esc_attr((string) ($record['website'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-phone">Phone</label></th>
                        <td><input id="parish-phone" class="regular-text" name="phone" type="tel" value="<?php echo esc_attr((string) ($record['phone'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-cadence">Expected cadence (days)</label></th>
                        <td><input id="parish-cadence" name="expected_cadence_days" type="number" min="1" max="65535" step="1" value="<?php echo esc_attr((string) ($record['expected_cadence_days'] ?? '')); ?>" /><p class="description">Leave blank if no reminder cadence is expected.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Reminders</th>
                        <td><label><input name="reminders_enabled" type="checkbox" value="1" <?php checked((int) ($record['reminders_enabled'] ?? 1), 1); ?> /> Enable parish reminders</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-status">Status</label></th>
                        <td>
                            <select id="parish-status" name="status">
                                <option value="active" <?php selected((string) ($record['status'] ?? 'active'), 'active'); ?>>Active</option>
                                <option value="inactive" <?php selected((string) ($record['status'] ?? 'active'), 'inactive'); ?>>Inactive</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="parish-notes">Notes</label></th>
                        <td><textarea id="parish-notes" class="large-text" rows="4" name="notes"><?php echo esc_textarea((string) ($record['notes'] ?? '')); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button($isNew ? 'Add parish' : 'Save parish'); ?>
            </form>

            <?php if (! $isNew) : ?>
                <?php $this->renderContacts($id); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $parish
     */
    private function renderVenueTab(array $parish): void
    {
        $parishId = (int) ($parish['id'] ?? 0);
        $venues = $this->venues->findForParish($parishId);
        ?>
        <div class="wrap">
            <h1>Venues for <?php echo esc_html((string) ($parish['name'] ?? '')); ?></h1>
            <p><a href="<?php echo esc_url($this->pageUrl([
                'action' => 'edit',
                'id' => $parishId,
            ])); ?>">&larr; Back to parish details</a></p>
            <?php $this->renderEditTabs($parishId, 'venues'); ?>
            <?php $this->renderVenueNotices(); ?>

            <p class="description">
                Keep one active default venue for this parish. Selecting a default clears the flag from its other venues.
                Venues created from the directory are provisional; confirm their names and locations before relying on them.
            </p>

            <?php if ($venues === []) : ?>
                <p>No venues are recorded for this parish yet.</p>
            <?php endif; ?>

            <?php foreach ($venues as $venue) : ?>
                <section class="postbox" style="padding: 12px 16px; margin-top: 16px;">
                    <h2 class="hndle">
                        <?php echo esc_html($venue->name); ?>
                        <?php if ($venue->isDefault) : ?>
                            <span class="description">(Default)</span>
                        <?php endif; ?>
                    </h2>
                    <p class="description">
                        Status: <?php echo esc_html($this->label($venue->status)); ?>
                        <?php if ($venue->sourceParishId !== null) : ?>
                            · Imported from linked parish record #<?php echo esc_html((string) $venue->sourceParishId); ?>
                        <?php endif; ?>
                    </p>
                    <?php $this->renderVenueForm($parishId, $venue); ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="adct_pi_venue_action" />
                        <input type="hidden" name="venue_action" value="<?php echo $venue->status === Venue::ACTIVE ? 'deactivate' : 'reactivate'; ?>" />
                        <input type="hidden" name="parish_id" value="<?php echo esc_attr((string) $parishId); ?>" />
                        <input type="hidden" name="venue_id" value="<?php echo esc_attr((string) $venue->id); ?>" />
                        <?php wp_nonce_field($this->venueNonceAction(
                            $venue->status === Venue::ACTIVE ? 'deactivate' : 'reactivate',
                            $parishId,
                            $venue->id
                        )); ?>
                        <?php
                        submit_button(
                            $venue->status === Venue::ACTIVE ? 'Deactivate venue' : 'Reactivate venue',
                            $venue->status === Venue::ACTIVE ? 'delete' : 'secondary',
                            'submit',
                            false
                        );
                        ?>
                    </form>
                </section>
            <?php endforeach; ?>

            <hr />
            <h2>Add a venue</h2>
            <p class="description">The first active venue is made the default automatically.</p>
            <?php $this->renderVenueForm($parishId, null); ?>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $parish
     */
    private function renderSourcesTab(array $parish): void
    {
        $parishId = (int) ($parish['id'] ?? 0);
        ?>
        <div class="wrap">
            <h1>Sources for <?php echo esc_html((string) ($parish['name'] ?? '')); ?></h1>
            <p><a href="<?php echo esc_url($this->pageUrl([
                'action' => 'edit',
                'id' => $parishId,
            ])); ?>">&larr; Back to parish details</a></p>
            <?php $this->renderEditTabs($parishId, 'sources'); ?>
            <?php $this->sourcesPage->renderParishTab($parishId); ?>
        </div>
        <?php
    }

    private function renderEditTabs(int $parishId, string $activeTab): void
    {
        ?>
        <nav class="nav-tab-wrapper wp-clearfix" aria-label="Parish edit sections">
            <a class="nav-tab <?php echo $activeTab === 'details' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($this->pageUrl([
                'action' => 'edit',
                'id' => $parishId,
            ])); ?>">Parish details</a>
            <a class="nav-tab <?php echo $activeTab === 'venues' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($this->pageUrl([
                'action' => 'edit',
                'id' => $parishId,
                'tab' => 'venues',
            ])); ?>">Venues</a>
            <a class="nav-tab <?php echo $activeTab === 'sources' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($this->pageUrl([
                'action' => 'edit',
                'id' => $parishId,
                'tab' => 'sources',
            ])); ?>">Sources</a>
        </nav>
        <?php
    }

    private function renderVenueForm(int $parishId, ?Venue $venue): void
    {
        $venueId = $venue?->id ?? 0;
        $prefix = 'venue-' . ($venueId > 0 ? (string) $venueId : 'new');
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adct_pi_venue_action" />
            <input type="hidden" name="venue_action" value="save" />
            <input type="hidden" name="parish_id" value="<?php echo esc_attr((string) $parishId); ?>" />
            <input type="hidden" name="venue_id" value="<?php echo esc_attr((string) $venueId); ?>" />
            <?php wp_nonce_field($this->venueNonceAction('save', $parishId, $venueId)); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-name">Name</label></th>
                    <td><input id="<?php echo esc_attr($prefix); ?>-name" class="regular-text" name="name" type="text" maxlength="191" required value="<?php echo esc_attr($venue?->name ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-aliases">Aliases</label></th>
                    <td><textarea id="<?php echo esc_attr($prefix); ?>-aliases" class="large-text" rows="3" name="aliases"><?php echo esc_textarea(implode("\n", $venue?->aliases ?? [])); ?></textarea><p class="description">Enter one alias per line or separate aliases with commas.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-address">Address</label></th>
                    <td><textarea id="<?php echo esc_attr($prefix); ?>-address" class="large-text" rows="3" name="address"><?php echo esc_textarea($venue?->address ?? ''); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-suburb">Suburb</label></th>
                    <td><input id="<?php echo esc_attr($prefix); ?>-suburb" class="regular-text" name="suburb" type="text" value="<?php echo esc_attr($venue?->suburb ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-latitude">Latitude</label></th>
                    <td><input id="<?php echo esc_attr($prefix); ?>-latitude" name="latitude" type="number" step="any" min="-90" max="90" value="<?php echo esc_attr($venue?->latitude === null ? '' : (string) $venue->latitude); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-longitude">Longitude</label></th>
                    <td><input id="<?php echo esc_attr($prefix); ?>-longitude" name="longitude" type="number" step="any" min="-180" max="180" value="<?php echo esc_attr($venue?->longitude === null ? '' : (string) $venue->longitude); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Default</th>
                    <td>
                        <label>
                            <input name="is_default" type="checkbox" value="1" <?php checked($venue?->isDefault ?? false); ?> <?php disabled($venue !== null && $venue->status !== Venue::ACTIVE); ?> />
                            Use as this parish's default venue
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button($venue === null ? 'Add venue' : 'Save venue', 'primary', 'submit', false); ?>
        </form>
        <?php
    }

    private function renderVenueNotices(): void
    {
        if (isset($_GET['venue_saved'])) {
            ?>
            <div class="notice notice-success is-dismissible"><p>Parish venue saved.</p></div>
            <?php
        }
    }

    private function renderContacts(int $parishId): void
    {
        $contacts = $this->contacts->findForParish($parishId);
        ?>
        <hr />
        <h2>Contacts</h2>
        <p>Sender trust applies to the email address across every parish link. A verified sender still needs a dean or archdiocese reviewer to approve each new event.</p>

        <?php if (isset($_GET['contact_saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Parish contact updated.</p></div>
        <?php endif; ?>

        <?php if ($contacts === []) : ?>
            <p>No contacts are linked to this parish yet.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col">Email and details</th>
                        <th scope="col">Trust</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contacts as $contact) : ?>
                        <?php
                        $contactId = (int) ($contact['id'] ?? 0);
                        $trust = (string) ($contact['trust'] ?? SenderTrust::UNKNOWN);
                        $emailFieldId = 'contact-email-' . $contactId;
                        ?>
                        <tr>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="adct_pi_parish_contact" />
                                    <input type="hidden" name="contact_action" value="save" />
                                    <input type="hidden" name="parish_id" value="<?php echo esc_attr((string) $parishId); ?>" />
                                    <input type="hidden" name="contact_id" value="<?php echo esc_attr((string) $contactId); ?>" />
                                    <?php wp_nonce_field($this->contactNonceAction('save', $parishId, $contactId)); ?>
                                    <label class="screen-reader-text" for="<?php echo esc_attr($emailFieldId); ?>">Email address</label>
                                    <input id="<?php echo esc_attr($emailFieldId); ?>" class="regular-text" name="email" type="email" maxlength="191" required value="<?php echo esc_attr((string) ($contact['email'] ?? '')); ?>" />
                                    <p>
                                        <label>Display name
                                            <input class="regular-text" name="display_name" type="text" maxlength="191" value="<?php echo esc_attr((string) ($contact['display_name'] ?? '')); ?>" />
                                        </label>
                                        <label>Role
                                            <input class="regular-text" name="role_label" type="text" maxlength="191" value="<?php echo esc_attr((string) ($contact['role_label'] ?? '')); ?>" />
                                        </label>
                                    </p>
                                    <label>
                                        <input name="receives_reminders" type="checkbox" value="1" <?php checked((int) ($contact['receives_reminders'] ?? 0), 1); ?> />
                                        Receives reminders
                                    </label>
                                    <p><button class="button button-secondary" type="submit">Save contact</button></p>
                                </form>
                            </td>
                            <td><?php echo esc_html($this->label($trust)); ?></td>
                            <td>
                                <?php if ($trust === SenderTrust::BLOCKED) : ?>
                                    <?php $this->renderContactActionForm('unblock', $parishId, $contactId, 'Unblock address'); ?>
                                <?php else : ?>
                                    <?php if ($trust !== SenderTrust::VERIFIED) : ?>
                                        <?php $this->renderContactActionForm('verify', $parishId, $contactId, 'Verify address'); ?>
                                    <?php endif; ?>
                                    <?php $this->renderContactActionForm('block', $parishId, $contactId, 'Block address', 'secondary'); ?>
                                <?php endif; ?>
                                <?php $this->renderContactActionForm('remove', $parishId, $contactId, 'Remove parish link', 'delete'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>Add a contact</h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adct_pi_parish_contact" />
            <input type="hidden" name="contact_action" value="save" />
            <input type="hidden" name="parish_id" value="<?php echo esc_attr((string) $parishId); ?>" />
            <input type="hidden" name="contact_id" value="0" />
            <?php wp_nonce_field($this->contactNonceAction('save', $parishId, 0)); ?>
            <p>
                <label for="new-contact-email">Email address</label><br />
                <input id="new-contact-email" class="regular-text" name="email" type="email" maxlength="191" required />
            </p>
            <p>
                <label for="new-contact-name">Display name</label><br />
                <input id="new-contact-name" class="regular-text" name="display_name" type="text" maxlength="191" />
            </p>
            <p>
                <label for="new-contact-role">Role</label><br />
                <input id="new-contact-role" class="regular-text" name="role_label" type="text" maxlength="191" />
            </p>
            <p><label><input name="receives_reminders" type="checkbox" value="1" checked /> Receives reminders</label></p>
            <?php submit_button('Add contact', 'secondary'); ?>
        </form>
        <?php
    }

    private function renderContactActionForm(
        string $action,
        int $parishId,
        int $contactId,
        string $buttonLabel,
        string $buttonClass = 'secondary'
    ): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adct_pi_parish_contact" />
            <input type="hidden" name="contact_action" value="<?php echo esc_attr($action); ?>" />
            <input type="hidden" name="parish_id" value="<?php echo esc_attr((string) $parishId); ?>" />
            <input type="hidden" name="contact_id" value="<?php echo esc_attr((string) $contactId); ?>" />
            <?php wp_nonce_field($this->contactNonceAction($action, $parishId, $contactId)); ?>
            <button class="button button-<?php echo esc_attr($buttonClass); ?>" type="submit"><?php echo esc_html($buttonLabel); ?></button>
        </form>
        <?php
    }

    private function renderImportSection(): void
    {
        ?>
        <hr />
        <h2>Import and export</h2>
        <p>Upload a CSV to see a preview first. Imports match rows by slug, update matching records, and never delete records. Import deaneries before parishes.</p>
        <p>
            <a class="button" href="<?php echo esc_url($this->exportUrl(false)); ?>">Export parishes CSV</a>
            <a class="button" href="<?php echo esc_url($this->exportUrl(true)); ?>">Download CSV template</a>
        </p>
        <h3>Import deaneries</h3>
        <?php $this->renderUploadForm('deaneries', 'Preview deaneries CSV'); ?>
        <h3>Import parishes</h3>
        <?php $this->renderUploadForm('parishes', 'Preview parish CSV'); ?>
        <p class="description">CSV files are limited to 1 MB and 2,000 data rows. The upload is kept in a short-lived admin transient only until you confirm or the preview expires.</p>
        <hr />
        <h3>Set up venue records</h3>
        <p>Parish imports create missing default venues and linked venues for outstations and mass centres. Use this repeatable action to backfill existing directory rows; it does not duplicate existing imported venues. Generated default venues are provisional and should be checked.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adct_pi_venue_backfill" />
            <?php wp_nonce_field('adct_pi_venue_backfill'); ?>
            <?php submit_button('Create missing venue records', 'secondary', 'submit', false); ?>
        </form>
        <?php
    }

    private function renderUploadForm(string $type, string $buttonLabel): void
    {
        ?>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('adct_pi_directory_import_preview', 'adct_pi_directory_import_nonce'); ?>
            <input type="hidden" name="action" value="adct_pi_directory_import_preview" />
            <input type="hidden" name="import_type" value="<?php echo esc_attr($type); ?>" />
            <label class="screen-reader-text" for="<?php echo esc_attr($type); ?>-csv">Choose a CSV file</label>
            <input id="<?php echo esc_attr($type); ?>-csv" name="csv_file" type="file" accept=".csv,text/csv" required />
            <?php submit_button($buttonLabel, 'secondary', 'submit', false); ?>
        </form>
        <?php
    }

    private function renderImportPreview(): void
    {
        $importId = sanitize_text_field($this->getText('import_preview'));

        if ($importId === '') {
            return;
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $importId) !== 1) {
            return;
        }

        $stored = get_transient(self::IMPORT_TRANSIENT_PREFIX . $importId);

        if (
            ! is_array($stored)
            || (int) ($stored['user_id'] ?? 0) !== get_current_user_id()
            || !(($stored['plan'] ?? null) instanceof ImportPlan)
            || ! in_array($stored['type'] ?? '', ['parishes', 'deaneries'], true)
        ) {
            ?>
            <div class="notice notice-warning"><p>This CSV preview has expired. Upload the file again.</p></div>
            <?php

            return;
        }

        $plan = $stored['plan'];
        $type = (string) $stored['type'];
        ?>
        <hr />
        <h2><?php echo $type === 'parishes' ? 'Parish import preview' : 'Deanery import preview'; ?></h2>
        <?php if (isset($_GET['import_error'])) : ?>
            <div class="notice notice-error"><p>The directory changed after preview. Review the latest validation results before importing.</p></div>
        <?php endif; ?>
        <?php if ($plan->fileErrors !== []) : ?>
            <div class="notice notice-error">
                <p><strong>Fix the CSV file before importing:</strong></p>
                <ul>
                    <?php foreach ($plan->fileErrors as $error) : ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php $this->renderPlanCounts($plan); ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col">CSV row</th>
                    <th scope="col">Action</th>
                    <th scope="col">Name</th>
                    <th scope="col">Slug</th>
                    <th scope="col">References</th>
                    <th scope="col">Errors</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plan->rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $row->rowNumber); ?></td>
                        <td><?php echo esc_html($this->label($row->action)); ?></td>
                        <td><?php echo esc_html((string) ($row->values['name'] ?? '')); ?></td>
                        <td><code><?php echo esc_html((string) ($row->values['slug'] ?? '')); ?></code></td>
                        <td>
                            <?php
                            if ($type === 'parishes') {
                                echo esc_html(implode(' / ', array_filter([
                                    (string) ($row->values['deanery_slug'] ?? ''),
                                    (string) ($row->values['parent_slug'] ?? ''),
                                ], static fn (string $value): bool => $value !== '')));
                            } else {
                                echo esc_html(implode(' / ', array_filter([
                                    (string) ($row->values['dean_name'] ?? ''),
                                    (string) ($row->values['vice_dean_name'] ?? ''),
                                    (string) ($row->values['secretary_name'] ?? ''),
                                ], static fn (string $value): bool => $value !== '')));
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html(implode(' ', $row->errors)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($plan->rows === []) : ?>
                    <tr><td colspan="6">No data rows were found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($plan->canImport()) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(
                    'adct_pi_directory_import_confirm_' . $importId,
                    'adct_pi_directory_import_confirm_nonce'
                ); ?>
                <input type="hidden" name="action" value="adct_pi_directory_import_confirm" />
                <input type="hidden" name="import_id" value="<?php echo esc_attr($importId); ?>" />
                <?php submit_button('Confirm import', 'primary'); ?>
            </form>
        <?php else : ?>
            <p>There are validation errors. Fix the CSV and upload it again; no rows have been imported.</p>
        <?php endif; ?>
        <?php
    }

    private function renderPlanCounts(ImportPlan $plan): void
    {
        $counts = $plan->counts();
        ?>
        <p>
            Creates: <strong><?php echo esc_html((string) $counts[ImportRow::CREATE]); ?></strong> |
            Updates: <strong><?php echo esc_html((string) $counts[ImportRow::UPDATE]); ?></strong> |
            Unchanged: <strong><?php echo esc_html((string) $counts[ImportRow::UNCHANGED]); ?></strong> |
            Errors: <strong><?php echo esc_html((string) $counts['errors']); ?></strong>
        </p>
        <?php
    }

    private function renderPagination(array $filters, int $page, int $total): void
    {
        $pages = (int) ceil($total / self::PAGE_SIZE);

        if ($pages <= 1) {
            return;
        }

        $arguments = ['page' => self::PAGE_SLUG, 'paged' => '%#%'];

        foreach ($filters as $key => $value) {
            if ($value !== '' && $value !== 0) {
                $arguments[$key] = $value;
            }
        }

        $links = paginate_links([
            'base' => add_query_arg($arguments, admin_url('admin.php')),
            'format' => '',
            'current' => $page,
            'total' => $pages,
            'type' => 'plain',
        ]);

        if (is_string($links)) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($links) . '</div></div>';
        }
    }

    private function renderNotices(): void
    {
        if (isset($_GET['saved'])) {
            ?>
            <div class="notice notice-success is-dismissible"><p>Parish details saved.</p></div>
            <?php
        }

        if (isset($_GET['bulk_assigned'])) {
            $count = absint($this->getText('bulk_assigned'));
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html((string) $count); ?> selected parish record(s) assigned.</p>
            </div>
            <?php
        }

        if (isset($_GET['venues_seeded'])) {
            $count = absint($this->getText('venues_seeded'));
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html((string) $count); ?> venue record(s) created or default assignment(s) repaired. Generated default venues are provisional; check their names and locations.</p>
            </div>
            <?php
        }

        if (isset($_GET['imported'])) {
            $type = sanitize_key($this->getText('import_type'));
            $counts = [
                'created' => absint($this->getText('created')),
                'updated' => absint($this->getText('updated')),
                'unchanged' => absint($this->getText('unchanged')),
            ];
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php echo $type === 'deaneries' ? 'Deanery' : 'Parish'; ?> import complete:
                    <?php echo esc_html((string) $counts['created']); ?> created,
                    <?php echo esc_html((string) $counts['updated']); ?> updated,
                    <?php echo esc_html((string) $counts['unchanged']); ?> unchanged.
                    <?php if ($type === 'parishes') : ?>
                        Missing default and linked outstation venues were also set up. Review provisional defaults on the Venues tabs.
                    <?php endif; ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * @param array<string, mixed> $record
     * @return array{url: string, label: string}|null
     */
    private function mapLink(array $record): ?array
    {
        $latitude = $record['latitude'] ?? null;
        $longitude = $record['longitude'] ?? null;

        if (
            $latitude !== null
            && $latitude !== ''
            && $longitude !== null
            && $longitude !== ''
            && is_numeric($latitude)
            && is_numeric($longitude)
            && (float) $latitude >= -90
            && (float) $latitude <= 90
            && (float) $longitude >= -180
            && (float) $longitude <= 180
        ) {
            return [
                'url' => add_query_arg(
                    ['mlat' => (string) $latitude, 'mlon' => (string) $longitude],
                    'https://www.openstreetmap.org/'
                ),
                'label' => 'Find on OpenStreetMap',
            ];
        }

        $address = trim((string) ($record['address'] ?? ''));
        $suburb = trim((string) ($record['suburb'] ?? ''));
        $search = trim($address . ' ' . $suburb);

        if ($search === '') {
            return [
                'url' => 'https://www.google.com/maps/',
                'label' => 'Find on Google Maps',
            ];
        }

        return [
            'url' => add_query_arg(
                ['api' => '1', 'query' => $search],
                'https://www.google.com/maps/search/'
            ),
            'label' => 'Find address on Google Maps',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     */
    private function indexById(array $records): array
    {
        $indexed = [];

        foreach ($records as $record) {
            $id = (int) ($record['id'] ?? 0);

            if ($id > 0) {
                $indexed[$id] = $record;
            }
        }

        return $indexed;
    }

    private function exportUrl(bool $template): string
    {
        $url = admin_url('admin-post.php?action=adct_pi_directory_export');

        if ($template) {
            $url = add_query_arg('template', '1', $url);
        }

        return wp_nonce_url($url, 'adct_pi_directory_export');
    }

    private function pageUrl(array $arguments = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::PAGE_SLUG], $arguments),
            admin_url('admin.php')
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<int, string|null>
     */
    private function exportRow(array $record): array
    {
        $kind = (string) ($record['kind'] ?? '');
        $parentSlug = $record['parent_slug'] ?? null;

        $values = [
            'slug' => $record['slug'] ?? '',
            'name' => $record['name'] ?? '',
            'area' => $record['area'] ?? '',
            'church' => $record['church'] ?? '',
            'kind' => $kind,
            'is_mother_parish' => $parentSlug !== null ? 'no' : '',
            'parent_slug' => $parentSlug ?? '',
            'deanery_slug' => $record['deanery_slug'] ?? '',
            'address' => $record['address'] ?? '',
            'office_email' => $record['office_email'] ?? '',
            'latitude' => $record['latitude'] ?? '',
            'longitude' => $record['longitude'] ?? '',
            'suburb' => $record['suburb'] ?? '',
            'website' => $record['website'] ?? '',
            'phone' => $record['phone'] ?? '',
            'expected_cadence_days' => $record['expected_cadence_days'] ?? '',
            'reminders_enabled' => (int) ($record['reminders_enabled'] ?? 1) === 1 ? 'yes' : 'no',
            'status' => $record['status'] ?? 'active',
            'notes' => $record['notes'] ?? '',
        ];

        $orderedValues = [];

        foreach (ParishCsvImporter::HEADERS as $header) {
            $orderedValues[] = CsvFormulaGuard::protect(
                (string) ($values[$header] ?? ''),
                $header === 'phone'
            );
        }

        return $orderedValues;
    }

    private function contactNonceAction(string $action, int $parishId, int $contactId): string
    {
        return 'adct_pi_parish_contact_' . $action . '_' . $parishId . '_' . $contactId;
    }

    private function venueNonceAction(string $action, int $parishId, int $venueId): string
    {
        return 'adct_pi_venue_' . $action . '_' . $parishId . '_' . $venueId;
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private function renderApprovalRouteStatus(?array $record): void
    {
        if ($record === null) {
            ?>
            <div class="notice notice-warning inline">
                <p><strong>Reviewers only.</strong> A new parish has no deanery until one is selected. A parish with no deanery, or no active deanery approver, goes to archdiocese reviewers only.</p>
            </div>
            <?php

            return;
        }

        $route = $this->approvalRouteResolver->forParish((int) ($record['id'] ?? 0));

        if ($route->reviewersOnly) {
            ?>
            <div class="notice notice-warning inline">
                <p><strong>Reviewers only.</strong> <?php echo esc_html($this->routeReason($route)); ?></p>
            </div>
            <?php

            return;
        }
        ?>
        <div class="notice notice-info inline">
            <p>Approval route: <strong><?php echo esc_html((string) count($route->approvers)); ?> active deanery approver(s)</strong> and archdiocese reviewers.</p>
        </div>
        <?php
    }

    private function routeReason(ApprovalRoute $route): string
    {
        return match ($route->reason) {
            ApprovalRoute::REASON_NO_DEANERY => 'No deanery is assigned to this parish.',
            ApprovalRoute::REASON_NO_ACTIVE_APPROVER => 'The assigned deanery has no active approver.',
            ApprovalRoute::REASON_DEANERY_INACTIVE => 'The assigned deanery is inactive.',
            default => 'No active deanery approver is available.',
        };
    }

    private function requireDirectoryCapability(): void
    {
        if (! current_user_can(Capabilities::MANAGE_DIRECTORY)) {
            wp_die(esc_html__('You do not have permission to manage the parish directory.', 'adct-parish-intake'), '', [
                'response' => 403,
            ]);
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
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
