<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\MailboxConnectionTestResult;
use ADCT\ParishIntake\Core\Ingestion\MailboxConnectionTestStatus;
use ADCT\ParishIntake\Core\Ingestion\MailboxConnectionTestService;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettingsValidator;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Security\SecretRegistry;
use ADCT\ParishIntake\Core\Sources\Source;
use ADCT\ParishIntake\Core\Sources\SourceRegistryService;
use ADCT\ParishIntake\Core\Sources\SourceRole;
use ADCT\ParishIntake\Core\Sources\SourceStatus;
use ADCT\ParishIntake\Core\Sources\SourceType;
use ADCT\ParishIntake\WordPress\Database\Repository\MailboxRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository;
use ADCT\ParishIntake\WordPress\Security\WordPressSecretResolver;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class MailboxesPage
{
    private const PAGE_SLUG = 'adct-parish-intake-mailboxes';

    public function __construct(
        private MailboxRepository $mailboxes,
        private SourceRepository $sources,
        private SourceRegistryService $sourceRegistry,
        private MailboxSettingsValidator $validator,
        private MailboxConnectionTestService $connectionTest,
        private WordPressSecretResolver $secrets,
        private ClockInterface $clock
    ) {
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'adct-parish-intake',
            'Mailboxes',
            'Mailboxes',
            Capabilities::MANAGE_SETTINGS,
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $this->requireSettingsCapability();
        $action = sanitize_key($this->getText('action'));

        if ($action === 'add') {
            $this->renderForm(null);

            return;
        }

        if ($action === 'edit') {
            $settings = $this->mailboxes->findMailboxById(absint($this->getText('id')));

            if ($settings === null) {
                wp_die(esc_html__('The mailbox could not be found.', 'adct-parish-intake'), '', [
                    'response' => 404,
                ]);
            }

            $this->renderForm($settings);

            return;
        }

        $mailboxes = $this->mailboxes->findAllMailboxes();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Mailboxes</h1>
            <a class="page-title-action" href="<?php echo esc_url($this->pageUrl(['action' => 'add'])); ?>">Add mailbox</a>
            <hr class="wp-header-end" />

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Mailbox settings saved.</p></div>
            <?php endif; ?>

            <p>Each intake mailbox is linked to an archdiocese-wide email source. Testing a connection does not fetch or process message bodies.</p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col">Mailbox</th>
                        <th scope="col">Server</th>
                        <th scope="col">Folders</th>
                        <th scope="col">Status and health</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($mailboxes === []) : ?>
                        <tr><td colspan="5">No mailboxes have been configured.</td></tr>
                    <?php else : ?>
                        <?php foreach ($mailboxes as $settings) : ?>
                            <?php $this->renderMailboxRow($settings); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handleSaveMailbox(): void
    {
        $this->requireSettingsCapability();
        $mailboxId = $this->postedId();
        check_admin_referer($this->nonceAction('save', $mailboxId), 'mailbox_nonce');
        $current = $mailboxId > 0 ? $this->mailboxes->findMailboxById($mailboxId) : null;

        if ($mailboxId > 0 && $current === null) {
            wp_die(esc_html__('The mailbox could not be found.', 'adct-parish-intake'), '', [
                'response' => 404,
            ]);
        }

        $password = $this->postText('password');

        if (preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            wp_die(esc_html__('The mailbox password contains an unsupported control character.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        try {
            $settings = $this->validator->validate([
                'label' => sanitize_text_field($this->postText('label')),
                'host' => sanitize_text_field($this->postText('host')),
                'port' => $this->postText('port'),
                'encryption' => sanitize_key($this->postText('encryption')),
                'username' => sanitize_text_field($this->postText('username')),
                'inbox_folder' => sanitize_text_field($this->postText('inbox_folder')),
                'processed_folder' => sanitize_text_field($this->postText('processed_folder')),
                'max_message_size_mb' => $this->postText('max_message_size_mb'),
                'active' => isset($_POST['active']) ? '1' : '0',
            ], $current?->id ?? 0, $current?->sourceId ?? 0);
            $source = $this->saveSourceForMailbox($settings, $current);
            $settings = $settings->withIdentity($current?->id ?? 0, $source->id);
            $saved = $this->mailboxes->saveMailbox($settings, $this->timestamp());
            $this->savePassword($saved, $password, isset($_POST['remove_password']));
        } catch (DomainException | InvalidArgumentException $failure) {
            wp_die(esc_html($failure->getMessage()), esc_html__('Mailbox update failed', 'adct-parish-intake'), [
                'response' => 400,
            ]);
        }

        wp_safe_redirect($this->pageUrl(['saved' => 1, 'mailbox_id' => $saved->id]));
        exit;
    }

    public function handleTestConnection(): void
    {
        $this->handleConnectionAction(false);
    }

    public function handleCreateProcessedFolder(): void
    {
        $this->handleConnectionAction(true);
    }

    private function handleConnectionAction(bool $createProcessedFolder): void
    {
        $this->requireSettingsCapability();
        $mailboxId = $this->postedId();
        $operation = $createProcessedFolder ? 'create_processed_folder' : 'test';
        check_admin_referer($this->nonceAction($operation, $mailboxId), 'mailbox_nonce');
        $settings = $this->mailboxes->findMailboxById($mailboxId);

        if ($settings === null) {
            wp_die(esc_html__('The mailbox could not be found.', 'adct-parish-intake'), '', [
                'response' => 404,
            ]);
        }

        $password = $this->secrets->resolve(SecretRegistry::IMAP_PASSWORD, $settings->secretScope());
        $result = $createProcessedFolder
            ? $this->connectionTest->createProcessedFolder($settings, $password)
            : $this->connectionTest->test($settings, $password);

        wp_safe_redirect($this->pageUrl([
            'test_result' => $result->status->value,
            'mailbox_id' => $settings->id,
            'waiting_count' => $result->waitingCount,
        ]));
        exit;
    }

    private function renderMailboxRow(MailboxSettings $settings): void
    {
        $source = $this->sources->findSource($settings->sourceId);
        ?>
        <tr>
            <td>
                <strong><?php echo esc_html($settings->label); ?></strong>
                <br /><code><?php echo esc_html($settings->username); ?></code>
            </td>
            <td>
                <code><?php echo esc_html($settings->host); ?>:<?php echo esc_html((string) $settings->port); ?></code>
                <br /><?php echo esc_html($this->encryptionLabel($settings->encryption)); ?>
                <br />TLS certificate verification on
            </td>
            <td>
                Inbox: <code><?php echo esc_html($settings->inboxFolder); ?></code>
                <br />Processed: <code><?php echo esc_html($settings->processedFolder); ?></code>
                <br />Maximum message size: <?php echo esc_html((string) intdiv($settings->maxMessageSizeBytes, 1024 * 1024)); ?> MB
            </td>
            <td>
                <?php echo $settings->active ? 'Active' : 'Inactive'; ?>
                <?php if ($source !== null) : ?>
                    <dl class="mailbox-health">
                        <dt>Last checked</dt>
                        <dd><?php echo esc_html($source->lastCheckedAt === null ? 'Not yet' : $source->lastCheckedAt . ' UTC'); ?></dd>
                        <dt>Consecutive failures</dt>
                        <dd><?php echo esc_html((string) $source->consecutiveFailures); ?></dd>
                        <dt>Last error</dt>
                        <dd><?php echo esc_html($source->lastError ?? '—'); ?></dd>
                    </dl>
                <?php else : ?>
                    <p class="description">The linked email source could not be found.</p>
                <?php endif; ?>
            </td>
            <td>
                <a class="button button-secondary" href="<?php echo esc_url($this->pageUrl([
                    'action' => 'edit',
                    'id' => $settings->id,
                ])); ?>">Edit</a>
                <?php $this->renderTestForm($settings); ?>
                <?php $this->renderConnectionResult($settings); ?>
            </td>
        </tr>
        <?php
    }

    private function renderTestForm(MailboxSettings $settings): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adct_pi_test_mailbox" />
            <input type="hidden" name="mailbox_id" value="<?php echo esc_attr((string) $settings->id); ?>" />
            <?php wp_nonce_field($this->nonceAction('test', $settings->id), 'mailbox_nonce'); ?>
            <button type="submit" class="button button-secondary">Test connection</button>
        </form>
        <?php
    }

    private function renderConnectionResult(MailboxSettings $settings): void
    {
        if (absint($this->getText('mailbox_id')) !== $settings->id) {
            return;
        }

        $status = MailboxConnectionTestStatus::tryFrom(sanitize_key($this->getText('test_result')));

        if ($status === null) {
            return;
        }

        $result = new MailboxConnectionTestResult(
            $status,
            absint($this->getText('waiting_count')),
            $settings->processedFolder
        );
        $noticeClass = $result->isSuccessful()
            ? 'notice-success'
            : ($result->needsProcessedFolder() ? 'notice-warning' : 'notice-error');
        ?>
        <div class="notice <?php echo esc_attr($noticeClass); ?> inline">
            <p><?php echo esc_html($result->message()); ?></p>
        </div>
        <?php if ($result->needsProcessedFolder()) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="adct_pi_create_mailbox_processed_folder" />
                <input type="hidden" name="mailbox_id" value="<?php echo esc_attr((string) $settings->id); ?>" />
                <?php wp_nonce_field(
                    $this->nonceAction('create_processed_folder', $settings->id),
                    'mailbox_nonce'
                ); ?>
                <button type="submit" class="button button-secondary">Create processed folder</button>
            </form>
        <?php endif; ?>
        <?php
    }

    private function renderForm(?MailboxSettings $settings): void
    {
        $mailboxId = $settings?->id ?? 0;
        $prefix = 'mailbox-' . ($mailboxId > 0 ? (string) $mailboxId : 'new');
        $encryption = $settings?->encryption ?? MailboxEncryption::SSL;
        $scope = $settings?->secretScope();
        $constantName = $this->secrets->configuredConstantName(SecretRegistry::IMAP_PASSWORD, $scope);
        $mailboxConstantName = $scope === null
            ? null
            : SecretRegistry::constantName(SecretRegistry::IMAP_PASSWORD, $scope);
        $hasStoredPassword = $scope !== null
            && $this->secrets->hasStoredOption(SecretRegistry::IMAP_PASSWORD, $scope);
        ?>
        <div class="wrap">
            <h1><?php echo $settings === null ? 'Add mailbox' : 'Edit mailbox'; ?></h1>
            <p><a href="<?php echo esc_url($this->pageUrl()); ?>">&larr; Back to mailboxes</a></p>
            <p>Connection settings are stored in the plugin database. Passwords are stored separately in a non-autoloaded WordPress option when no password constant is configured; they are not encrypted by this plugin.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="adct_pi_save_mailbox" />
                <input type="hidden" name="mailbox_id" value="<?php echo esc_attr((string) $mailboxId); ?>" />
                <?php wp_nonce_field($this->nonceAction('save', $mailboxId), 'mailbox_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-label">Label</label></th>
                        <td><input id="<?php echo esc_attr($prefix); ?>-label" class="regular-text" name="label" type="text" maxlength="191" required value="<?php echo esc_attr($settings?->label ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-host">IMAP host</label></th>
                        <td><input id="<?php echo esc_attr($prefix); ?>-host" class="regular-text" name="host" type="text" maxlength="253" required value="<?php echo esc_attr($settings?->host ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-port">Port</label></th>
                        <td><input id="<?php echo esc_attr($prefix); ?>-port" name="port" type="number" min="1" max="65535" step="1" required value="<?php echo esc_attr((string) ($settings?->port ?? MailboxSettings::DEFAULT_PORT)); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-encryption">Encryption</label></th>
                        <td>
                            <select id="<?php echo esc_attr($prefix); ?>-encryption" name="encryption" required>
                                <?php foreach ([MailboxEncryption::SSL, MailboxEncryption::STARTTLS] as $value) : ?>
                                    <option value="<?php echo esc_attr($value->value); ?>" <?php selected($encryption->value, $value->value); ?>>
                                        <?php echo esc_html($this->encryptionLabel($value)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-username">Username / email address</label></th>
                        <td><input id="<?php echo esc_attr($prefix); ?>-username" class="regular-text" name="username" type="email" maxlength="191" required value="<?php echo esc_attr($settings?->username ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-password">Password</label></th>
                        <td>
                            <?php if ($constantName !== null) : ?>
                                <p><strong>Set in wp-config.php.</strong> This mailbox uses <code><?php echo esc_html($constantName); ?></code>; a database password is ignored.</p>
                                <?php if ($hasStoredPassword) : ?>
                                    <label><input type="checkbox" name="remove_password" value="1" /> Remove saved password</label>
                                <?php endif; ?>
                            <?php else : ?>
                                <?php if ($mailboxConstantName !== null) : ?>
                                    <p class="description">To use wp-config.php for this mailbox, define <code><?php echo esc_html($mailboxConstantName); ?></code>.</p>
                                <?php else : ?>
                                    <p class="description">After saving this mailbox, its form will show the per-mailbox password constant name.</p>
                                <?php endif; ?>
                                <input id="<?php echo esc_attr($prefix); ?>-password" class="regular-text" name="password" type="password" autocomplete="new-password" value="" />
                                <?php if ($hasStoredPassword) : ?>
                                    <p class="description">A password is saved. Leave blank to keep it.</p>
                                    <label><input type="checkbox" name="remove_password" value="1" /> Remove saved password</label>
                                <?php else : ?>
                                    <p class="description">Enter a password, or save the mailbox and configure its password constant before testing.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                            <p class="description">Database passwords are not encrypted by this plugin. Prefer a wp-config.php constant where practical.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-inbox">Inbox folder</label></th>
                        <td><input id="<?php echo esc_attr($prefix); ?>-inbox" class="regular-text" name="inbox_folder" type="text" maxlength="191" required value="<?php echo esc_attr($settings?->inboxFolder ?? 'INBOX'); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-processed">Processed folder</label></th>
                        <td><input id="<?php echo esc_attr($prefix); ?>-processed" class="regular-text" name="processed_folder" type="text" maxlength="191" required value="<?php echo esc_attr($settings?->processedFolder ?? 'Processed'); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($prefix); ?>-max-size">Maximum message size (MB)</label></th>
                        <td>
                            <input id="<?php echo esc_attr($prefix); ?>-max-size" name="max_message_size_mb" type="number" min="1" max="<?php echo esc_attr((string) MailboxSettingsValidator::MAX_MESSAGE_SIZE_MB); ?>" step="1" required value="<?php echo esc_attr((string) intdiv($settings?->maxMessageSizeBytes ?? MailboxSettings::DEFAULT_MAX_MESSAGE_SIZE_BYTES, 1024 * 1024)); ?>" />
                            <p class="description">Choose between 1 and 30 MB. Messages above this limit are rejected before their body is downloaded.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Mailbox status</th>
                        <td>
                            <label for="<?php echo esc_attr($prefix); ?>-active">
                                <input id="<?php echo esc_attr($prefix); ?>-active" name="active" type="checkbox" value="1" <?php checked($settings?->active ?? true); ?> />
                                Active
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button($settings === null ? 'Add mailbox' : 'Save mailbox', 'primary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    private function saveSourceForMailbox(
        MailboxSettings $settings,
        ?MailboxSettings $current
    ): Source {
        $identifier = SourceType::normalizeIdentifier(SourceType::EMAIL, $settings->username);
        $source = $current === null
            ? $this->sources->findGlobalEmailSource($identifier)
            : $this->sources->findSource($current->sourceId);

        if ($current !== null && $source === null) {
            throw new DomainException('The mailbox email source could not be found.');
        }

        if ($source !== null && ($source->parishId !== null || $source->type !== SourceType::EMAIL)) {
            throw new DomainException('A mailbox must use an archdiocese-wide email source.');
        }

        if ($current !== null && $source !== null) {
            $matchingSource = $this->sources->findGlobalEmailSource($identifier);

            if ($matchingSource !== null && $matchingSource->id !== $source->id) {
                throw new DomainException('An archdiocese-wide source already uses this email address.');
            }
        }

        if (
            $current === null
            && $source !== null
            && $this->mailboxes->findMailboxBySourceId($source->id) !== null
        ) {
            throw new DomainException('This email source is already linked to a mailbox.');
        }

        return $this->sourceRegistry->save(new Source(
            $source?->id ?? 0,
            null,
            SourceType::EMAIL,
            $identifier,
            SourceRole::OFFICIAL,
            $source?->status ?? SourceStatus::ACTIVE,
            $source?->pollIntervalMinutes,
            $source?->lastCheckedAt,
            $source?->lastSuccessAt,
            $source?->lastItemAt,
            $source?->consecutiveFailures ?? 0,
            $source?->lastError
        ));
    }

    private function savePassword(
        MailboxSettings $settings,
        string $password,
        bool $removePassword
    ): void {
        $scope = $settings->secretScope();
        $optionName = SecretRegistry::optionName(SecretRegistry::IMAP_PASSWORD, $scope);

        if ($this->secrets->configuredConstantName(SecretRegistry::IMAP_PASSWORD, $scope) !== null) {
            $this->deleteSavedPassword($optionName);

            return;
        }

        if ($removePassword) {
            $this->deleteSavedPassword($optionName);

            return;
        }

        if (trim($password) === '') {
            return;
        }

        $updated = update_option($optionName, $password, false);

        if (! $updated && get_option($optionName, false) !== $password) {
            throw new RuntimeException('The mailbox password could not be saved.');
        }
    }

    private function deleteSavedPassword(string $optionName): void
    {
        delete_option($optionName);

        if (get_option($optionName, false) !== false) {
            throw new RuntimeException('The saved mailbox password could not be removed.');
        }
    }

    private function postedId(): int
    {
        $value = trim($this->postText('mailbox_id'));

        if (preg_match('/\A\d+\z/D', $value) !== 1) {
            wp_die(esc_html__('The mailbox selection is invalid.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        return (int) $value;
    }

    private function pageUrl(array $arguments = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::PAGE_SLUG], $arguments),
            admin_url('admin.php')
        );
    }

    private function nonceAction(string $operation, int $mailboxId): string
    {
        return 'adct_pi_mailbox_' . $operation . '_' . $mailboxId;
    }

    private function requireSettingsCapability(): void
    {
        if (! current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html__('You do not have permission to manage mailboxes.', 'adct-parish-intake'), '', [
                'response' => 403,
            ]);
        }
    }

    private function encryptionLabel(MailboxEncryption $encryption): string
    {
        return match ($encryption) {
            MailboxEncryption::SSL => 'SSL/TLS',
            MailboxEncryption::STARTTLS => 'STARTTLS',
            MailboxEncryption::NONE => 'Unencrypted',
        };
    }

    private function timestamp(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
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
