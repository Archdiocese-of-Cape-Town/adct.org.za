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
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\AuthenticationResult;
use ADCT\ParishIntake\Core\Ingestion\AuthenticationResults;
use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
use ADCT\ParishIntake\Core\Ingestion\InboundAttachmentRecord;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettingsValidator;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\MailQueueClaimStatus;
use ADCT\ParishIntake\Core\Mail\MailQueueConfiguration;
use ADCT\ParishIntake\Core\Mail\MailQueueDispatchStatus;
use ADCT\ParishIntake\Core\Mail\MailQueueDispatcher;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Security\SecretRegistry;
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
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishContactRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\MailboxRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Database\WordPressMailQueueRepository;
use ADCT\ParishIntake\WordPress\Admin\OutboundMailPage;
use ADCT\ParishIntake\WordPress\Plugin;
use ADCT\ParishIntake\WordPress\Database\WordPressInboundMessageStore;
use ADCT\ParishIntake\WordPress\Directory\DirectoryImportService;
use ADCT\ParishIntake\WordPress\Directory\DeaneryApproverAssignmentService;
use ADCT\ParishIntake\WordPress\Directory\WordPressDirectoryVersionStore;
use ADCT\ParishIntake\WordPress\Events\EventEditor;
use ADCT\ParishIntake\WordPress\Events\EventPostType;
use ADCT\ParishIntake\WordPress\Mail\WordPressMailDeliveryAdapter;
use ADCT\ParishIntake\WordPress\Mail\WordPressTestModeRecipientPolicy;
use ADCT\ParishIntake\WordPress\Mail\WordPressTestModeSettings;

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

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

delete_option('adct_pi_test_mode');
delete_option('adct_pi_test_allowlist');

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

if (
    get_option(WordPressTestModeSettings::TEST_MODE_OPTION) !== WordPressTestModeSettings::MODE_DISABLED
    || get_option(WordPressTestModeSettings::ALLOWLIST_OPTION) !== []
) {
    $fail('Fresh plugin activation did not default outbound test mode to off with an empty allow-list.');
}

if ((int) get_option('adct_pi_db_version', 0) !== 5) {
    $fail('Activation did not set the parish intake schema version to 5.');
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

$eventPostType = get_post_type_object(EventPostType::POST_TYPE);
$eventTaxonomy = get_taxonomy(EventPostType::TAXONOMY);

if (
    $eventPostType === null
    || ! $eventPostType->public
    || $eventPostType->has_archive !== 'events'
    || ! is_array($eventPostType->rewrite)
    || ($eventPostType->rewrite['slug'] ?? '') !== 'events'
    || ! $eventPostType->show_in_rest
    || $eventTaxonomy === false
    || ! $eventTaxonomy->hierarchical
    || ! $eventTaxonomy->show_in_rest
) {
    $fail('The public event post type or hierarchical REST taxonomy was not registered correctly.');
}

$expectedEventPostCapabilities = [
    'edit_posts' => Capabilities::EDIT_EVENTS,
    'create_posts' => Capabilities::EDIT_EVENTS,
    'edit_others_posts' => Capabilities::EDIT_OTHERS_EVENTS,
    'edit_private_posts' => Capabilities::EDIT_PRIVATE_EVENTS,
    'edit_published_posts' => Capabilities::EDIT_PUBLISHED_EVENTS,
    'publish_posts' => Capabilities::PUBLISH_EVENTS,
    'read_private_posts' => Capabilities::READ_PRIVATE_EVENTS,
    'delete_posts' => Capabilities::DELETE_EVENTS,
    'delete_private_posts' => Capabilities::DELETE_PRIVATE_EVENTS,
    'delete_published_posts' => Capabilities::DELETE_PUBLISHED_EVENTS,
    'delete_others_posts' => Capabilities::DELETE_OTHERS_EVENTS,
];

foreach ($expectedEventPostCapabilities as $capabilityName => $capability) {
    if (
        ! ($eventPostType instanceof WP_Post_Type)
        || ($eventPostType->cap->{$capabilityName} ?? null) !== $capability
    ) {
        $fail('The event post type does not map ' . $capabilityName . ' to ' . $capability . '.');
    }
}

if (($eventTaxonomy->cap->assign_terms ?? '') !== Capabilities::EDIT_EVENTS) {
    $fail('The event type taxonomy does not use the namespaced event assignment capability.');
}

foreach (['title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields'] as $support) {
    if (! post_type_supports(EventPostType::POST_TYPE, $support)) {
        $fail('The event post type is missing support for ' . $support . '.');
    }
}

$eventTermsBeforeRepeat = get_terms([
    'taxonomy' => EventPostType::TAXONOMY,
    'hide_empty' => false,
]);

if (is_wp_error($eventTermsBeforeRepeat) || count($eventTermsBeforeRepeat) !== count(EventPostType::DEFAULT_TERMS)) {
    $fail('Activation did not seed each default event type exactly once.');
}

$eventContent = new EventPostType();
$eventContent->activate();
$eventContent->activate();
$eventTermsAfterRepeat = get_terms([
    'taxonomy' => EventPostType::TAXONOMY,
    'hide_empty' => false,
]);
$expectedEventTermSlugs = array_column(EventPostType::DEFAULT_TERMS, 'slug');
$actualEventTermSlugs = is_array($eventTermsAfterRepeat)
    ? array_map(static fn (WP_Term $term): string => $term->slug, $eventTermsAfterRepeat)
    : [];
sort($expectedEventTermSlugs, SORT_STRING);
sort($actualEventTermSlugs, SORT_STRING);

if ($actualEventTermSlugs !== $expectedEventTermSlugs) {
    $fail('Repeated event setup created duplicate or missing default event types.');
}

foreach (['administrator', 'editor', 'adct_pi_intake_manager', 'adct_pi_intake_reviewer'] as $roleName) {
    $role = get_role($roleName);

    foreach (Capabilities::eventCapabilities() as $capability) {
        if ($role === null || ! $role->has_cap($capability)) {
            $fail(sprintf('The %s role is missing the event capability %s.', $roleName, $capability));
        }
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
    'adct_pi_mailboxes',
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
        'Schema v5 tables differ. Missing: [%s]; unexpected: [%s].',
        implode(', ', $missingTables),
        implode(', ', $unexpectedTables)
    ));
}

$occurrencesTable = $wpdb->prefix . 'adct_pi_occurrences';
$occurrenceParishColumn = $wpdb->get_row(
    $wpdb->prepare("SHOW COLUMNS FROM {$occurrencesTable} LIKE %s", 'parish_id'),
    ARRAY_A
);

if (
    ! is_array($occurrenceParishColumn)
    || strtoupper((string) ($occurrenceParishColumn['Null'] ?? '')) !== 'YES'
) {
    $fail('A fresh install did not create a nullable occurrence parish_id column.');
}

$mailQueueTable = $wpdb->prefix . 'adct_pi_mail_queue';
$freshMailQueueIndexRows = (array) $wpdb->get_results(
    $wpdb->prepare("SHOW INDEX FROM {$mailQueueTable} WHERE Key_name = %s", 'recipient_group'),
    ARRAY_A
);
$freshMailQueueIndexColumns = [];
$freshMailQueueIndexIsUnique = count($freshMailQueueIndexRows) === 2;

foreach ($freshMailQueueIndexRows as $index) {
    $freshMailQueueIndexIsUnique = $freshMailQueueIndexIsUnique
        && (int) ($index['Non_unique'] ?? 1) === 0;
    $freshMailQueueIndexColumns[(int) ($index['Seq_in_index'] ?? 0)] = (string) ($index['Column_name'] ?? '');
}

ksort($freshMailQueueIndexColumns, SORT_NUMERIC);

if (
    ! $freshMailQueueIndexIsUnique
    || array_values($freshMailQueueIndexColumns) !== ['recipient', 'group_key']
) {
    $fail('A fresh install did not create the unique recipient/group_key mail queue index.');
}

$forcedNotNull = $wpdb->query(
    "ALTER TABLE {$occurrencesTable} MODIFY COLUMN parish_id bigint(20) unsigned NOT NULL"
);

if ($forcedNotNull === false) {
    $fail('The v3-to-v4 migration test could not restore the v3 occurrences column definition.');
}

update_option('adct_pi_db_version', 3, false);
do_action('admin_init');
$occurrenceParishColumn = $wpdb->get_row(
    $wpdb->prepare("SHOW COLUMNS FROM {$occurrencesTable} LIKE %s", 'parish_id'),
    ARRAY_A
);

if (
    (int) get_option('adct_pi_db_version', 0) !== 5
    || ! is_array($occurrenceParishColumn)
    || strtoupper((string) ($occurrenceParishColumn['Null'] ?? '')) !== 'YES'
) {
    $fail('The v3 upgrade did not preserve the nullable occurrences.parish_id column.');
}

$dropMailQueueIndex = $wpdb->query(
    "ALTER TABLE {$mailQueueTable} DROP INDEX `recipient_group`"
);

if ($dropMailQueueIndex === false) {
    $fail('The v4-to-v5 migration test could not prepare the pre-v5 mail queue schema.');
}

$queueMigrationPrefix = 'integration:mail-queue:v5:' . bin2hex(random_bytes(8));
$preservedQueueGroupKey = $queueMigrationPrefix . ':preserved';
$duplicateQueueGroupKey = $queueMigrationPrefix . ':duplicate';
$queueMigrationTimestamp = (new SystemClock())->now()
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d H:i:s');
$queueMigrationFields = [
    'subject' => 'Synthetic migration fixture',
    'body_html' => '<p>Fictional migration fixture.</p>',
    'body_text' => 'Fictional migration fixture.',
    'priority' => MailPriority::REMINDER_OR_DIGEST->value,
    'status' => MailQueueStatus::QUEUED->value,
    'attempts' => 0,
    'next_attempt_at' => null,
    'sent_at' => null,
    'error' => null,
    'created_at' => $queueMigrationTimestamp,
    'updated_at' => $queueMigrationTimestamp,
];
$insertQueueMigrationFixture = static function (string $recipient, string $groupKey) use (
    $wpdb,
    $mailQueueTable,
    $queueMigrationFields
): int|false {
    return $wpdb->insert(
        $mailQueueTable,
        array_merge($queueMigrationFields, [
            'recipient' => $recipient,
            'group_key' => $groupKey,
        ])
    );
};
$preservedQueueRowResult = $insertQueueMigrationFixture(
    'migration-preserved@example.test',
    $preservedQueueGroupKey
);
$duplicateQueueRowOneResult = $insertQueueMigrationFixture(
    'migration-duplicate@example.test',
    $duplicateQueueGroupKey
);
$duplicateQueueRowTwoResult = $insertQueueMigrationFixture(
    'migration-duplicate@example.test',
    $duplicateQueueGroupKey
);

if (
    $preservedQueueRowResult !== 1
    || $duplicateQueueRowOneResult !== 1
    || $duplicateQueueRowTwoResult !== 1
) {
    $fail('The synthetic v4 mail queue migration fixtures could not be created.');
}

update_option('adct_pi_db_version', 4, false);
do_action('admin_init');
$queueRowsAfterDuplicateCheck = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$mailQueueTable} WHERE group_key LIKE %s",
    $queueMigrationPrefix . ':%'
));
$indexAfterDuplicateCheck = (array) $wpdb->get_results(
    $wpdb->prepare("SHOW INDEX FROM {$mailQueueTable} WHERE Key_name = %s", 'recipient_group'),
    ARRAY_A
);
$migrationError = get_option('adct_pi_db_migration_error', '');

if (
    (int) get_option('adct_pi_db_version', 0) !== 4
    || $queueRowsAfterDuplicateCheck !== 3
    || $indexAfterDuplicateCheck !== []
    || ! is_string($migrationError)
    || strpos($migrationError, 'schema version 5') === false
) {
    $fail('The v5 migration did not report duplicate legacy keys while preserving all queue rows.');
}

$removedDuplicateQueueRows = $wpdb->delete(
    $mailQueueTable,
    [
        'recipient' => 'migration-duplicate@example.test',
        'group_key' => $duplicateQueueGroupKey,
    ]
);

if ($removedDuplicateQueueRows !== 2) {
    $fail('The duplicate mail queue migration fixtures could not be removed for upgrade retry.');
}

do_action('admin_init');
$upgradedMailQueueIndexRows = (array) $wpdb->get_results(
    $wpdb->prepare("SHOW INDEX FROM {$mailQueueTable} WHERE Key_name = %s", 'recipient_group'),
    ARRAY_A
);
$upgradedMailQueueIndexColumns = [];
$upgradedMailQueueIndexIsUnique = count($upgradedMailQueueIndexRows) === 2;

foreach ($upgradedMailQueueIndexRows as $index) {
    $upgradedMailQueueIndexIsUnique = $upgradedMailQueueIndexIsUnique
        && (int) ($index['Non_unique'] ?? 1) === 0;
    $upgradedMailQueueIndexColumns[(int) ($index['Seq_in_index'] ?? 0)] = (string) ($index['Column_name'] ?? '');
}

ksort($upgradedMailQueueIndexColumns, SORT_NUMERIC);
$preservedQueueRowCount = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$mailQueueTable} WHERE recipient = %s AND group_key = %s",
    'migration-preserved@example.test',
    $preservedQueueGroupKey
));

