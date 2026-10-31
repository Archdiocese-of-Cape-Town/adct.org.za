<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Ingestion\MailboxCheckpoint;
use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ingestion\RawMailMessage;
use ADCT\ParishIntake\Core\Jobs\JobState;
use ADCT\ParishIntake\Core\Jobs\MailboxPollingSummary;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\WordPress\Admin\HealthPage;
use ADCT\ParishIntake\WordPress\Admin\ScheduledJobsPage;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobLock;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobStateStore;

final class ManualMailboxRedirect extends RuntimeException
{
}

final class ManualMailboxClock implements ClockInterface
{
    public DateTimeImmutable $time;

    public function __construct()
    {
        $this->time = new DateTimeImmutable('2026-10-01T10:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

final class ManualMailboxClient implements MailboxInterface
{
    public function __construct(private Closure $onSearch)
    {
    }

    public function uidValidity(?string $folder = null): int { return 12345; }
    public function search(MailboxSearchCriteria $criteria): array { return ($this->onSearch)($criteria); }
    public function close(): void {}
    public function listFolders(): array { throw new RuntimeException('Unexpected folder listing.'); }
    public function ensureFolder(string $folder): void { throw new RuntimeException('Unexpected folder creation.'); }
    public function uidNext(string $folder): int { throw new RuntimeException('Unexpected UIDNEXT.'); }
    public function searchFolder(MailboxSearchCriteria $criteria, string $folder): array { throw new RuntimeException('Unexpected folder search.'); }
    public function fetch(int $uid): RawMailMessage { throw new RuntimeException('Unexpected message fetch.'); }
    public function move(int $uid, string $folder): void { throw new RuntimeException('Unexpected message move.'); }
    public function delete(int $uid, string $folder): void { throw new RuntimeException('Unexpected message deletion.'); }
    public function markSeen(int $uid): void { throw new RuntimeException('Unexpected flag change.'); }
}

final class ManualMailboxCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;
        $check = static function (bool $condition, string $message) use ($fail): void {
            if (! $condition) {
                $fail('Manual mailbox check: ' . $message);
            }
        };
        $callback = static function (string $hook, string $class) use ($check): array {
            foreach ($GLOBALS['wp_filter'][$hook]->callbacks ?? [] as $callbacks) {
                foreach ($callbacks as $entry) {
                    $fn = $entry['function'];
                    if (is_array($fn) && $fn[0] instanceof $class) {
                        return $fn;
                    }
                }
            }
            $check(false, 'The installed plugin administration callback was not registered: ' . $hook);
            throw new RuntimeException('Missing callback.');
        };
        $healthAction = $callback('admin_post_adct_pi_health_check_now', HealthPage::class);
        $jobsAction = $callback('admin_post_adct_pi_run_job', ScheduledJobsPage::class);
        $scheduler = (new ReflectionProperty($jobsAction[0], 'scheduler'))->getValue($jobsAction[0]);
        $runner = (new ReflectionProperty($jobsAction[0], 'runner'))->getValue($jobsAction[0]);
        $poller = $scheduler->findJob('poll_mailboxes');
        $clock = new ManualMailboxClock();
        $restorations = [];
        $replace = static function (object $object, string $name, mixed $value) use (&$restorations): void {
            $property = new ReflectionProperty($object, $name);
            $restorations[] = [$property, $object, $property->getValue($object)];
            $property->setValue($object, $value);
        };

        $prefix = $wpdb->prefix . 'adct_pi_';
        $oldActive = $wpdb->get_col("SELECT id FROM {$prefix}mailboxes WHERE active = 1");
        $sourceIds = [];
        $connections = [];
        $searches = [];
        $failSearch = false;
        $advanceSeconds = 0;
        $missingPassword = false;
        $states = new WordPressJobStateStore();
        $savedStates = [];
        foreach (['poll_mailboxes', 'process_inbound_messages', 'send_mail'] as $id) {
            $savedStates[$id] = $states->load($id);
        }
        $oldGet = $_GET;
        $oldPost = $_POST;
        $oldRequest = $_REQUEST;
        $oldUser = get_current_user_id();
        $oldMode = get_option('adct_pi_test_mode');
        $oldAllowlist = get_option('adct_pi_test_allowlist');
        $noMail = static function (): never { throw new RuntimeException('Manual mailbox tests must never call wp_mail.'); };
        $die = static fn (): Closure => static function ($message): never {
            throw new RuntimeException(wp_strip_all_tags((string) $message));
        };
        $invoke = static function (array $action, array $post) use ($die): array {
            $_GET = [];
            $_POST = $_REQUEST = $post;
            $redirect = static function (string $url): never { throw new ManualMailboxRedirect($url); };
            add_filter('wp_redirect', $redirect, PHP_INT_MIN);
            add_filter('wp_die_handler', $die, PHP_INT_MAX);
            try {
                $action();
            } catch (ManualMailboxRedirect $result) {
                parse_str((string) parse_url($result->getMessage(), PHP_URL_QUERY), $query);
                return $query;
            } finally {
                remove_filter('wp_redirect', $redirect, PHP_INT_MIN);
                remove_filter('wp_die_handler', $die, PHP_INT_MAX);
            }
            throw new RuntimeException('An admin action did not redirect.');
        };
        $insert = static function (string $table, array $values) use ($wpdb, $check): int {
            $check($wpdb->insert($table, $values) === 1, 'Could not insert a synthetic fixture: ' . $wpdb->last_error);
            return (int) $wpdb->insert_id;
        };
        $source = static function (string $type = 'email', string $status = 'active', bool $active = true) use (
            &$sourceIds, $insert, $prefix
        ): int {
            $id = $insert($prefix . 'sources', [
                'type' => $type, 'identifier' => 'manual-check-' . bin2hex(random_bytes(6)) . '@example.test',
                'role' => 'official', 'status' => $status, 'poll_interval_minutes' => $type === 'manual' ? null : 1440,
                'checkpoint' => (new MailboxCheckpoint(12345, 7, true))->toJson(),
                'created_at' => '2026-10-01 09:59:00', 'updated_at' => '2026-10-01 09:59:00',
            ]);
            $sourceIds[] = $id;
            $insert($prefix . 'mailboxes', [
                'source_id' => $id, 'label' => 'Synthetic manual-check mailbox', 'host' => 'imap.example.test',
                'port' => 993, 'encryption' => 'ssl', 'username' => 'intake@example.test',
                'inbox_folder' => 'INBOX', 'processed_folder' => 'Processed',
                'max_message_size_bytes' => 30 * 1024 * 1024, 'active' => $active ? 1 : 0,
                'created_at' => '2026-10-01 09:59:00', 'updated_at' => '2026-10-01 09:59:00',
            ]);
            return $id;
        };
        try {
            update_option('adct_pi_test_mode', 'enabled');
            update_option('adct_pi_test_allowlist', []);
            add_filter('pre_wp_mail', $noMail, PHP_INT_MIN);
            $check($wpdb->query("UPDATE {$prefix}mailboxes SET active = 0 WHERE active = 1") !== false, 'Could not isolate fixture mailboxes.');
            $replace($runner, 'clock', $clock);
            $replace($poller, 'clock', $clock);
            $replace($poller, 'healthRecorder', new \ADCT\ParishIntake\Core\Sources\SourceHealthRecorder(
                new \ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository(
                    new \ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection()
                ), $clock
            ));
            $replace($poller, 'passwordResolver', static function (MailboxSettings $settings) use (&$missingPassword): string {
                return $missingPassword ? '' : 'synthetic-password';
            });
            // Swap only the network boundary on the installed job; the real
            // registered pages, runner, locks, source query and checkpoints run.
            $replace($poller, 'mailboxFactory', static function (MailboxSettings $settings, string $password) use (
                &$connections, &$searches, &$sourceIds, &$failSearch, &$advanceSeconds, $clock
            ): MailboxInterface {
                if (! in_array($settings->sourceId, $sourceIds, true)) {
                    throw new RuntimeException('A non-fixture mailbox reached the synthetic client.');
                }
                $connections[] = $settings->sourceId;
                return new ManualMailboxClient(static function (MailboxSearchCriteria $criteria) use (
                    &$searches, &$failSearch, &$advanceSeconds, $clock, $settings
                ): array {
                    $searches[] = [$settings->sourceId, $criteria->afterUid];
                    $clock->time = $clock->time->modify('+' . $advanceSeconds . ' seconds');
                    if ($failSearch) {
                        throw new RuntimeException('Synthetic poll failure.');
                    }
                    return [];
                });
            });

            $active = $source();
            foreach (['disabled', 'paused', 'unreliable'] as $status) { $source(status: $status); }
            $source(type: 'manual');
            $source(active: false);
            $manualPost = static fn (bool $health): array => $health
                ? ['_wpnonce' => wp_create_nonce('adct_pi_health_check_now')]
                : ['job_id' => 'poll_mailboxes', '_wpnonce' => wp_create_nonce('adct_pi_run_job_poll_mailboxes')];
            $summary = static fn (array $query): ?MailboxPollingSummary =>
                MailboxPollingSummary::fromQueryValue($query['mailbox_summary'] ?? null);

            foreach ([$healthAction, $jobsAction] as $action) {
                foreach (['permission', 'nonce'] as $refusal) {
                    wp_set_current_user($refusal === 'permission' ? 0 : $oldUser);
                    $before = count($connections);
                    $denied = false;
                    try {
                        $invoke($action, ['job_id' => 'poll_mailboxes', '_wpnonce' => 'invalid']);
                    } catch (RuntimeException $error) {
                        $denied = ! $error instanceof ManualMailboxRedirect;
                    }
                    $check($denied && count($connections) === $before, 'Capability/nonce refusal must precede polling.');
                }
            }
            wp_set_current_user($oldUser);

            $query = $invoke($jobsAction, $manualPost(false));
            $check($summary($query)?->successful === 1, 'Initial successful Scheduled jobs poll was not reported.');
            $check($searches === [[$active, 7]], 'The saved UID checkpoint or source exclusions were bypassed.');
            $states->save('poll_mailboxes', new JobState());
            do_action('adct_pi_job_poll_mailboxes');
            $check(count($connections) === 1, 'Cron must still wait for the successful source interval.');

            foreach ([[$healthAction, true], [$jobsAction, false]] as [$action, $health]) {
                $before = count($connections);
                $query = $invoke($action, $manualPost($health));
                $check(count($connections) === $before + 1 && $summary($query)?->successful === 1,
                    'An authorized manual action must recheck before the source interval.');
                $_GET = $query;
                ob_start();
                if ($health) { $healthAction[0]->render(); } else { $jobsAction[0]->renderResultNotice(); }
                $html = (string) ob_get_clean();
                $check(str_contains($html, '1 checked successfully'), 'The actual page did not render mailbox outcomes.');
                if ($health) {
                    foreach (['process_inbound_messages', 'send_mail'] as $id) {
                        $check($states->load($id)->lastTrigger === 'manual', 'Health must retain its bounded queue steps.');
                    }
                }
            }

            $lock = new WordPressJobLock();
            $token = $lock->acquire('poll_mailboxes', $clock->now(), 180);
            $check(is_string($token), 'Could not acquire the synthetic overlap lock.');
            try {
                foreach ([[$healthAction, true], [$jobsAction, false]] as [$action, $health]) {
                    $before = count($connections);
                    $query = $invoke($action, $manualPost($health));
                    $result = $health ? explode(',', $query['checked'])[0] : $query['adct_pi_job_result'];
                    $check($result === 'locked' && $summary($query) === null && count($connections) === $before,
                        'Locked actions must not connect or reuse earlier success counts.');
                }
            } finally { $lock->release('poll_mailboxes', $token); }

            $failSearch = true;
            $query = $invoke($healthAction, $manualPost(true));
            $check($summary($query)?->failed === 1 && $summary($query)?->successful === 0, 'Failed poll was reported as checked.');
            $checkpoint = $wpdb->get_var($wpdb->prepare("SELECT checkpoint FROM {$prefix}sources WHERE id = %d", $active));
            foreach ([[$healthAction, true], [$jobsAction, false]] as [$action, $health]) {
                $before = count($connections);
                $query = $invoke($action, $manualPost($health));
                $check($summary($query)?->backoff === 1 && count($connections) === $before, 'Manual polling bypassed retry backoff.');
                $check($checkpoint === $wpdb->get_var($wpdb->prepare("SELECT checkpoint FROM {$prefix}sources WHERE id = %d", $active)),
                    'A skipped poll changed its checkpoint.');
            }
            $failSearch = false;
            $clock->time = $clock->time->modify('+10 minutes');
            $query = $invoke($jobsAction, $manualPost(false));
            $check($summary($query)?->successful === 1, 'Polling did not resume when backoff expired.');

            $missingPassword = true;
            $before = count($connections);
            $query = $invoke($jobsAction, $manualPost(false));
            $check($summary($query)?->failed === 1 && count($connections) === $before,
                'Missing passwords must fail explicitly without connecting.');
            $missingPassword = false;
            $clock->time = $clock->time->modify('+10 minutes');
            $second = $source();
            foreach ([[$healthAction, true, 26], [$jobsAction, false, 61]] as [$action, $health, $seconds]) {
                $states->save('poll_mailboxes', new JobState());
                $advanceSeconds = $seconds;
                $query = $invoke($action, $manualPost($health));
                $result = $health ? explode(',', $query['checked'])[0] : $query['adct_pi_job_result'];
                $check($result === 'time_budget_reached' && $summary($query)?->pending === 1,
                    'The real admin time limit or pending-mailbox report was lost.');
            }
            $advanceSeconds = 0;
            $query = $invoke($healthAction, $manualPost(true));
            $check($summary($query)?->successful === 1 && explode(',', $query['checked'])[0] === 'completed',
                'A time-limited cursor did not resume at the remaining mailbox.');

            for ($i = 0; $i < 99; ++$i) { $source(); }
            foreach ([[$healthAction, true], [$jobsAction, false]] as [$action, $health]) {
                $states->save('poll_mailboxes', new JobState());
                $before = count($connections);
                $query = $invoke($action, $manualPost($health));
                $result = $health ? explode(',', $query['checked'])[0] : $query['adct_pi_job_result'];
                $check($result === 'item_budget_reached' && count($connections) - $before === 100
                    && $summary($query)?->pending === 1, 'The real 100-item limit was bypassed.');
                $check($states->load('poll_mailboxes')->checkpoint !== null, 'The item-limited run did not save its cursor.');
            }
            $check($wpdb->query("UPDATE {$prefix}mailboxes SET active = 0 WHERE active = 1") !== false, 'Could not disable fixtures.');
            foreach ([[$healthAction, true], [$jobsAction, false]] as [$action, $health]) {
                $query = $invoke($action, $manualPost($health));
                $check($summary($query)?->total === 0, 'No-mailbox actions must report no eligible mailbox.');
            }
        } finally {
            remove_filter('pre_wp_mail', $noMail, PHP_INT_MIN);
            foreach (array_reverse($restorations) as [$property, $object, $value]) { $property->setValue($object, $value); }
            foreach ($sourceIds as $id) {
                $wpdb->delete($prefix . 'mailboxes', ['source_id' => $id]);
                $wpdb->delete($prefix . 'sources', ['id' => $id]);
            }
            foreach ($oldActive as $id) { $wpdb->update($prefix . 'mailboxes', ['active' => 1], ['id' => $id]); }
            foreach ($savedStates as $id => $state) { $states->save($id, $state); }
            update_option('adct_pi_test_mode', $oldMode);
            update_option('adct_pi_test_allowlist', $oldAllowlist);
            wp_set_current_user($oldUser);
            $_GET = $oldGet;
            $_POST = $oldPost;
            $_REQUEST = $oldRequest;
        }
    }
}

ManualMailboxCheck::run($fail);
