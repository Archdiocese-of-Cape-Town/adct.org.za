<?php

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Approval\ApprovalRoute;
use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Approval\ApproverSettings;
use ADCT\ParishIntake\Core\Directory\ContactService;
use ADCT\ParishIntake\Core\Directory\DeaneryCsvImporter;
use ADCT\ParishIntake\Core\Directory\ImportRow;
use ADCT\ParishIntake\Core\Directory\ParishCsvImporter;
use ADCT\ParishIntake\Core\Directory\Venue;
use ADCT\ParishIntake\Core\Directory\VenueAdministrationService;
use ADCT\ParishIntake\Core\Directory\VenueDirectoryImporter;
use ADCT\ParishIntake\Core\Directory\VenueLookup;
use ADCT\ParishIntake\Core\Sources\Source;
use ADCT\ParishIntake\Core\Sources\SourceHealthRecorder;
use ADCT\ParishIntake\Core\Sources\SourceRegistryService;
use ADCT\ParishIntake\Core\Sources\SourceRole;
use ADCT\ParishIntake\Core\Sources\SourceStatus;
use ADCT\ParishIntake\Core\Sources\SourceType;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\Core\Auth\VersionedRoleInstaller;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryApproverRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ApprovalRouteRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishContactRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Directory\DirectoryImportService;
use ADCT\ParishIntake\WordPress\Directory\DeaneryApproverAssignmentService;

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

