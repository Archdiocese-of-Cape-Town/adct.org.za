<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\WordPress\Database\WordPressMailQueueRepository;
use ADCT\ParishIntake\WordPress\Mail\WordPressTestModeSettings;

final class OutboundMailPage
{
    private const PAGE_SLUG = 'adct-parish-intake-outbound-mail';
    private const SAVE_FIELD = 'adct_pi_save_outbound_mail';
    private const NONCE_ACTION = 'adct_pi_save_outbound_mail';
    private const NONCE_FIELD = 'outbound_mail_nonce';

    private bool $settingsSaveSucceeded = false;
    private ?string $settingsSaveError = null;

    public function __construct(
        private readonly WordPressMailQueueRepository $mailQueue
    ) {
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'adct-parish-intake',
            'Outbound email',
            'Outbound email',
            Capabilities::MANAGE_SETTINGS,
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function maybeHandleSettings(): void
    {
        if (
            ! is_admin()
            || ! current_user_can(Capabilities::MANAGE_SETTINGS)
            || ! isset($_POST[self::SAVE_FIELD])
        ) {
            return;
        }

        $this->settingsSaveSucceeded = false;
        $this->settingsSaveError = null;
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $mode = isset($_POST['test_mode']) && is_string($_POST['test_mode'])
            ? wp_unslash($_POST['test_mode'])
            : '';
        $allowlistText = isset($_POST['allowlist']) && is_string($_POST['allowlist'])
            ? wp_unslash($_POST['allowlist'])
            : null;
        $allowlist = $allowlistText === null
            ? ['invalid allow-list input']
            : preg_split('/\r\n|\n|\r/', $allowlistText);

        if (! is_array($allowlist)) {
            $allowlist = ['invalid allow-list input'];
        }

        $allowlist = array_values(array_filter(
            array_map('trim', $allowlist),
            static fn (string $entry): bool => $entry !== ''
        ));

        update_option(WordPressTestModeSettings::TEST_MODE_OPTION, $mode, false);
        update_option(WordPressTestModeSettings::ALLOWLIST_OPTION, $allowlist, false);

        $storedMode = get_option(WordPressTestModeSettings::TEST_MODE_OPTION, null);
        $storedAllowlist = get_option(WordPressTestModeSettings::ALLOWLIST_OPTION, null);

        if ($storedMode !== $mode || $storedAllowlist !== $allowlist) {
            $this->settingsSaveError = 'The outbound email settings could not be saved. The stored values do not match the submitted settings; review the current settings before continuing.';

            return;
        }

        if (
            WordPressTestModeSettings::fromValues($storedMode, $storedAllowlist)
                ->configurationError() !== null
        ) {
            return;
        }

        $this->settingsSaveSucceeded = true;
    }

    public function renderAdminNotice(): void
    {
        if (! function_exists('current_user_can') || ! current_user_can(Capabilities::MANAGE_SETTINGS)) {
            return;
        }

        $settings = WordPressTestModeSettings::current();

        if (! $settings->isEnabled() && $settings->configurationError() === null) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p>
                <?php if ($settings->isEnabled()) : ?>
                    <strong>TEST MODE IS ON.</strong>
                    Only addresses and exact domains on the Parish Intake allow-list can receive queued email.
                    Other Parish Intake recipients are suppressed. This does not change email from other plugins.
                <?php else : ?>
                    <strong>Parish Intake outbound mail is blocked.</strong>
                <?php endif; ?>
                <?php if ($settings->configurationError() !== null) : ?>
                    <?php echo esc_html($settings->configurationError()); ?>
                <?php endif; ?>
                <a href="<?php echo esc_url($this->pageUrl()); ?>">Review outbound email settings</a>
            </p>
        </div>
        <?php
    }

    public function renderPage(): void
    {
        if (! current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'adct-parish-intake'));
        }

