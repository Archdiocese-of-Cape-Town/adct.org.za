<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Jobs;

use ADCT\ParishIntake\Core\Jobs\JobState;
use ADCT\ParishIntake\Core\Ports\JobStateStoreInterface;
use DateTimeImmutable;
use RuntimeException;
use UnexpectedValueException;

final class WordPressJobStateStore implements JobStateStoreInterface
{
    private const OPTION_PREFIX = 'adct_pi_job_state_';

    public function load(string $jobId): JobState
    {
        $optionName = $this->optionName($jobId);
        $value = get_option($optionName, null);

        if ($value === null) {
            return JobState::empty();
        }

        if (! is_array($value)) {
            throw new UnexpectedValueException('The stored state for job "' . $jobId . '" is invalid.');
        }

        $checkpoint = $value['checkpoint'] ?? null;
        $lastRunAt = $this->readDate($value['last_run_at'] ?? null, $jobId, 'last_run_at');
        $lastSuccessAt = $this->readDate($value['last_success_at'] ?? null, $jobId, 'last_success_at');
        $lastErrorMessage = $value['last_error_message'] ?? null;
        $lastErrorAt = $this->readDate($value['last_error_at'] ?? null, $jobId, 'last_error_at');
        $itemsProcessed = $value['items_processed'] ?? 0;

        if ($checkpoint !== null && ! is_string($checkpoint)) {
            throw new UnexpectedValueException('The checkpoint for job "' . $jobId . '" is invalid.');
        }

        if ($lastErrorMessage !== null && ! is_string($lastErrorMessage)) {
            throw new UnexpectedValueException('The last error for job "' . $jobId . '" is invalid.');
        }

        if (! is_int($itemsProcessed) || $itemsProcessed < 0) {
            throw new UnexpectedValueException('The item count for job "' . $jobId . '" is invalid.');
        }

        try {
            return new JobState(
                $checkpoint,
                $lastRunAt,
                $lastSuccessAt,
                $lastErrorMessage,
                $lastErrorAt,
                $itemsProcessed
            );
        } catch (\InvalidArgumentException $failure) {
            throw new UnexpectedValueException(
                'The stored state for job "' . $jobId . '" is inconsistent.',
                0,
                $failure
            );
        }
    }

    public function save(string $jobId, JobState $state): void
    {
        $optionName = $this->optionName($jobId);
        $value = [
            'checkpoint' => $state->checkpoint,
            'last_run_at' => $this->formatDate($state->lastRunAt),
            'last_success_at' => $this->formatDate($state->lastSuccessAt),
            'last_error_message' => $state->lastErrorMessage,
            'last_error_at' => $this->formatDate($state->lastErrorAt),
            'items_processed' => $state->itemsProcessed,
        ];

        if (! update_option($optionName, $value, false) && get_option($optionName, null) !== $value) {
            throw new RuntimeException('Unable to save the state for job "' . $jobId . '".');
        }
    }

    private function optionName(string $jobId): string
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $jobId) !== 1) {
            throw new \InvalidArgumentException('Job IDs must be lowercase letters, digits, and underscores.');
        }

        return self::OPTION_PREFIX . $jobId;
    }

    private function readDate(mixed $value, string $jobId, string $field): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException(
                'The ' . $field . ' timestamp for job "' . $jobId . '" is invalid.'
            );
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $failure) {
            throw new UnexpectedValueException(
                'The ' . $field . ' timestamp for job "' . $jobId . '" is invalid.',
                0,
                $failure
            );
        }
    }

    private function formatDate(?DateTimeImmutable $date): ?string
    {
        return $date === null ? null : $date->format('Y-m-d\TH:i:sP');
    }
}
