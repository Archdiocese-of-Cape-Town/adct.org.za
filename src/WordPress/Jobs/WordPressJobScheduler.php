<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Jobs;

use ADCT\ParishIntake\Core\Jobs\JobInterface;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use InvalidArgumentException;
use RuntimeException;

final class WordPressJobScheduler
{
    public const CRON_SCHEDULE = 'adct_pi_every_ten_minutes';
    public const CRON_INTERVAL_SECONDS = 600;

    private JobRunner $runner;
    private ClockInterface $clock;

    /**
     * @var array<string, JobInterface>
     */
    private array $jobsById = [];

    private bool $hooksRegistered = false;

    /**
     * @param JobInterface[] $jobs
     */
    public function __construct(array $jobs, JobRunner $runner, ClockInterface $clock)
    {
        $this->runner = $runner;
        $this->clock = $clock;

        foreach ($jobs as $job) {
            if (! $job instanceof JobInterface) {
                throw new InvalidArgumentException('Every scheduled job must implement JobInterface.');
            }

            $jobId = $job->id();

            if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $jobId) !== 1) {
                throw new InvalidArgumentException('Job IDs must be lowercase letters, digits, and underscores.');
            }

            if (isset($this->jobsById[$jobId])) {
                throw new InvalidArgumentException('Scheduled job IDs must be unique.');
            }

            $this->jobsById[$jobId] = $job;
        }
    }

    public function registerHooks(): void
    {
        if ($this->hooksRegistered) {
            return;
        }

        add_filter('cron_schedules', [$this, 'registerCronSchedule']);

        foreach ($this->jobsById as $jobId => $job) {
            add_action($this->cronHook($jobId), function () use ($job): void {
                $this->runner->run($job);
            });
        }

        add_action('init', [$this, 'scheduleMissingEvents']);
        $this->hooksRegistered = true;
    }

    /**
     * @param array<string, array<string, int|string>> $schedules
     * @return array<string, array<string, int|string>>
     */
    public function registerCronSchedule(array $schedules): array
    {
        $schedules[self::CRON_SCHEDULE] = [
            'interval' => self::CRON_INTERVAL_SECONDS,
            'display' => 'Every 10 minutes',
        ];

        return $schedules;
    }

    public function scheduleMissingEvents(): void
    {
        foreach ($this->jobsById as $jobId => $job) {
            $hook = $this->cronHook($jobId);

            if (wp_next_scheduled($hook) !== false) {
                continue;
            }

            $scheduled = wp_schedule_event(
                $this->clock->now()->getTimestamp() + self::CRON_INTERVAL_SECONDS,
                self::CRON_SCHEDULE,
                $hook,
                [],
                true
            );

            if (is_wp_error($scheduled)) {
                throw new RuntimeException(
                    'Unable to schedule job "' . $jobId . '": ' . $scheduled->get_error_message()
                );
            }

            if ($scheduled === false) {
                throw new RuntimeException('Unable to schedule job "' . $jobId . '".');
            }
        }
    }

    public function clearScheduledEvents(): void
    {
        foreach (array_keys($this->jobsById) as $jobId) {
            $cleared = wp_clear_scheduled_hook($this->cronHook($jobId));

            if ($cleared === false) {
                throw new RuntimeException('Unable to clear the scheduled hook for job "' . $jobId . '".');
            }
        }
    }

    /**
     * @return JobInterface[]
     */
    public function registeredJobs(): array
    {
        return array_values($this->jobsById);
    }

    public function findJob(string $jobId): ?JobInterface
    {
        return $this->jobsById[$jobId] ?? null;
    }

    public function cronHook(string $jobId): string
    {
        return 'adct_pi_job_' . $jobId;
    }
}
