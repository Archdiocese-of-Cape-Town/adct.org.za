<?php

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Directory\ContactService;
use ADCT\ParishIntake\Core\Directory\DeaneryCsvImporter;
use ADCT\ParishIntake\Core\Directory\ImportRow;
use ADCT\ParishIntake\Core\Directory\ParishCsvImporter;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\Core\Auth\VersionedRoleInstaller;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishContactRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Directory\DirectoryImportService;

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

if ((int) get_option('adct_pi_db_version', 0) !== 1) {
    $fail('Activation did not set the parish intake schema version to 1.');
}

if ((int) get_option('adct_pi_roles_version', 0) !== VersionedRoleInstaller::CURRENT_VERSION) {
    $fail('Activation did not set the parish intake roles version.');
}

foreach (Capabilities::customRoleLabels() as $roleName => $roleLabel) {
    if (get_role($roleName) === null) {
        $fail(sprintf('Activation did not create the %s role (%s).', $roleLabel, $roleName));
    }
}

$administratorRole = get_role('administrator');
if ($administratorRole === null) {
    $fail('The administrator role is missing.');
}

foreach (Capabilities::all() as $capability) {
    if (! $administratorRole->has_cap($capability)) {
        $fail('The administrator role is missing the ' . $capability . ' capability.');
    }
}

global $wpdb;
$installedTables = (array) $wpdb->get_col('SHOW TABLES');
$expectedTableSuffixes = [
    'adct_pi_action_tokens',
    'adct_pi_attachments',
    'adct_pi_audit_log',
    'adct_pi_deaneries',
    'adct_pi_deanery_approvers',
    'adct_pi_event_candidates',
    'adct_pi_event_changes',
    'adct_pi_follow_ups',
    'adct_pi_inbound_messages',
    'adct_pi_mail_queue',
    'adct_pi_occurrences',
    'adct_pi_parish_contacts',
    'adct_pi_parishes',
    'adct_pi_sources',
    'adct_pi_venues',
];
$expectedTables = array_map(
    static fn (string $suffix): string => $wpdb->prefix . $suffix,
    $expectedTableSuffixes
);
$actualTables = array_values(array_filter(
    $installedTables,
    static fn (string $tableName): bool => str_starts_with($tableName, $wpdb->prefix . 'adct_pi_')
));
sort($expectedTables, SORT_STRING);
sort($actualTables, SORT_STRING);

if ($actualTables !== $expectedTables) {
    $missingTables = array_diff($expectedTables, $actualTables);
    $unexpectedTables = array_diff($actualTables, $expectedTables);
    $fail(sprintf(
        'Schema v1 tables differ. Missing: [%s]; unexpected: [%s].',
        implode(', ', $missingTables),
        implode(', ', $unexpectedTables)
    ));
}

$legacyTable = $wpdb->prefix . 'adct_parish_intake_items';
if (! in_array($legacyTable, $installedTables, true)) {
    $fail('Activation did not preserve the legacy parish intake table.');
}

$administrators = get_users([
    'role' => 'administrator',
    'number' => 1,
]);

if ($administrators === []) {
    $fail('The wp-env site must have an administrator user.');
}

wp_set_current_user($administrators[0]->ID);

if (! current_user_can(Capabilities::REVIEW)) {
    $fail('The current administrator does not have the Parish Intake menu capability.');
}

$intakeReviewerRole = get_role('adct_pi_intake_reviewer');
$intakeReviewerRole->remove_cap(Capabilities::VIEW_REPORTS);
update_option('adct_pi_roles_version', 0, false);
do_action('admin_init');

if (! get_role('adct_pi_intake_reviewer')->has_cap(Capabilities::VIEW_REPORTS)) {
    $fail('An admin_init role-version upgrade did not restore the missing reviewer capability.');
}

