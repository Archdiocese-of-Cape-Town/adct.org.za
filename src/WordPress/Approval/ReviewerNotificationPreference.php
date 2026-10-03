<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Approval;

use ADCT\ParishIntake\Core\Auth\Capabilities;

final class ReviewerNotificationPreference
{
    private const META_KEY = 'adct_pi_approval_notify_mode';
    private const REMINDERS_META_KEY = ApprovalRecipients::REVIEWER_REMINDERS_META_KEY;
    private const NONCE_ACTION_PREFIX = 'adct_pi_notify_mode_';

    public function registerHooks(): void
    {
        add_action('show_user_profile', [$this, 'render']);
        add_action('edit_user_profile', [$this, 'render']);
        add_action('personal_options_update', [$this, 'save']);
        add_action('edit_user_profile_update', [$this, 'save']);
    }

    public function render(\WP_User $user): void
    {
        if (! current_user_can('edit_user', $user->ID) || ! user_can($user, Capabilities::REVIEW)) {
            return;
        }
        $mode = get_user_meta($user->ID, self::META_KEY, true);
        echo '<h2>' . esc_html__('Event approval emails', 'adct-parish-intake') . '</h2>';
        echo '<p><label for="adct_pi_notify_mode">'
            . esc_html__('How often should approval requests be emailed?', 'adct-parish-intake')
            . '</label> <select id="adct_pi_notify_mode" name="adct_pi_notify_mode">'
            . '<option value="each"' . selected($mode !== 'digest', true, true) . '>'
            . esc_html__('As events arrive', 'adct-parish-intake') . '</option>'
            . '<option value="digest"' . selected($mode === 'digest', true, true) . '>'
            . esc_html__('Daily digest', 'adct-parish-intake') . '</option></select></p>';
        // An unchecked box is absent from $_POST entirely, so absence is the
        // "off" signal rather than a missing value to guess at.
        $remindersOn = ! in_array(
            get_user_meta($user->ID, self::REMINDERS_META_KEY, true),
            ['0', 'off', 'false', 'no'],
            true
        );
        echo '<p><label for="adct_pi_approval_reminders">'
            . '<input type="checkbox" id="adct_pi_approval_reminders"'
            . ' name="adct_pi_approval_reminders" value="1"'
            . checked($remindersOn, true, true) . ' /> '
            . esc_html__(
                'Email me a reminder when an event has been waiting for a decision for several days.',
                'adct-parish-intake'
            )
            . '</label></p>';
        wp_nonce_field(self::NONCE_ACTION_PREFIX . $user->ID, 'adct_pi_notify_nonce');
    }

    public function save(int $userId): void
    {
        if (! isset($_POST['adct_pi_notify_mode']) && ! isset($_POST['adct_pi_notify_nonce'])) {
            return;
        }
        if (! current_user_can('edit_user', $userId) || ! user_can($userId, Capabilities::REVIEW)
            || ! isset($_POST['adct_pi_notify_nonce'])
            || ! is_string($_POST['adct_pi_notify_nonce'])
            || ! wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['adct_pi_notify_nonce'])),
                self::NONCE_ACTION_PREFIX . $userId
            )) {
            wp_die(esc_html__('Approval notification settings could not be verified.', 'adct-parish-intake'), '', [
                'response' => 403,
            ]);
        }
        $mode = isset($_POST['adct_pi_notify_mode'])
            ? sanitize_text_field(wp_unslash($_POST['adct_pi_notify_mode'])) : '';
        if (! in_array($mode, ['each', 'digest'], true)) {
            wp_die(esc_html__('Choose per-item notices or a daily digest.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }
        update_user_meta($userId, self::META_KEY, $mode);
        if (get_user_meta($userId, self::META_KEY, true) !== $mode) {
            wp_die(esc_html__('The approval notification setting could not be saved.', 'adct-parish-intake'), '', [
                'response' => 503,
            ]);
        }

        $reminders = isset($_POST['adct_pi_approval_reminders']) ? '1' : '0';
        update_user_meta($userId, self::REMINDERS_META_KEY, $reminders);
        if ((string) get_user_meta($userId, self::REMINDERS_META_KEY, true) !== $reminders) {
            wp_die(esc_html__('The approval reminder setting could not be saved.', 'adct-parish-intake'), '', [
                'response' => 503,
            ]);
        }
    }
}
