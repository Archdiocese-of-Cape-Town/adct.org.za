<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Jobs;

use ADCT\ParishIntake\Core\Jobs\JobState;
use ADCT\ParishIntake\Core\Jobs\RetentionJob;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\ProcessedMailRetentionInterface;
use ADCT\ParishIntake\Core\Ports\RetentionStoreInterface;
use ADCT\ParishIntake\Core\Retention\RetentionSettings;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RetentionJobTest extends TestCase
{
    public function testBoundedSweepResumesAfterFailureAndLeavesNewerAndPublishedRecords(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-25 12:00:00 UTC'));
        $store = new class implements RetentionStoreInterface {
            public array $messages = [
                1 => ['until' => '2026-09-24 00:00:00', 'status' => 'parsed', 'file' => true],
                2 => ['until' => '2026-10-01 00:00:00', 'status' => 'parsed', 'file' => true],
                3 => ['until' => '2026-09-24 00:00:00', 'status' => 'extracting', 'file' => true],
                4 => ['until' => '2026-09-24 00:00:00', 'status' => 'parsed', 'file' => true],
            ];
            public array $publishedEvents = [17 => 'published'];
            public array $tokens = [1 => '2026-08-01 00:00:00', 2 => '2026-09-01 00:00:00'];
            public array $audit = [1 => '2024-08-01 00:00:00', 2 => '2025-01-01 00:00:00'];
            public bool $failOnce = true;

            public function nextExpiredMessageId(int $afterId, string $cutoff): ?int
            {
                foreach ($this->messages as $id => $row) {
                    if ($id > $afterId && $row['until'] < $cutoff && $row['file']
                        && in_array($row['status'], ['parsed', 'ignored', 'skipped', 'failed'], true)) {
                        return $id;
                    }
                }
                return null;
            }
            public function removeExpiredMessageFiles(int $id, string $cutoff): void
            {
                if ($id === 4 && $this->failOnce) {
                    $this->failOnce = false;
                    throw new RuntimeException('Temporary storage failure');
                }
                $this->messages[$id]['file'] = false;
            }
            public function nextExpiredTokenId(int $afterId, string $cutoff): ?int
            {
                foreach ($this->tokens as $id => $date) {
                    if ($id > $afterId && $date < $cutoff) {
                        return $id;
                    }
                }
                return null;
            }
            public function removeExpiredToken(int $id, string $cutoff): void { unset($this->tokens[$id]); }
            public function nextExpiredAuditId(int $afterId, string $cutoff): ?int
            {
                foreach ($this->audit as $id => $date) {
                    if ($id > $afterId && $date < $cutoff) {
                        return $id;
                    }
                }
                return null;
            }
            public function removeExpiredAudit(int $id, string $cutoff): void { unset($this->audit[$id]); }
        };
        $mail = new class implements ProcessedMailRetentionInterface {
            public array $uids = [10 => '2026-01-01', 20 => '2026-09-01'];
                    public function nextOldProcessedUid(DateTimeImmutable $before, int $afterUid): array
            {
                foreach ($this->uids as $uid => $date) {
                    if ($uid > $afterUid && $date < $before->format('Y-m-d')) {
                        return ['validity' => 123, 'uid' => $uid];
                    }
                }
                        return ['validity' => 123, 'uid' => null];
            }
            public function deleteOldProcessedUid(int $uid, int $expectedValidity): void { unset($this->uids[$uid]); }
            public function close(): void {}
        };
        $job = new RetentionJob($store, $clock, static fn () => new RetentionSettings(), static fn () => [1], static fn (int $id) => $mail);
        $checkpoint = null;
        $steps = 0;
        while (true) {
            try {
                $step = $job->processNext($checkpoint);
            } catch (RuntimeException $failure) {
                self::assertSame('Temporary storage failure', $failure->getMessage());
                $step = $job->processNext($checkpoint);
            }
            ++$steps;
            self::assertLessThan(20, $steps);
            if ($step === null || $step->isComplete()) {
                break;
            }
            $checkpoint = $step->checkpoint();
        }
        self::assertFalse($store->messages[1]['file']);
        self::assertFalse($store->messages[4]['file']);
        self::assertTrue($store->messages[2]['file']);
        self::assertTrue($store->messages[3]['file']);
        self::assertSame([17 => 'published'], $store->publishedEvents);
        self::assertSame([20 => '2026-09-01'], $mail->uids);
        self::assertSame([2 => '2026-09-01 00:00:00'], $store->tokens);
        self::assertSame([2 => '2025-01-01 00:00:00'], $store->audit);
        self::assertNotNull($checkpoint);
        self::assertTrue($job->processNext($checkpoint)->isComplete());
        self::assertTrue($job->isDue(new DateTimeImmutable('2026-09-25 UTC'), new JobState(checkpoint: $checkpoint)));
    }
}
