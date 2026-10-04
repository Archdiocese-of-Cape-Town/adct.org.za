<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin {
    /**
     * Guarded WordPress stubs for the admin screen tests.
     *
     * PHP allows a namespaced function to be declared only once per process,
     * and PHPUnit loads every test file into a single process. So a screen test
     * cannot unconditionally declare a stub another screen test has already
     * declared: whichever file loads second would fatal with "Cannot
     * redeclare". Each declaration below is therefore guarded by
     * function_exists() against the fully-qualified name, so the first screen
     * test file loaded wins and later ones fall back to these definitions.
     *
     * That is safe because every stub here is either a pass-through no test
     * observes, or one whose behaviour the screen tests never vary. Where two
     * screen tests genuinely need different behaviour from the same function,
     * the shared stub dispatches on a per-class global rather than each test
     * file racing to declare its own.
     *
     * current_user_can() is deliberately NOT declared here. It belongs to the
     * one copy in tests/Support/WordPressStubs.php, shared by every test in
     * this namespace, because ParserPageTest used to declare a second copy
     * that always returned true: whichever file PHPUnit happened to include
     * first won, which made every capability gate in this namespace untestable
     * and handed the help pane to a user the screen itself would refuse.
     * Declaring a second, more permissive copy here would reinstate that race,
     * so screen tests grant capabilities through $GLOBALS['adct_test_wp_caps']
     * instead.
     *
     * This is test scaffolding. The WordPress names are unavoidable because the
     * screens under test call them unqualified, and no test bootstrap loads
     * WordPress.
     */

    // error_log() is shared rather than declared per test file for the same
    // reason current_user_can() is: several screen tests need it, and a second
    // declaration would be a fatal. It fans out to whichever per-class buffer
    // the calling test set up, so each test still owns its own capture.
    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\error_log')) {
        function error_log(string $message): bool
        {
            if (isset($GLOBALS['audit_page_logs']) && is_array($GLOBALS['audit_page_logs'])) {
                $GLOBALS['audit_page_logs'][] = $message;
            }

            if (isset($GLOBALS['parser_page_logs']) && is_array($GLOBALS['parser_page_logs'])) {
                $GLOBALS['parser_page_logs'][] = $message;
            }

            return true;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_html')) {
        function esc_html(mixed $text): string
        {
            return htmlspecialchars(is_string($text) ? $text : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_attr')) {
        function esc_attr(mixed $text): string
        {
            return htmlspecialchars(is_string($text) ? $text : '', ENT_QUOTES, 'UTF-8');
        }
    }

    // `esc_textarea()` differs from `esc_html()` only in that it is meant for
    // textarea content, which is the same escaping. WordPress escapes
    // single quotes here too, which matters because the admin screens write
    // attributes and textarea bodies through both.
    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_textarea')) {
        function esc_textarea(mixed $text): string
        {
            return htmlspecialchars(is_string($text) ? $text : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_url')) {
        function esc_url(mixed $url): string
        {
            return htmlspecialchars(is_string($url) ? $url : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_url_raw')) {
        function esc_url_raw(mixed $url): string
        {
            return is_string($url) ? trim($url) : '';
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_html__')) {
        function esc_html__(string $text, string $domain = 'default'): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\absint')) {
        function absint(mixed $value): int
        {
            return abs((int) $value);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\selected')) {
        function selected(mixed $current, mixed $value, bool $echo = true): string
        {
            $markup = (string) $current === (string) $value ? " selected='selected'" : '';

            if ($echo) {
                echo $markup;
            }

            return $markup;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . ltrim($path, '/');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\add_query_arg')) {
        function add_query_arg(array $arguments, string $url): string
        {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($arguments);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\sanitize_text_field')) {
        function sanitize_text_field(mixed $value): string
        {
            return is_string($value) ? trim(strip_tags($value)) : '';
        }
    }

        if (! function_exists('ADCT\ParishIntake\WordPress\Admin\sanitize_key')) {
            /**
             * WordPress keeps only the characters a key or a slug may contain. The
             * admin screens use it on every $_GET value before dispatching on it, so
             * the stub has to actually drop the rest — a screen that dispatched on an
             * unsanitised value would otherwise pass here and fail on the site.
             */
            function sanitize_key(string $key): string
            {
                return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
            }
        }

    // Near enough to WordPress for these purposes: it drops anything that is
    // not part of a single address, which is exactly the filter a query
    // argument carrying a recipient has to survive before it is echoed back.
    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\sanitize_email')) {
        function sanitize_email(mixed $value): string
        {
            return is_string($value) ? (string) filter_var($value, FILTER_SANITIZE_EMAIL) : '';
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            return $value;
        }
    }
}