if ((int) get_option('adct_pi_roles_version', 0) !== VersionedRoleInstaller::CURRENT_VERSION) {
    $fail('The admin_init role upgrade did not advance the roles version.');
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

$seedDeaneriesCsv = file_get_contents(__DIR__ . '/seed/deaneries.csv');
$seedParishesCsv = file_get_contents(__DIR__ . '/seed/parishes.csv');

if (! is_string($seedDeaneriesCsv) || ! is_string($seedParishesCsv)) {
    $fail('The directory seed CSV files are not mounted in the integration environment.');
}

$database = new WordPressDatabaseConnection($wpdb);
$parishRepository = new ParishRepository($database);
$deaneryRepository = new DeaneryRepository($database);
$contactRepository = new ParishContactRepository($database);
$clock = new SystemClock();
$contactService = new ContactService($contactRepository, $clock);
$importService = new DirectoryImportService(
    new ParishCsvImporter(),
    new DeaneryCsvImporter(),
    $parishRepository,
    $deaneryRepository,
    $contactService,
    $clock
);
$contactTable = $wpdb->prefix . 'adct_pi_parish_contacts';
$parishTable = $wpdb->prefix . 'adct_pi_parishes';
$deaneryTable = $wpdb->prefix . 'adct_pi_deaneries';

foreach ([$contactTable, $parishTable, $deaneryTable] as $table) {
    if ($wpdb->query("DELETE FROM {$table}") === false) {
        $fail('The integration directory tables could not be reset.');
    }
}

$firstDeaneryImport = $importService->importDeaneries($seedDeaneriesCsv);
$firstParishImport = $importService->importParishes($seedParishesCsv);

if (
    $firstDeaneryImport->counts()[ImportRow::CREATE] !== 8
    || $firstDeaneryImport->counts()['errors'] !== 0
) {
    $fail('The seed deaneries CSV did not create 8 deaneries without errors.');
}

if (
    $firstParishImport->counts()[ImportRow::CREATE] !== 124
    || $firstParishImport->counts()['errors'] !== 0
) {
    $fail('The seed parishes CSV did not create 124 parishes without errors.');
}

if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$deaneryTable}") !== 8) {
    $fail('The deanery import did not produce 8 database rows.');
}

if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$parishTable}") !== 124) {
    $fail('The parish import did not produce 124 database rows.');
}

$seedStream = fopen('php://temp', 'r+');
if (! is_resource($seedStream) || fwrite($seedStream, $seedParishesCsv) !== strlen($seedParishesCsv)) {
    $fail('The parish seed CSV could not be parsed for contact assertions.');
}
rewind($seedStream);
$seedHeaders = fgetcsv($seedStream, null, ',', '"', '');
$emailIndex = is_array($seedHeaders) ? array_search('office_email', $seedHeaders, true) : false;
$slugIndex = is_array($seedHeaders) ? array_search('slug', $seedHeaders, true) : false;
$firstOfficeEmail = null;
$firstOfficeParishSlug = null;
$officeEmailCount = 0;

if (! is_int($emailIndex) || ! is_int($slugIndex)) {
    $fail('The parish seed CSV is missing its email or slug column.');
}

while (($seedRow = fgetcsv($seedStream, null, ',', '"', '')) !== false) {
    $email = trim((string) ($seedRow[$emailIndex] ?? ''));

    if ($email !== '') {
        ++$officeEmailCount;

        if ($firstOfficeEmail === null) {
            $firstOfficeEmail = strtolower($email);
            $firstOfficeParishSlug = (string) ($seedRow[$slugIndex] ?? '');
        }
    }
}
fclose($seedStream);

$contactsAfterFirstImport = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$contactTable}");
$verifiedAfterFirstImport = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$contactTable} WHERE trust = 'verified' AND verified_at IS NOT NULL"
);

if ($contactsAfterFirstImport !== $officeEmailCount || $verifiedAfterFirstImport !== $officeEmailCount) {
    $fail('Imported office emails were not all created as verified parish contacts.');
}

if ($firstOfficeEmail === null || $firstOfficeParishSlug === null) {
    $fail('The parish seed CSV does not contain an office email for the trust-preservation check.');
}

