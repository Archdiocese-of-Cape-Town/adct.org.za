<?php

declare(strict_types=1);

/**
 * WordPress stand-ins shared by the magic-link tests.
 *
 * These live in one file because PHP will not let two test files in the same
 * namespace declare the same function, and several of them need `get_user_by()`
 * and `user_can()` with the *same* behaviour: the point of the magic link is
 * that the account directory and its capabilities are read live, so a test can
 * change what is in there between a preview and the POST that follows it.
 *
 * Require this file from a test with:
 *
 *     require_once __DIR__ . '/../../../Support/WordPressAuthDoubles.php';
 *
 * and drive it through these globals:
 *
 *     $GLOBALS['adct_test_users']   lowercase address => \WP_User
 *     $GLOBALS['adct_test_caps']    user ID           => list<string>
 *     $GLOBALS['adct_test_cookies'] list of ['user_id' => int, 'remember' => bool]
 *
 * Every declaration is guarded, so whichever file PHPUnit loads first wins and
 * a second copy is skipped rather than being a fatal redeclaration.
 */

namespace ADCT\ParishIntake\WordPress\Auth {

    if (! function_exists(__NAMESPACE__ . '\\get_user_by')) {
        /**
         * The account directory is read fresh on every call, so a test can swap
         * what is in it between a preview and the POST that follows it.
         */
        function get_user_by(string $field, mixed $value): ?\WP_User
        {
            if ($field !== 'email') {
                return null;
            }

            return $GLOBALS['adct_test_users'][strtolower(trim((string) $value))] ?? null;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\user_can')) {
        /**
         * Capabilities are read live too, for the same reason: a dean whose
         * capability is stripped between the GET and the POST must be refused.
         */
        function user_can(\WP_User $user, string $capability): bool
        {
            return in_array($capability, (array) ($GLOBALS['adct_test_caps'][$user->ID] ?? []), true);
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\wp_set_auth_cookie')) {
        /**
         * Captured rather than sent, so a test can assert that no cookie was set.
         */
        function wp_set_auth_cookie(
            int $userId,
            bool $remember = false,
            string $secure = '',
            string $token = ''
        ): void {
            $GLOBALS['adct_test_cookies'][] = ['user_id' => $userId, 'remember' => $remember];
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\esc_html__')) {
        function esc_html__(string $text, string $domain = 'default'): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\esc_html')) {
        function esc_html(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\esc_textarea')) {
        /**
         * Used only by the description field on the correction page. Guarded
         * because ParserPageTest and UnparsedDateTimeWarningTest each declare
         * their own copy in their own namespaces, and this one is the copy the
         * Auth namespace resolves to.
         */
        function esc_textarea(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\esc_attr')) {
        function esc_attr(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\esc_url')) {
        /**
         * Close enough to WordPress's own filter to catch a quote or a tag
         * breaking out of an href, which is what the escaping tests are for.
         * The expectations must not pre-escape their input.
         */
        function esc_url(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\sanitize_text_field')) {
        function sanitize_text_field(string $value): string
        {
            return trim(strip_tags($value));
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\sanitize_email')) {
        function sanitize_email(string $email): string
        {
            return trim((string) filter_var(trim($email), FILTER_SANITIZE_EMAIL));
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\is_email')) {
        function is_email(string $email): bool
        {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\wp_unslash')) {
        function wp_unslash(string $value): string
        {
            return stripslashes($value);
        }
    }
}

    namespace {
        if (! function_exists('register_block_type')) {
            /**
             * Declared in the global namespace on purpose.
             *
             * `MagicLinkLoginRequestPage::register()` calls `register_block_type()`
             * unqualified, so PHP resolves it to this. But the `function_exists()`
             * in front of that call is *also* unqualified, and so is resolved from
             * `ADCT\ParishIntake\WordPress\Auth` — which asks about the global name.
             * A test that supplied only a namespaced double would therefore have
             * skipped the block registration without failing.
             * `FrontEndApprovalQueue::register()` guards the same way.
             *
             * @param array<string, mixed> $args
             */
            function register_block_type(string $name, array $args = []): void
            {
                $GLOBALS['adct_test_blocks'][$name] = $args;
            }
        }

        if (! class_exists('WP_User', false)) {
        class WP_User
        {
            public int $ID = 0;
            public string $user_email = '';
            public int $user_status = 0;

            public function __construct(int $id = 0, string $email = '', int $userStatus = 0)
            {
                $this->ID = $id;
                $this->user_email = $email;
                $this->user_status = $userStatus;
            }
        }
    }
}