if ((int) get_option('adct_pi_db_version', 0) !== 2) {
    $fail('Activation did not set the parish intake schema version to 2.');
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

$venueTable = $wpdb->prefix . 'adct_pi_venues';
$sourceTable = $wpdb->prefix . 'adct_pi_sources';
$venueColumns = (array) $wpdb->get_col("SHOW COLUMNS FROM {$venueTable}", 0);
$venueIndexes = (array) $wpdb->get_results("SHOW INDEX FROM {$venueTable}", ARRAY_A);
$sourceParishIsUnique = false;

foreach ($venueIndexes as $index) {
    if (
        ($index['Key_name'] ?? '') === 'source_parish_id'
        && (int) ($index['Non_unique'] ?? 1) === 0
        && ($index['Column_name'] ?? '') === 'source_parish_id'
    ) {
        $sourceParishIsUnique = true;
        break;
    }
}

foreach (['aliases', 'latitude', 'longitude', 'is_default', 'status', 'source_parish_id'] as $column) {
    if (! in_array($column, $venueColumns, true)) {
        $fail('The v2 venues table is missing the ' . $column . ' column.');
    }
}

if (! $sourceParishIsUnique) {
    $fail('The v2 venues table is missing the unique source-parish index.');
}

$sourceColumns = (array) $wpdb->get_col("SHOW COLUMNS FROM {$sourceTable}", 0);

foreach ([
    'type',
    'identifier',
    'role',
    'status',
    'poll_interval_minutes',
    'last_checked_at',
    'last_success_at',
    'last_item_at',
    'consecutive_failures',
    'last_error',
] as $column) {
    if (! in_array($column, $sourceColumns, true)) {
        $fail('The source registry table is missing the ' . $column . ' column.');
    }
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

$previousPost = $_POST;
$previousRequest = $_REQUEST;
$_POST = [
    'adct_parish_intake_parse_nonce' => wp_create_nonce('adct_parish_intake_parse'),
    'adct_parish_intake_parse' => '1',
    'source_type' => 'manual-test',
    'source_identifier' => 'bulletin-integration-test',
    'sender_email' => 'events@example.test',
    'sender_name' => 'Fictional Parish Office',
    'subject' => 'Fictional Parish bulletin',
    'body' => "Parish: Fictional Parish\nOCTOBER 2026\nUPCOMING EVENTS\n- Youth gathering on Saturday 10 October 2026 at 16:00.\n- Family picnic on Sunday 11 October 2026 at 12:00.",
];
$_REQUEST = $_POST;
ob_start();
try {
    do_action($pageHook);
} finally {
    $submittedParserHtml = (string) ob_get_clean();
    $_POST = $previousPost;
    $_REQUEST = $previousRequest;
}

if (
    strpos($submittedParserHtml, 'Latest parse outcome') === false
    || strpos($submittedParserHtml, 'candidate_count') === false
    || strpos($submittedParserHtml, 'Youth gathering') === false
    || strpos($submittedParserHtml, 'Family picnic') === false
) {
    $fail('The Manual parser did not render all candidates for a bulletin.');
}

$legacyParserRow = $wpdb->get_row(
    "SELECT source_identifier, title FROM {$legacyTable} ORDER BY id DESC LIMIT 1",
    ARRAY_A
);

if (
    ! is_array($legacyParserRow)
    || $legacyParserRow['source_identifier'] !== 'bulletin-integration-test'
    || $legacyParserRow['title'] !== 'Youth gathering'
) {
    $fail('The legacy Manual parser table did not store the first bulletin candidate.');
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
$venueRepository = new VenueRepository($database);
$sourceRepository = new SourceRepository($database);
$clock = new SystemClock();
$contactService = new ContactService($contactRepository, $clock);
$sourceRegistry = new SourceRegistryService($sourceRepository, $clock);
$sourceHealthRecorder = new SourceHealthRecorder($sourceRepository, $clock);
$venueImporter = new VenueDirectoryImporter($venueRepository, $clock);
$importService = new DirectoryImportService(
    new ParishCsvImporter(),
    new DeaneryCsvImporter(),
    $parishRepository,
    $deaneryRepository,
    $contactService,
    $clock,
    $sourceRegistry,
    $venueImporter
);
$contactTable = $wpdb->prefix . 'adct_pi_parish_contacts';
$parishTable = $wpdb->prefix . 'adct_pi_parishes';
$deaneryTable = $wpdb->prefix . 'adct_pi_deaneries';
$approverTable = $wpdb->prefix . 'adct_pi_deanery_approvers';

foreach ([$approverTable, $contactTable, $venueTable, $sourceTable, $parishTable, $deaneryTable] as $table) {
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

$venueCountAfterFirstImport = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$venueTable}");
$importedOutstationVenueCount = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$venueTable} WHERE source_parish_id IS NOT NULL"
);
$parishesWithoutDefault = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$parishTable} p "
    . "WHERE p.status = 'active' AND NOT EXISTS ("
    . "SELECT 1 FROM {$venueTable} v "
    . "WHERE v.parish_id = p.id AND v.status = 'active' AND v.is_default = 1)"
);
$parishesWithMultipleDefaults = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM ("
    . "SELECT parish_id FROM {$venueTable} "
    . "WHERE status = 'active' GROUP BY parish_id HAVING SUM(is_default) > 1"
    . ') venue_defaults'
);

if (
    $venueCountAfterFirstImport < 124
    || $importedOutstationVenueCount < 1
    || $parishesWithoutDefault !== 0
    || $parishesWithMultipleDefaults !== 0
) {
    $fail('The parish import did not create provisional defaults and linked outstation venues with one default per parish.');
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

if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$sourceTable} WHERE role = 'official'") !== $officeEmailCount) {
    $fail('Imported office emails were not registered as one official email source per parish.');
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

$officialSources = array_values(array_filter(
    $sourceRepository->findForParish($firstParishId),
    static fn (Source $source): bool => $source->role === SourceRole::OFFICIAL
));

if (count($officialSources) !== 1 || $officialSources[0]->type !== SourceType::EMAIL) {
    $fail('The imported parish did not have exactly one official email source.');
}

