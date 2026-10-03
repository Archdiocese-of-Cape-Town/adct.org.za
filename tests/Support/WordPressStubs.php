<?php

declare(strict_types=1);

/**
 * Stand-ins for the WordPress functions and classes the approval pipeline
 * touches, shared by every test that builds approver emails or resolves
 * recipients without a WordPress runtime.
 *
 * These used to live inside ApprovalNoticeJobTest, which made any other test
 * that needed them depend on that file's load order: run on its own, it saw
 * "WP_User not found". They are declared here, once, behind guards, and
 * required by the tests that use them.
 *
 * The guards matter because PHP treats a second declaration of a function or
 * class in the same namespace as a fatal, not a shadow — see the note in
 * RevertChangeHandlerTest, which drives this same copy through the globals
 * below rather than declaring its own.
 *
 * Escaping is real, not identity, so a regression that drops escaping from an
 * approver email shows up as a failing expectation instead of passing silently.
 */

namespace ADCT\ParishIntake\WordPress\Auth {

    /**
     * Stand-ins so ActionTokenEndpoint::urlForToken() can build links in a
     * test without a WordPress runtime.
     */
    if (! function_exists('ADCT\ParishIntake\WordPress\Auth\home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://adct.example.test' . $path;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Auth\add_query_arg')) {
        function add_query_arg(string $key, string $value, string $url): string
        {
            return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . rawurlencode($value);
        }
    }
}

namespace {

    if (! class_exists('WP_User', false)) {
        /**
         * Stand-in for the WordPress user class ApprovalRecipients type-checks.
         */
        class WP_User
        {
            public int $ID = 0;
            public string $user_email = '';
            public int $user_status = 0;

            public function __construct(int $id, string $email, int $userStatus = 0)
            {
                $this->ID = $id;
                $this->user_email = $email;
                $this->user_status = $userStatus;
            }
        }
    }

    if (! function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (! function_exists('esc_url')) {
        /**
         * WordPress's esc_url percent-encodes the characters that would let a
         * value break out of an href. The approval and reminder emails rely on
         * that: a regression that stops encoding them shows up here as a
         * changed expectation rather than passing silently.
         */
        function esc_url(string $url): string
        {
            return str_replace(['"', "'", '<', '>'], ['%22', '%27', '%3C', '%3E'], $url);
        }
    }}

namespace ADCT\ParishIntake\WordPress\Approval {

    /**
     * Minimal stand-ins for the WordPress user helpers ApprovalRecipients
     * calls. Each test drives them through the globals below and restores
     * them in tearDown.
     */
    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\get_users')) {
        function get_users(array $args = []): array
        {
            return $GLOBALS['adct_test_wp_users'] ?? [];
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\get_userdata')) {
        function get_userdata(int $userId): ?\WP_User
        {
            return ($GLOBALS['adct_test_wp_users'] ?? [])[$userId] ?? null;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\user_can')) {
        function user_can(\WP_User|int $user, string $capability): bool
        {
            $id = $user instanceof \WP_User ? $user->ID : $user;

            return in_array($capability, $GLOBALS['adct_test_wp_caps'][$id] ?? [], true);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\get_user_meta')) {
        function get_user_meta(int $userId, string $key, bool $single = false): string
        {
            return $GLOBALS['adct_test_wp_meta'][$userId][$key] ?? '';
        }
    }
}