$firstParishId = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$parishTable} WHERE slug = %s LIMIT 1",
    $firstOfficeParishSlug
));
$firstContactId = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$contactTable} WHERE parish_id = %d AND email = %s LIMIT 1",
    $firstParishId,
    $firstOfficeEmail
));

if ($firstParishId < 1 || $firstContactId < 1) {
    $fail('An imported parish office contact could not be found.');
}

if ($wpdb->query($wpdb->prepare(
    "UPDATE {$contactTable} SET trust = %s, verified_at = NULL WHERE id = %d",
    'blocked',
    $firstContactId
)) === false) {
    $fail('The integration trust-preservation fixture could not be prepared.');
}

$secondDeaneryImport = $importService->importDeaneries($seedDeaneriesCsv);
$secondParishImport = $importService->importParishes($seedParishesCsv);

if (
    $secondDeaneryImport->counts()[ImportRow::CREATE] !== 0
    || $secondDeaneryImport->counts()[ImportRow::UPDATE] !== 0
    || $secondDeaneryImport->counts()[ImportRow::UNCHANGED] !== 8
) {
    $fail('A second deanery import did not leave all 8 rows unchanged.');
}

if (
    $secondParishImport->counts()[ImportRow::CREATE] !== 0
    || $secondParishImport->counts()[ImportRow::UPDATE] !== 0
    || $secondParishImport->counts()[ImportRow::UNCHANGED] !== 124
) {
    $fail('A second parish import created or updated rows instead of leaving all 124 unchanged.');
}

$contactAfterRepeat = $wpdb->get_row($wpdb->prepare(
    "SELECT trust, verified_at FROM {$contactTable} WHERE id = %d LIMIT 1",
    $firstContactId
), ARRAY_A);

if (
    ! is_array($contactAfterRepeat)
    || $contactAfterRepeat['trust'] !== 'blocked'
    || $contactAfterRepeat['verified_at'] !== null
) {
    $fail('A repeated import changed an existing parish contact trust value.');
}

$secondParishId = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$parishTable} WHERE id <> %d ORDER BY id ASC LIMIT 1",
    $firstParishId
));

if ($secondParishId < 1) {
    $fail('A second parish could not be found for the shared sender test.');
}

$sharedSenderEmail = 'sender@example.test';
$contactService->link($firstParishId, $sharedSenderEmail, 'Sample Sender', 'Secretary', true);
$contactService->link($secondParishId, $sharedSenderEmail, 'Sample Sender', 'Secretary', true);
$contactService->block($sharedSenderEmail);
$sharedSenderLinks = $wpdb->get_results($wpdb->prepare(
    "SELECT parish_id, trust FROM {$contactTable} WHERE email = %s ORDER BY parish_id ASC",
    $sharedSenderEmail
), ARRAY_A);
$sharedSenderParishIds = is_array($sharedSenderLinks)
    ? array_map(static fn (array $row): int => (int) $row['parish_id'], $sharedSenderLinks)
    : [];
$expectedSharedSenderParishIds = [$firstParishId, $secondParishId];
sort($expectedSharedSenderParishIds, SORT_NUMERIC);

if (
    ! is_array($sharedSenderLinks)
    || count($sharedSenderLinks) !== 2
    || array_column($sharedSenderLinks, 'trust') !== ['blocked', 'blocked']
    || $sharedSenderParishIds !== $expectedSharedSenderParishIds
) {
    $fail('Blocking a shared sender did not update both parish contact links.');
}

$senderAddressRows = $contactRepository->findSenderAddresses(['search' => $sharedSenderEmail], 20, 0);
$senderAddressEmails = array_map(
    static fn (array $sender): string => (string) ($sender['email'] ?? ''),
    $senderAddressRows
);

if (! in_array($sharedSenderEmail, $senderAddressEmails, true)) {
    $fail('The Senders repository query did not return the linked sample address.');
}

$senderLinkRows = $contactRepository->findSenderLinksByEmails([$sharedSenderEmail]);

if (count($senderLinkRows) !== 2) {
    $fail('The Senders repository query did not return both parish links.');
}