$icsSource = $sourceRegistry->save(new Source(
    0,
    $firstParishId,
    SourceType::ICS,
    'https://example.test/calendar.ics',
    SourceRole::MONITORED
));
$icsSource = $sourceRegistry->save(new Source(
    $icsSource->id,
    $firstParishId,
    SourceType::ICS,
    'https://example.test/calendar.ics',
    SourceRole::OFFICIAL
));
$parishSources = $sourceRepository->findForParish($firstParishId);
$officialSourcesAfterSwitch = array_values(array_filter(
    $parishSources,
    static fn (Source $source): bool => $source->role === SourceRole::OFFICIAL
));
$demotedEmailSource = array_values(array_filter(
    $parishSources,
    static fn (Source $source): bool => $source->type === SourceType::EMAIL
));
$parishAfterSourceSwitch = $parishRepository->findById($firstParishId);

if (
    count($officialSourcesAfterSwitch) !== 1
    || $officialSourcesAfterSwitch[0]->id !== $icsSource->id
    || count($demotedEmailSource) !== 1
    || $demotedEmailSource[0]->role !== SourceRole::MONITORED
    || (int) ($parishAfterSourceSwitch['official_source_id'] ?? 0) !== $icsSource->id
) {
    $fail('Selecting a new official source did not demote the previous source and update the parish pointer.');
}

$sourceHealthRecorder->recordFailure($icsSource->id, 'HTTP check failed');
$sourceHealthRecorder->recordFailure($icsSource->id, 'HTTP check failed again');
$sourceHealthRecorder->recordFailure($icsSource->id, 'HTTP check failed again');
$sourceHealthRecorder->recordFailure($icsSource->id, 'HTTP check failed again');
$sourceHealthRecorder->recordFailure($icsSource->id, 'HTTP check failed again');
$unreliableSource = $sourceRepository->findSource($icsSource->id);

if (
    $unreliableSource === null
    || $unreliableSource->status !== SourceStatus::UNRELIABLE
    || $unreliableSource->consecutiveFailures !== SourceHealthRecorder::UNRELIABLE_FAILURE_THRESHOLD
    || $unreliableSource->lastCheckedAt === null
    || $unreliableSource->lastError !== 'HTTP check failed again'
) {
    $fail('Recording five source failures did not update health and mark the source unreliable.');
}

$sourceHealthRecorder->recordSuccess($icsSource->id, $clock->now()->modify('-1 hour'));
$recoveredSource = $sourceRepository->findSource($icsSource->id);

if (
    $recoveredSource === null
    || $recoveredSource->status !== SourceStatus::UNRELIABLE
    || $recoveredSource->consecutiveFailures !== 0
    || $recoveredSource->lastSuccessAt === null
    || $recoveredSource->lastItemAt === null
    || $recoveredSource->lastError !== null
) {
    $fail('A successful source check did not reset health while retaining the operator-controlled unreliable status.');
}

$archdioceseSourceOne = $sourceRegistry->save(new Source(
    0,
    null,
    SourceType::MANUAL,
    'Archdiocese event desk',
    SourceRole::OFFICIAL
));
$archdioceseSourceTwo = $sourceRegistry->save(new Source(
    0,
    null,
    SourceType::MANUAL,
    'Archdiocese calendar notes',
    SourceRole::OFFICIAL
));

if (
    $archdioceseSourceOne->role !== SourceRole::OFFICIAL
    || $archdioceseSourceTwo->role !== SourceRole::OFFICIAL
) {
    $fail('Archdiocese-wide official sources were incorrectly constrained to one per type.');
}

$venueAdministration = new VenueAdministrationService($venueRepository, $clock);
$acceptanceVenue = $venueAdministration->save($firstParishId, 0, [
    'name' => "St Mary's Hall",
    'aliases' => "Saint Mary's Hall, St Marys",
    'address' => '14 Example Road',
    'suburb' => 'Sample Suburb',
    'latitude' => '-33.9',
    'longitude' => '18.4',
    'is_default' => '1',
]);
$venueLookup = new VenueLookup($venueRepository);
$venueMatch = $venueLookup->match('The gathering will be at Saint Marys Hall.', $firstParishId);
$defaultVenue = $venueLookup->defaultVenueFor($firstParishId);
$defaultVenueCount = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$venueTable} WHERE parish_id = %d AND status = 'active' AND is_default = 1",
    $firstParishId
));

