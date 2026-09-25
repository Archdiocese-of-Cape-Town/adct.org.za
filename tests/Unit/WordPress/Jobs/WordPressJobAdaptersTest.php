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

    function add_action(string $hook, $callback): bool
    {
        \ADCT\ParishIntake\Tests\Unit\WordPress\Jobs\WordPressCronFixture::$actions[$hook][] = $callback;

        return true;
    }

    function add_filter(string $hook, $callback): bool
    {
        \ADCT\ParishIntake\Tests\Unit\WordPress\Jobs\WordPressCronFixture::$filters[$hook][] = $callback;

        return true;
    }

    function wp_next_scheduled(string $hook, array $args = [])
    {
        return \ADCT\ParishIntake\Tests\Unit\WordPress\Jobs\WordPressCronFixture::$scheduledEvents[$hook] ?? false;
    }

    function wp_schedule_event(
        int $timestamp,
        string $recurrence,
        string $hook,
        array $args = [],
        bool $wpError = false
    ) {
        $fixture = \ADCT\ParishIntake\Tests\Unit\WordPress\Jobs\WordPressCronFixture::class;
        $fixture::$scheduleAttempts[] = $hook;

        if ($fixture::$throwOnSchedule) {
            throw new \RuntimeException('schedule fixture detail');
        }

        if (! $fixture::$scheduleSucceeds) {
            return false;
        }

        $fixture::$scheduledEvents[$hook] = $timestamp;

        return true;
    }

    function is_wp_error($value): bool
    {
        return false;
    }

    function wp_clear_scheduled_hook(string $hook, array $args = [])
    {
        $fixture = \ADCT\ParishIntake\Tests\Unit\WordPress\Jobs\WordPressCronFixture::class;
        $fixture::$clearAttempts[] = $hook;

        if ($fixture::$throwOnClear) {
            throw new \RuntimeException('clear fixture detail');
        }

        if (! $fixture::$clearSucceeds) {
            return false;
        }

        unset($fixture::$scheduledEvents[$hook]);

        return 1;
    }

    function error_log(string $message): bool
    {
        \ADCT\ParishIntake\Tests\Unit\WordPress\Jobs\WordPressCronFixture::$logs[] = $message;

        return true;
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Jobs {
    use ADCT\ParishIntake\Core\Jobs\AbstractJob;
    use ADCT\ParishIntake\Core\Jobs\FrameworkHeartbeatJob;
    use ADCT\ParishIntake\Core\Jobs\JobInterface;
    use ADCT\ParishIntake\Core\Jobs\JobRunner;
    use ADCT\ParishIntake\Core\Jobs\JobState;
    use ADCT\ParishIntake\Core\Jobs\JobStepResult;
    use ADCT\ParishIntake\Core\Ports\JobLockInterface;
    use ADCT\ParishIntake\WordPress\Jobs\WordPressJobLock;
    use ADCT\ParishIntake\WordPress\Jobs\WordPressJobScheduler;
    use ADCT\ParishIntake\WordPress\Jobs\WordPressJobStateStore;
    use ADCT\ParishIntake\WordPress\Jobs\WordPressInboundMessageProcessingFailureLogger;
    use DateTimeImmutable;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use ADCT\ParishIntake\Core\Support\SystemClock;

    final class WordPressJobAdaptersTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['wpdb'] = new FakeWordPressOptionsDatabase();
            WordPressCronFixture::reset();
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

        public function testInboundProcessingFailureLogContainsOnlyDiagnosticMetadata(): void
        {
            $logger = new WordPressInboundMessageProcessingFailureLogger();
            $logger->logFailure(41, 'pipeline_parse', RuntimeException::class);

            self::assertSame([
                '[ADCT Parish Intake] Inbound message processing failed (message_id=41 context=pipeline_parse exception=RuntimeException).',
            ], WordPressCronFixture::$logs);
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

        public function testMalformedLockIsCompareAndDeletedBeforeAReplacementIsAcquired(): void
        {
            $lock = new WordPressJobLock();
            $now = new DateTimeImmutable('2026-09-25T01:00:00+02:00');
            $optionName = 'adct_pi_job_lock_corrupt_job';
            $GLOBALS['wpdb']->rows[$optionName] = 'not-valid-json';
            $GLOBALS['wpdb']->autoload[$optionName] = false;

            $token = $lock->acquire('corrupt_job', $now, 30);

            self::assertNotNull($token);
            self::assertTrue($lock->isHeldBy('corrupt_job', $token, $now));
            self::assertSame(false, $GLOBALS['wpdb']->autoload[$optionName]);
        }

        public function testCronScheduleFailureIsLoggedAndDoesNotEscapeOrStopOtherJobs(): void
        {
            WordPressCronFixture::$throwOnSchedule = true;
            $scheduler = $this->createScheduler([
                new FrameworkHeartbeatJob(),
                new SecondHeartbeatJob(),
            ]);

            $scheduler->scheduleMissingEvents();

            self::assertSame([
                'adct_pi_job_framework_heartbeat',
                'adct_pi_job_second_heartbeat',
            ], WordPressCronFixture::$scheduleAttempts);
            self::assertCount(2, WordPressCronFixture::$logs);
            self::assertStringNotContainsString('schedule fixture detail', implode(' ', WordPressCronFixture::$logs));
        }

        public function testCronCleanupFailureIsLoggedAndDoesNotEscape(): void
        {
            WordPressCronFixture::$throwOnClear = true;
            $scheduler = $this->createScheduler();

            $scheduler->clearScheduledEvents();

            WordPressCronFixture::$throwOnClear = false;
            WordPressCronFixture::$clearSucceeds = false;
            $scheduler->clearScheduledEvents();

            self::assertSame([
                'adct_pi_job_framework_heartbeat',
                'adct_pi_job_framework_heartbeat',
            ], WordPressCronFixture::$clearAttempts);
            self::assertCount(2, WordPressCronFixture::$logs);
            self::assertStringNotContainsString(
                'clear fixture detail',
                implode(' ', WordPressCronFixture::$logs)
            );
        }

        public function testUnexpectedCronRunnerFailureIsLoggedAndRecordedWhenPossible(): void
        {
            $stateStore = new WordPressJobStateStore();
            $clock = new SystemClock();
            $runner = new JobRunner(new ThrowingJobLock(), $stateStore, $clock);
            $scheduler = new WordPressJobScheduler(
                [new FrameworkHeartbeatJob()],
                $runner,
                $stateStore,
                $clock
            );
            $scheduler->registerHooks();
            $callbacks = WordPressCronFixture::$actions[$scheduler->cronHook('framework_heartbeat')];

            self::assertCount(1, $callbacks);
            $callbacks[0]();

            $state = $stateStore->load('framework_heartbeat');
            self::assertSame('runner fixture detail', $state->lastErrorMessage);
            self::assertNotNull($state->lastErrorAt);
            self::assertCount(1, WordPressCronFixture::$logs);
            self::assertStringNotContainsString('runner fixture detail', WordPressCronFixture::$logs[0]);
        }

        /**
         * @param JobInterface[] $jobs
         */
        private function createScheduler(?array $jobs = null): WordPressJobScheduler
        {
            $clock = new SystemClock();
            $stateStore = new WordPressJobStateStore();
            $runner = new JobRunner(new WordPressJobLock(), $stateStore, $clock);

            return new WordPressJobScheduler(
                $jobs ?? [new FrameworkHeartbeatJob()],
                $runner,
                $stateStore,
                $clock
            );
        }
    }

    final class WordPressCronFixture
    {
        /**
         * @var array<string, callable[]>
         */
        public static array $actions = [];

        /**
         * @var array<string, callable[]>
         */
        public static array $filters = [];

        /**
         * @var array<string, int>
         */
        public static array $scheduledEvents = [];

        /**
         * @var string[]
         */
        public static array $scheduleAttempts = [];

        /**
         * @var string[]
         */
        public static array $clearAttempts = [];

        /**
         * @var string[]
         */
        public static array $logs = [];

        public static bool $throwOnSchedule = false;
        public static bool $scheduleSucceeds = true;
        public static bool $throwOnClear = false;
        public static bool $clearSucceeds = true;

        public static function reset(): void
        {
            self::$actions = [];
            self::$filters = [];
            self::$scheduledEvents = [];
            self::$scheduleAttempts = [];
            self::$clearAttempts = [];
            self::$logs = [];
            self::$throwOnSchedule = false;
            self::$scheduleSucceeds = true;
            self::$throwOnClear = false;
            self::$clearSucceeds = true;
        }
    }

    final class SecondHeartbeatJob extends AbstractJob
    {
        public function __construct()
        {
            parent::__construct('second_heartbeat', 'Second heartbeat', 600);
        }

        public function processNext(?string $checkpoint): ?JobStepResult
        {
            return null;
        }
    }

    final class ThrowingJobLock implements JobLockInterface
    {
        public function acquire(string $jobId, DateTimeImmutable $now, int $expiresInSeconds): ?string
        {
            throw new RuntimeException('runner fixture detail');
        }

        public function isHeldBy(string $jobId, string $token, DateTimeImmutable $now): bool
        {
            return false;
        }

        public function release(string $jobId, string $token): bool
        {
            return false;
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
