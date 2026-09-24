<?php

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$fail = static function (string $message): void {
    WP_CLI::error($message);
};

$pluginBasename = 'adct-parish-intake/adct-parish-intake.php';
$pluginFile = WP_PLUGIN_DIR . '/' . $pluginBasename;

if (! is_file($pluginFile)) {
    $fail('The plugin must be installed from the built release zip.');
}

if (is_plugin_active($pluginBasename)) {
    $fail('The integration environment must start with the release plugin inactive.');
}

$activationMessages = [];
$previousErrorReporting = error_reporting();
error_reporting(E_ALL);
set_error_handler(
    static function (int $severity, string $message, string $file, int $line) use (&$activationMessages): bool {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        $activationMessages[] = sprintf('%s in %s:%d', $message, $file, $line);
        return true;
    }
);

try {
    $activationResult = activate_plugin($pluginBasename);
} finally {
    restore_error_handler();
    error_reporting($previousErrorReporting);
}

if (is_wp_error($activationResult)) {
    $fail('Plugin activation returned an error: ' . $activationResult->get_error_message());
}

if ($activationMessages !== []) {
    $fail("Plugin activation emitted PHP errors or notices:\n" . implode("\n", $activationMessages));
}

if (! is_plugin_active($pluginBasename)) {
    $fail('The release plugin was not active after activation.');
}

$administrators = get_users([
    'role' => 'administrator',
    'number' => 1,
]);

if ($administrators === []) {
    $fail('The wp-env site must have an administrator user.');
}

wp_set_current_user($administrators[0]->ID);

if (! current_user_can('edit_others_posts')) {
    $fail('The current administrator does not have the Parish Intake menu capability.');
}

$parentSlug = 'adct-parish-intake';
$manualParserSlug = 'adct-parish-intake-manual-parser';
$GLOBALS['menu'] = [];
$GLOBALS['submenu'] = [];
do_action('admin_menu');

$parentItems = array_values(array_filter(
    $GLOBALS['menu'],
    static fn ($item): bool => is_array($item) && ($item[2] ?? null) === $parentSlug
));

if (count($parentItems) !== 1 || strip_tags($parentItems[0][0]) !== 'Parish Intake') {
    $fail('The Parish Intake admin menu was not registered.');
}

$manualParserItems = array_values(array_filter(
    $GLOBALS['submenu'][$parentSlug] ?? [],
    static fn ($item): bool => is_array($item) && ($item[2] ?? null) === $manualParserSlug
));

if (count($manualParserItems) !== 1 || $manualParserItems[0][0] !== 'Manual parser') {
    $fail('The Manual parser submenu was not registered.');
}

$pageHook = get_plugin_page_hookname($manualParserSlug, $parentSlug);
if (has_action($pageHook) === false) {
    $fail('The Manual parser page callback was not registered.');
}

ob_start();
try {
    do_action($pageHook);
} finally {
    $manualParserHtml = (string) ob_get_clean();
}

if (strpos($manualParserHtml, '<h1>Parish Intake Manual Parser</h1>') === false) {
    $fail('The Manual parser page did not render for an administrator.');
}

if (strpos($manualParserHtml, 'name="body"') === false) {
    $fail('The Manual parser message form did not render.');
}

WP_CLI::success('Release ZIP activation and Manual parser integration checks passed.');