if (
    $venueMatch === null
    || $venueMatch->venueId !== $acceptanceVenue->id
    || $venueMatch->latitude !== -33.9
    || $venueMatch->longitude !== 18.4
    || $defaultVenue === null
    || $defaultVenue->venueId !== $acceptanceVenue->id
    || $defaultVenueCount !== 1
) {
    $fail('Venue creation, default selection, or location lookup did not work.');
}

$venueAdministration->save($firstParishId, 0, [
    'name' => 'Secondary Sample Hall',
]);
$replacementVenue = null;

foreach ($venueRepository->findForParish($firstParishId) as $candidateVenue) {
    if ($candidateVenue->status === Venue::ACTIVE && $candidateVenue->id !== $acceptanceVenue->id) {
        $replacementVenue = $candidateVenue;
        break;
    }
}

if ($replacementVenue === null) {
    $fail('The integration venue fixture has no active replacement venue.');
}

$venueAdministration->deactivate($firstParishId, $acceptanceVenue->id);
$defaultAfterDeactivation = $venueLookup->defaultVenueFor($firstParishId);
$deactivatedVenue = $venueRepository->findVenue($acceptanceVenue->id);

if (
    $defaultAfterDeactivation === null
    || $defaultAfterDeactivation->venueId !== $replacementVenue->id
    || $deactivatedVenue === null
    || $deactivatedVenue->status !== 'inactive'
) {
    $fail('Deactivating a default venue did not promote another active venue.');
}

$venueAdministration->reactivate($firstParishId, $acceptanceVenue->id);
$defaultAfterReactivation = $venueLookup->defaultVenueFor($firstParishId);

if ($defaultAfterReactivation === null || $defaultAfterReactivation->venueId !== $replacementVenue->id) {
    $fail('Reactivating a venue changed the parish default unexpectedly.');
}

$parishesPageSlug = 'adct-parish-intake-parishes';
$parishesPageHook = get_plugin_page_hookname($parishesPageSlug, $parentSlug);

if (has_action($parishesPageHook) === false) {
    $fail('The Parishes admin page callback was not registered.');
}

$originalGet = $_GET;
$_GET['page'] = $parishesPageSlug;
$_GET['action'] = 'edit';
$_GET['id'] = (string) $firstParishId;
$_GET['tab'] = 'venues';
ob_start();
try {
    do_action($parishesPageHook);
} finally {
    $venueTabHtml = (string) ob_get_clean();
    $_GET = $originalGet;
}

if (
    strpos($venueTabHtml, 'Venues for ') === false
    || strpos($venueTabHtml, 'name="aliases"') === false
    || strpos($venueTabHtml, 'Deactivate venue') === false
) {
    $fail('The parish Venues tab did not render its venue management controls.');
}

$originalGet = $_GET;
$_GET = [
    'action' => 'edit',
    'id' => (string) $firstParishId,
    'tab' => 'sources',
];
ob_start();
try {
    do_action($parishesPageHook);
} finally {
    $sourceTabHtml = (string) ob_get_clean();
    $_GET = $originalGet;
}

if (
    strpos($sourceTabHtml, 'Sources for ') === false
    || strpos($sourceTabHtml, 'calendar.ics') === false
    || strpos($sourceTabHtml, 'name="poll_interval_minutes"') === false
    || strpos($sourceTabHtml, 'Last checked') === false
    || strpos($sourceTabHtml, 'name="source_nonce"') === false
) {
    $fail('The parish Sources tab did not render source controls and read-only health.');
}

$sourcesPageSlug = 'adct-parish-intake-sources';
$sourcesPageItems = array_values(array_filter(
    $GLOBALS['submenu'][$parentSlug] ?? [],
    static fn ($item): bool => is_array($item) && ($item[2] ?? null) === $sourcesPageSlug
));

