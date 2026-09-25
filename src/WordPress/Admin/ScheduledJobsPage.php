<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Jobs\JobInterface;
use ADCT\ParishIntake\Core\Jobs\JobRunStatus;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Ports\JobStateStoreInterface;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobScheduler;
use DateTimeImmutable;

final class ScheduledJobsPage
{
    private const CAPABILITY = Capabilities::VIEW_REPORTS;
    private const RUN_CAPABILITY = Capabilities::MANAGE_SETTINGS;
    private const PAGE_SLUG = 'adct-parish-intake-jobs';
    private const ACTION = 'adct_pi_run_job';

    private WordPressJobScheduler $scheduler;
    private JobRunner $runner;
    private JobStateStoreInterface $stateStore;

    public function __construct(
        WordPressJobScheduler $scheduler,
        JobRunner $runner,
        JobStateStoreInterface $stateStore
    ) {
        $this->scheduler = $scheduler;
        $this->runner = $runner;
        $this->stateStore = $stateStore;
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'adct-parish-intake',
            'Parish Intake Scheduled Jobs',
            'Scheduled jobs',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function handleRunNow(): void
    {
        if (! current_user_can(self::RUN_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to run scheduled jobs.', 'adct-parish-intake'), '', [
                'response' => 403,
            ]);
        }

        if (! isset($_POST['job_id']) || ! is_string($_POST['job_id'])) {
            wp_die(esc_html__('A scheduled job was not selected.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        $jobId = sanitize_key(wp_unslash($_POST['job_id']));
        $job = $this->scheduler->findJob($jobId);

        if (! $job instanceof JobInterface) {
            wp_die(esc_html__('The selected scheduled job does not exist.', 'adct-parish-intake'), '', [
                'response' => 404,
            ]);
        }

        check_admin_referer($this->nonceAction($jobId));

        $result = $this->runner->run($job, true, null, null, 'manual');
        $redirect = add_query_arg([
            'page' => self::PAGE_SLUG,
            'adct_pi_job_id' => $jobId,
            'adct_pi_job_result' => $result->status->value,
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to view scheduled jobs.', 'adct-parish-intake'), '', [
                'response' => 403,
            ]);
        }

        $jobs = $this->scheduler->registeredJobs();
        $canRunJobs = current_user_can(self::RUN_CAPABILITY);
        ?>
        <div class="wrap">
            <h1>Parish Intake Scheduled Jobs</h1>
            <p>Jobs run in batches with a time limit, an item limit, and a saved checkpoint.<?php if ($canRunJobs) : ?> Use <strong>Run now</strong> to start a job immediately.<?php endif; ?></p>
            <p>The framework heartbeat is only a scheduling check; it does not read mail, process events, or send email.</p>

            <?php if ($jobs === []) : ?>
                <p>No scheduled jobs are registered.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col">Job</th>
                            <th scope="col">Last run</th>
                            <th scope="col">Trigger</th>
                            <th scope="col">Last success</th>
                            <th scope="col">Last error</th>
                            <th scope="col">Items processed</th>
                            <?php if ($canRunJobs) : ?>
                                <th scope="col">Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobs as $job) : ?>
                            <?php $state = $this->stateStore->load($job->id()); ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($job->label()); ?></strong><br />
                                    <code><?php echo esc_html($job->id()); ?></code>
                                </td>
                                <td><?php echo esc_html($this->formatDate($state->lastRunAt)); ?></td>
                                <td><?php echo esc_html($state->lastTrigger ?? 'Not yet recorded'); ?></td>
                                <td><?php echo esc_html($this->formatDate($state->lastSuccessAt)); ?></td>
                                <td>
                                    <?php if ($state->lastErrorMessage === null) : ?>
                                        None
                                    <?php else : ?>
                                        <?php echo esc_html($state->lastErrorMessage); ?><br />
                                        <span class="description"><?php echo esc_html($this->formatDate($state->lastErrorAt)); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html((string) $state->itemsProcessed); ?></td>
                                <?php if ($canRunJobs) : ?>
                                    <td>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />
                                            <input type="hidden" name="job_id" value="<?php echo esc_attr($job->id()); ?>" />
                                            <?php wp_nonce_field($this->nonceAction($job->id())); ?>
                                            <button class="button button-secondary" type="submit">Run now</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function renderResultNotice(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $notice = $this->resultNotice();

        if ($notice === null) {
            return;
        }
        ?>
        <div class="notice <?php echo esc_attr($notice['class']); ?> is-dismissible">
            <p><?php echo esc_html($notice['message']); ?></p>
        </div>
        <?php
    }

    /**
     * @return array{class: string, message: string}|null
     */
    private function resultNotice(): ?array
    {
        if (
            ! isset($_GET['adct_pi_job_id'], $_GET['adct_pi_job_result'])
            || ! is_string($_GET['adct_pi_job_id'])
            || ! is_string($_GET['adct_pi_job_result'])
        ) {
            return null;
        }

        $jobId = sanitize_key(wp_unslash($_GET['adct_pi_job_id']));
        $status = JobRunStatus::tryFrom(sanitize_key(wp_unslash($_GET['adct_pi_job_result'])));

        if ($this->scheduler->findJob($jobId) === null || $status === null) {
            return null;
        }

        $notices = [
            JobRunStatus::LOCKED->value => ['notice-warning', 'Another run is already in progress; no work was started.'],
            JobRunStatus::NOT_DUE->value => ['notice-info', 'The job is not due yet.'],
            JobRunStatus::COMPLETED->value => ['notice-success', 'The job completed successfully.'],
            JobRunStatus::TIME_BUDGET_REACHED->value => [
                'notice-warning',
                'The job paused at its time budget. Its checkpoint was saved for the next run.',
            ],
            JobRunStatus::ITEM_BUDGET_REACHED->value => [
                'notice-warning',
                'The job paused at its item limit. Its checkpoint was saved for the next run.',
            ],
            JobRunStatus::LOCK_LOST->value => [
                'notice-error',
                'The job stopped after its lock expired or was replaced. The last saved checkpoint is retained.',
            ],
            JobRunStatus::FAILED->value => ['notice-error', 'The job failed. See Last error for details.'],
        ];
        [$class, $message] = $notices[$status->value];

        return ['class' => $class, 'message' => $message];
    }

    private function nonceAction(string $jobId): string
    {
        return self::ACTION . '_' . $jobId;
    }

    private function formatDate(?DateTimeImmutable $date): string
    {
        return $date === null ? 'Not yet recorded' : $date->format('Y-m-d H:i:s P');
    }
}