$parentSlug = 'adct-parish-intake';
$parishesSlug = 'adct-parish-intake-parishes';
$parishItems = array_values(array_filter(
    $GLOBALS['submenu'][$parentSlug] ?? [],
    static fn ($item): bool => is_array($item) && ($item[2] ?? null) === $parishesSlug
));

if (count($parishItems) !== 1 || $parishItems[0][0] !== 'Parishes') {
    $fail('The Parishes admin submenu was not registered for a directory manager.');
}

if (! current_user_can(Capabilities::MANAGE_DIRECTORY)) {
    $fail('The administrator does not have directory-management capability.');
}

$parishesPageHook = get_plugin_page_hookname($parishesSlug, $parentSlug);
if (has_action($parishesPageHook) === false) {
    $fail('The Parishes page callback was not registered.');
}

ob_start();
try {
    do_action($parishesPageHook);
} finally {
    $parishesHtml = (string) ob_get_clean();
}

if (strpos($parishesHtml, '<h1 class="wp-heading-inline">Parishes</h1>') === false) {
    $fail('The Parishes page did not render for a directory manager.');
}

if (strpos($parishesHtml, 'name="search"') === false || strpos($parishesHtml, 'name="csv_file"') === false) {
    $fail('The Parishes screen is missing its search or CSV import controls.');
}

$previousGet = $_GET;
$_GET = ['action' => 'add'];
ob_start();
try {
    do_action($parishesPageHook);
} finally {
    $parishFormHtml = (string) ob_get_clean();
    $_GET = $previousGet;
}

foreach ([
    'name="name"',
    'name="slug"',
    'name="deanery_id"',
    'name="parent_parish_id"',
    'name="latitude"',
    'name="longitude"',
    'name="expected_cadence_days"',
    'name="reminders_enabled"',
    'name="status"',
    'name="notes"',
    'Find on Google Maps',
] as $formField) {
    if (strpos($parishFormHtml, $formField) === false) {
        $fail('The parish form is missing a required field or map helper: ' . $formField);
    }
}

$previousGet = $_GET;
$_GET = ['action' => 'edit', 'id' => (string) $firstParishId];
ob_start();
try {
    do_action($parishesPageHook);
} finally {
    $contactFormHtml = (string) ob_get_clean();
    $_GET = $previousGet;
}

if (
    strpos($contactFormHtml, '<h2>Contacts</h2>') === false
    || strpos($contactFormHtml, $sharedSenderEmail) === false
) {
    $fail('The parish edit screen did not render its linked contacts section.');
}

$sendersSlug = 'adct-parish-intake-senders';
$sendersItems = array_values(array_filter(
    $GLOBALS['submenu'][$parentSlug] ?? [],
    static fn ($item): bool => is_array($item) && ($item[2] ?? null) === $sendersSlug
));

if (count($sendersItems) !== 1 || $sendersItems[0][0] !== 'Senders') {
    $fail('The Senders admin submenu was not registered.');
}

$sendersPageHook = get_plugin_page_hookname($sendersSlug, $parentSlug);
if (has_action($sendersPageHook) === false) {
    $fail('The Senders page callback was not registered.');
}

$previousGet = $_GET;
$_GET = ['page' => $sendersSlug];
$_GET['search'] = $sharedSenderEmail;
ob_start();
try {
    do_action($sendersPageHook);
} finally {
    $sendersHtml = (string) ob_get_clean();
    $_GET = $previousGet;
}

$missingSendersContent = [];

foreach ([
    'heading' => '<h1 class="wp-heading-inline">Senders</h1>',
    'email' => $sharedSenderEmail,
    'unblock action' => 'Unblock address',
] as $label => $needle) {
    if (strpos($sendersHtml, $needle) === false) {
        $missingSendersContent[] = $label;
    }
}

if ($missingSendersContent !== []) {
    $fail('The Senders page did not render the shared blocked sender and actions (missing: '
        . implode(', ', $missingSendersContent) . ').');
}

WP_CLI::success('Release ZIP activation, directory CSV imports, parish contacts, Senders admin screen and Manual parser integration checks passed.');