if (count($sourcesPageItems) !== 1 || $sourcesPageItems[0][0] !== 'Sources') {
    $fail('The Sources admin submenu was not registered for a directory manager.');
}

$sourcesPageHook = get_plugin_page_hookname($sourcesPageSlug, $parentSlug);

if (has_action($sourcesPageHook) === false) {
    $fail('The Sources page callback was not registered.');
}

$originalGet = $_GET;
$_GET = ['type' => SourceType::ICS];
ob_start();
try {
    do_action($sourcesPageHook);
} finally {
    $sourcesListHtml = (string) ob_get_clean();
    $_GET = $originalGet;
}

if (
    strpos($sourcesListHtml, '<h1 class="wp-heading-inline">Sources</h1>') === false
    || strpos($sourcesListHtml, 'calendar.ics') === false
    || strpos($sourcesListHtml, 'Last checked') === false
    || strpos($sourcesListHtml, 'name="parish_id"') === false
    || strpos($sourcesListHtml, 'name="role"') === false
    || strpos($sourcesListHtml, 'name="status"') === false
) {
    $fail('The Sources page did not render its filters, source list and health fields.');
}

$originalGet = $_GET;
$_GET = ['action' => 'add'];
ob_start();
try {
    do_action($sourcesPageHook);
} finally {
    $newGlobalSourceHtml = (string) ob_get_clean();
    $_GET = $originalGet;
}

if (
    strpos($newGlobalSourceHtml, 'Add archdiocese-wide source') === false
    || strpos($newGlobalSourceHtml, 'name="type"') === false
    || strpos($newGlobalSourceHtml, 'name="identifier"') === false
    || strpos($newGlobalSourceHtml, 'name="source_nonce"') === false
) {
    $fail('The archdiocese-wide Add source form is missing required registry fields.');
}

if ($wpdb->query($wpdb->prepare(
    "UPDATE {$contactTable} SET trust = %s, verified_at = NULL WHERE id = %d",
    'blocked',
    $firstContactId
)) === false) {
    $fail('The integration trust-preservation fixture could not be prepared.');
}

$venueCountBeforeRepeatImport = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$venueTable}");
$sourceCountBeforeRepeatImport = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sourceTable}");
$lastVenueIdBeforeRepeatImport = (int) $wpdb->get_var("SELECT MAX(id) FROM {$venueTable}");
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

$venueCountAfterSecondImport = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$venueTable}");
$sourceCountAfterRepeatImport = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sourceTable}");

if (
    $venueCountAfterSecondImport !== $venueCountBeforeRepeatImport
    || $sourceCountAfterRepeatImport !== $sourceCountBeforeRepeatImport
) {
    $venuesAddedOnRepeat = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT id, parish_id, source_parish_id, name, is_default "
        . "FROM {$venueTable} WHERE id > %d ORDER BY id ASC",
        $lastVenueIdBeforeRepeatImport
    ), ARRAY_A);
    $fail(sprintf(
        'A repeated parish import changed the venue or source count (venues %d before, %d after; sources %d before, %d after); added venue rows: %s.',
        $venueCountBeforeRepeatImport,
        $venueCountAfterSecondImport,
        $sourceCountBeforeRepeatImport,
        $sourceCountAfterRepeatImport,
        wp_json_encode($venuesAddedOnRepeat)
    ));
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

$backfillTimestamp = $clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$backfillParishId = $parishRepository->insert([
    'name' => 'Sample Backfill Parish',
    'slug' => 'sample-backfill-parish',
    'church' => 'Sample Chapel',
    'kind' => 'parish',
    'parent_parish_id' => null,
    'status' => 'active',
    'created_at' => $backfillTimestamp,
    'updated_at' => $backfillTimestamp,
]);
$venueCountBeforeBackfill = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$venueTable}");
$backfillCreated = $importService->ensureVenuesFromDirectory();
$backfilledVenues = $venueRepository->findForParish($backfillParishId);
$venueCountAfterBackfill = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$venueTable}");
$repeatedBackfillCreated = $importService->ensureVenuesFromDirectory();
$venueCountAfterRepeatedBackfill = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$venueTable}");

