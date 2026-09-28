<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Jobs\JobRunStatus;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Ports\JobStateStoreInterface;
use ADCT\ParishIntake\Core\Mail\MailQueueService;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\MailboxRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository;
use ADCT\ParishIntake\WordPress\Jobs\HealthAlerts;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobScheduler;
use DateTimeImmutable;
use DateTimeZone;

final class HealthPage
{
    public const SLUG = 'adct-parish-intake-health';
    private const ACTION = 'adct_pi_health_check_now';
    private const GUIDE = 'https://github.com/Archdiocese-of-Cape-Town/adct.org.za/blob/main/docs/operator-guide.md';

    public function __construct(
        private readonly WordPressJobScheduler $scheduler,
        private readonly JobRunner $runner,
        private readonly JobStateStoreInterface $states,
        private readonly SourceRepository $sources,
        private readonly MailboxRepository $mailboxes,
        private readonly InboundMessageRepository $inbound,
        private readonly MailQueueService $mail,
        private readonly DeaneryRepository $deaneries,
        private readonly HealthAlerts $alerts
    ) {
    }

    public function registerMenu(): void
    {
        add_submenu_page('adct-parish-intake', 'Parish Intake Health', 'Health',
            Capabilities::VIEW_REPORTS, self::SLUG, [$this, 'render']);
    }