if (
    (int) get_option('adct_pi_db_version', 0) !== 5
    || ! $upgradedMailQueueIndexIsUnique
    || array_values($upgradedMailQueueIndexColumns) !== ['recipient', 'group_key']
    || $preservedQueueRowCount !== 1
    || get_option('adct_pi_db_migration_error', '') !== ''
) {
    $fail('The v4-to-v5 migration did not add the unique key while preserving the existing queue row.');
}

$deletedPreservedQueueRows = $wpdb->delete(
    $mailQueueTable,
    [
        'recipient' => 'migration-preserved@example.test',
        'group_key' => $preservedQueueGroupKey,
    ]
);

if ($deletedPreservedQueueRows !== 1) {
    $fail('The preserved v4-to-v5 mail queue fixture could not be removed.');
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

$mailboxTable = $wpdb->prefix . 'adct_pi_mailboxes';
$mailboxColumns = (array) $wpdb->get_col("SHOW COLUMNS FROM {$mailboxTable}", 0);

foreach ([
    'source_id',
    'label',
    'host',
    'port',
    'encryption',
    'username',
    'inbox_folder',
    'processed_folder',
    'max_message_size_bytes',
    'active',
] as $column) {
    if (! in_array($column, $mailboxColumns, true)) {
        $fail('The v3 mailbox settings table is missing the ' . $column . ' column.');
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
$settingsSlug = 'adct-parish-intake-settings';
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

$settingsItems = array_values(array_filter(
    $GLOBALS['submenu'][$parentSlug] ?? [],
    static fn ($item): bool => is_array($item) && ($item[2] ?? null) === $settingsSlug
));

if (count($settingsItems) !== 1 || $settingsItems[0][0] !== 'Settings') {
    $fail('The Parish Intake Settings submenu was not registered.');
}

$settingsHook = get_plugin_page_hookname($settingsSlug, $parentSlug);
if (has_action($settingsHook) === false) {
    $fail('The Settings page callback was not registered.');
}

$outboundMailSlug = 'adct-parish-intake-outbound-mail';
$outboundMailItems = array_values(array_filter(
    $GLOBALS['submenu'][$parentSlug] ?? [],
    static fn ($item): bool => is_array($item) && ($item[2] ?? null) === $outboundMailSlug
));

if (
    count($outboundMailItems) !== 1
    || $outboundMailItems[0][0] !== 'Outbound email'
    || $outboundMailItems[0][1] !== Capabilities::MANAGE_SETTINGS
) {
    $fail('The Outbound email settings and suppressed-mail submenu was not registered.');
}

$outboundMailHook = get_plugin_page_hookname($outboundMailSlug, $parentSlug);
if (has_action($outboundMailHook) === false) {
    $fail('The Outbound email page callback was not registered.');
}

$outboundMailPage = new OutboundMailPage(new WordPressMailQueueRepository(
    new WordPressDatabaseConnection()
));
ob_start();
try {
    do_action($outboundMailHook);
} finally {
    $outboundMailHtml = (string) ob_get_clean();
}

if (
    strpos($outboundMailHtml, 'Test mode') === false
    || strpos($outboundMailHtml, 'name="test_mode"') === false
    || strpos($outboundMailHtml, 'name="allowlist"') === false
    || strpos($outboundMailHtml, 'Suppressed mail log') === false
    || strpos($outboundMailHtml, 'does not change') === false
) {
    $fail('The Outbound email page did not render its safe test-mode settings and suppressed-mail log.');
}

$originalOutboundMailPost = $_POST;
$originalOutboundMailRequest = $_REQUEST;
$originalOutboundMailScreen = $GLOBALS['current_screen'] ?? null;
set_current_screen('dashboard');
$_POST = [
    'adct_pi_save_outbound_mail' => '1',
    'outbound_mail_nonce' => wp_create_nonce('adct_pi_save_outbound_mail'),
    'test_mode' => WordPressTestModeSettings::MODE_ENABLED,
    'allowlist' => "approved@example.test\n@qa.example.test",
];
$_REQUEST = $_POST;
do_action('admin_init');

$outboundPolicy = new WordPressTestModeRecipientPolicy();
ob_start();
try {
    do_action($outboundMailHook);
} finally {
    $validSettingsPageHtml = (string) ob_get_clean();
}

if (
    get_option(WordPressTestModeSettings::TEST_MODE_OPTION) !== WordPressTestModeSettings::MODE_ENABLED
    || get_option(WordPressTestModeSettings::ALLOWLIST_OPTION) !== [
        'approved@example.test',
        '@qa.example.test',
    ]
    || ! $outboundPolicy->allows('approved@example.test')
    || ! $outboundPolicy->allows('tester@qa.example.test')
    || $outboundPolicy->allows('parish@example.test')
    || strpos($validSettingsPageHtml, 'Outbound email settings saved.') === false
) {
    $fail('The Outbound email form did not save settings or enforce its exact address/domain allow-list.');
}

$previewSuffix = bin2hex(random_bytes(8));
$previewMarker = 'outbound-preview-' . $previewSuffix;
$previewSubject = '<script>' . $previewMarker . '</script>';
$previewBody = '<img src=x onerror=' . $previewMarker . '>';
$previewRecipient = 'suppressed-preview-' . $previewSuffix . '@example.test';
$previewMail = new OutboundEmail(
    $previewRecipient,
    $previewSubject,
    '<p>' . $previewMarker . '</p>',
    $previewBody,
    MailPriority::REMINDER_OR_DIGEST,
    'integration:test-mode:preview:' . $previewSuffix
);
$previewEnqueue = Plugin::mailer()->enqueue($previewMail);

if ($previewEnqueue->status !== MailQueueStatus::SUPPRESSED) {
    $fail('A non-allow-listed queue recipient was not recorded as suppressed.');
}

ob_start();
try {
    do_action($outboundMailHook);
} finally {
    $suppressedPreviewHtml = (string) ob_get_clean();
}

if (
    strpos($suppressedPreviewHtml, esc_html($previewSubject)) === false
    || strpos($suppressedPreviewHtml, esc_html($previewBody)) === false
    || strpos($suppressedPreviewHtml, $previewSubject) !== false
    || strpos($suppressedPreviewHtml, $previewBody) !== false
) {
    $fail('The suppressed-mail preview did not escape its subject and body before rendering.');
}

$previewRestRoutes = array_filter(
    array_keys(rest_get_server()->get_routes()),
    static fn (string $route): bool => stripos($route, 'mail-queue') !== false
        || stripos($route, 'outbound-mail') !== false
);

if ($previewRestRoutes !== []) {
    $fail('The suppressed mail log was exposed through a public REST route.');
}

wp_set_current_user(0);
$publicEventsResponse = rest_do_request(new WP_REST_Request('GET', '/wp/v2/adct_event'));
$publicEventsJson = $publicEventsResponse instanceof WP_REST_Response
    ? wp_json_encode($publicEventsResponse->get_data())
    : '';

if (
    ! ($publicEventsResponse instanceof WP_REST_Response)
    || $publicEventsResponse->get_status() !== 200
    || ! is_string($publicEventsJson)
    || strpos($publicEventsJson, $previewMarker) !== false
    || strpos($publicEventsJson, $previewRecipient) !== false
) {
    $fail('The public events REST response exposed a suppressed mail preview.');
}

wp_set_current_user($administrators[0]->ID);
$accessTestSuffix = bin2hex(random_bytes(8));
$accessTestUserId = wp_insert_user([
    'user_login' => 'outbound-preview-reader-' . $accessTestSuffix,
    'user_pass' => wp_generate_password(24),
    'user_email' => 'outbound-preview-reader-' . $accessTestSuffix . '@example.test',
    'role' => 'subscriber',
]);

if (is_wp_error($accessTestUserId)) {
    $fail('The synthetic outbound preview access-test user could not be created.');
}

wp_set_current_user((int) $accessTestUserId);
$unauthorizedNotice = '';
ob_start();
try {
    do_action('admin_notices');
} finally {
    $unauthorizedNotice = (string) ob_get_clean();
}

$originalUnauthorizedPost = $_POST;
$originalUnauthorizedRequest = $_REQUEST;
$_POST = [
    'adct_pi_save_outbound_mail' => '1',
    'outbound_mail_nonce' => wp_create_nonce('adct_pi_save_outbound_mail'),
    'test_mode' => WordPressTestModeSettings::MODE_DISABLED,
    'allowlist' => '',
];
$_REQUEST = $_POST;
$outboundMailPage->maybeHandleSettings();
$unauthorizedSettingsChanged = get_option(WordPressTestModeSettings::TEST_MODE_OPTION)
    !== WordPressTestModeSettings::MODE_ENABLED
    || get_option(WordPressTestModeSettings::ALLOWLIST_OPTION) !== [
        'approved@example.test',
        '@qa.example.test',
    ];
$_POST = $originalUnauthorizedPost;
$_REQUEST = $originalUnauthorizedRequest;

$accessDenied = false;
$accessDeniedHandler = static function ($message = '', $title = '', $args = []): void {
    throw new \RuntimeException('Outbound mail page access denied.');
};
$accessDeniedHandlerFilter = static function ($handler) use ($accessDeniedHandler) {
    return $accessDeniedHandler;
};
add_filter('wp_die_handler', $accessDeniedHandlerFilter, PHP_INT_MAX, 1);

try {
    $outboundMailPage->renderPage();
} catch (\RuntimeException $failure) {
    $accessDenied = $failure->getMessage() === 'Outbound mail page access denied.';
} finally {
    remove_filter('wp_die_handler', $accessDeniedHandlerFilter, PHP_INT_MAX);
}

if (
    strpos($unauthorizedNotice, 'TEST MODE IS ON') !== false
    || $unauthorizedSettingsChanged
    || ! $accessDenied
) {
    $fail('Users without the settings capability could access the suppressed-mail preview.');
}

wp_set_current_user($administrators[0]->ID);
wp_delete_user((int) $accessTestUserId);
$deletedPreview = $wpdb->delete(
    $mailQueueTable,
    ['id' => $previewEnqueue->id],
    ['%d']
);

if ($deletedPreview !== 1) {
    $fail('The synthetic suppressed-preview queue fixture could not be removed.');
}

update_option(WordPressTestModeSettings::ALLOWLIST_OPTION, [], false);
if ($outboundPolicy->allows('approved@example.test')) {
    $fail('An empty enabled test-mode allow-list did not fail closed.');
}

ob_start();
try {
    do_action('admin_notices');
} finally {
    $emptyAllowlistNotice = (string) ob_get_clean();
}

if (
    strpos($emptyAllowlistNotice, 'TEST MODE IS ON') === false
    || strpos($emptyAllowlistNotice, 'empty allow-list') === false
) {
    $fail('An empty enabled allow-list did not produce a conspicuous, clear admin error.');
}

update_option(WordPressTestModeSettings::ALLOWLIST_OPTION, ['not-a-valid-address'], false);
if ($outboundPolicy->allows('approved@example.test')) {
    $fail('An invalid test-mode allow-list did not fail closed.');
}

ob_start();
try {
    do_action('admin_notices');
} finally {
    $invalidAllowlistNotice = (string) ob_get_clean();
}

if (strpos($invalidAllowlistNotice, 'allow-list is invalid') === false) {
    $fail('An invalid test-mode allow-list did not produce a clear admin error.');
}

$invalidSettingsPost = [
    'adct_pi_save_outbound_mail' => '1',
    'outbound_mail_nonce' => wp_create_nonce('adct_pi_save_outbound_mail'),
    'test_mode' => WordPressTestModeSettings::MODE_ENABLED,
    'allowlist' => 'not-a-valid-address',
];
$_POST = $invalidSettingsPost;
$_REQUEST = $_POST;
do_action('admin_init');

ob_start();
try {
    do_action($outboundMailHook);
} finally {
    $invalidSettingsPageHtml = (string) ob_get_clean();
}

if (
    strpos($invalidSettingsPageHtml, 'Outbound email settings saved.') !== false
    || strpos($invalidSettingsPageHtml, 'Outbound mail is fail-closed.') === false
    || strpos($invalidSettingsPageHtml, 'allow-list is invalid') === false
) {
    $fail('Invalid outbound email settings showed success or omitted the fail-closed error.');
}

$rejectAllowlistWrite = static function ($newValue, $oldValue) {
    return $oldValue;
};
add_filter('pre_update_option_adct_pi_test_allowlist', $rejectAllowlistWrite, 10, 2);
$_POST = [
    'adct_pi_save_outbound_mail' => '1',
    'outbound_mail_nonce' => wp_create_nonce('adct_pi_save_outbound_mail'),
    'test_mode' => WordPressTestModeSettings::MODE_ENABLED,
    'allowlist' => 'write-failure@example.test',
];
$_REQUEST = $_POST;

try {
    do_action('admin_init');
} finally {
    remove_filter('pre_update_option_adct_pi_test_allowlist', $rejectAllowlistWrite, 10);
}

ob_start();
try {
    do_action($outboundMailHook);
} finally {
    $failedSavePageHtml = (string) ob_get_clean();
}

if (
    strpos($failedSavePageHtml, 'Outbound email settings saved.') !== false
    || strpos($failedSavePageHtml, 'settings could not be saved') === false
) {
    $fail('A failed outbound-mail setting write showed success instead of a persistence error.');
}

$_POST = $originalOutboundMailPost;
$_REQUEST = $originalOutboundMailRequest;

if ($originalOutboundMailScreen !== null) {
    $GLOBALS['current_screen'] = $originalOutboundMailScreen;
} else {
    unset($GLOBALS['current_screen']);
}

$storedTestApiKey = 'sk-test-DO-NOT-ECHO-123';
update_option('adct_parish_intake_openrouter_api_key', $storedTestApiKey);

ob_start();
try {
    do_action($settingsHook);
} finally {
    $settingsHtml = (string) ob_get_clean();
}

if (
    strpos($settingsHtml, $storedTestApiKey) !== false
    || strpos($settingsHtml, 'name="openrouter_api_key" value=""') === false
    || strpos($settingsHtml, 'A key is saved. Leave blank to keep it.') === false
    || strpos($settingsHtml, 'name="remove_openrouter_api_key"') === false
) {
    $fail('The Settings page exposed a stored API key or omitted its safe saved-key controls.');
}

foreach ([
    'mass_times',
    'mass_intentions',
    'sick_list',
    'deceased',
    'anniversaries',
    'raffle_winners',
    'collections_finances',
    'banking_details',
    'readings',
] as $category) {
    if (strpos($settingsHtml, 'name="section_keywords[' . $category . ']"') === false) {
        $fail('The Settings page did not render the section keyword field for ' . $category . '.');
    }
}

if (strpos($settingsHtml, 'Reset section keywords to defaults') === false) {
    $fail('The Settings page did not render the section keyword reset action.');
}

$originalSettingsPost = $_POST;
$originalSettingsRequest = $_REQUEST;
$originalSettingsScreen = $GLOBALS['current_screen'] ?? null;
set_current_screen('dashboard');
$_POST = [
    'adct_parish_intake_settings_nonce' => wp_create_nonce('adct_parish_intake_save_settings'),
    'adct_parish_intake_save_settings' => '1',
    'ai_provider' => 'none',
    'openrouter_model' => 'openrouter/auto',
    'openrouter_api_key' => '',
    'ai_threshold' => '0.55',
    'section_keywords' => [
        'sick_list' => " \n<strong>Care Circle</strong>\nCARE-CIRCLE\n ",
    ],
];
$_REQUEST = $_POST;
do_action('admin_init');
$customSectionKeywords = get_option('adct_parish_intake_section_keywords');

if (
    ! is_array($customSectionKeywords)
    || ($customSectionKeywords['sick_list'] ?? null) !== ['Care Circle']
) {
    $fail('The Settings handler did not sanitize and save a custom section keyword list.');
}

if (get_option('adct_parish_intake_openrouter_api_key') !== $storedTestApiKey) {
    $fail('Saving a blank API key unexpectedly removed the stored key.');
}

$_POST = [
    'adct_parish_intake_settings_nonce' => wp_create_nonce('adct_parish_intake_save_settings'),
    'adct_parish_intake_save_settings' => '1',
    'ai_provider' => 'none',
    'openrouter_model' => 'openrouter/auto',
    'ai_threshold' => '0.55',
    'openrouter_api_key' => '',
    'remove_openrouter_api_key' => '1',
    'section_keywords' => [
        'sick_list' => 'Care Circle',
    ],
];
$_REQUEST = $_POST;
do_action('admin_init');

if (get_option('adct_parish_intake_openrouter_api_key', false) !== false) {
    $fail('The Settings handler did not remove the stored API key when requested.');
}

$constantTestApiKey = 'sk-test-wp-config-DO-NOT-ECHO-456';
define('ADCT_PI_AI_API_KEY', $constantTestApiKey);
update_option('adct_parish_intake_openrouter_api_key', $storedTestApiKey);
$resolvedConstantApiKey = (new \ADCT\ParishIntake\WordPress\Security\WordPressSecretResolver())
    ->resolve(\ADCT\ParishIntake\Core\Security\SecretRegistry::AI_API_KEY);

if ($resolvedConstantApiKey !== $constantTestApiKey) {
    $fail('The wp-config.php API key constant did not take precedence over the stored option.');
}

ob_start();
try {
    do_action($settingsHook);
} finally {
    $constantSettingsHtml = (string) ob_get_clean();
}

if (
    strpos($constantSettingsHtml, 'Set in wp-config.php') === false
    || strpos($constantSettingsHtml, 'name="openrouter_api_key"') !== false
    || strpos($constantSettingsHtml, $constantTestApiKey) !== false
    || strpos($constantSettingsHtml, 'Remove saved key') === false
) {
    $fail('The Settings page did not render the read-only wp-config.php secret state.');
}

$_POST = $originalSettingsPost;
$_REQUEST = $originalSettingsRequest;

if ($originalSettingsScreen !== null) {
    $GLOBALS['current_screen'] = $originalSettingsScreen;
} else {
    unset($GLOBALS['current_screen']);
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

if (
    strpos($manualParserHtml, '<h1>Parish Intake Manual Parser</h1>') === false
    || strpos($manualParserHtml, $storedTestApiKey) !== false
    || strpos($manualParserHtml, $constantTestApiKey) !== false
) {
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
    'body' => "Parish: Fictional Parish\nOCTOBER 2026\nUPCOMING EVENTS\n- Youth gathering on Saturday 10 October 2026 at 16:00.\n- Family picnic on Sunday 11 October 2026 at 12:00.\n\nCARE-CIRCLE\nFictional Person Alpha has a fictional health concern.",
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
    || strpos($submittedParserHtml, 'skipped_sections: sick_list=1') === false
    || strpos($submittedParserHtml, 'Fictional Person Alpha') !== false
    || strpos($submittedParserHtml, $storedTestApiKey) !== false
    || strpos($submittedParserHtml, $constantTestApiKey) !== false
) {
    $fail('The Manual parser did not render candidates and text-free skip metadata for a bulletin.');
}

$_POST = [
    'adct_parish_intake_settings_nonce' => wp_create_nonce('adct_parish_intake_save_settings'),
    'adct_parish_intake_save_settings' => '1',
    'remove_openrouter_api_key' => '1',
];
$_REQUEST = $_POST;
$removalScreen = $GLOBALS['current_screen'] ?? null;
set_current_screen('dashboard');
do_action('admin_init');

if (get_option('adct_parish_intake_openrouter_api_key', false) !== false) {
    $fail('The Settings handler did not remove a stored key while a constant was configured.');
}

if ($removalScreen !== null) {
    $GLOBALS['current_screen'] = $removalScreen;
} else {
    unset($GLOBALS['current_screen']);
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

$previousPost = $_POST;
$previousRequest = $_REQUEST;
$_POST = [
    'adct_parish_intake_parse_nonce' => wp_create_nonce('adct_parish_intake_parse'),
    'adct_parish_intake_parse' => '1',
    'source_type' => 'manual-test',
    'source_identifier' => 'skipped-section-integration-test',
    'sender_email' => 'events@example.test',
    'subject' => 'Fictional Parish bulletin',
    'body' => "MASS INTENTIONS\nFictional Person Alpha\n\n"
        . 'Parish braai on Sunday 11 October 2026 at 12:00 at Fictional Hall.',
];
$_REQUEST = $_POST;
ob_start();
try {
    do_action($pageHook);
} finally {
    $skippedSectionHtml = (string) ob_get_clean();
    $_POST = $previousPost;
    $_REQUEST = $previousRequest;
}

if (
    strpos($skippedSectionHtml, 'A skipped private section may contain an event') === false
    || strpos($skippedSectionHtml, 'possible_missed_event_after_skipped_section: 1') === false
    || strpos($skippedSectionHtml, 'Fictional Person Alpha') !== false
    || strpos($skippedSectionHtml, 'Parish braai') !== false
) {
    $fail('The Manual parser must flag a possible missed event without exposing skipped text.');
}

$originalSettingsScreen = $GLOBALS['current_screen'] ?? null;
set_current_screen('dashboard');
$_POST = [
    'adct_parish_intake_settings_nonce' => wp_create_nonce('adct_parish_intake_save_settings'),
    'adct_parish_intake_save_settings' => '1',
    'reset_section_keywords' => '1',
];
$_REQUEST = $_POST;
do_action('admin_init');
$resetSectionKeywords = get_option('adct_parish_intake_section_keywords');

if ($resetSectionKeywords !== \ADCT\ParishIntake\Core\Parsing\SectionSkipper::defaultKeywordLists()) {
    $fail('The Settings handler did not reset section keywords to their built-in defaults.');
}

$_POST = $originalSettingsPost;
$_REQUEST = $originalSettingsRequest;

if ($originalSettingsScreen !== null) {
    $GLOBALS['current_screen'] = $originalSettingsScreen;
} else {
    unset($GLOBALS['current_screen']);
}

$seedDeaneriesCsv = file_get_contents(__DIR__ . '/seed/deaneries.csv');
$seedParishesCsv = file_get_contents(__DIR__ . '/seed/parishes.csv');

if (! is_string($seedDeaneriesCsv) || ! is_string($seedParishesCsv)) {
    $fail('The directory seed CSV files are not mounted in the integration environment.');
}

$database = new WordPressDatabaseConnection($wpdb);
$directoryVersions = new WordPressDirectoryVersionStore($database);
$parishRepository = new ParishRepository($database, $directoryVersions);
$deaneryRepository = new DeaneryRepository($database);
$contactRepository = new ParishContactRepository($database, $directoryVersions);
$venueRepository = new VenueRepository($database, $directoryVersions);
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

// Remove only test events from an earlier run before replacing their directory rows.
$previousTestEventIds = get_posts([
    'post_type' => EventPostType::POST_TYPE,
    'post_status' => 'any',
    'numberposts' => -1,
    'fields' => 'ids',
]);

foreach ($previousTestEventIds as $previousTestEventId) {
    $previousTestEvent = get_post((int) $previousTestEventId);

    if (
        $previousTestEvent instanceof WP_Post
        && str_starts_with($previousTestEvent->post_title, 'Fictional ')
        && wp_delete_post((int) $previousTestEventId, true) === false
    ) {
        $fail('An earlier fictional event integration fixture could not be removed.');
    }
}

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

$originalEventPost = $_POST;
$originalEventRequest = $_REQUEST;
$_POST = [];
$_REQUEST = [];
$eventPostId = wp_insert_post([
    'post_type' => EventPostType::POST_TYPE,
    'post_status' => 'draft',
    'post_title' => 'Fictional community gathering',
    'post_content' => 'A fictional event created by the integration test.',
], true);

if (is_wp_error($eventPostId) || (int) $eventPostId < 1) {
    $_POST = $originalEventPost;
    $_REQUEST = $originalEventRequest;
    $fail('The event integration post could not be created.');
}

$eventPostId = (int) $eventPostId;
$eventMeta = [
    'parish_id' => (string) $firstParishId,
    'venue_id' => (string) $acceptanceVenue->id,
    'start_local' => '2026-10-10T16:00',
    'end_local' => '2026-10-10T17:00',
    'all_day' => '0',
    'recurrence_preset' => 'monthly_ordinal',
    'weekday' => 'FR',
    'ordinal' => '1',
    'month_day' => '1',
    'rrule_custom' => '',
    'exdates' => "2026-11-06T16:00\n",
    'rdates' => "2026-11-13T16:00\n",
    'featured' => '1',
    'status_flag' => 'scheduled',
    'contact' => [
        'name' => 'Fictional Event Contact',
        'email' => 'event-contact@example.test',
        'phone' => '021 555 0188',
    ],
];
$_POST = [
    'adct_event_meta_nonce' => wp_create_nonce('adct_pi_save_event_meta_' . $eventPostId),
    'adct_event' => $eventMeta,
];
$_REQUEST = $_POST;
$eventSaveResult = wp_update_post([
    'ID' => $eventPostId,
    'post_status' => 'publish',
], true);

if (is_wp_error($eventSaveResult) || (int) $eventSaveResult !== $eventPostId) {
    $_POST = $originalEventPost;
    $_REQUEST = $originalEventRequest;
    $fail('The event integration post could not be published through its save handler.');
}

foreach ([
    'parish_id' => $firstParishId,
    'venue_id' => $acceptanceVenue->id,
    'start_local' => '2026-10-10T16:00',
    'end_local' => '2026-10-10T17:00',
    'rrule' => 'FREQ=MONTHLY;BYDAY=1FR',
    'status_flag' => 'scheduled',
] as $metaKey => $expectedValue) {
    if (get_post_meta($eventPostId, $metaKey, true) != $expectedValue) {
        $_POST = $originalEventPost;
        $_REQUEST = $originalEventRequest;
        $fail('The event save handler did not persist the valid ' . $metaKey . ' value.');
    }
}

if (
    get_post_meta($eventPostId, 'exdates', true) !== ['2026-11-06T16:00']
    || get_post_meta($eventPostId, 'rdates', true) !== ['2026-11-13T16:00']
    || ! in_array(get_post_meta($eventPostId, 'featured', true), [true, 1, '1'], true)
    || (get_post_meta($eventPostId, 'contact', true)['email'] ?? '') !== 'event-contact@example.test'
) {
    $_POST = $originalEventPost;
    $_REQUEST = $originalEventRequest;
    $fail('The event save handler did not persist all validated schedule and private contact metadata.');
}

$hadMetaBoxes = array_key_exists('wp_meta_boxes', $GLOBALS);
$previousMetaBoxes = $GLOBALS['wp_meta_boxes'] ?? null;
$GLOBALS['wp_meta_boxes'] = [
    EventPostType::POST_TYPE => [
        'normal' => [
            'core' => [
                'postcustom' => true,
            ],
        ],
    ],
];
do_action('add_meta_boxes_adct_event', get_post($eventPostId));
$eventMetaBoxes = $GLOBALS['wp_meta_boxes'][EventPostType::POST_TYPE]['normal']['high'] ?? [];
$eventMetaBox = $eventMetaBoxes['adct_event_details'] ?? null;

if (! is_array($eventMetaBox) || ! is_callable($eventMetaBox['callback'] ?? null)) {
    $fail('The event details meta box was not registered.');
}

if (($GLOBALS['wp_meta_boxes'][EventPostType::POST_TYPE]['normal']['core']['postcustom'] ?? false) !== false) {
    $fail('The unrestricted custom-fields meta box was not removed.');
}

ob_start();
try {
    call_user_func($eventMetaBox['callback'], get_post($eventPostId), $eventMetaBox);
} finally {
    $eventMetaBoxHtml = (string) ob_get_clean();
    if ($hadMetaBoxes) {
        $GLOBALS['wp_meta_boxes'] = $previousMetaBoxes;
    } else {
        unset($GLOBALS['wp_meta_boxes']);
    }
}

foreach ([
    'name="adct_event[parish_id]"',
    'name="adct_event[venue_id]"',
    'type="datetime-local"',
    'name="adct_event[recurrence_preset]"',
    'name="adct_event[exdates]"',
    'name="adct_event[rdates]"',
    'name="adct_event[contact][email]"',
] as $field) {
    if (strpos($eventMetaBoxHtml, $field) === false) {
        $fail('The event details meta box did not render the ' . $field . ' control.');
    }
}

$invalidEventMeta = $eventMeta;
$invalidEventMeta['end_local'] = '2026-10-10T15:00';
$_POST = [
    'adct_event_meta_nonce' => wp_create_nonce('adct_pi_save_event_meta_' . $eventPostId),
    'adct_event' => $invalidEventMeta,
];
$_REQUEST = $_POST;
$invalidSaveResult = wp_update_post([
    'ID' => $eventPostId,
    'post_excerpt' => 'The invalid event metadata must not replace the previous values.',
], true);

if (
    is_wp_error($invalidSaveResult)
    || (int) $invalidSaveResult !== $eventPostId
    || get_post_meta($eventPostId, 'end_local', true) !== '2026-10-10T17:00'
) {
    $_POST = $originalEventPost;
    $_REQUEST = $originalEventRequest;
    $fail('The event save handler accepted invalid metadata or replaced the last valid values.');
}

$_POST = [
    'adct_event_meta_nonce' => wp_create_nonce('adct_pi_save_event_meta_' . $eventPostId),
    'adct_event' => $eventMeta,
];
$_REQUEST = $_POST;
wp_update_post([
    'ID' => $eventPostId,
    'post_excerpt' => 'The valid event metadata was restored.',
], true);
$_POST = $originalEventPost;
$_REQUEST = $originalEventRequest;

$eventColumns = apply_filters('manage_adct_event_posts_columns', [
    'cb' => 'Select',
    'title' => 'Title',
    'date' => 'Date',
]);

foreach (['event_start', 'event_parish', 'event_type', 'event_status', 'event_featured'] as $column) {
    if (! array_key_exists($column, $eventColumns)) {
        $fail('The event admin list is missing the ' . $column . ' column.');
    }
}

$currentEventUserId = get_current_user_id();
wp_set_current_user(0);
$eventRestResponse = rest_do_request(new WP_REST_Request(
    'GET',
    '/wp/v2/adct_event/' . $eventPostId
));
wp_set_current_user($currentEventUserId);

if (
    is_wp_error($eventRestResponse)
    || ! ($eventRestResponse instanceof WP_REST_Response)
    || $eventRestResponse->get_status() !== 200
) {
    $fail('A published event could not be read through the public REST API.');
}

$eventRestData = $eventRestResponse->get_data();
$eventRestMeta = is_array($eventRestData) ? ($eventRestData['meta'] ?? null) : null;

if (! is_array($eventRestMeta)) {
    $responseKeys = is_array($eventRestData) ? implode(', ', array_keys($eventRestData)) : gettype($eventRestData);
    $fail('The public REST event response did not include a metadata object (response fields: '
        . $responseKeys . ').');
}

if (array_key_exists('contact', $eventRestMeta)) {
    $fail('The public REST event response exposed private contact metadata.');
}

foreach (['parish_id', 'venue_id', 'start_local', 'end_local', 'all_day', 'rrule', 'exdates', 'rdates', 'featured', 'status_flag'] as $publicMetaKey) {
    if (! array_key_exists($publicMetaKey, $eventRestMeta)) {
        $fail('The public REST event response did not expose ' . $publicMetaKey . '.');
    }
}

$eventRestTestUsers = [];

foreach (['subscriber', 'editor', 'adct_pi_intake_manager'] as $roleName) {
    $username = 'adct-event-' . str_replace('_', '-', $roleName) . '-'
        . strtolower(wp_generate_password(8, false, false));
    $userId = wp_insert_user([
        'user_login' => $username,
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => $username . '@example.test',
        'role' => $roleName,
    ]);

    if (is_wp_error($userId)) {
        $fail('The ' . $roleName . ' event REST test user could not be created.');
    }

    $eventRestTestUsers[$roleName] = (int) $userId;
}

$subscriberId = $eventRestTestUsers['subscriber'];
wp_set_current_user($subscriberId);
$subscriberCanEditMeta = current_user_can('edit_post_meta', $eventPostId, 'featured');
$subscriberRestUpdateRequest = new WP_REST_Request(
    'POST',
    '/wp/v2/adct_event/' . $eventPostId
);
$subscriberRestUpdateRequest->set_param('meta', ['featured' => false]);
$subscriberRestUpdateResponse = rest_do_request($subscriberRestUpdateRequest);

if (
    $subscriberCanEditMeta
    || ! ($subscriberRestUpdateResponse instanceof WP_REST_Response)
    || ! in_array($subscriberRestUpdateResponse->get_status(), [401, 403], true)
    || ! in_array(get_post_meta($eventPostId, 'featured', true), [true, 1, '1'], true)
) {
    $fail('A subscriber could edit event metadata through the REST API.');
}

foreach ([
    'editor' => false,
    'adct_pi_intake_manager' => true,
] as $roleName => $featuredValue) {
    wp_set_current_user($eventRestTestUsers[$roleName]);

    if (
        ! current_user_can('edit_post', $eventPostId)
        || ! current_user_can('edit_post_meta', $eventPostId, 'featured')
    ) {
        $fail('The ' . $roleName . ' role cannot edit event metadata.');
    }

    $eventRoleUpdateRequest = new WP_REST_Request(
        'POST',
        '/wp/v2/adct_event/' . $eventPostId
    );
    $eventRoleUpdateRequest->set_param('meta', ['featured' => $featuredValue]);
    $eventRoleUpdateResponse = rest_do_request($eventRoleUpdateRequest);
    $storedFeatured = get_post_meta($eventPostId, 'featured', true);
    $roleResponseData = $eventRoleUpdateResponse instanceof WP_REST_Response
        ? $eventRoleUpdateResponse->get_data()
        : [];
    $roleResponseMeta = is_array($roleResponseData) ? ($roleResponseData['meta'] ?? null) : null;
    $featuredMatches = is_array($roleResponseMeta)
        && array_key_exists('featured', $roleResponseMeta)
        && $roleResponseMeta['featured'] === $featuredValue;

    if (
        ! ($eventRoleUpdateResponse instanceof WP_REST_Response)
        || $eventRoleUpdateResponse->get_status() !== 200
        || ! $featuredMatches
    ) {
        $responseData = $eventRoleUpdateResponse instanceof WP_REST_Response
            ? $eventRoleUpdateResponse->get_data()
            : (is_wp_error($eventRoleUpdateResponse) ? $eventRoleUpdateResponse->get_error_message() : gettype($eventRoleUpdateResponse));
        $responseStatus = $eventRoleUpdateResponse instanceof WP_REST_Response
            ? (string) $eventRoleUpdateResponse->get_status()
            : 'no response';
        $fail('The ' . $roleName . ' role could not update event metadata through the REST API (status: '
            . $responseStatus . ', response: ' . wp_json_encode($responseData)
            . ', stored featured value: ' . var_export($storedFeatured, true) . ').');
    }
}

wp_set_current_user($currentEventUserId);
foreach ($eventRestTestUsers as $userId) {
    wp_delete_user($userId);
}

$eventRestUpdateRequest = new WP_REST_Request(
    'POST',
    '/wp/v2/adct_event/' . $eventPostId
);
$eventRestUpdateRequest->set_param('meta', [
    'all_day' => true,
    'start_local' => '2026-10-10T18:00',
    'end_local' => '2026-10-10T19:00',
    'exdates' => ['2026-11-06T12:00'],
    'rdates' => ['2026-11-13T11:00'],
]);
$eventRestUpdateResponse = rest_do_request($eventRestUpdateRequest);

if (
    is_wp_error($eventRestUpdateResponse)
    || ! ($eventRestUpdateResponse instanceof WP_REST_Response)
    || $eventRestUpdateResponse->get_status() !== 200
    || get_post_meta($eventPostId, 'start_local', true) !== '2026-10-10T00:00'
    || get_post_meta($eventPostId, 'end_local', true) !== '2026-10-10T00:00'
    || get_post_meta($eventPostId, 'exdates', true) !== ['2026-11-06T00:00']
    || get_post_meta($eventPostId, 'rdates', true) !== ['2026-11-13T00:00']
) {
    $fail('The REST save handler did not normalize a valid all-day event to local midnight.');
}

$invalidEventRestRequest = new WP_REST_Request(
    'POST',
    '/wp/v2/adct_event/' . $eventPostId
);
$invalidEventRestRequest->set_param('meta', [
    'end_local' => '2026-10-09T00:00',
]);
$invalidEventRestResponse = rest_do_request($invalidEventRestRequest);
$invalidEventRestData = $invalidEventRestResponse instanceof WP_REST_Response
    ? $invalidEventRestResponse->get_data()
    : [];

if (
    ! ($invalidEventRestResponse instanceof WP_REST_Response)
    || $invalidEventRestResponse->get_status() !== 400
    || ($invalidEventRestData['code'] ?? '') !== 'adct_event_invalid_meta'
    || get_post_meta($eventPostId, 'end_local', true) !== '2026-10-10T00:00'
) {
    $fail('The REST save handler accepted invalid event metadata or replaced the last valid values.');
}

$occurrenceTimezone = wp_timezone();
$occurrenceStart = (new DateTimeImmutable('today', $occurrenceTimezone))
    ->modify('+7 days')
    ->setTime(16, 0);
$weekdayCodes = [
    1 => 'MO',
    2 => 'TU',
    3 => 'WE',
    4 => 'TH',
    5 => 'FR',
    6 => 'SA',
    7 => 'SU',
];
$occurrenceRule = 'FREQ=WEEKLY;INTERVAL=2;COUNT=3;BYDAY='
    . $weekdayCodes[(int) $occurrenceStart->format('N')];
$occurrenceVenue = $venueAdministration->save($firstParishId, 0, [
    'name' => 'Occurrence integration hall',
    'address' => 'Fictional test address',
    'suburb' => 'Test suburb',
    'latitude' => '-33.9249',
    'longitude' => '18.4241',
    'is_default' => false,
]);
$occurrenceType = get_term_by('slug', 'social', EventPostType::TAXONOMY);

if (! $occurrenceType instanceof WP_Term) {
    $fail('The occurrence integration event type could not be found.');
}

$occurrenceEventId = wp_insert_post([
    'post_type' => EventPostType::POST_TYPE,
    'post_status' => 'draft',
    'post_title' => 'Fictional recurring occurrence event',
], true);

if (is_wp_error($occurrenceEventId) || (int) $occurrenceEventId < 1) {
    $fail('The occurrence integration event could not be created.');
}

$occurrenceEventId = (int) $occurrenceEventId;
$assignedOccurrenceType = wp_set_object_terms(
    $occurrenceEventId,
    (int) $occurrenceType->term_id,
    EventPostType::TAXONOMY,
    false
);

if (is_wp_error($assignedOccurrenceType)) {
    $fail('The occurrence integration event type could not be assigned.');
}

$occurrenceForm = [
    'parish_id' => (string) $firstParishId,
    'venue_id' => (string) $occurrenceVenue->id,
    'start_local' => $occurrenceStart->format('Y-m-d\TH:i'),
    'end_local' => $occurrenceStart->modify('+1 hour')->format('Y-m-d\TH:i'),
    'all_day' => '0',
    'recurrence_preset' => 'custom',
    'weekday' => $weekdayCodes[(int) $occurrenceStart->format('N')],
    'ordinal' => '1',
    'month_day' => '1',
    'rrule_custom' => $occurrenceRule,
    'exdates' => '',
    'rdates' => '',
    'featured' => '0',
    'status_flag' => 'scheduled',
    'contact' => [
        'name' => 'Fictional Occurrence Contact',
        'email' => 'occurrence-contact@example.test',
        'phone' => '',
    ],
];
$saveOccurrenceFromEditor = static function (
    int $postId,
    array $form,
    array $postChanges = []
): mixed {
    $savedPost = $_POST;
    $savedRequest = $_REQUEST;
    $_POST = [
        EventEditor::NONCE_FIELD => wp_create_nonce(EventEditor::NONCE_ACTION_PREFIX . $postId),
        EventEditor::FORM_KEY => $form,
    ];
    $_REQUEST = $_POST;

    try {
        return wp_update_post(array_merge(['ID' => $postId], $postChanges), true);
    } finally {
        $_POST = $savedPost;
        $_REQUEST = $savedRequest;
    }
};
$fetchOccurrenceRows = static function (int $postId) use ($wpdb, $occurrencesTable): array {
    return (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT event_id, start_utc, start_local_date, parish_id, event_type_term_id, "
            . "latitude, longitude, is_cancelled, created_at, updated_at FROM {$occurrencesTable} "
            . 'WHERE event_id = %d ORDER BY start_utc ASC',
            $postId
        ),
        ARRAY_A
    );
};
$manualOccurrenceSave = $saveOccurrenceFromEditor(
    $occurrenceEventId,
    $occurrenceForm,
    ['post_status' => 'publish']
);

if (is_wp_error($manualOccurrenceSave) || (int) $manualOccurrenceSave !== $occurrenceEventId) {
    $fail('Publishing through the manual event editor did not complete.');
}

$expectedOccurrenceDates = static function (DateTimeImmutable $start): array {
    return [
        $start->format('Y-m-d'),
        $start->modify('+2 weeks')->format('Y-m-d'),
        $start->modify('+4 weeks')->format('Y-m-d'),
    ];
};
$manualOccurrenceRows = $fetchOccurrenceRows($occurrenceEventId);

if (
    count($manualOccurrenceRows) !== 3
    || array_column($manualOccurrenceRows, 'start_local_date') !== $expectedOccurrenceDates($occurrenceStart)
    || (int) $manualOccurrenceRows[0]['parish_id'] !== $firstParishId
    || (int) $manualOccurrenceRows[0]['event_type_term_id'] !== (int) $occurrenceType->term_id
    || abs((float) $manualOccurrenceRows[0]['latitude'] + 33.9249) > 0.000001
    || abs((float) $manualOccurrenceRows[0]['longitude'] - 18.4241) > 0.000001
) {
    $fail('Publishing a recurring event did not create the expected filtered occurrence rows.');
}

$repeatedManualOccurrenceSave = $saveOccurrenceFromEditor(
    $occurrenceEventId,
    $occurrenceForm,
    ['post_excerpt' => 'Repeated manual occurrence save.']
);
$repeatedManualOccurrenceRows = $fetchOccurrenceRows($occurrenceEventId);

if (
    is_wp_error($repeatedManualOccurrenceSave)
    || (int) $repeatedManualOccurrenceSave !== $occurrenceEventId
    || count($repeatedManualOccurrenceRows) !== 3
    || array_column($repeatedManualOccurrenceRows, 'start_local_date') !== $expectedOccurrenceDates($occurrenceStart)
) {
    $fail('Repeating an unchanged event save did not preserve exactly one row per occurrence.');
}

$editedOccurrenceStart = $occurrenceStart->modify('+1 week');
$editedOccurrenceForm = $occurrenceForm;
$editedOccurrenceForm['start_local'] = $editedOccurrenceStart->format('Y-m-d\TH:i');
$editedOccurrenceForm['end_local'] = $editedOccurrenceStart->modify('+1 hour')->format('Y-m-d\TH:i');
$manualOccurrenceEdit = $saveOccurrenceFromEditor(
    $occurrenceEventId,
    $editedOccurrenceForm,
    ['post_excerpt' => 'Manual occurrence dates refreshed.']
);
$editedOccurrenceRows = $fetchOccurrenceRows($occurrenceEventId);

if (
    is_wp_error($manualOccurrenceEdit)
    || (int) $manualOccurrenceEdit !== $occurrenceEventId
    || count($editedOccurrenceRows) !== 3
    || array_column($editedOccurrenceRows, 'start_local_date') !== $expectedOccurrenceDates($editedOccurrenceStart)
) {
    $fail('Editing a published event did not replace its occurrence rows.');
}

$restOccurrenceStart = $occurrenceStart->modify('+2 weeks');
$occurrenceRestRequest = new WP_REST_Request(
    'POST',
    '/wp/v2/adct_event/' . $occurrenceEventId
);
$occurrenceRestRequest->set_param('meta', [
    'start_local' => $restOccurrenceStart->format('Y-m-d\TH:i'),
    'end_local' => $restOccurrenceStart->modify('+1 hour')->format('Y-m-d\TH:i'),
    'all_day' => false,
    'rrule' => $occurrenceRule,
]);
$occurrenceRestResponse = rest_do_request($occurrenceRestRequest);
$restOccurrenceRows = $fetchOccurrenceRows($occurrenceEventId);

if (
    ! ($occurrenceRestResponse instanceof WP_REST_Response)
    || $occurrenceRestResponse->get_status() !== 200
    || count($restOccurrenceRows) !== 3
    || array_column($restOccurrenceRows, 'start_local_date') !== $expectedOccurrenceDates($restOccurrenceStart)
) {
    $fail('A REST event write did not refresh its occurrence rows.');
}

$forcedVenueFailureEventId = 0;
$forceMissingOccurrenceVenue = static function (
    mixed $value,
    int $objectId,
    string $metaKey,
    bool $single
) use (&$forcedVenueFailureEventId): mixed {
    if ($objectId === $forcedVenueFailureEventId && $metaKey === 'venue_id') {
        return $single ? (string) PHP_INT_MAX : [(string) PHP_INT_MAX];
    }

    return $value;
};
$installMissingOccurrenceVenue = static function (
    WP_Post $post,
    WP_REST_Request $_request,
    bool $_creating
) use (&$forcedVenueFailureEventId, $forceMissingOccurrenceVenue): void {
    if ($post->post_status !== 'publish') {
        return;
    }

    $forcedVenueFailureEventId = (int) $post->ID;
    add_filter('get_post_metadata', $forceMissingOccurrenceVenue, 10, 4);
};
$removeMissingOccurrenceVenue = static function (
    WP_Post $post,
    WP_REST_Request $_request,
    bool $_creating
) use (&$forcedVenueFailureEventId, $forceMissingOccurrenceVenue): void {
    if ((int) $post->ID === $forcedVenueFailureEventId) {
        remove_filter('get_post_metadata', $forceMissingOccurrenceVenue, 10);
    }
};

$preexistingOccurrenceRows = $fetchOccurrenceRows($occurrenceEventId);

if (count($preexistingOccurrenceRows) !== 3) {
    $fail('The REST rebuild-failure test did not start with existing occurrence rows.');
}

$forcedVenueFailureEventId = $occurrenceEventId;
$failedUpdateStart = $restOccurrenceStart->modify('+3 weeks');
$failedUpdateRequest = new WP_REST_Request(
    'PATCH',
    '/wp/v2/adct_event/' . $occurrenceEventId
);
$failedUpdateRequest->set_param('meta', [
    'start_local' => $failedUpdateStart->format('Y-m-d\TH:i'),
    'end_local' => $failedUpdateStart->modify('+1 hour')->format('Y-m-d\TH:i'),
    'all_day' => false,
    'rrule' => $occurrenceRule,
]);
add_action('rest_after_insert_adct_event', $installMissingOccurrenceVenue, 5, 3);
add_action('rest_after_insert_adct_event', $removeMissingOccurrenceVenue, 15, 3);

try {
    $failedUpdateResponse = rest_do_request($failedUpdateRequest);
    // rest_do_request() bypasses the REST server's response-pipeline filter.
    $failedUpdateResponse = apply_filters(
        'rest_post_dispatch',
        rest_ensure_response($failedUpdateResponse),
        rest_get_server(),
        $failedUpdateRequest
    );
} finally {
    remove_action('rest_after_insert_adct_event', $installMissingOccurrenceVenue, 5);
    remove_action('rest_after_insert_adct_event', $removeMissingOccurrenceVenue, 15);
    remove_filter('get_post_metadata', $forceMissingOccurrenceVenue, 10);
}

$failedUpdateResponsePayload = $failedUpdateResponse instanceof WP_REST_Response
    ? $failedUpdateResponse->get_data()
    : null;
$failedUpdateResponseData = is_array($failedUpdateResponsePayload)
    && is_array($failedUpdateResponsePayload['data'] ?? null)
    ? $failedUpdateResponsePayload['data']
    : [];
$failedUpdateEventId = absint($failedUpdateResponseData['event_id'] ?? 0);
$occurrenceRowsAfterFailedUpdate = $fetchOccurrenceRows($occurrenceEventId);

if (
    ! ($failedUpdateResponse instanceof WP_REST_Response)
    || $failedUpdateResponse->get_status() !== 500
    || ($failedUpdateResponsePayload['code'] ?? null) !== 'adct_event_occurrence_rebuild_failed'
    || $failedUpdateEventId !== $occurrenceEventId
    || $forcedVenueFailureEventId !== $occurrenceEventId
    || $occurrenceRowsAfterFailedUpdate !== $preexistingOccurrenceRows
) {
    $fail(sprintf(
        'A failed REST update did not report its event ID and preserve existing occurrence rows '
        . '(status: %d, code: %s, request event ID: %d, error event ID: %d, rows before: %s, '
        . 'rows after: %s, response: %s).',
        $failedUpdateResponse instanceof WP_REST_Response ? $failedUpdateResponse->get_status() : 0,
        is_array($failedUpdateResponsePayload)
            && is_string($failedUpdateResponsePayload['code'] ?? null)
            ? $failedUpdateResponsePayload['code']
            : 'missing',
        $forcedVenueFailureEventId,
        $failedUpdateEventId,
        wp_json_encode($preexistingOccurrenceRows),
        wp_json_encode($occurrenceRowsAfterFailedUpdate),
        wp_json_encode($failedUpdateResponsePayload)
    ));
}

$forcedVenueFailureEventId = 0;

$failedCreateStart = $occurrenceStart->modify('+6 weeks');
$failedCreateRequest = new WP_REST_Request('POST', '/wp/v2/adct_event');
$failedCreateRequest->set_param('title', ['raw' => 'Fictional occurrence rebuild failure event']);
$failedCreateRequest->set_param('status', 'publish');
$failedCreateRequest->set_param('meta', [
    'parish_id' => $firstParishId,
    'venue_id' => $occurrenceVenue->id,
    'start_local' => $failedCreateStart->format('Y-m-d\TH:i'),
    'end_local' => $failedCreateStart->modify('+1 hour')->format('Y-m-d\TH:i'),
    'all_day' => false,
    'rrule' => $occurrenceRule,
    'exdates' => [],
    'rdates' => [],
    'featured' => false,
    'status_flag' => 'scheduled',
]);
add_action('rest_after_insert_adct_event', $installMissingOccurrenceVenue, 5, 3);
add_action('rest_after_insert_adct_event', $removeMissingOccurrenceVenue, 15, 3);

try {
    $failedCreateResponse = rest_do_request($failedCreateRequest);
    // rest_do_request() bypasses the REST server's response-pipeline filter.
    $failedCreateResponse = apply_filters(
        'rest_post_dispatch',
        rest_ensure_response($failedCreateResponse),
        rest_get_server(),
        $failedCreateRequest
    );
} finally {
    remove_action('rest_after_insert_adct_event', $installMissingOccurrenceVenue, 5);
    remove_action('rest_after_insert_adct_event', $removeMissingOccurrenceVenue, 15);
    remove_filter('get_post_metadata', $forceMissingOccurrenceVenue, 10);
}

$failedCreateCode = '';
$failedCreateMessage = '';
$failedCreateDetails = [];
$failedCreateStatus = 0;
$failedCreateResponsePayload = null;

if ($failedCreateResponse instanceof WP_REST_Response) {
    $failedCreateResponsePayload = $failedCreateResponse->get_data();
    $failedCreateStatus = $failedCreateResponse->get_status();

    if (is_array($failedCreateResponsePayload)) {
        $failedCreateCode = is_string($failedCreateResponsePayload['code'] ?? null)
            ? $failedCreateResponsePayload['code']
            : '';
        $failedCreateMessage = is_string($failedCreateResponsePayload['message'] ?? null)
            ? $failedCreateResponsePayload['message']
            : '';
        $failedCreateDetails = is_array($failedCreateResponsePayload['data'] ?? null)
            ? $failedCreateResponsePayload['data']
            : [];
    }
} elseif (is_wp_error($failedCreateResponse)) {
    $failedCreateCode = $failedCreateResponse->get_error_code();
    $failedCreateMessage = $failedCreateResponse->get_error_message();
    $failedCreateDetails = $failedCreateResponse->get_error_data($failedCreateCode);
    $failedCreateDetails = is_array($failedCreateDetails) ? $failedCreateDetails : [];
    $failedCreateStatus = (int) ($failedCreateDetails['status'] ?? 0);
}

$savedFailureEventId = absint($failedCreateDetails['event_id'] ?? 0);
$savedFailureEvent = get_post($savedFailureEventId);

if (
    $failedCreateStatus !== 500
    || $failedCreateCode !== 'adct_event_occurrence_rebuild_failed'
    || $savedFailureEventId < 1
    || $forcedVenueFailureEventId !== $savedFailureEventId
    || ! str_contains(
        $failedCreateMessage,
        'Update the saved event at ID ' . $savedFailureEventId . ' instead of retrying the create request'
    )
    || ! $savedFailureEvent instanceof WP_Post
    || $savedFailureEvent->post_status !== 'publish'
    || $fetchOccurrenceRows($savedFailureEventId) !== []
) {
    $fail(sprintf(
        'A failed REST create did not report its saved event ID and instruct clients to update it '
        . '(status: %d, code: %s, request event ID: %d, error event ID: %d, post status: %s, '
        . 'message: %s, details: %s, route: %s, method: %s, response: %s).',
        $failedCreateStatus,
        $failedCreateCode,
        $forcedVenueFailureEventId,
        $savedFailureEventId,
        $savedFailureEvent instanceof WP_Post ? $savedFailureEvent->post_status : 'missing',
        $failedCreateMessage,
        wp_json_encode($failedCreateDetails),
        $failedCreateRequest->get_route(),
        $failedCreateRequest->get_method(),
        wp_json_encode($failedCreateResponsePayload)
    ));
}

if (wp_delete_post($savedFailureEventId, true) === false) {
    $fail('The failed REST-create occurrence fixture could not be removed.');
}

foreach ([
    'cancelled' => 1,
    'postponed' => 0,
] as $eventStatus => $expectedCancelled) {
    $statusRequest = new WP_REST_Request(
        'POST',
        '/wp/v2/adct_event/' . $occurrenceEventId
    );
    $statusRequest->set_param('meta', ['status_flag' => $eventStatus]);
    $statusResponse = rest_do_request($statusRequest);
    $statusRows = $fetchOccurrenceRows($occurrenceEventId);

    if (
        ! ($statusResponse instanceof WP_REST_Response)
        || $statusResponse->get_status() !== 200
        || count($statusRows) !== 3
        || array_filter(
            $statusRows,
            static fn (array $row): bool => (int) $row['is_cancelled'] !== $expectedCancelled
        ) !== []
    ) {
        $fail('Occurrence cancellation flags did not follow the event’s ' . $eventStatus . ' status.');
    }
}

$demotedOccurrenceEvent = wp_update_post([
    'ID' => $occurrenceEventId,
    'post_status' => 'draft',
], true);

if (
    is_wp_error($demotedOccurrenceEvent)
    || (int) $demotedOccurrenceEvent !== $occurrenceEventId
    || $fetchOccurrenceRows($occurrenceEventId) !== []
) {
    $fail('Changing a published event to draft did not remove its occurrence rows.');
}

$republishedOccurrenceEvent = $saveOccurrenceFromEditor(
    $occurrenceEventId,
    $occurrenceForm,
    ['post_status' => 'publish']
);

if (
    is_wp_error($republishedOccurrenceEvent)
    || (int) $republishedOccurrenceEvent !== $occurrenceEventId
    || count($fetchOccurrenceRows($occurrenceEventId)) !== 3
) {
    $fail('Republishing a draft event did not rebuild its occurrence rows.');
}

$privatizedOccurrenceEvent = wp_update_post([
    'ID' => $occurrenceEventId,
    'post_status' => 'private',
], true);

if (
    is_wp_error($privatizedOccurrenceEvent)
    || (int) $privatizedOccurrenceEvent !== $occurrenceEventId
    || $fetchOccurrenceRows($occurrenceEventId) !== []
) {
    $fail('Changing a published event to private did not remove its occurrence rows.');
}

$republishedPrivateOccurrenceEvent = $saveOccurrenceFromEditor(
    $occurrenceEventId,
    $occurrenceForm,
    ['post_status' => 'publish']
);

if (
    is_wp_error($republishedPrivateOccurrenceEvent)
    || (int) $republishedPrivateOccurrenceEvent !== $occurrenceEventId
    || count($fetchOccurrenceRows($occurrenceEventId)) !== 3
) {
    $fail('Republishing a private event did not rebuild its occurrence rows.');
}

$nonPublicEventIds = [];

foreach (['draft', 'pending', 'private'] as $postStatus) {
    $nonPublicEventId = wp_insert_post([
        'post_type' => EventPostType::POST_TYPE,
        'post_status' => $postStatus,
        'post_title' => 'Fictional ' . $postStatus . ' occurrence event',
    ], true);

    if (is_wp_error($nonPublicEventId) || (int) $nonPublicEventId < 1) {
        $fail('The ' . $postStatus . ' occurrence event could not be created.');
    }

    $nonPublicEventId = (int) $nonPublicEventId;
    $nonPublicEventIds[] = $nonPublicEventId;

    foreach ([
        'parish_id' => $firstParishId,
        'venue_id' => $occurrenceVenue->id,
        'start_local' => $occurrenceStart->format('Y-m-d\TH:i'),
        'end_local' => $occurrenceStart->modify('+1 hour')->format('Y-m-d\TH:i'),
        'all_day' => false,
        'rrule' => $occurrenceRule,
        'exdates' => [],
        'rdates' => [],
        'status_flag' => 'scheduled',
    ] as $metaKey => $metaValue) {
        update_post_meta($nonPublicEventId, $metaKey, $metaValue);
    }

    wp_update_post([
        'ID' => $nonPublicEventId,
        'post_excerpt' => 'The unpublished event remains hidden from occurrence queries.',
    ], true);

    if ($fetchOccurrenceRows($nonPublicEventId) !== []) {
        $fail('The ' . $postStatus . ' event created public occurrence rows.');
    }
}

$previousOccurrenceUserId = get_current_user_id();
wp_set_current_user(0);

try {
    foreach ($nonPublicEventIds as $nonPublicEventId) {
        $nonPublicItemResponse = rest_do_request(new WP_REST_Request(
            'GET',
            '/wp/v2/adct_event/' . $nonPublicEventId
        ));

        if (
            $nonPublicItemResponse instanceof WP_REST_Response
            && $nonPublicItemResponse->get_status() === 200
        ) {
            $fail('The public REST API exposed a non-public event by ID.');
        }
    }

    $nonPublicCollectionRequest = new WP_REST_Request('GET', '/wp/v2/adct_event');
    $nonPublicCollectionRequest->set_param('include', $nonPublicEventIds);
    $nonPublicCollectionRequest->set_param('per_page', count($nonPublicEventIds));
    $nonPublicCollectionResponse = rest_do_request($nonPublicCollectionRequest);

    if (
        ! ($nonPublicCollectionResponse instanceof WP_REST_Response)
        || $nonPublicCollectionResponse->get_status() !== 200
    ) {
        $fail('The public REST event collection could not be queried anonymously.');
    }

    $nonPublicCollectionData = $nonPublicCollectionResponse->get_data();

    if (! is_array($nonPublicCollectionData)) {
        $fail('The public REST event collection returned an invalid response.');
    }

    foreach ($nonPublicCollectionData as $eventData) {
        if (
            is_array($eventData)
            && in_array(absint($eventData['id'] ?? 0), $nonPublicEventIds, true)
        ) {
            $fail('The public REST event collection exposed a non-public event.');
        }
    }
} finally {
    wp_set_current_user($previousOccurrenceUserId);
}

$archdioceseEventId = wp_insert_post([
    'post_type' => EventPostType::POST_TYPE,
    'post_status' => 'draft',
    'post_title' => 'Fictional archdiocese-wide event',
], true);

if (is_wp_error($archdioceseEventId) || (int) $archdioceseEventId < 1) {
    $fail('The archdiocese-wide occurrence event could not be created.');
}

$archdioceseEventId = (int) $archdioceseEventId;
wp_set_object_terms(
    $archdioceseEventId,
    (int) $occurrenceType->term_id,
    EventPostType::TAXONOMY,
    false
);
$archdioceseStart = (new DateTimeImmutable('today', $occurrenceTimezone))
    ->modify('+3 days')
    ->setTime(10, 0);
$archdioceseForm = [
    'parish_id' => '',
    'venue_id' => '',
    'start_local' => $archdioceseStart->format('Y-m-d\TH:i'),
    'end_local' => $archdioceseStart->modify('+1 hour')->format('Y-m-d\TH:i'),
    'all_day' => '0',
    'recurrence_preset' => 'none',
    'weekday' => 'MO',
    'ordinal' => '1',
    'month_day' => '1',
    'rrule_custom' => '',
    'exdates' => '',
    'rdates' => '',
    'featured' => '0',
    'status_flag' => 'scheduled',
    'contact' => [
        'name' => '',
        'email' => '',
        'phone' => '',
    ],
];
$archdioceseSave = $saveOccurrenceFromEditor(
    $archdioceseEventId,
    $archdioceseForm,
    ['post_status' => 'publish']
);
$archdioceseRows = $fetchOccurrenceRows($archdioceseEventId);

if (
    is_wp_error($archdioceseSave)
    || (int) $archdioceseSave !== $archdioceseEventId
    || count($archdioceseRows) !== 1
    || $archdioceseRows[0]['parish_id'] !== null
    || $archdioceseRows[0]['latitude'] !== null
    || $archdioceseRows[0]['longitude'] !== null
) {
    $fail('An archdiocese-wide event did not retain one occurrence without parish coordinates.');
}

do_action('init');

if (wp_next_scheduled('adct_pi_job_expand_occurrences') === false) {
    $fail('The daily occurrence maintenance job was not scheduled.');
}

delete_option('adct_pi_job_state_expand_occurrences');

$staleOccurrenceDate = '2000-01-01';
$staleInserted = $wpdb->insert(
    $occurrencesTable,
    [
        'event_id' => $occurrenceEventId,
        'start_utc' => '2000-01-01 00:00:00',
        'end_utc' => '2000-01-01 01:00:00',
        'start_local_date' => $staleOccurrenceDate,
        'parish_id' => $firstParishId,
        'event_type_term_id' => (int) $occurrenceType->term_id,
        'latitude' => -33.9249,
        'longitude' => 18.4241,
        'is_cancelled' => 0,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ],
    ['%d', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%d', '%s', '%s']
);

if ($staleInserted !== 1) {
    $fail('The occurrence integration test could not seed a stale row for the daily job.');
}

do_action('adct_pi_job_expand_occurrences');
$dailyJobRows = $fetchOccurrenceRows($occurrenceEventId);
$staleRowCount = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$occurrencesTable} WHERE event_id = %d AND start_local_date = %s",
    $occurrenceEventId,
    $staleOccurrenceDate
));
$windowStartDate = (new DateTimeImmutable('today', $occurrenceTimezone))->format('Y-m-d');
$windowEndDate = (new DateTimeImmutable('today', $occurrenceTimezone))->modify('+1 year')->format('Y-m-d');
$occurrenceJobState = get_option('adct_pi_job_state_expand_occurrences', []);
$occurrenceJobError = is_array($occurrenceJobState)
    && is_string($occurrenceJobState['last_error_message'] ?? null)
    ? $occurrenceJobState['last_error_message']
    : 'none';

if (
    $staleRowCount !== 0
    || count($dailyJobRows) !== 3
    || array_filter(
        $dailyJobRows,
        static fn (array $row): bool => $row['start_local_date'] < $windowStartDate
            || $row['start_local_date'] > $windowEndDate
    ) !== []
) {
    $fail(sprintf(
        'The daily occurrence job did not replace stale rows with the current rolling window '
        . '(stale rows: %d, event rows: %d, dates: %s, job error: %s).',
        $staleRowCount,
        count($dailyJobRows),
        wp_json_encode(array_column($dailyJobRows, 'start_local_date')),
        $occurrenceJobError
    ));
}

foreach ($nonPublicEventIds as $nonPublicEventId) {
    if ($fetchOccurrenceRows($nonPublicEventId) !== []) {
        $fail('The daily occurrence job indexed a non-public event.');
    }
}

if (count($fetchOccurrenceRows($archdioceseEventId)) !== 1) {
    $fail('The daily occurrence job did not preserve the archdiocese-wide event occurrence.');
}

$deleteOccurrenceRequest = new WP_REST_Request(
    'DELETE',
    '/wp/v2/adct_event/' . $archdioceseEventId
);
$deleteOccurrenceResponse = rest_do_request($deleteOccurrenceRequest);

if (
    ! ($deleteOccurrenceResponse instanceof WP_REST_Response)
    || $deleteOccurrenceResponse->get_status() !== 200
    || $fetchOccurrenceRows($archdioceseEventId) !== []
) {
    $fail('Deleting an event through REST did not remove its occurrence rows.');
}

$manualParserVerifiedEmail = 'manual-parser@example.test';
$contactService->linkOfficial($firstParishId, $manualParserVerifiedEmail);
$manualParserDefaultVenue = $venueLookup->defaultVenueFor($firstParishId);

if ($manualParserDefaultVenue === null) {
    $fail('The verified-sender parser integration fixture has no active default venue.');
}

$previousPost = $_POST;
$previousRequest = $_REQUEST;
$_POST = [
    'adct_parish_intake_parse_nonce' => wp_create_nonce('adct_parish_intake_parse'),
    'adct_parish_intake_parse' => '1',
    'source_type' => 'manual-test',
    'source_identifier' => 'verified-sender-lookup-test',
    'sender_email' => $manualParserVerifiedEmail,
    'sender_name' => 'Fictional Parser Desk',
    'subject' => 'Verified sender gathering',
    'body' => 'A community gathering is on Sunday 11 October 2026 at 16:00.',
];
$_REQUEST = $_POST;
ob_start();
try {
    do_action($pageHook);
} finally {
    $verifiedParserHtml = (string) ob_get_clean();
    $_POST = $previousPost;
    $_REQUEST = $previousRequest;
}

$verifiedParserOutput = html_entity_decode($verifiedParserHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');

if (
    strpos($verifiedParserOutput, '"parish_id": ' . $firstParishId) === false
    || strpos($verifiedParserOutput, '"venue_id": ' . $manualParserDefaultVenue->venueId) === false
    || strpos($verifiedParserOutput, '"source": "sender"') === false
) {
    $fail('The Manual parser did not resolve a parish and default venue from the verified test sender.');
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

$mailboxesPageSlug = 'adct-parish-intake-mailboxes';
$mailboxesPageItems = array_values(array_filter(
    $GLOBALS['submenu'][$parentSlug] ?? [],
    static fn ($item): bool => is_array($item) && ($item[2] ?? null) === $mailboxesPageSlug
));

if (count($mailboxesPageItems) !== 1 || $mailboxesPageItems[0][0] !== 'Mailboxes') {
    $fail('The Mailboxes admin submenu was not registered.');
}

$mailboxesPageHook = get_plugin_page_hookname($mailboxesPageSlug, $parentSlug);

if (has_action($mailboxesPageHook) === false) {
    $fail('The Mailboxes page callback was not registered.');
}

$mailboxEmail = 'intake-mailbox-integration@example.test';
$mailboxSource = $sourceRepository->findGlobalEmailSource($mailboxEmail);
$mailboxSource = $sourceRegistry->save(new Source(
    $mailboxSource?->id ?? 0,
    null,
    SourceType::EMAIL,
    $mailboxEmail,
    SourceRole::OFFICIAL,
    $mailboxSource?->status ?? SourceStatus::ACTIVE,
    $mailboxSource?->pollIntervalMinutes,
    $mailboxSource?->lastCheckedAt,
    $mailboxSource?->lastSuccessAt,
    $mailboxSource?->lastItemAt,
    $mailboxSource?->consecutiveFailures ?? 0,
    $mailboxSource?->lastError
));
$mailboxDatabase = new WordPressDatabaseConnection();
$mailboxRepository = new MailboxRepository($mailboxDatabase);
$existingMailbox = $mailboxRepository->findMailboxBySourceId($mailboxSource->id);
$mailboxSettings = (new MailboxSettingsValidator())->validate([
    'label' => 'Integration mailbox',
    'host' => 'imap.example.test',
    'port' => '993',
    'encryption' => 'ssl',
    'username' => $mailboxEmail,
    'inbox_folder' => 'INBOX',
    'processed_folder' => 'Processed-Integration',
    'max_message_size_mb' => '15',
    'active' => '1',
], $existingMailbox?->id ?? 0, $mailboxSource->id)->withIdentity(
    $existingMailbox?->id ?? 0,
    $mailboxSource->id
);
$savedMailbox = $mailboxRepository->saveMailbox($mailboxSettings, '2026-09-25 00:00:00');
$persistedMailbox = $mailboxRepository->findMailboxById($savedMailbox->id);
$persistedMailboxSource = $sourceRepository->findSource($savedMailbox->sourceId);

if (
    $persistedMailbox === null
    || $persistedMailbox->host !== 'imap.example.test'
    || $persistedMailbox->maxMessageSizeBytes !== 15 * 1024 * 1024
    || ! $persistedMailbox->active
    || $persistedMailboxSource === null
    || $persistedMailboxSource->parishId !== null
    || $persistedMailboxSource->type !== SourceType::EMAIL
    || $persistedMailboxSource->identifier !== $mailboxEmail
    || $persistedMailboxSource->role !== SourceRole::OFFICIAL
) {
    $fail('Mailbox settings did not persist with their archdiocese-wide email source.');
}

$activeMailboxIds = array_map(
    static fn ($settings): int => $settings->id,
    $mailboxRepository->findActiveMailboxes()
);

if (! in_array($savedMailbox->id, $activeMailboxIds, true)) {
    $fail('The active archdiocese-wide mailbox was not available to the polling job.');
}

if (has_action('adct_pi_job_poll_mailboxes') === false) {
    $fail('The mailbox polling job was not registered with the scheduled-job framework.');
}

if (has_action('adct_pi_job_send_mail') === false) {
    $fail('The outbound mail sender was not registered with the scheduled-job framework.');
}

$inboundMessageRepository = new InboundMessageRepository($mailboxDatabase);
$attachmentRepository = new AttachmentRepository($mailboxDatabase);
$inboundMessageStore = new WordPressInboundMessageStore(
    $mailboxDatabase,
    $inboundMessageRepository,
    $attachmentRepository
);
$integrationTimestamp = '2026-09-25 04:00:00';
$oversizedMessage = new InboundMessageRecord(
    $mailboxSource->id,
    'imap:54321:1',
    null,
    'notices@example.test',
    'Example Notices',
    'Invented oversized integration message',
    new DateTimeImmutable('2026-09-25T04:00:00+00:00'),
    null,
    [],
    InboundMessageRecord::STATUS_SKIPPED,
    'Message size 16000001 bytes exceeds the configured limit of 15728640 bytes.'
);
$inboundMessageStore->store($oversizedMessage, $integrationTimestamp);
$oversizedDuplicate = $inboundMessageStore->store($oversizedMessage, $integrationTimestamp);

if (! $oversizedDuplicate->duplicate) {
    $fail('A retried skipped message was not de-duplicated by its external ID.');
}

$attachmentHash = hash('sha256', 'invented unsupported attachment bytes');
$messageContentHash = hash('sha256', 'invented normalized email body and attachment hashes');
$messageWithSkippedAttachment = new InboundMessageRecord(
    $mailboxSource->id,
    '<attachment-message@example.test>',
    $messageContentHash,
    'notices@example.test',
    'Example Notices',
    'Invented attachment integration message',
    new DateTimeImmutable('2026-09-25T04:01:00+00:00'),
    'integration-only-placeholder.eml',
    [
        new InboundAttachmentRecord(
            'example-notes.txt',
            'text/plain',
            strlen('invented unsupported attachment bytes'),
            '',
            $attachmentHash,
            AttachmentStoragePolicy::STATUS_SKIPPED_TYPE
        ),
    ]
);
$inboundMessageStore->store($messageWithSkippedAttachment, $integrationTimestamp);
$contentHashDuplicate = new InboundMessageRecord(
    $mailboxSource->id,
    '<attachment-resend@example.test>',
    $messageContentHash,
    'notices@example.test',
    'Example Notices',
    'Invented attachment integration resend',
    new DateTimeImmutable('2026-09-25T04:02:00+00:00'),
    'integration-only-resend-placeholder.eml'
);
$storedContentHashDuplicate = $inboundMessageStore->store($contentHashDuplicate, $integrationTimestamp);

if (! $storedContentHashDuplicate->duplicate) {
    $fail('A resent message with a new Message-ID was not de-duplicated by content hash.');
}

$screeningAuthenticationResults = new AuthenticationResults([
    new AuthenticationResult('spf', 'pass', 'spoofed.example.test', false),
    new AuthenticationResult('dkim', 'pass', 'spoofed.example.test', false),
    new AuthenticationResult('dmarc', 'fail', 'spoofed.example.test', false),
]);
$screeningMessage = new InboundMessageRecord(
    $mailboxSource->id,
    '<screening-message@example.test>',
    hash('sha256', 'synthetic screening message body'),
    'no-reply@example.test',
    'Synthetic Sender Name Must Not Render',
    'Synthetic subject must not render',
    new DateTimeImmutable('2026-09-25T04:03:00+00:00'),
    'integration-only-screened-message.eml',
    [],
    InboundMessageRecord::STATUS_RECEIVED,
    null,
    true,
    $screeningAuthenticationResults
);
$screeningStoreResult = $inboundMessageStore->store($screeningMessage, $integrationTimestamp);
$screeningRows = $inboundMessageRepository->findRecentScreeningMessagesBySourceId($mailboxSource->id, 5);

if (
    $screeningStoreResult->duplicate
    || $screeningRows === []
    || (int) ($screeningRows[0]['is_auto_reply'] ?? 0) !== 1
    || ! is_string($screeningRows[0]['auth_results'] ?? null)
    || ! AuthenticationResults::fromJson($screeningRows[0]['auth_results'])->hasReportedDmarcFailure()
) {
    $fail('The inbound store did not persist automation and structured authentication flags.');
}

$secondaryMailboxEmail = 'intake-mailbox-secondary@example.test';
$secondaryMailboxSource = $sourceRepository->findGlobalEmailSource($secondaryMailboxEmail);
$secondaryMailboxSource = $sourceRegistry->save(new Source(
    $secondaryMailboxSource?->id ?? 0,
    null,
    SourceType::EMAIL,
    $secondaryMailboxEmail,
    SourceRole::OFFICIAL,
    $secondaryMailboxSource?->status ?? SourceStatus::ACTIVE,
    $secondaryMailboxSource?->pollIntervalMinutes,
    $secondaryMailboxSource?->lastCheckedAt,
    $secondaryMailboxSource?->lastSuccessAt,
    $secondaryMailboxSource?->lastItemAt,
    $secondaryMailboxSource?->consecutiveFailures ?? 0,
    $secondaryMailboxSource?->lastError
));
$existingSecondaryMailbox = $mailboxRepository->findMailboxBySourceId($secondaryMailboxSource->id);
$secondaryMailboxSettings = (new MailboxSettingsValidator())->validate([
    'label' => 'Secondary integration mailbox',
    'host' => 'imap-secondary.example.test',
    'port' => '143',
    'encryption' => 'starttls',
    'username' => $secondaryMailboxEmail,
    'inbox_folder' => 'INBOX',
    'processed_folder' => 'Processed-Secondary',
    'max_message_size_mb' => '30',
    'active' => '0',
], $existingSecondaryMailbox?->id ?? 0, $secondaryMailboxSource->id)->withIdentity(
    $existingSecondaryMailbox?->id ?? 0,
    $secondaryMailboxSource->id
);
$savedSecondaryMailbox = $mailboxRepository->saveMailbox($secondaryMailboxSettings, '2026-09-25 00:00:00');
$persistedSecondaryMailbox = $mailboxRepository->findMailboxById($savedSecondaryMailbox->id);
$allMailboxes = $mailboxRepository->findAllMailboxes();

if (
    $persistedSecondaryMailbox === null
    || $persistedSecondaryMailbox->sourceId === $savedMailbox->sourceId
    || $persistedSecondaryMailbox->encryption !== MailboxEncryption::STARTTLS
    || $persistedSecondaryMailbox->active
    || count($allMailboxes) < 2
) {
    $fail('The Mailboxes store did not persist multiple independent mailbox configurations.');
}

$mailboxPassword = 'imap-test-DO-NOT-ECHO-456';
$mailboxPasswordOption = SecretRegistry::optionName(
    SecretRegistry::IMAP_PASSWORD,
    $savedMailbox->secretScope()
);
update_option($mailboxPasswordOption, $mailboxPassword, false);
$originalGet = $_GET;
$_GET = ['page' => $mailboxesPageSlug];
ob_start();
try {
    do_action($mailboxesPageHook);
} finally {
    $mailboxesListHtml = (string) ob_get_clean();
    $_GET = $originalGet;
}

$originalGet = $_GET;
$_GET = [
    'page' => $mailboxesPageSlug,
    'action' => 'edit',
    'id' => (string) $savedMailbox->id,
];
ob_start();
try {
    do_action($mailboxesPageHook);
} finally {
    $mailboxesEditHtml = (string) ob_get_clean();
    $_GET = $originalGet;
}

$passwordInput = '';

if (preg_match('/<input\b(?=[^>]*\bname="password")[^>]*>/i', $mailboxesEditHtml, $passwordInputMatches) === 1) {
    $passwordInput = $passwordInputMatches[0];
}

if (
    strpos($mailboxesListHtml, 'Test connection') === false
    || strpos($mailboxesListHtml, 'Secondary integration mailbox') === false
    || strpos($mailboxesListHtml, 'Source status') === false
    || strpos($mailboxesListHtml, 'Last success') === false
    || strpos($mailboxesListHtml, 'Skipped oversized messages') === false
    || strpos($mailboxesListHtml, 'Message size 16000001 bytes') === false
    || strpos($mailboxesListHtml, 'Skipped attachments') === false
    || strpos($mailboxesListHtml, 'unsupported MIME type') === false
    || strpos($mailboxesListHtml, 'Recent message screening') === false
    || strpos($mailboxesListHtml, 'No confirmation: automated or list mail detected.') === false
    || strpos($mailboxesListHtml, 'SPF: PASS (unverified claim)') === false
    || strpos($mailboxesListHtml, 'DMARC: FAIL (unverified claim)') === false
    || strpos($mailboxesListHtml, 'Review flag: reported DMARC fail') === false
    || strpos($mailboxesListHtml, 'Synthetic subject must not render') !== false
    || strpos($mailboxesListHtml, 'Synthetic Sender Name Must Not Render') !== false
    || strpos($mailboxesListHtml, 'spoofed.example.test') !== false
    || strpos($mailboxesListHtml, $mailboxPassword) !== false
    || strpos($mailboxesEditHtml, $mailboxPassword) !== false
    || $passwordInput === ''
    || preg_match('/\bvalue\s*=/i', $passwordInput) === 1
    || strpos($mailboxesEditHtml, 'A password is saved. Leave blank to keep it.') === false
    || strpos($mailboxesEditHtml, 'name="remove_password"') === false
    || strpos($mailboxesEditHtml, 'name="verify_tls_certificate"') !== false
    || strpos($mailboxesEditHtml, 'value="none"') !== false
) {
    $fail('The Mailboxes page did not persist and render settings without exposing a saved password.');
}

delete_option($mailboxPasswordOption);

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

update_option(
    WordPressTestModeSettings::TEST_MODE_OPTION,
    WordPressTestModeSettings::MODE_DISABLED,
    false
);
update_option(WordPressTestModeSettings::ALLOWLIST_OPTION, [], false);

$mailQueueTestSuffix = bin2hex(random_bytes(8));
$mailQueueRecipient = 'queue-login-' . $mailQueueTestSuffix . '@example.test';
$mailQueueGroupKey = 'integration:mail-queue:login:' . $mailQueueTestSuffix;
$digestRecipientPrefix = 'queue-digest-' . $mailQueueTestSuffix;
$mailQueueEmail = new OutboundEmail(
    $mailQueueRecipient,
    'A fictional queue integration notice',
    '<p>Queue integration fixture.</p>',
    'Queue integration fixture.',
    MailPriority::LOGIN_OR_CONFIRMATION,
    $mailQueueGroupKey
);
$mailQueueAttempts = [];
$mailQueueIntercept = static function ($pre, $arguments) use (&$mailQueueAttempts) {
    $recipients = is_array($arguments['to'] ?? null)
        ? $arguments['to']
        : [$arguments['to'] ?? null];

    foreach ($recipients as $recipient) {
        if (! is_string($recipient) || ! str_ends_with(strtolower(trim($recipient)), '@example.test')) {
            return false;
        }
    }

    $mailQueueAttempts[] = $arguments;

    return true;
};
add_filter('pre_wp_mail', $mailQueueIntercept, 10, 2);

try {
    for ($index = 1; $index <= 200; ++$index) {
        $digestResult = Plugin::mailer()->enqueue(new OutboundEmail(
            sprintf('%s-%03d@example.test', $digestRecipientPrefix, $index),
            'A fictional digest fixture',
            '<p>Priority integration digest.</p>',
            'Priority integration digest.',
            MailPriority::REMINDER_OR_DIGEST
        ));

        if ($digestResult->status !== MailQueueStatus::QUEUED) {
            $fail('A synthetic digest did not remain queued ahead of the login priority test.');
        }
    }

    $mailQueueResult = Plugin::mailer()->enqueue($mailQueueEmail);
    $mailQueueDuplicate = Plugin::mailer()->enqueue($mailQueueEmail);
} finally {
    remove_filter('pre_wp_mail', $mailQueueIntercept, 10);
}

if (
    $mailQueueResult->status !== MailQueueStatus::QUEUED
    || $mailQueueDuplicate->status !== MailQueueStatus::SENT
    || ! $mailQueueDuplicate->duplicate
    || count($mailQueueAttempts) !== 1
) {
    $fail('The mail queue did not send the login notice first from behind 200 digests and de-duplicate it.');
}

$mailQueueAttempt = $mailQueueAttempts[0];
if (
    ($mailQueueAttempt['to'] ?? null) !== $mailQueueRecipient
    || ($mailQueueAttempt['subject'] ?? null) !== $mailQueueEmail->subject
    || ($mailQueueAttempt['message'] ?? null) !== $mailQueueEmail->htmlBody
    || ($mailQueueAttempt['attachments'] ?? null) !== []
) {
    $fail('The WordPress mail adapter did not call wp_mail with one fake recipient and no attachments.');
}

$pendingDigestCount = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$mailQueueTable} WHERE recipient LIKE %s AND status = %s",
    $digestRecipientPrefix . '-%@example.test',
    MailQueueStatus::QUEUED->value
));

if ($pendingDigestCount !== 200) {
    $fail('The priority-one login was not sent before all 200 queued digest messages.');
}

$deletedDigestCount = $wpdb->query($wpdb->prepare(
    "DELETE FROM {$mailQueueTable} WHERE recipient LIKE %s AND status = %s",
    $digestRecipientPrefix . '-%@example.test',
    MailQueueStatus::QUEUED->value
));

if ($deletedDigestCount !== 200) {
    $fail('The 200 synthetic digest fixtures could not be removed after the priority test.');
}

$changedMailQueueEmail = new OutboundEmail(
    $mailQueueEmail->recipient,
    $mailQueueEmail->subject,
    '<p>Different composed content.</p>',
    'Different composed content.',
    $mailQueueEmail->priority,
    $mailQueueEmail->groupKey
);
$changedPayloadRejected = false;

try {
    Plugin::mailer()->enqueue($changedMailQueueEmail);
} catch (DomainException) {
    $changedPayloadRejected = true;
}

if (! $changedPayloadRejected) {
    $fail('The database-backed mail queue accepted changed content under an existing group key.');
}

$mailQueueRow = $wpdb->get_row($wpdb->prepare(
    "SELECT status, attempts, sent_at FROM {$mailQueueTable} WHERE recipient = %s AND group_key = %s LIMIT 1",
    $mailQueueRecipient,
    $mailQueueGroupKey
), ARRAY_A);

if (
    ! is_array($mailQueueRow)
    || ($mailQueueRow['status'] ?? '') !== MailQueueStatus::SENT->value
    || (int) ($mailQueueRow['attempts'] ?? 0) !== 1
    || ! is_string($mailQueueRow['sent_at'] ?? null)
) {
    $fail('The immediate mail sender did not persist its successful attempt in the mail queue.');
}

$mailQueueStats = Plugin::mailQueueStats();

if ($mailQueueStats->sentInLastHour < 1) {
    $fail('The mail queue status API did not count the safely intercepted test delivery.');
}

$mailQueueRepository = new WordPressMailQueueRepository(new WordPressDatabaseConnection());
$queueNow = (new SystemClock())->now();
$claimOne = $mailQueueRepository->enqueue(
    new OutboundEmail(
        'claim-one@example.test',
        'Fictional claim one',
        '<p>Claim fixture.</p>',
        'Claim fixture.',
        MailPriority::APPROVER_OR_CHANGE
    ),
    MailQueueStatus::QUEUED,
    $queueNow
);
$claimTwo = $mailQueueRepository->enqueue(
    new OutboundEmail(
        'claim-two@example.test',
        'Fictional claim two',
        '<p>Claim fixture.</p>',
        'Claim fixture.',
        MailPriority::APPROVER_OR_CHANGE
    ),
    MailQueueStatus::QUEUED,
    $queueNow
);
$oneRemainingSlot = $mailQueueStats->sentInLastHour + 1;
$firstDatabaseClaim = $mailQueueRepository->claim($claimOne->id, $queueNow, $oneRemainingSlot, 3600);
$secondDatabaseClaim = $mailQueueRepository->claim($claimTwo->id, $queueNow, $oneRemainingSlot, 3600);

if (
    $firstDatabaseClaim->status !== MailQueueClaimStatus::CLAIMED
    || $secondDatabaseClaim->status !== MailQueueClaimStatus::CAP_REACHED
) {
    $fail('Database-backed mail claims did not reserve the final hourly slot atomically.');
}

$deletedClaimFixtures = $wpdb->query($wpdb->prepare(
    "DELETE FROM {$mailQueueTable} WHERE id IN (%d, %d)",
    $claimOne->id,
    $claimTwo->id
));

if ($deletedClaimFixtures !== 2) {
    $fail('The temporary fake-recipient claim fixtures could not be removed.');
}

$toggleSuffix = bin2hex(random_bytes(8));
$toggleRecipient = 'queued-before-test-mode-' . $toggleSuffix . '@example.test';
$toggleMail = new OutboundEmail(
    $toggleRecipient,
    'A fictional queued test-mode notice',
    '<p>Queued before test mode was enabled.</p>',
    'Queued before test mode was enabled.',
    MailPriority::REMINDER_OR_DIGEST,
    'integration:test-mode:toggle:' . $toggleSuffix
);
$toggleEnqueue = Plugin::mailer()->enqueue($toggleMail);

if ($toggleEnqueue->status !== MailQueueStatus::QUEUED) {
    $fail('A production-mode test recipient was not queued before the test-mode toggle.');
}

update_option(
    WordPressTestModeSettings::TEST_MODE_OPTION,
    WordPressTestModeSettings::MODE_ENABLED,
    false
);
update_option(
    WordPressTestModeSettings::ALLOWLIST_OPTION,
    ['approved@example.test'],
    false
);
$attemptedDuringSuppression = [];
$testModeDispatchGuard = static function ($pre, $arguments) use (&$attemptedDuringSuppression) {
    $attemptedDuringSuppression[] = $arguments;
    $recipients = is_array($arguments['to'] ?? null)
        ? $arguments['to']
        : [$arguments['to'] ?? null];

    foreach ($recipients as $recipient) {
        if (! is_string($recipient) || ! str_ends_with(strtolower(trim($recipient)), '@example.test')) {
            return false;
        }
    }

    return true;
};
$toggleDispatcher = new MailQueueDispatcher(
    new WordPressMailQueueRepository(new WordPressDatabaseConnection()),
    new WordPressMailDeliveryAdapter(),
    new WordPressTestModeRecipientPolicy(),
    new SystemClock(),
    new MailQueueConfiguration()
);
$sentBeforeTestModeDispatch = Plugin::mailQueueStats()->sentInLastHour;
add_filter('pre_wp_mail', $testModeDispatchGuard, 10, 2);

try {
    $toggleDispatchResult = $toggleDispatcher->dispatchOne();
} finally {
    remove_filter('pre_wp_mail', $testModeDispatchGuard, 10);
}

$toggleRow = $wpdb->get_row($wpdb->prepare(
    "SELECT status, attempts, sent_at, error FROM {$mailQueueTable} WHERE id = %d LIMIT 1",
    $toggleEnqueue->id
), ARRAY_A);

if (
    $toggleDispatchResult->status !== MailQueueDispatchStatus::SUPPRESSED
    || $attemptedDuringSuppression !== []
    || ! is_array($toggleRow)
    || ($toggleRow['status'] ?? '') !== MailQueueStatus::SUPPRESSED->value
    || (int) ($toggleRow['attempts'] ?? -1) !== 0
    || ($toggleRow['sent_at'] ?? null) !== null
    || ($toggleRow['error'] ?? '') !== 'recipient_not_allowlisted'
    || Plugin::mailQueueStats()->sentInLastHour !== $sentBeforeTestModeDispatch
) {
    $fail('A queued non-allow-listed recipient was not suppressed at dispatch after test mode was enabled.');
}

update_option(
    WordPressTestModeSettings::TEST_MODE_OPTION,
    WordPressTestModeSettings::MODE_DISABLED,
    false
);
update_option(WordPressTestModeSettings::ALLOWLIST_OPTION, [], false);
$deletedToggleFixture = $wpdb->delete(
    $mailQueueTable,
    ['id' => $toggleEnqueue->id],
    ['%d']
);

if ($deletedToggleFixture !== 1) {
    $fail('The queued test-mode toggle fixture could not be removed.');
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

foreach (['administrator', 'editor'] as $roleName) {
    $role = get_role($roleName);

    if ($role === null) {
        $fail('The ' . $roleName . ' role is unavailable for the uninstall capability check.');
    }

    $role->add_cap('edit_events');
    $role->add_cap('publish_events');
}

if (! defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', $pluginBasename);
}
require dirname($pluginFile) . '/uninstall.php';

foreach (['administrator', 'editor'] as $roleName) {
    $role = get_role($roleName);

    if ($role === null) {
        $fail('Uninstall removed the built-in ' . $roleName . ' role.');
    }

    foreach (Capabilities::all() as $capability) {
        if ($role->has_cap($capability)) {
            $fail('Uninstall did not remove the ADCT capability ' . $capability . ' from ' . $roleName . '.');
        }
    }

    foreach (['edit_events', 'publish_events'] as $unrelatedCapability) {
        if (! $role->has_cap($unrelatedCapability)) {
            $fail('Uninstall removed the unrelated ' . $unrelatedCapability . ' capability from ' . $roleName . '.');
        }
    }
}

foreach (array_keys(Capabilities::customRoleLabels()) as $roleName) {
    if (get_role($roleName) !== null) {
        $fail('Uninstall did not remove the Parish Intake role ' . $roleName . '.');
    }
}

WP_CLI::success('Release ZIP activation, schema v5/v3-to-v5 and v4-to-v5 migrations, fresh and upgraded mail queue unique indexes with duplicate preservation, occurrence expansion/save/REST/job behavior, mailbox settings and safe password rendering, polling and outbound-mail job registration, inbound-message de-duplication/skip notices, login-priority delivery ahead of 200 queued digests through intercepted wp_mail, mail group idempotency and atomic hourly-cap claims, event post type/taxonomy/default-term seeding, event metadata validation, REST privacy/role authorization and namespaced capability cleanup, settings and parser safeguards, venue schema/import/backfill/default/lookup/deactivation and parish Venues tab, source registry/import/health checks and official-source switching with the parish Sources tab, deanery routes and role assignments, directory CSV imports, parish contacts, Deaneries and Senders admin screens, and Manual parser integration checks passed.');
