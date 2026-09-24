<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Jobs {
    function add_option(string $option, $value = '', string $deprecated = '', $autoload = 'yes'): bool
    {
        global $wpdb;

        if (array_key_exists($option, $wpdb->rows)) {
            return false;
        }

        $wpdb->rows[$option] = $value;
        $wpdb->autoload[$option] = $autoload;

        return true;
    }

    function get_option(string $option, $default = false)
    {
        global $wpdb;

        return $wpdb->rows[$option] ?? $default;
    }

    function update_option(string $option, $value, $autoload = null): bool
    {
        global $wpdb;

        $changed = ! array_key_exists($option, $wpdb->rows) || $wpdb->rows[$option] !== $value;
        $wpdb->rows[$option] = $value;
        $wpdb->autoload[$option] = $autoload;

        return $changed;
    }

    function wp_cache_delete(string $key, string $group = ''): bool
    {
        return true;
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Jobs {
    use ADCT\ParishIntake\Core\Jobs\JobState;
    use ADCT\ParishIntake\WordPress\Jobs\WordPressJobLock;
    use ADCT\ParishIntake\WordPress\Jobs\WordPressJobStateStore;
    use DateTimeImmutable;
    use PHPUnit\Framework\TestCase;

    final class WordPressJobAdaptersTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['wpdb'] = new FakeWordPressOptionsDatabase();
        }

        public function testStateIsStoredInANonAutoloadedOption(): void
        {
            $store = new WordPressJobStateStore();
            $state = new JobState(
                'next-item',
                new DateTimeImmutable('2026-09-25T01:00:00+02:00'),
                new DateTimeImmutable('2026-09-25T01:01:00+02:00'),
                'previous error',
                new DateTimeImmutable('2026-09-25T00:59:00+02:00'),
                3
            );

            $store->save('test_job', $state);

            self::assertEquals($state, $store->load('test_job'));
            self::assertFalse($GLOBALS['wpdb']->autoload['adct_pi_job_state_test_job']);
        }

        public function testLockUsesAtomicCreationAndTokenGuardedExpiredLockRecovery(): void
        {
            $lock = new WordPressJobLock();
            $now = new DateTimeImmutable('2026-09-25T01:00:00+02:00');
            $oldToken = $lock->acquire('test_job', $now, 1);

            self::assertNotNull($oldToken);
            self::assertTrue($lock->isHeldBy('test_job', $oldToken, $now));
            self::assertNull($lock->acquire('test_job', $now, 30));
            self::assertFalse($GLOBALS['wpdb']->autoload['adct_pi_job_lock_test_job']);

            $newToken = $lock->acquire('test_job', $now->modify('+2 seconds'), 30);

            self::assertNotNull($newToken);
            self::assertNotSame($oldToken, $newToken);
            self::assertFalse($lock->release('test_job', $oldToken));
            self::assertTrue($lock->isHeldBy('test_job', $newToken, $now->modify('+2 seconds')));
            self::assertTrue($lock->release('test_job', $newToken));
            self::assertFalse($lock->isHeldBy('test_job', $newToken, $now->modify('+2 seconds')));
        }
    }

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
