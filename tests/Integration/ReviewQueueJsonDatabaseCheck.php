<?php

use ADCT\ParishIntake\Core\Database\SchemaDefinitions;

// Invoke with wp eval-file in the installed-ZIP test environment, never a real site.
$host = getenv('ADCT_PI_QUEUE_TEST_DB_HOST');
$expectedVersion = getenv('ADCT_PI_QUEUE_TEST_DB_VERSION');
if (! is_string($host)
    || preg_match('/\A(?:host\.docker\.internal:\d+|queue-mysql|queue-mariadb)\z/D', $host) !== 1
    || ! in_array($expectedVersion, ['8.0', '10.11'], true)) {
    throw new RuntimeException('Specify an isolated queue test database host and version (8.0 or 10.11).');
}

$originalDatabase = $GLOBALS['wpdb'];
$testDatabase = new wpdb('root', 'synthetic-queue-test', 'queue_test', $host);
$testDatabase->set_prefix('queue_test_');
$GLOBALS['wpdb'] = $testDatabase;
try {
    $version = (string) $testDatabase->get_var('SELECT VERSION()');
    if (! str_starts_with($version, $expectedVersion . '.')) {
        throw new RuntimeException('Unexpected queue test database version: ' . $version);
    }
    if ($testDatabase->get_results('SHOW TABLES') !== [] || $testDatabase->last_error !== '') {
        throw new RuntimeException('The queue test database must be empty; refusing to reuse or reset it.');
    }
    foreach (SchemaDefinitions::statements() as $statement) {
        $sql = strtr($statement, [
            '{table_prefix}' => $testDatabase->prefix,
            '{charset_collate}' => $testDatabase->get_charset_collate(),
        ]);
        if ($testDatabase->query($sql) === false) {
            throw new RuntimeException('Could not create fresh queue test schema: ' . $testDatabase->last_error);
        }
    }
    require_once __DIR__ . '/ReviewQueueJsonCheck.php';
    ReviewQueueJsonCheck::run(static function (string $message): void {
        throw new RuntimeException($message);
    });
    WP_CLI::success('Review queue JSON checks passed on ' . $version);
} finally {
    $GLOBALS['wpdb'] = $originalDatabase;
}