        $settings = WordPressTestModeSettings::current();
        $suppressedMessages = $this->mailQueue->findRecentSuppressed();
        ?>
        <div class="wrap">
            <h1>Outbound email</h1>
            <p>Test mode applies only to email sent through the Parish Intake queue. It does not change <code>wp_mail()</code> calls made by other plugins.</p>

            <?php if ($this->settingsSaveError !== null) : ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($this->settingsSaveError); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($this->settingsSaveSucceeded) : ?>
                <div class="notice notice-success is-dismissible"><p>Outbound email settings saved.</p></div>
            <?php endif; ?>

            <?php if ($settings->configurationError() !== null) : ?>
                <div class="notice notice-error">
                    <p><strong>Outbound mail is fail-closed.</strong> <?php echo esc_html($settings->configurationError()); ?></p>
                </div>
            <?php endif; ?>

            <h2>Test mode</h2>
            <form method="post" action="<?php echo esc_url($this->pageUrl()); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="<?php echo esc_attr(self::SAVE_FIELD); ?>" value="1" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="adct-pi-test-mode">Test mode</label></th>
                        <td>
                            <select id="adct-pi-test-mode" name="test_mode">
                                <option value="" <?php selected($settings->modeValue(), ''); ?>>Invalid configuration (mail blocked)</option>
                                <option value="<?php echo esc_attr(WordPressTestModeSettings::MODE_DISABLED); ?>" <?php selected($settings->modeValue(), WordPressTestModeSettings::MODE_DISABLED); ?>>Off — normal production delivery</option>
                                <option value="<?php echo esc_attr(WordPressTestModeSettings::MODE_ENABLED); ?>" <?php selected($settings->modeValue(), WordPressTestModeSettings::MODE_ENABLED); ?>>On — allow-listed recipients only</option>
                            </select>
                            <p class="description">When enabled, non-allow-listed Parish Intake messages are recorded as suppressed and are never passed to <code>wp_mail()</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adct-pi-test-allowlist">Allowed addresses and domains</label></th>
                        <td>
                            <textarea id="adct-pi-test-allowlist" class="large-text code" rows="6" name="allowlist"><?php
                                echo esc_textarea(implode("\n", $settings->allowlistForDisplay()));
                            ?></textarea>
                            <p class="description">Enter one email address or exact domain per line. Prefix domains with <code>@</code>, for example <code>@example.test</code>. A domain matches only that exact domain, not its subdomains. An empty list while test mode is on suppresses every Parish Intake message.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save outbound email settings'); ?>
            </form>

            <h2>Suppressed mail log</h2>
            <p>Recent Parish Intake messages blocked before delivery. Previews are available only to users who can manage Parish Intake settings.</p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col">Suppressed</th>
                        <th scope="col">Recipient</th>
                        <th scope="col">Preview</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($suppressedMessages === []) : ?>
                        <tr><td colspan="3">No suppressed messages have been recorded.</td></tr>
                    <?php else : ?>
                        <?php foreach ($suppressedMessages as $message) : ?>
                            <tr>
                                <td><?php echo esc_html($message['suppressed_at']); ?> UTC</td>
                                <td><code><?php echo esc_html($message['recipient']); ?></code></td>
                                <td>
                                    <details>
                                        <summary>Show message preview</summary>
                                        <p><strong>Subject</strong></p>
                                        <pre><?php echo esc_html($message['subject']); ?></pre>
                                        <p><strong>Body (up to <?php echo esc_html((string) WordPressMailQueueRepository::SUPPRESSED_PREVIEW_BODY_LIMIT); ?> characters)</strong></p>
                                        <pre><?php echo esc_html($message['body_preview']); ?></pre>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <p class="description">Showing at most <?php echo esc_html((string) WordPressMailQueueRepository::SUPPRESSED_PREVIEW_LIMIT); ?> recent suppressed messages.</p>
        </div>
        <?php
    }

    private function pageUrl(): string
    {
        return admin_url('admin.php?page=' . self::PAGE_SLUG);
    }
}
