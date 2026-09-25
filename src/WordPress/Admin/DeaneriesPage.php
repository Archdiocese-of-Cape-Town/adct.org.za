<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Approval\Approver;
use ADCT\ParishIntake\Core\Approval\ApproverSettings;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Directory\ParishDataValidator;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryApproverRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryRepository;
use ADCT\ParishIntake\WordPress\Directory\DeaneryApproverAssignmentService;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

final class DeaneriesPage
{
    private const PAGE_SLUG = 'adct-parish-intake-deaneries';

    public function __construct(
        private DeaneryRepository $deaneries,
        private DeaneryApproverRepository $approvers,
        private DeaneryApproverAssignmentService $assignmentService,
        private ClockInterface $clock
    ) {
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'adct-parish-intake',
            'Deaneries',
            'Deaneries',
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
            $this->renderForm(null);

            return;
        }

        if ($action === 'edit') {
            $id = absint($this->getText('id'));
            $deanery = $id > 0 ? $this->deaneries->findById($id) : null;

            if ($deanery === null) {
                wp_die(esc_html__('The deanery could not be found.', 'adct-parish-intake'), '', [
                    'response' => 404,
                ]);
            }

            $this->renderForm($deanery);

            return;
        }

        $this->renderList();
    }

    public function handleSaveDeanery(): void
    {
        $this->requireDirectoryCapability();
        check_admin_referer('adct_pi_save_deanery', 'deanery_nonce');

        $id = absint($this->postText('deanery_id'));
        $name = sanitize_text_field($this->postText('name'));
        $slug = sanitize_title($this->postText('slug'));
        $status = sanitize_key($this->postText('status'));
        $values = [
            'name' => $name,
            'slug' => $slug,
            'dean_name' => $this->nullableText($this->postText('dean_name')),
            'vice_dean_name' => $this->nullableText($this->postText('vice_dean_name')),
            'secretary_name' => $this->nullableText($this->postText('secretary_name')),
            'status' => $status,
            'updated_at' => $this->timestamp(),
        ];

        if ($id > 0 && $this->deaneries->findById($id) === null) {
            wp_die(esc_html__('The deanery could not be found.', 'adct-parish-intake'), '', [
                'response' => 404,
            ]);
        }

        if ($name === '' || $slug === '' || ! ParishDataValidator::isValidSlug($slug)) {
            wp_die(esc_html__('Enter a deanery name and a valid slug.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        if (! in_array($status, ParishDataValidator::STATUSES, true)) {
            wp_die(esc_html__('Choose active or inactive for the deanery status.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        $existingSlug = $this->deaneries->findBySlug($slug);

        if ($existingSlug !== null && (int) ($existingSlug['id'] ?? 0) !== $id) {
            wp_die(esc_html__('A deanery with that slug already exists.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        if ($id > 0) {
            $this->deaneries->update($id, $values);
        } else {
            $values['created_at'] = $values['updated_at'];
            $this->deaneries->insert($values);
        }

        wp_safe_redirect($this->pageUrl(['saved' => 1]));
        exit;
    }

    public function handleDeactivateDeanery(): void
    {
        $this->requireDirectoryCapability();
        $id = absint($this->postText('deanery_id'));
        check_admin_referer($this->deaneryNonceAction($id), 'deanery_nonce');

        if ($id < 1 || $this->deaneries->findById($id) === null) {
            wp_die(esc_html__('The deanery could not be found.', 'adct-parish-intake'), '', [
                'response' => 404,
            ]);
        }

        $this->deaneries->update($id, [
            'status' => 'inactive',
            'updated_at' => $this->timestamp(),
        ]);

        wp_safe_redirect($this->pageUrl(['deactivated' => 1]));
        exit;
    }

    public function handleApproverAction(): void
    {
        $this->requireDirectoryCapability();
        $action = sanitize_key($this->postText('approver_action'));
        $deaneryId = absint($this->postText('deanery_id'));

        if (! in_array($action, ['save', 'deactivate'], true)) {
            wp_die(esc_html__('Choose a valid approver action.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        if ($deaneryId < 1 || $this->deaneries->findById($deaneryId) === null) {
            wp_die(esc_html__('The deanery could not be found.', 'adct-parish-intake'), '', [
                'response' => 404,
            ]);
        }

        $assignmentId = absint($this->postText('assignment_id'));

        if ($action === 'deactivate') {
            check_admin_referer(
                $this->approverDeactivateNonceAction($deaneryId, $assignmentId),
                'approver_nonce'
            );

            try {
                $this->assignmentService->deactivateAssignment(
                    $assignmentId,
                    $deaneryId,
                    $this->timestamp()
                );
            } catch (DomainException | InvalidArgumentException $failure) {
                wp_die(esc_html($failure->getMessage()), esc_html__('Approver update failed', 'adct-parish-intake'), [
                    'response' => 400,
                ]);
            }

            wp_safe_redirect($this->pageUrl([
                'action' => 'edit',
                'id' => $deaneryId,
                'approver_deactivated' => 1,
            ]));
            exit;
        }

        check_admin_referer(
            $this->approverSaveNonceAction($deaneryId, $assignmentId),
            'approver_nonce'
        );

        try {
            $settings = new ApproverSettings(
                $this->postText('approval_email'),
                sanitize_text_field($this->postText('label')),
                sanitize_key($this->postText('notify_mode')),
                $this->postText('reminders_enabled') === '1',
                $this->postText('active') === '1'
            );
            $timestamp = $this->timestamp();

            if ($assignmentId > 0) {
                $this->assignmentService->updateAssignment(
                    $assignmentId,
                    $deaneryId,
                    $settings,
                    $timestamp
                );
            } else {
                $userSource = sanitize_key($this->postText('user_source'));

                if ($userSource === 'existing') {
                    $this->assignmentService->assignExistingUser(
                        $deaneryId,
                        absint($this->postText('wp_user_id')),
                        $settings,
                        $timestamp
                    );
                } elseif ($userSource === 'create') {
                    $this->assignmentService->createUserAndAssign(
                        $deaneryId,
                        $this->postText('new_user_login'),
                        $this->postText('new_user_email'),
                        $settings,
                        $timestamp
                    );
                } else {
                    throw new InvalidArgumentException('Choose an existing user or create a new account.');
                }
            }
        } catch (DomainException | InvalidArgumentException $failure) {
            wp_die(esc_html($failure->getMessage()), esc_html__('Approver update failed', 'adct-parish-intake'), [
                'response' => 400,
            ]);
        }

        wp_safe_redirect($this->pageUrl([
            'action' => 'edit',
            'id' => $deaneryId,
            'approver_saved' => 1,
        ]));
        exit;
    }

    private function renderList(): void
    {
        $deaneries = $this->deaneries->findAllWithActiveApproverCounts();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Deaneries</h1>
            <a class="page-title-action" href="<?php echo esc_url($this->pageUrl(['action' => 'add'])); ?>">Add deanery</a>
            <hr class="wp-header-end" />

            <?php $this->renderNotices(); ?>

            <p>Parishes with no deanery, or whose deanery has no active approver, go to archdiocese reviewers only.</p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col">Deanery</th>
                        <th scope="col">Display names</th>
                        <th scope="col">Status</th>
                        <th scope="col">Approval route</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($deaneries === []) : ?>
                        <tr><td colspan="5">No deaneries have been added.</td></tr>
                    <?php else : ?>
                        <?php foreach ($deaneries as $deanery) : ?>
                            <?php
                            $id = (int) ($deanery['id'] ?? 0);
                            $status = (string) ($deanery['status'] ?? 'inactive');
                            $activeApproverCount = (int) ($deanery['active_approver_count'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <strong><a href="<?php echo esc_url($this->pageUrl([
                                        'action' => 'edit',
                                        'id' => $id,
                                    ])); ?>"><?php echo esc_html((string) ($deanery['name'] ?? '')); ?></a></strong>
                                    <br /><code><?php echo esc_html((string) ($deanery['slug'] ?? '')); ?></code>
                                </td>
                                <td>
                                    <?php
                                    echo esc_html(implode(' / ', array_filter([
                                        (string) ($deanery['dean_name'] ?? ''),
                                        (string) ($deanery['vice_dean_name'] ?? ''),
                                        (string) ($deanery['secretary_name'] ?? ''),
                                    ], static fn (string $value): bool => trim($value) !== '')));
                                    ?>
                                </td>
                                <td><?php echo esc_html(ucfirst($status)); ?></td>
                                <td><?php echo esc_html($this->routeSummary($status, $activeApproverCount)); ?></td>
                                <td>
                                    <?php if ($status === 'active') : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <input type="hidden" name="action" value="adct_pi_deactivate_deanery" />
                                            <input type="hidden" name="deanery_id" value="<?php echo esc_attr((string) $id); ?>" />
                                            <?php wp_nonce_field($this->deaneryNonceAction($id), 'deanery_nonce'); ?>
                                            <button class="button button-secondary" type="submit">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private function renderForm(?array $record): void
    {
        $isNew = $record === null;
        $record = $record ?? [];
        $id = (int) ($record['id'] ?? 0);
        ?>
        <div class="wrap">
            <h1><?php echo $isNew ? 'Add deanery' : 'Edit deanery'; ?></h1>
            <p><a href="<?php echo esc_url($this->pageUrl()); ?>">&larr; Back to deaneries</a></p>

            <?php $this->renderNotices(); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="adct_pi_save_deanery" />
                <input type="hidden" name="deanery_id" value="<?php echo esc_attr((string) $id); ?>" />
                <?php wp_nonce_field('adct_pi_save_deanery', 'deanery_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="deanery-name">Name</label></th>
                        <td><input id="deanery-name" class="regular-text" name="name" type="text" maxlength="191" required value="<?php echo esc_attr((string) ($record['name'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="deanery-slug">Slug</label></th>
                        <td><input id="deanery-slug" class="regular-text" name="slug" type="text" maxlength="191" required value="<?php echo esc_attr((string) ($record['slug'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="deanery-dean">Dean display name</label></th>
                        <td><input id="deanery-dean" class="regular-text" name="dean_name" type="text" maxlength="191" value="<?php echo esc_attr((string) ($record['dean_name'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="deanery-vice-dean">Vice-dean display name</label></th>
                        <td><input id="deanery-vice-dean" class="regular-text" name="vice_dean_name" type="text" maxlength="191" value="<?php echo esc_attr((string) ($record['vice_dean_name'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="deanery-secretary">Secretary display name</label></th>
                        <td><input id="deanery-secretary" class="regular-text" name="secretary_name" type="text" maxlength="191" value="<?php echo esc_attr((string) ($record['secretary_name'] ?? '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="deanery-status">Status</label></th>
                        <td>
                            <select id="deanery-status" name="status">
                                <option value="active" <?php selected((string) ($record['status'] ?? 'active'), 'active'); ?>>Active</option>
                                <option value="inactive" <?php selected((string) ($record['status'] ?? 'active'), 'inactive'); ?>>Inactive</option>
                            </select>
                            <p class="description">Inactive deaneries route to archdiocese reviewers only. Re-activate a deanery here.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button($isNew ? 'Add deanery' : 'Save deanery'); ?>
            </form>

            <?php if (! $isNew) : ?>
                <?php $this->renderApprovers($id); ?>
            <?php else : ?>
                <p>Save the deanery before assigning approvers.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderApprovers(int $deaneryId): void
    {
        $assignments = $this->approvers->findForDeanery($deaneryId);
        ?>
        <hr />
        <h2>Deanery approvers</h2>
        <p>Each approver needs a WordPress user, approval email, label, notification mode and reminder setting. Creating a user assigns a random password and sends no notification email. The approval email may differ from the WordPress account email.</p>

        <?php if (isset($_GET['approver_saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Deanery approver saved.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['approver_deactivated'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Deanery approver deactivated.</p></div>
        <?php endif; ?>

        <?php if ($assignments === []) : ?>
            <p>No approvers are assigned. Parishes in this deanery go to archdiocese reviewers only.</p>
        <?php else : ?>
            <?php foreach ($assignments as $assignment) : ?>
                <?php
                $assignmentId = (int) ($assignment['id'] ?? 0);
                $wpUserId = (int) ($assignment['wp_user_id'] ?? 0);
                $userName = trim((string) ($assignment['user_display_name'] ?? ''));
                $userLogin = trim((string) ($assignment['user_login'] ?? ''));
                $userLabel = $userName !== '' ? $userName : $userLogin;

                if ($userLabel === '') {
                    $userLabel = 'WordPress user #' . $wpUserId . ' (account unavailable)';
                }
                ?>
                <hr />
                <h3><?php echo esc_html($userLabel); ?> — <?php echo (int) ($assignment['active'] ?? 0) === 1 ? 'Active' : 'Inactive'; ?></h3>
                <?php if ($userLogin !== '') : ?>
                    <p><code><?php echo esc_html($userLogin); ?></code> (user #<?php echo esc_html((string) $wpUserId); ?>)</p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="adct_pi_deanery_approver" />
                    <input type="hidden" name="approver_action" value="save" />
                    <input type="hidden" name="deanery_id" value="<?php echo esc_attr((string) $deaneryId); ?>" />
                    <input type="hidden" name="assignment_id" value="<?php echo esc_attr((string) $assignmentId); ?>" />
                    <?php wp_nonce_field(
                        $this->approverSaveNonceAction($deaneryId, $assignmentId),
                        'approver_nonce'
                    ); ?>
                    <table class="form-table" role="presentation">
                        <?php $this->renderApproverSettingsFields(
                            'approver-' . $assignmentId,
                            (string) ($assignment['email'] ?? ''),
                            (string) ($assignment['label'] ?? ''),
                            (string) ($assignment['notify_mode'] ?? Approver::NOTIFY_EACH),
                            (int) ($assignment['reminders_enabled'] ?? 1) === 1,
                            (int) ($assignment['active'] ?? 0) === 1
                        ); ?>
                    </table>
                    <?php submit_button('Save approver', 'secondary'); ?>
                </form>
                <?php if ((int) ($assignment['active'] ?? 0) === 1) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="adct_pi_deanery_approver" />
                        <input type="hidden" name="approver_action" value="deactivate" />
                        <input type="hidden" name="deanery_id" value="<?php echo esc_attr((string) $deaneryId); ?>" />
                        <input type="hidden" name="assignment_id" value="<?php echo esc_attr((string) $assignmentId); ?>" />
                        <?php wp_nonce_field(
                            $this->approverDeactivateNonceAction($deaneryId, $assignmentId),
                            'approver_nonce'
                        ); ?>
                        <button class="button button-secondary" type="submit">Deactivate approver</button>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <hr />
        <h3>Add an approver</h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adct_pi_deanery_approver" />
            <input type="hidden" name="approver_action" value="save" />
            <input type="hidden" name="deanery_id" value="<?php echo esc_attr((string) $deaneryId); ?>" />
            <input type="hidden" name="assignment_id" value="0" />
            <?php wp_nonce_field(
                $this->approverSaveNonceAction($deaneryId, 0),
                'approver_nonce'
            ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="approver-user-source">WordPress user</label></th>
                    <td>
                        <select id="approver-user-source" name="user_source">
                            <option value="existing">Assign an existing user</option>
                            <option value="create">Create a new user</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="approver-wp-user">Existing user</label></th>
                    <td>
                        <select id="approver-wp-user" name="wp_user_id">
                            <option value="0">Choose a WordPress user</option>
                            <?php foreach (get_users(['orderby' => 'display_name', 'order' => 'ASC']) as $user) : ?>
                                <?php if (! ($user instanceof \WP_User)) : ?>
                                    <?php continue; ?>
                                <?php endif; ?>
                                <?php
                                $displayName = trim((string) $user->display_name);
                                if ($displayName === '') {
                                    $displayName = (string) $user->user_login;
                                }
                                ?>
                                <option value="<?php echo esc_attr((string) $user->ID); ?>">
                                    <?php echo esc_html($displayName . ' (' . (string) $user->user_email . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="approver-new-user-login">New username</label></th>
                    <td><input id="approver-new-user-login" class="regular-text" name="new_user_login" type="text" maxlength="60" autocomplete="off" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="approver-new-user-email">New account email</label></th>
                    <td><input id="approver-new-user-email" class="regular-text" name="new_user_email" type="email" maxlength="100" autocomplete="off" /></td>
                </tr>
                <?php $this->renderApproverSettingsFields(
                    'approver-new',
                    '',
                    '',
                    Approver::NOTIFY_EACH,
                    true,
                    true
                ); ?>
            </table>
            <p class="description">Only the selected user source is used. New accounts receive a random password; no WordPress new-user notification or other email is sent.</p>
            <?php submit_button('Add approver', 'secondary'); ?>
        </form>
        <?php
    }

    private function renderApproverSettingsFields(
        string $fieldPrefix,
        string $email,
        string $label,
        string $notifyMode,
        bool $remindersEnabled,
        bool $active
    ): void {
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($fieldPrefix . '-email'); ?>">Approval email</label></th>
            <td><input id="<?php echo esc_attr($fieldPrefix . '-email'); ?>" class="regular-text" name="approval_email" type="email" maxlength="191" required value="<?php echo esc_attr($email); ?>" /></td>
        </tr>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($fieldPrefix . '-label'); ?>">Approver label</label></th>
            <td><input id="<?php echo esc_attr($fieldPrefix . '-label'); ?>" class="regular-text" name="label" type="text" maxlength="191" required value="<?php echo esc_attr($label); ?>" /></td>
        </tr>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($fieldPrefix . '-notify-mode'); ?>">Notifications</label></th>
            <td>
                <select id="<?php echo esc_attr($fieldPrefix . '-notify-mode'); ?>" name="notify_mode">
                    <option value="<?php echo esc_attr(Approver::NOTIFY_EACH); ?>" <?php selected($notifyMode, Approver::NOTIFY_EACH); ?>>Each item</option>
                    <option value="<?php echo esc_attr(Approver::NOTIFY_DIGEST); ?>" <?php selected($notifyMode, Approver::NOTIFY_DIGEST); ?>>Daily digest</option>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row">Reminders</th>
            <td><label><input name="reminders_enabled" type="checkbox" value="1" <?php checked($remindersEnabled, true); ?> /> Send reminders to this approver</label></td>
        </tr>
        <tr>
            <th scope="row">Assignment</th>
            <td><label><input name="active" type="checkbox" value="1" <?php checked($active, true); ?> /> Active approver</label></td>
        </tr>
        <?php
    }

    private function renderNotices(): void
    {
        if (isset($_GET['saved'])) {
            ?>
            <div class="notice notice-success is-dismissible"><p>Deanery details saved.</p></div>
            <?php
        }

        if (isset($_GET['deactivated'])) {
            ?>
            <div class="notice notice-success is-dismissible"><p>Deanery deactivated. Its parishes now route to archdiocese reviewers only.</p></div>
            <?php
        }
    }

    private function routeSummary(string $status, int $activeApproverCount): string
    {
        if ($status !== 'active') {
            return 'Inactive — reviewers only';
        }

        if ($activeApproverCount === 0) {
            return 'No active approver — reviewers only';
        }

        return $activeApproverCount . ' active approver'
            . ($activeApproverCount === 1 ? '' : 's')
            . ' plus reviewers';
    }

    private function deaneryNonceAction(int $deaneryId): string
    {
        return 'adct_pi_deactivate_deanery_' . $deaneryId;
    }

    private function approverSaveNonceAction(int $deaneryId, int $assignmentId): string
    {
        return 'adct_pi_save_deanery_approver_' . $deaneryId . '_'
            . ($assignmentId > 0 ? (string) $assignmentId : 'new');
    }

    private function approverDeactivateNonceAction(int $deaneryId, int $assignmentId): string
    {
        return 'adct_pi_deactivate_deanery_approver_' . $deaneryId . '_' . $assignmentId;
    }

    private function pageUrl(array $arguments = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::PAGE_SLUG], $arguments),
            admin_url('admin.php')
        );
    }

    private function requireDirectoryCapability(): void
    {
        if (! current_user_can(Capabilities::MANAGE_DIRECTORY)) {
            wp_die(esc_html__('You do not have permission to manage deaneries.', 'adct-parish-intake'), '', [
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

    private function nullableText(string $value): ?string
    {
        $value = sanitize_text_field($value);

        return $value === '' ? null : $value;
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