if (
    $backfillCreated !== 1
    || count($backfilledVenues) !== 1
    || $backfilledVenues[0]->name !== 'Sample Chapel'
    || ! $backfilledVenues[0]->isDefault
    || $venueCountAfterBackfill !== $venueCountBeforeBackfill + 1
    || $repeatedBackfillCreated !== 0
    || $venueCountAfterRepeatedBackfill !== $venueCountAfterBackfill
) {
    $fail('The existing-directory venue backfill did not create one provisional default idempotently.');
}

$centralDeanery = $deaneryRepository->findBySlug('central');

if ($centralDeanery === null) {
    $fail('The central deanery could not be found after importing the seed directory.');
}

$centralDeaneryId = (int) $centralDeanery['id'];
$routeTimestamp = $clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$centralParishIds = [
    $parishRepository->insert([
        'name' => 'Sample Central Parish One',
        'slug' => 'sample-central-parish-one',
        'kind' => 'parish',
        'deanery_id' => null,
        'status' => 'active',
        'created_at' => $routeTimestamp,
        'updated_at' => $routeTimestamp,
    ]),
    $parishRepository->insert([
        'name' => 'Sample Central Parish Two',
        'slug' => 'sample-central-parish-two',
        'kind' => 'parish',
        'deanery_id' => null,
        'status' => 'active',
        'created_at' => $routeTimestamp,
        'updated_at' => $routeTimestamp,
    ]),
];

if ($parishRepository->updateDeaneryForParishes(
    $centralParishIds,
    $centralDeaneryId,
    $routeTimestamp
) < 1) {
    $fail('The sample parishes could not be assigned to the central deanery.');
}

$approverRepository = new DeaneryApproverRepository($database);
$assignmentService = new DeaneryApproverAssignmentService($approverRepository);
$approverAssignmentIds = [];
$approverUserIds = [];
$mailAttemptCount = 0;
$mailAttemptFilter = static function ($pre, $arguments) use (&$mailAttemptCount) {
    ++$mailAttemptCount;

    return true;
};
add_filter('pre_wp_mail', $mailAttemptFilter, 10, 2);

try {
    foreach ([
        [
            'login' => 'route-approver-one',
            'account_email' => 'route-approver-one@example.test',
            'approval_email' => 'approval-one@example.test',
            'label' => 'Sample dean',
            'mode' => ApproverSettings::NOTIFY_EACH,
        ],
        [
            'login' => 'route-approver-two',
            'account_email' => 'route-approver-two@example.test',
            'approval_email' => 'approval-two@example.test',
            'label' => 'Sample assistant',
            'mode' => ApproverSettings::NOTIFY_DIGEST,
        ],
    ] as $index => $approver) {
        $settings = new ApproverSettings(
            $approver['approval_email'],
            $approver['label'],
            $approver['mode'],
            true,
            true
        );
        $existingUser = get_user_by('email', $approver['account_email']);

        if ($existingUser instanceof WP_User) {
            $userId = (int) $existingUser->ID;
            $assignmentId = $assignmentService->assignExistingUser(
                $centralDeaneryId,
                $userId,
                $settings,
                $routeTimestamp
            );
        } else {
            $assignmentId = $assignmentService->createUserAndAssign(
                $centralDeaneryId,
                $approver['login'],
                $approver['account_email'],
                $settings,
                $routeTimestamp
            );
            $createdUser = get_user_by('email', $approver['account_email']);

            if (! $createdUser instanceof WP_User) {
                $fail('The sample deanery approver WordPress user could not be found after creation.');
            }

            $userId = (int) $createdUser->ID;
        }

        $approverAssignmentIds[$index] = $assignmentId;
        $approverUserIds[$index] = $userId;
    }
} finally {
    remove_filter('pre_wp_mail', $mailAttemptFilter, 10);
}

