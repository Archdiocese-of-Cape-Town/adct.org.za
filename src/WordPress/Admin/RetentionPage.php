<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Retention\RetentionSettings;
use ADCT\ParishIntake\WordPress\Retention\WordPressRetentionSettings;
use Throwable;

final class RetentionPage
{
    public function registerMenu(): void
    {
        add_submenu_page('adct-parish-intake', 'Data retention', 'Data retention',
            Capabilities::MANAGE_SETTINGS, 'adct-parish-intake-retention', [$this, 'renderPage']);
    }

    public function handleSave(): void
    {
        if (! current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html__('You do not have permission to change retention settings.', 'adct-parish-intake'));
        }
        check_admin_referer('adct_pi_save_retention', 'retention_nonce');
        $raw = isset($_POST['raw_months']) && is_string($_POST['raw_months'])
            ? wp_unslash($_POST['raw_months']) : null;
        $processed = isset($_POST['processed_days']) && is_string($_POST['processed_days'])
            ? wp_unslash($_POST['processed_days']) : null;
        if (! WordPressRetentionSettings::validInteger($raw)
            || ! WordPressRetentionSettings::validInteger($processed)) {
            wp_die(esc_html__('Enter whole numbers within the shown limits.', 'adct-parish-intake'));
        }
        try {
            $settings = new RetentionSettings((int) $raw, (int) $processed);
        } catch (Throwable $failure) {
            wp_die(esc_html($failure->getMessage()));
        }
        update_option(WordPressRetentionSettings::RAW_OPTION, $settings->rawMonths, false);
        update_option(WordPressRetentionSettings::PROCESSED_OPTION, $settings->processedDays, false);
        if (WordPressRetentionSettings::current() != $settings) {
            wp_die(esc_html__('Retention settings could not be saved. Check the database and try again.', 'adct-parish-intake'));
        }
        wp_safe_redirect(admin_url('admin.php?page=adct-parish-intake-retention&saved=1'));
        exit;
    }

    public function renderPage(): void
    {
        if (! current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html__('You do not have permission to view retention settings.', 'adct-parish-intake'));
        }
        try {
            $settings = WordPressRetentionSettings::current();
        } catch (Throwable $failure) {
            $settings = new RetentionSettings();
            $error = $failure->getMessage();
        }
        ?>
        <div class="wrap">
            <h1>Data retention</h1>
            <?php if (isset($error)) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?> Enter valid values below to resume cleanup.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success"><p>Retention settings saved.</p></div>
            <?php endif; ?>
            <p>The daily job removes old private email files, attachments and copies in the mailbox's Processed folder. It does not delete events or their change history. Use Scheduled jobs to see errors or retry.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="adct_pi_save_retention" />
                <?php wp_nonce_field('adct_pi_save_retention', 'retention_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="raw_months">Private files (months)</label></th><td>
                        <input type="number" id="raw_months" name="raw_months" min="1" max="120" required value="<?php echo esc_attr((string) $settings->rawMonths); ?>" />
                        <p class="description">Default 12. New email and attachment files are removed after this many months from receipt. Already saved messages keep their original removal date. Parsed event details remain.</p>
                    </td></tr>
                    <tr><th><label for="processed_days">Processed mailbox (days)</label></th><td>
                        <input type="number" id="processed_days" name="processed_days" min="1" max="3650" required value="<?php echo esc_attr((string) $settings->processedDays); ?>" />
                        <p class="description">Default 90. Only messages older than this many whole calendar days in the Processed folder are removed. Inbox and Too large are not touched. The mail server must support UIDPLUS for safe deletion.</p>
                    </td></tr>
                </table>
                <p>Expired action links are removed 30 days after expiry; audit entries after 24 months. Those periods cannot be changed here.</p>
                <?php submit_button('Save retention settings'); ?>
            </form>
        </div>
        <?php
    }
}
