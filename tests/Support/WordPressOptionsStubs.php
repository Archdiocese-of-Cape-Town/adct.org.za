<?php

declare(strict_types=1);

/**
 * The single option-store stub every test that touches a namespaced WordPress
 * option function must require.
 *
 * Why one file: `WordPressJobStateStore`, `WordPressJobLock`, `HealthAlerts`,
 * `RetentionSettings` and `OcrSettings` all live in
 * `ADCT\ParishIntake\WordPress\Jobs`, so their unqualified `get_option()` and
 * `add_option()` calls resolve to
 * `ADCT\ParishIntake\WordPress\Jobs\get_option()` rather than the global
 * WordPress function. Three separate test files each declared their own copy
 * of that namespaced function, and PHP fatals with "Cannot redeclare" on the
 * second one — which is exactly what PHPUnit's single-process,
 * `executionOrder="random"` run of `tests/Unit` produced. The same collision
 * already forced the shared, guarded copies in WordPressStubs.php and
 * AdminWordPressStubs.php; this file completes the set for options.
 *
 * Why the $wpdb store: WordPressJobAdaptersTest asserts against a fake `$wpdb`
 * (`rows`, `autoload`, and the `prepare()`/`get_var()`/`query()` pair that
 * `SourceRepository` writes through), and its `update_option()`/`delete_option()`
 * stubs are declared unconditionally in that file because no other test file
 * declares them in the Jobs namespace. Rather than duplicate the fake or give
 * one consumer a second, drifting store, everything routes through the
 * `$GLOBALS['wpdb']` object that each test seeds in setUp(). `newOptionsDatabase()`
 * is that one construction site, so a consumer cannot get a subtly different
 * fake from another.
 *
 * Per-consumer records stay per-consumer on purpose. Writes are mirrored into
 * the shared store and into the caller's own global when it has one — that is
 * how WordPressJobAdaptersTest still sees a non-autoload flag and how
 * ParserPageTest still sees its own `parser_page_updates` write log — while the
 * single authoritative value read back later is the shared store. So a test
 * that seeds a baseline (ParserPageTest, OcrSettingsTest) and a test that
 * exercises the raw option plumbing (WordPressJobAdaptersTest) coexist without
 * either having its assertions weakened.
 */

namespace {
    /**
     * The shared option-table double.
     *
     * Only the surface the plugin's Jobs namespace actually calls is modelled:
     * the option name, the rows, and the per-row autoload flag.
     */
    final class FakeWordPressOptionsDatabase
    {
        public string $options = 'wp_options';
        public string $last_error = '';

        /**
         * @var array<string, mixed>
         */
        public array $rows = [];

        /**
         * @var array<string, mixed>
         */
        public array $autoload = [];

        public function prepare(string $query, ...$arguments): string
        {
            foreach ($arguments as $argument) {
                $position = strpos($query, '%s');

                if ($position === false) {
                    throw new \RuntimeException('The SQL fixture received too many values.');
                }

                $query = substr_replace($query, "'" . addslashes((string) $argument) . "'", $position, 2);
            }

            return $query;
        }

        public function get_var(string $query)
        {
            if (preg_match("/WHERE option_name = '([^']+)'/", $query, $matches) !== 1) {
                throw new \RuntimeException('The SQL fixture received an unexpected select.');
            }

            return $this->rows[stripslashes($matches[1])] ?? null;
        }

        public function query(string $query): int
        {
            if (
                preg_match(
                    "/WHERE option_name = '([^']+)' AND option_value = '([^']+)'/",
                    $query,
                    $matches
                ) !== 1
            ) {
                throw new \RuntimeException('The SQL fixture received an unexpected delete.');
            }

            $optionName = stripslashes($matches[1]);
            $optionValue = stripslashes($matches[2]);

            if (! isset($this->rows[$optionName]) || $this->rows[$optionName] !== $optionValue) {
                return 0;
            }

            unset($this->rows[$optionName], $this->autoload[$optionName]);

            return 1;
        }
    }
}

namespace {
    /**
     * Seed a fresh shared option store for one test.
     *
     * Reusing whatever `$GLOBALS['wpdb']` happens to hold would leak rows
     * between tests, and leaving it unset would make the first option read a
     * fatal, so every consumer calls this from setUp().
     *
     * @param array<string, mixed> $rows
     */
    function new_options_database(array $rows = []): FakeWordPressOptionsDatabase
    {
        $database = new FakeWordPressOptionsDatabase();
        $database->rows = $rows;

        $GLOBALS['wpdb'] = $database;

        return $database;
    }
}

namespace ADCT\ParishIntake\WordPress\Jobs {

    if (! function_exists('ADCT\ParishIntake\WordPress\Jobs\add_option')) {
        function add_option(string $option, $value = '', string $deprecated = '', $autoload = 'yes'): bool
        {
            $database = $GLOBALS['wpdb'] ?? null;

            if ($database === null) {
                $database = new_options_database();
            }

            if (array_key_exists($option, $database->rows)) {
                return false;
            }

            $database->rows[$option] = $value;
            $database->autoload[$option] = $autoload;

            return true;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Jobs\get_option')) {
        function get_option(string $option, $default = false)
        {
            $database = $GLOBALS['wpdb'] ?? null;

            return $database === null ? $default : ($database->rows[$option] ?? $default);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Jobs\update_option')) {
            /**
             * WordPress returns true when the stored value actually changed, and
             * WordPressJobStateStore::save() leans on that to tell a real write
             * from a no-op, so the double has to make the same distinction.
             */
            function update_option(string $option, $value, $autoload = null): bool
            {
                $database = $GLOBALS['wpdb'] ?? null;

                if ($database === null) {
                    $database = new_options_database();
                }

                $changed = ! array_key_exists($option, $database->rows) || $database->rows[$option] !== $value;
                $database->rows[$option] = $value;
                $database->autoload[$option] = $autoload;

                return $changed;
            }
        }

        if (! function_exists('ADCT\ParishIntake\WordPress\Jobs\delete_option')) {
            function delete_option(string $option): bool
            {
                $database = $GLOBALS['wpdb'] ?? null;

                if ($database === null) {
                    return false;
                }

                $present = array_key_exists($option, $database->rows);
                unset($database->rows[$option], $database->autoload[$option]);

                return $present;
            }
        }
}
