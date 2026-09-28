<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Jobs;

use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\JobStateStoreInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository;
use InvalidArgumentException;

final class HealthAlerts
{
    public const FAILURE_THRESHOLD = 3;
    public const STALL_SECONDS = 8100;
    public const RATE_LIMIT_SECONDS = 86400;
    private const STARTED_OPTION = 'adct_pi_health_started_at';

    public function __construct(
        private readonly WordPressJobScheduler $scheduler,
        private readonly JobStateStoreInterface $states,
        private readonly SourceRepository $sources,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock
    ) {
    }

    public function check(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $started = get_option(self::STARTED_OPTION, false);
        if ($started === false) {
            add_option(self::STARTED_OPTION, $now, '', false);
            $started = get_option(self::STARTED_OPTION);
        }
        if (! is_numeric($started)) {
            throw new InvalidArgumentException('The intake health start time is invalid.');
        }

        $latest = (int) $started;
        foreach ($this->scheduler->registeredJobs() as $job) {
            $state = $this->states->load($job->id());
            if ($state->lastRunAt !== null) {
                $latest = max($latest, $state->lastRunAt->getTimestamp());
            }
            $this->notifyIfNeeded(
                'job_' . $job->id(),
                $state->consecutiveFailures >= self::FAILURE_THRESHOLD,
                'A Parish Intake background job has failed repeatedly.',
                $now
            );
        }

        foreach ($this->sources->findHealthFailureCounts() as $row) {
            $this->notifyIfNeeded(
                'source_' . (int) $row['id'],
                (int) $row['consecutive_failures'] >= self::FAILURE_THRESHOLD,
                'A Parish Intake source has failed repeatedly.',
                $now
            );
        }
        $this->notifyIfNeeded('cron', $now - $latest > self::STALL_SECONDS,
            "Background jobs haven't run for more than 2 hours 15 minutes.", $now);
    }

    public function isStalled(): bool
    {
        $started = get_option(self::STARTED_OPTION, false);
        if ($started === false) {
            return false;
        }
        $latest = (int) $started;
        foreach ($this->scheduler->registeredJobs() as $job) {
            $state = $this->states->load($job->id());
            if ($state->lastRunAt !== null) {
                $latest = max($latest, $state->lastRunAt->getTimestamp());
            }
        }
        return $this->clock->now()->getTimestamp() - $latest > self::STALL_SECONDS;
    }

    private function notifyIfNeeded(string $key, bool $failing, string $message, int $now): void
    {
        $incident = 'adct_pi_health_incident_' . $key;
        $lastAlert = 'adct_pi_health_alerted_' . $key;
        if (! $failing) {
            delete_option($incident);
            return;
        }
        if (! add_option($incident, $now, '', false)) {
            return;
        }
        $previous = (int) get_option($lastAlert, 0);
        if ($previous > 0 && $now - $previous < self::RATE_LIMIT_SECONDS) {
            delete_option($incident);
            return;
        }
        try {
            $recipients = $this->recipients();
            foreach ($recipients as $recipient) {
                $this->mailer->enqueue(new OutboundEmail(
                    $recipient,
                    'Parish Intake needs attention',
                    '',
                    $message . "\n\nOpen Parish Intake > Health to see what to do: "
                        . admin_url('admin.php?page=adct-parish-intake-health'),
                    MailPriority::APPROVER_OR_CHANGE,
                    'health:' . $key . ':' . $now
                ));
            }
            update_option($lastAlert, $now, false);
        } catch (\Throwable $failure) {
            delete_option($incident);
            throw $failure;
        }
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $configured = defined('ADCT_PI_HEALTH_ALERT_EMAILS')
            ? constant('ADCT_PI_HEALTH_ALERT_EMAILS')
            : [get_option('admin_email')];
        if (! is_array($configured) || $configured === []) {
            throw new InvalidArgumentException('Configure one or more health alert administrator emails.');
        }
        $recipients = [];
        foreach ($configured as $email) {
            if (! is_string($email) || ! is_email($email)) {
                throw new InvalidArgumentException('A configured health alert administrator email is invalid.');
            }
            $recipients[] = strtolower($email);
        }
        return array_values(array_unique($recipients));
    }
}