if ($mailAttemptCount !== 0) {
    $fail('Creating deanery approver users attempted to send an email.');
}

$routeResolver = new ApprovalRouteResolver(new ApprovalRouteRepository($database));

foreach ($centralParishIds as $centralParishId) {
    $route = $routeResolver->forParish($centralParishId);

    if (
        $route->reviewersOnly
        || $route->reason !== ApprovalRoute::REASON_OK
        || count($route->approvers) !== 2
    ) {
        $fail('A central deanery parish did not resolve to both active approvers and reviewers.');
    }
}

$noDeaneryParishId = $parishRepository->insert([
    'name' => 'Sample Group Without Deanery',
    'slug' => 'sample-group-without-deanery',
    'kind' => 'group',
    'deanery_id' => null,
    'status' => 'active',
    'created_at' => $routeTimestamp,
    'updated_at' => $routeTimestamp,
]);
$noDeaneryRoute = $routeResolver->forParish($noDeaneryParishId);

if (
    ! $noDeaneryRoute->reviewersOnly
    || $noDeaneryRoute->reason !== ApprovalRoute::REASON_NO_DEANERY
) {
    $fail('A parish without a deanery did not resolve to reviewers only.');
}

$cityDeanery = $deaneryRepository->findBySlug('city-bowl');

if ($cityDeanery === null) {
    $fail('The city-bowl deanery could not be found.');
}

$cityDeaneryId = (int) $cityDeanery['id'];
$extraAssignmentId = $assignmentService->assignExistingUser(
    $cityDeaneryId,
    $approverUserIds[0],
    new ApproverSettings(
        'approval-one-city@example.test',
        'Sample assistant',
        ApproverSettings::NOTIFY_EACH,
        false,
        true
    ),
    $routeTimestamp
);
$assignmentService->deactivateAssignment(
    $approverAssignmentIds[0],
    $centralDeaneryId,
    $routeTimestamp
);
$firstApproverUser = get_user_by('id', $approverUserIds[0]);

if (! ($firstApproverUser instanceof WP_User) || ! in_array('deanery_approver', $firstApproverUser->roles, true)) {
    $fail('Deactivating one assignment removed a role still needed by another active assignment.');
}

$assignmentService->deactivateAssignment($extraAssignmentId, $cityDeaneryId, $routeTimestamp);
$firstApproverUser = get_user_by('id', $approverUserIds[0]);

if ($firstApproverUser instanceof WP_User && in_array('deanery_approver', $firstApproverUser->roles, true)) {
    $fail('The deanery approver role remained after the user lost their final active assignment.');
}

$administratorAssignmentId = $assignmentService->assignExistingUser(
    $cityDeaneryId,
    (int) $administrators[0]->ID,
    new ApproverSettings(
        'administrator-approval@example.test',
        'Sample reviewer account',
        ApproverSettings::NOTIFY_EACH,
        true,
        true
    ),
    $routeTimestamp
);
$administratorAfterAssignment = get_user_by('id', (int) $administrators[0]->ID);

if (
    ! ($administratorAfterAssignment instanceof WP_User)
    || ! in_array('administrator', $administratorAfterAssignment->roles, true)
    || ! in_array('deanery_approver', $administratorAfterAssignment->roles, true)
) {
    $fail('Assigning an existing user removed one of their other WordPress roles.');
}

$assignmentService->deactivateAssignment(
    $administratorAssignmentId,
    $cityDeaneryId,
    $routeTimestamp
);
$administratorAfterDeactivation = get_user_by('id', (int) $administrators[0]->ID);