    public function handleCheckNow(): void
    {
        if (! current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die('You do not have permission to check intake now.', '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);
        $results = [];
        foreach (['poll_mailboxes', 'process_inbound_messages', 'send_mail'] as $id) {
            $job = $this->scheduler->findJob($id);
            if ($job === null) {
                wp_die('An intake job is not registered.', '', ['response' => 500]);
            }
            $results[] = $this->runner->run($job, true, 25, 100, 'manual')->status->value;
        }
        $this->alerts->check();
        wp_safe_redirect(add_query_arg([
            'page' => self::SLUG,
            'checked' => implode(',', $results),
        ], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (! current_user_can(Capabilities::VIEW_REPORTS)) {
            wp_die('You do not have permission to view intake health.', '', ['response' => 403]);
        }
        $queue = $this->inbound->healthStats();
        $mail = $this->mail->stats();
        $mailboxes = $this->mailboxes->findAllMailboxes();
        $deaneries = array_filter($this->deaneries->findAllWithActiveApproverCounts(),
            static fn (array $row): bool => $row['status'] === 'active'
                && (int) $row['active_approver_count'] === 0);
        $requestedPage = $_GET['source_page'] ?? '1';
        if (! is_string($requestedPage) || preg_match('/\A[1-9][0-9]{0,4}\z/D', $requestedPage) !== 1) {
            wp_die('The source page number is invalid.', '', ['response' => 400]);
        }
        $page = (int) $requestedPage;
        $sources = $this->sources->findForAdmin([], 100, ($page - 1) * 100);
        $sourceCount = $this->sources->countForAdmin([]);
        ?>
        <div class="wrap">
            <h1>Parish Intake health</h1>
            <p>This page shows when intake was last checked and what needs attention. Times are in South African time.</p>
            <?php if ($this->alerts->isStalled()) : ?>
                <div class="notice notice-error"><p>Background jobs haven't run for more than 2 hours 15 minutes.
                    Check the cron job. <a href="<?php echo esc_url(self::GUIDE . '#keep-scheduled-jobs-running'); ?>">What to do</a></p></div>
            <?php endif; ?>
            <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) : ?>
                <div class="notice notice-warning"><p>WordPress background jobs are disabled.
                    <a href="<?php echo esc_url(self::GUIDE . '#keep-scheduled-jobs-running'); ?>">What to do</a></p></div>
            <?php endif; ?>
            <?php if (! $this->smtpPluginActive()) : ?>
                <div class="notice notice-warning"><p>No supported SMTP plugin is active. Email may use PHP mail without DKIM.
                    <a href="<?php echo esc_url(self::GUIDE . '#outbound-email-and-hourly-cap'); ?>">What to do</a></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['checked']) && is_string($_GET['checked'])) :
                $results = explode(',', sanitize_text_field(wp_unslash($_GET['checked'])));
                $labels = [];
                foreach (['Mailbox check', 'Saved-message parsing', 'Outbound email'] as $index => $label) {
                    $status = JobRunStatus::tryFrom($results[$index] ?? '');
                    if ($status !== null) {
                        $labels[] = $label . ': ' . str_replace('_', ' ', $status->value);
                    }
                }
                ?>
                <?php if (count($labels) === 3) : ?>
                    <div class="notice <?php echo esc_attr(in_array('failed', $results, true) ? 'notice-error' : 'notice-info'); ?>">
                        <p>Check now results: <?php echo esc_html(implode('; ', $labels)); ?>. Review the jobs and queues below.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (current_user_can(Capabilities::MANAGE_SETTINGS)) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />
                    <?php wp_nonce_field(self::ACTION); ?>
                    <button type="submit" class="button button-primary">Check now</button>
                </form>
                <p>Checks mailboxes, processes saved messages and sends queued email in three limited batches (up to 25 seconds each). It does not wait for the next cron run.</p>
            <?php endif; ?>
            <h2>Background jobs</h2>
            <p><a href="<?php echo esc_url(self::GUIDE . '#keep-scheduled-jobs-running'); ?>">What to do if a job fails</a></p>
            <table class="widefat striped"><thead><tr><th>Job</th><th>Last run</th><th>Trigger</th><th>Last success</th><th>Failures in a row</th></tr></thead><tbody>
            <?php foreach ($this->scheduler->registeredJobs() as $job) :
                $state = $this->states->load($job->id()); ?>
                <tr><td><?php echo esc_html($job->label()); ?></td><td><?php echo esc_html($this->date($state->lastRunAt)); ?></td>
                <td><?php echo esc_html($state->lastTrigger ?? 'Not yet recorded'); ?></td>
                <td><?php echo esc_html($this->date($state->lastSuccessAt)); ?></td>
                <td><?php echo esc_html((string) $state->consecutiveFailures); ?></td></tr>
            <?php endforeach; ?></tbody></table>
            <h2>Waiting work</h2>
            <p>Inbound messages waiting to parse: <strong><?php echo esc_html((string) $queue['waiting']); ?></strong>;
                currently extracting: <?php echo esc_html((string) $queue['processing']); ?>;
                oldest waiting: <?php echo esc_html($this->date($queue['oldest_waiting'])); ?>;
                oldest extracting: <?php echo esc_html($this->date($queue['oldest_processing'])); ?>.
                <a href="<?php echo esc_url(admin_url('admin.php?page=adct-parish-intake-inbox')); ?>">View inbound messages</a></p>
            <p>Outbound email waiting: <strong><?php echo esc_html((string) $mail->pendingCount); ?></strong>;
                oldest waiting: <?php echo esc_html($mail->oldestPendingAgeSeconds === null ? 'None' : $this->age($mail->oldestPendingAgeSeconds)); ?>;
                permanently failed: <?php echo esc_html((string) $mail->failedCount); ?>.
                <a href="<?php echo esc_url(self::GUIDE . '#outbound-email-and-hourly-cap'); ?>">What to do</a></p>
            <h2>Deaneries without an active approver</h2>
            <?php if ($deaneries === []) : ?><p>All active deaneries have an approver.</p>
            <?php else : ?><p>These deaneries need an approver; their items go to archdiocese reviewers:
                <?php echo esc_html(implode(', ', array_column($deaneries, 'name'))); ?>.
                <a href="<?php echo esc_url(admin_url('admin.php?page=adct-parish-intake-deaneries')); ?>">Set up approvers</a></p><?php endif; ?>
            <h2>Mailboxes</h2>
            <table class="widefat striped"><thead><tr><th>Mailbox</th><th>Last check</th><th>Last success</th><th>Last item</th><th>Failures in a row</th></tr></thead><tbody>
            <?php foreach ($mailboxes as $mailbox) :
                $health = $this->sources->findHealth($mailbox->sourceId); ?>
                <tr><td><?php echo esc_html($mailbox->label); ?></td>
                <td><?php echo esc_html($this->date($health?->lastCheckedAt)); ?></td>
                <td><?php echo esc_html($this->date($health?->lastSuccessAt)); ?></td>
                <td><?php echo esc_html($this->date($health?->lastItemAt)); ?></td>
                <td><?php echo esc_html((string) ($health?->consecutiveFailures ?? 0)); ?></td></tr>
            <?php endforeach; ?></tbody></table>
            <h2>Sources</h2>
            <p>Showing <?php echo esc_html((string) count($sources)); ?> of <?php echo esc_html((string) $sourceCount); ?> sources.
                <a href="<?php echo esc_url(admin_url('admin.php?page=adct-parish-intake-sources')); ?>">Manage sources and view errors</a></p>
            <table class="widefat striped"><thead><tr><th>Source</th><th>Status</th><th>Last check</th><th>Last success</th><th>Last item</th><th>Failures in a row</th></tr></thead><tbody>
            <?php foreach ($sources as $source) : ?>
                <tr><td><?php echo esc_html((string) ($source['parish_name'] ?? 'Archdiocese-wide') . ' — ' . (string) $source['type']); ?></td>
                <td><?php echo esc_html((string) $source['status']); ?></td>
                <td><?php echo esc_html($this->date($source['last_checked_at'])); ?></td>
                <td><?php echo esc_html($this->date($source['last_success_at'])); ?></td>
                <td><?php echo esc_html($this->date($source['last_item_at'])); ?></td>
                <td><?php echo esc_html((string) $source['consecutive_failures']); ?></td></tr>
            <?php endforeach; ?></tbody></table>
            <?php if ($page > 1) : ?><a href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'source_page' => $page - 1], admin_url('admin.php'))); ?>">Previous sources</a><?php endif; ?>
            <?php if ($page * 100 < $sourceCount) : ?><a href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'source_page' => $page + 1], admin_url('admin.php'))); ?>">Next sources</a><?php endif; ?>
        </div>
        <?php
    }

    private function smtpPluginActive(): bool
    {
        foreach (['fluent-smtp/fluent-smtp.php', 'wp-mail-smtp/wp_mail_smtp.php', 'post-smtp/postman-smtp.php'] as $plugin) {
            if (is_plugin_active($plugin) || (function_exists('is_plugin_active_for_network')
                && is_plugin_active_for_network($plugin))) {
                return true;
            }
        }
        return false;
    }

    private function date(DateTimeImmutable|string|null $value): string
    {
        if ($value === null || $value === '') {
            return 'Not yet recorded';
        }
        $date = is_string($value) ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : $value;
        return $date->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('d/m/Y H:i');
    }

    private function age(int $seconds): string
    {
        return (string) intdiv($seconds, 3600) . ' hours ' . (string) intdiv($seconds % 3600, 60) . ' minutes';
    }
}