if (
    ! ($administratorAfterDeactivation instanceof WP_User)
    || ! in_array('administrator', $administratorAfterDeactivation->roles, true)
    || in_array('deanery_approver', $administratorAfterDeactivation->roles, true)
) {
    $fail('Removing the final approver assignment changed the administrator role.');
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

$deaneriesSlug = 'adct-parish-intake-deaneries';
$deaneryItems = array_values(array_filter(
    $GLOBALS['submenu'][$parentSlug] ?? [],
    static fn ($item): bool => is_array($item) && ($item[2] ?? null) === $deaneriesSlug
));

if (count($deaneryItems) !== 1 || $deaneryItems[0][0] !== 'Deaneries') {
    $fail('The Deaneries admin submenu was not registered for a directory manager.');
}

$deaneriesPageHook = get_plugin_page_hookname($deaneriesSlug, $parentSlug);
if (has_action($deaneriesPageHook) === false) {
    $fail('The Deaneries page callback was not registered.');
}

$previousGet = $_GET;
$_GET = ['page' => $deaneriesSlug];
ob_start();
try {
    do_action($deaneriesPageHook);
} finally {
    $deaneriesHtml = (string) ob_get_clean();
    $_GET = $previousGet;
}

if (
    strpos($deaneriesHtml, '<h1 class="wp-heading-inline">Deaneries</h1>') === false
    || strpos($deaneriesHtml, 'No active approver — reviewers only') === false
) {
    $fail('The Deaneries page did not render its list and reviewers-only warning.');
}

$previousGet = $_GET;
$_GET = ['action' => 'edit', 'id' => (string) $centralDeaneryId];
ob_start();
try {
    do_action($deaneriesPageHook);
} finally {
    $centralDeaneryHtml = (string) ob_get_clean();
    $_GET = $previousGet;
}

foreach ([
    '<h2>Deanery approvers</h2>',
    'approval-one@example.test',
    'approval-two@example.test',
    'name="user_source"',
    'name="new_user_login"',
    'name="new_user_email"',
    'name="approval_email"',
    'name="notify_mode"',
    'name="reminders_enabled"',
    'name="active"',
] as $approverField) {
    if (strpos($centralDeaneryHtml, $approverField) === false) {
        $fail('The Deanery edit page is missing an approver field or assignment.');
    }
}

$previousGet = $_GET;
$_GET = ['action' => 'add'];
ob_start();
try {
    do_action($deaneriesPageHook);
} finally {
    $newDeaneryHtml = (string) ob_get_clean();
    $_GET = $previousGet;
}

foreach ([
    '<h1>Add deanery</h1>',
    'name="name"',
    'name="slug"',
    'name="dean_name"',
    'name="vice_dean_name"',
    'name="secretary_name"',
    'name="status"',
] as $deaneryField) {
    if (strpos($newDeaneryHtml, $deaneryField) === false) {
        $fail('The Add deanery screen is missing a required field.');
    }
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

if (
    strpos($parishesHtml, 'name="search"') === false
    || strpos($parishesHtml, 'name="csv_file"') === false
    || strpos($parishesHtml, 'name="parish_ids[]"') === false
) {
    $fail('The Parishes screen is missing its search, CSV import or bulk assignment controls.');
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

$previousGet = $_GET;
$_GET = ['action' => 'edit', 'id' => (string) $noDeaneryParishId];
ob_start();
try {
    do_action($parishesPageHook);
} finally {
    $noDeaneryParishHtml = (string) ob_get_clean();
    $_GET = $previousGet;
}

if (strpos($noDeaneryParishHtml, '<strong>Reviewers only.</strong>') === false) {
    $fail('The parish edit screen did not clearly show its reviewers-only route.');
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

$assignmentService->deactivateAssignment(
    $approverAssignmentIds[1],
    $centralDeaneryId,
    $routeTimestamp
);
$secondApproverUser = get_user_by('id', $approverUserIds[1]);

if ($secondApproverUser instanceof WP_User && in_array('deanery_approver', $secondApproverUser->roles, true)) {
    $fail('The final sample approver role was not removed during integration cleanup.');
}

WP_CLI::success('Release ZIP activation, venue and source registry/import/health checks, official-source switching, parish Sources tab, deanery routes and role assignments, directory CSV imports, parish contacts, admin screens, and Manual parser checks passed.');
