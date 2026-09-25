<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Mail;

use ADCT\ParishIntake\Core\Mail\AllowAllRecipientPolicy;
use ADCT\ParishIntake\Core\Mail\AllowlistRecipientPolicy;
use ADCT\ParishIntake\Core\Mail\MailDeliveryResult;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\MailQueueClaimResult;
use ADCT\ParishIntake\Core\Mail\MailQueueClaimStatus;
use ADCT\ParishIntake\Core\Mail\MailQueueConfiguration;
use ADCT\ParishIntake\Core\Mail\MailQueueDispatchStatus;
use ADCT\ParishIntake\Core\Mail\MailQueueDispatcher;
use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Mail\MailQueueRecord;
use ADCT\ParishIntake\Core\Mail\MailQueueService;
use ADCT\ParishIntake\Core\Mail\MailQueueStats;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailDeliveryInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueImmediateDispatchInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\RecipientPolicyInterface;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class MailQueueServiceTest extends TestCase
{
    private DateTimeImmutable $start;

    protected function setUp(): void
    {
        $this->start = new DateTimeImmutable('2026-09-25T08:00:00+02:00');
    }

    public function testRejectsInvalidRecipientsAndHeaderInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OutboundEmail(
            "person@example.test\r\nBcc: other@example.test",
            'Safe subject',
            '<p>Notice</p>',
            'Notice',
            MailPriority::APPROVER_OR_CHANGE
        );
    }

    public function testRejectsNewlinesInSubject(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OutboundEmail(
            'person@example.test',
            "Notice\r\nBcc: other@example.test",
            '<p>Notice</p>',
            'Notice',
            MailPriority::APPROVER_OR_CHANGE
        );
    }

    public function testHourlyCapDefaultsToOneHundredAndCannotExceedSharedHostLimit(): void
    {
        self::assertSame(100, (new MailQueueConfiguration())->hourlyCap);
        self::assertSame(1, (new MailQueueConfiguration(1))->hourlyCap);
        self::assertSame(500, (new MailQueueConfiguration(500))->hourlyCap);

        $this->expectException(InvalidArgumentException::class);
        new MailQueueConfiguration(501);
    }

    public function testGroupKeyDeduplicatesIdenticalComposedMessageAndRejectsChangedContent(): void
    {
        $repository = new InMemoryMailQueueRepository();
        $clock = new MailQueueTestClock($this->start);
        $service = $this->service($repository, $clock);
        $email = $this->email(
            'approver@example.test',
            MailPriority::APPROVER_OR_CHANGE,
            'approver:17:run:20260925:recipient:approver'
        );

        $first = $service->enqueue($email);
        $duplicate = $service->enqueue($email);

        self::assertFalse($first->duplicate);
        self::assertTrue($duplicate->duplicate);
        self::assertSame($first->id, $duplicate->id);
        self::assertSame(MailQueueStatus::QUEUED, $duplicate->status);

        $differentContent = new OutboundEmail(
            $email->recipient,
            $email->subject,
            '<p>A different, distinct notice.</p>',
            'A different, distinct notice.',
            $email->priority,
            $email->groupKey
        );

        $this->expectException(DomainException::class);
        $service->enqueue($differentContent);
    }

    public function testPriorityOneEnqueueRequestsBoundedImmediateDispatch(): void
    {
        $repository = new InMemoryMailQueueRepository();
        $clock = new MailQueueTestClock($this->start);
        $trigger = new RecordingMailQueueImmediateDispatch();
        $service = $this->service($repository, $clock, new AllowAllRecipientPolicy(), $trigger);

        $service->enqueue($this->email('login@example.test', MailPriority::LOGIN_OR_CONFIRMATION));
        $service->enqueue($this->email('approver@example.test', MailPriority::APPROVER_OR_CHANGE));

        self::assertSame(1, $trigger->dispatchCalls);
    }

    public function testSuppressedRecipientNeverReachesDeliveryOrConsumesHourlyCap(): void
    {
        $repository = new InMemoryMailQueueRepository();
        $clock = new MailQueueTestClock($this->start);
        $policy = new AllowlistRecipientPolicy(true, ['allowed@example.test']);
        $service = $this->service($repository, $clock, $policy);
        $delivery = new RecordingMailDelivery();
        $dispatcher = $this->dispatcher($repository, $clock, $delivery, $policy, 1);

        $suppressed = $service->enqueue($this->email(
            'blocked@example.test',
            MailPriority::LOGIN_OR_CONFIRMATION,
            'login:blocked:1'
        ));
        $service->enqueue($this->email(
            'allowed@example.test',
            MailPriority::REMINDER_OR_DIGEST,
            'digest:allowed:1'
        ));

        self::assertSame(MailQueueStatus::SUPPRESSED, $suppressed->status);
        self::assertSame(MailQueueDispatchStatus::SENT, $dispatcher->dispatchOne()->status);
        self::assertSame(['allowed@example.test'], array_map(
            static fn (OutboundEmail $email): string => $email->recipient,
            $delivery->delivered
        ));
        self::assertSame(1, $service->stats()->sentInLastHour);
        self::assertSame(0, $service->stats()->pendingCount);
    }

    public function testRecipientPolicyIsRecheckedBeforeAnAlreadyQueuedMessageIsDelivered(): void
    {
        $repository = new InMemoryMailQueueRepository();
        $clock = new MailQueueTestClock($this->start);
        $service = $this->service($repository, $clock, new AllowAllRecipientPolicy());
        $service->enqueue($this->email('blocked@example.test', MailPriority::APPROVER_OR_CHANGE));
        $policy = new AllowlistRecipientPolicy(true, ['other@example.test']);
        $delivery = new RecordingMailDelivery();
        $dispatcher = $this->dispatcher($repository, $clock, $delivery, $policy, 1);

        self::assertSame(MailQueueDispatchStatus::SUPPRESSED, $dispatcher->dispatchOne()->status);
        self::assertSame([], $delivery->delivered);
        self::assertSame(0, $service->stats()->sentInLastHour);
    }

    public function testPriorityOneLoginLinkGoesBeforeTwoHundredDigests(): void
    {
        $repository = new InMemoryMailQueueRepository();
        $clock = new MailQueueTestClock($this->start);
        $service = $this->service($repository, $clock);

        for ($index = 1; $index <= 200; ++$index) {
            $service->enqueue($this->email(
                sprintf('digest-%03d@example.test', $index),
                MailPriority::REMINDER_OR_DIGEST
            ));
        }

        $service->enqueue($this->email('login@example.test', MailPriority::LOGIN_OR_CONFIRMATION));
        $delivery = new RecordingMailDelivery();
        $dispatcher = $this->dispatcher($repository, $clock, $delivery);

        self::assertSame(MailQueueDispatchStatus::SENT, $dispatcher->dispatchOne()->status);
        self::assertSame('login@example.test', $delivery->delivered[0]->recipient);
    }

    public function testHourlyCapCountsSuccessfulSendsAcrossDispatchesAndRollingWindow(): void
    {
        $repository = new InMemoryMailQueueRepository();
        $clock = new MailQueueTestClock($this->start);
        $service = $this->service($repository, $clock);

        foreach (['one', 'two', 'three'] as $name) {
            $service->enqueue($this->email($name . '@example.test', MailPriority::REMINDER_OR_DIGEST));
        }

        $dispatcher = $this->dispatcher($repository, $clock, new RecordingMailDelivery(), null, 2);

        self::assertSame(MailQueueDispatchStatus::SENT, $dispatcher->dispatchOne()->status);
        self::assertSame(MailQueueDispatchStatus::SENT, $dispatcher->dispatchOne()->status);
        self::assertSame(MailQueueDispatchStatus::CAP_REACHED, $dispatcher->dispatchOne()->status);
        self::assertSame(2, $service->stats()->sentInLastHour);

        $clock->advance(3599);
        self::assertSame(MailQueueDispatchStatus::CAP_REACHED, $dispatcher->dispatchOne()->status);

        $clock->advance(1);
        self::assertSame(MailQueueDispatchStatus::SENT, $dispatcher->dispatchOne()->status);
        self::assertSame(1, $service->stats()->sentInLastHour);
    }

    public function testConcurrentClaimsReserveTheLastHourlySlotAtomically(): void
    {
        $repository = new InMemoryMailQueueRepository();
        $clock = new MailQueueTestClock($this->start);
        $first = $repository->enqueue(
            $this->email('first@example.test', MailPriority::APPROVER_OR_CHANGE),
            MailQueueStatus::QUEUED,
            $clock->now()
        );
        $second = $repository->enqueue(
            $this->email('second@example.test', MailPriority::APPROVER_OR_CHANGE),
            MailQueueStatus::QUEUED,
            $clock->now()
        );

        $firstClaim = $repository->claim($first->id, $clock->now(), 1, 3600);
        $secondClaim = $repository->claim($second->id, $clock->now(), 1, 3600);

        self::assertSame(MailQueueClaimStatus::CLAIMED, $firstClaim->status);
        self::assertSame(MailQueueClaimStatus::CAP_REACHED, $secondClaim->status);
        self::assertSame(MailQueueStatus::SENDING, $firstClaim->record->status);
    }

    public function testFailedDeliveryUsesExponentialBackoffAndStopsAfterFiveAttempts(): void
    {
        $repository = new InMemoryMailQueueRepository();
        $clock = new MailQueueTestClock($this->start);
        $service = $this->service($repository, $clock);
        $enqueued = $service->enqueue($this->email(
            'retry@example.test',
            MailPriority::APPROVER_OR_CHANGE,
            'retry:example:1'
        ));
        $delivery = new RecordingMailDelivery();
        $delivery->result = MailDeliveryResult::failed('wp_mail_returned_false');
        $dispatcher = $this->dispatcher($repository, $clock, $delivery);
        $delays = [60, 120, 240, 480];

        for ($attempt = 1; $attempt <= MailQueueConfiguration::MAX_ATTEMPTS; ++$attempt) {
            $result = $dispatcher->dispatchOne();
            $record = $repository->records[$enqueued->id];

            if ($attempt < MailQueueConfiguration::MAX_ATTEMPTS) {
                self::assertSame(MailQueueDispatchStatus::RETRY_SCHEDULED, $result->status);
                self::assertSame(MailQueueStatus::QUEUED, $record->status);
                self::assertSame(
                    $clock->now()->getTimestamp() + $delays[$attempt - 1],
                    $record->nextAttemptAt?->getTimestamp()
                );
                self::assertSame(MailQueueDispatchStatus::EMPTY, $dispatcher->dispatchOne()->status);
                $clock->advance($delays[$attempt - 1]);
            } else {
                self::assertSame(MailQueueDispatchStatus::FAILED, $result->status);
                self::assertSame(MailQueueStatus::FAILED, $record->status);
                self::assertNull($record->nextAttemptAt);
                self::assertSame('wp_mail_returned_false', $record->errorCode);
            }
        }

        self::assertCount(MailQueueConfiguration::MAX_ATTEMPTS, $delivery->delivered);
        self::assertSame(0, $service->stats()->sentInLastHour);
        self::assertSame(1, $service->stats()->failedCount);
        self::assertSame(MailQueueDispatchStatus::EMPTY, $dispatcher->dispatchOne()->status);
    }

    public function testUnknownDeliveryExceptionKeepsTheCapReservationUntilRecovery(): void
    {
        $repository = new InMemoryMailQueueRepository();
        $clock = new MailQueueTestClock($this->start);
        $service = $this->service($repository, $clock);
        $unknown = $service->enqueue($this->email('unknown@example.test', MailPriority::APPROVER_OR_CHANGE));
        $waiting = $service->enqueue($this->email('waiting@example.test', MailPriority::REMINDER_OR_DIGEST));
        $delivery = new RecordingMailDelivery();
        $delivery->exception = new RuntimeException('SMTP may have accepted the message.');
        $dispatcher = $this->dispatcher($repository, $clock, $delivery, null, 1);

        $dispatch = $dispatcher->dispatchOne();

        self::assertSame(MailQueueDispatchStatus::OUTCOME_UNKNOWN, $dispatch->status);
        self::assertSame(MailQueueStatus::SENDING, $repository->records[$unknown->id]->status);
        self::assertSame(1, $repository->records[$unknown->id]->attempts);
        self::assertSame(MailQueueStatus::QUEUED, $repository->records[$waiting->id]->status);

        $clock->advance(3599);

        self::assertSame(MailQueueDispatchStatus::CAP_REACHED, $dispatcher->dispatchOne()->status);
        self::assertSame(MailQueueStatus::SENDING, $repository->records[$unknown->id]->status);
        self::assertSame(0, $service->stats()->sentInLastHour);
        self::assertSame(0, $service->stats()->failedCount);

        $clock->advance(1);

        self::assertSame(
            MailQueueDispatchStatus::INTERRUPTED_REQUEUED,
            $dispatcher->dispatchOne()->status
        );
        self::assertSame(MailQueueStatus::QUEUED, $repository->records[$unknown->id]->status);
        self::assertSame(
            $clock->now()->getTimestamp() + 60,
            $repository->records[$unknown->id]->nextAttemptAt?->getTimestamp()
        );
        self::assertCount(1, $delivery->delivered);
    }

    public function testUnknownDeliveryResultLeavesTheClaimReserved(): void
    {
        $repository = new InMemoryMailQueueRepository();
        $clock = new MailQueueTestClock($this->start);
        $service = $this->service($repository, $clock);
        $enqueued = $service->enqueue($this->email(
            'unknown-result@example.test',
            MailPriority::APPROVER_OR_CHANGE
        ));
        $delivery = new RecordingMailDelivery();
        $delivery->result = MailDeliveryResult::unknown();
        $dispatcher = $this->dispatcher($repository, $clock, $delivery);

        self::assertSame(
            MailQueueDispatchStatus::OUTCOME_UNKNOWN,
            $dispatcher->dispatchOne()->status
        );
        self::assertSame(MailQueueStatus::SENDING, $repository->records[$enqueued->id]->status);
        self::assertSame(1, $repository->records[$enqueued->id]->attempts);
        self::assertSame(0, $service->stats()->failedCount);
    }

    public function testInterruptedClaimRemainsReservedForAnHourThenRetries(): void
    {
        $repository = new InMemoryMailQueueRepository();
        $clock = new MailQueueTestClock($this->start);
        $service = $this->service($repository, $clock);
        $email = $this->email('crash@example.test', MailPriority::APPROVER_OR_CHANGE);
        $enqueued = $service->enqueue($email);
        $abandonedClaim = $repository->claim($enqueued->id, $clock->now(), 1, 3600);
        $other = $repository->enqueue(
            $this->email('other@example.test', MailPriority::REMINDER_OR_DIGEST),
            MailQueueStatus::QUEUED,
            $clock->now()
        );

        self::assertSame(MailQueueClaimStatus::CLAIMED, $abandonedClaim->status);
        self::assertSame(
            MailQueueClaimStatus::CAP_REACHED,
            $repository->claim($other->id, $clock->now(), 1, 3600)->status
        );
        $repository->markSuppressed($repository->records[$other->id], $clock->now());

        $delivery = new RecordingMailDelivery();
        $dispatcher = $this->dispatcher($repository, $clock, $delivery, null, 1);
        $clock->advance(3599);
        self::assertSame(MailQueueDispatchStatus::EMPTY, $dispatcher->dispatchOne()->status);

        $clock->advance(1);
        self::assertSame(
            MailQueueDispatchStatus::INTERRUPTED_REQUEUED,
            $dispatcher->dispatchOne()->status
        );
        self::assertSame(MailQueueStatus::QUEUED, $repository->records[$enqueued->id]->status);
        self::assertSame(
            $clock->now()->getTimestamp() + 60,
            $repository->records[$enqueued->id]->nextAttemptAt?->getTimestamp()
        );

        $clock->advance(59);
        self::assertSame(MailQueueDispatchStatus::EMPTY, $dispatcher->dispatchOne()->status);
        $clock->advance(1);
        self::assertSame(MailQueueDispatchStatus::SENT, $dispatcher->dispatchOne()->status);
        self::assertSame('crash@example.test', $delivery->delivered[0]->recipient);
    }

    public function testStatsExposePendingCountAndAgeWithoutMessageContent(): void
    {
        $repository = new InMemoryMailQueueRepository();
        $clock = new MailQueueTestClock($this->start);
        $service = $this->service($repository, $clock);
        $service->enqueue($this->email('pending@example.test', MailPriority::REMINDER_OR_DIGEST));
        $clock->advance(120);

        $stats = $service->stats();

        self::assertSame(1, $stats->pendingCount);
        self::assertSame(120, $stats->oldestPendingAgeSeconds);
        self::assertSame(0, $stats->sentInLastHour);
        self::assertSame(0, $stats->failedCount);
        self::assertObjectNotHasProperty('body_html', $stats);
    }

    private function service(
        InMemoryMailQueueRepository $repository,
        MailQueueTestClock $clock,
        ?RecipientPolicyInterface $policy = null,
        ?RecordingMailQueueImmediateDispatch $trigger = null
    ): MailQueueService {
        return new MailQueueService(
            $repository,
            $policy ?? new AllowlistRecipientPolicy(false, []),
            $clock,
            $trigger
        );
    }

    private function dispatcher(
        InMemoryMailQueueRepository $repository,
        MailQueueTestClock $clock,
        RecordingMailDelivery $delivery,
        ?RecipientPolicyInterface $policy = null,
        int $hourlyCap = 100
    ): MailQueueDispatcher {
        return new MailQueueDispatcher(
            $repository,
            $delivery,
            $policy ?? new AllowlistRecipientPolicy(false, []),
            $clock,
            new MailQueueConfiguration($hourlyCap)
        );
    }

    private function email(
        string $recipient,
        MailPriority $priority,
        ?string $groupKey = null
    ): OutboundEmail {
        return new OutboundEmail(
            $recipient,
            'A fictional parish notice',
            '<p>This is an invented example.</p>',
            'This is an invented example.',
            $priority,
            $groupKey
        );
    }
}

final class MailQueueTestClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $currentTime)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->currentTime;
    }

    public function advance(int $seconds): void
    {
        $this->currentTime = $this->currentTime->modify('+' . $seconds . ' seconds');
    }
}

final class RecordingMailDelivery implements MailDeliveryInterface
{
    /**
     * @var list<OutboundEmail>
     */
    public array $delivered = [];

    public MailDeliveryResult $result;
    public ?Throwable $exception = null;

    public function __construct()
    {
        $this->result = MailDeliveryResult::sent();
    }

    public function deliver(OutboundEmail $email): MailDeliveryResult
    {
        $this->delivered[] = $email;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result;
    }
}

final class RecordingMailQueueImmediateDispatch implements MailQueueImmediateDispatchInterface
{
    public int $dispatchCalls = 0;

    public function dispatchImmediately(): void
    {
        ++$this->dispatchCalls;
    }
}

final class InMemoryMailQueueRepository implements MailQueueRepositoryInterface
{
    /**
     * @var array<int, MailQueueRecord>
     */
    public array $records = [];

    private int $nextId = 1;

    public function enqueue(
        OutboundEmail $email,
        MailQueueStatus $initialStatus,
        DateTimeImmutable $now
    ): MailQueueEnqueueResult {
        if ($email->groupKey !== null) {
            foreach ($this->records as $existing) {
                if (
                    $existing->email->recipient === $email->recipient
                    && $existing->email->groupKey === $email->groupKey
                ) {
                    if (! $existing->email->hasSamePayload($email)) {
                        throw new DomainException('Different composed content uses this group key.');
                    }

                    return new MailQueueEnqueueResult($existing->id, $existing->status, true);
                }
            }
        }

        $id = $this->nextId++;
        $this->records[$id] = new MailQueueRecord(
            $id,
            $email,
            $initialStatus,
            0,
            $now,
            $now,
            null,
            null,
            $initialStatus === MailQueueStatus::SUPPRESSED ? 'recipient_not_allowlisted' : null
        );

        return new MailQueueEnqueueResult($id, $initialStatus, false);
    }

    public function findNextDue(DateTimeImmutable $now): ?MailQueueRecord
    {
        $queued = array_values(array_filter(
            $this->records,
            static fn (MailQueueRecord $record): bool => $record->status === MailQueueStatus::QUEUED
                && ($record->nextAttemptAt === null || $record->nextAttemptAt <= $now)
        ));
        usort($queued, static function (MailQueueRecord $first, MailQueueRecord $second): int {
            return ($first->email->priority->value <=> $second->email->priority->value)
                ?: ($first->createdAt->getTimestamp() <=> $second->createdAt->getTimestamp())
                ?: ($first->id <=> $second->id);
        });

        return $queued[0] ?? null;
    }

    public function findExpiredClaim(DateTimeImmutable $cutoff): ?MailQueueRecord
    {
        $expired = array_values(array_filter(
            $this->records,
            static fn (MailQueueRecord $record): bool => $record->status === MailQueueStatus::SENDING
                && $record->updatedAt <= $cutoff
        ));
        usort($expired, static fn (MailQueueRecord $first, MailQueueRecord $second): int =>
            ($first->updatedAt->getTimestamp() <=> $second->updatedAt->getTimestamp())
            ?: ($first->id <=> $second->id)
        );

        return $expired[0] ?? null;
    }

    public function claim(
        int $id,
        DateTimeImmutable $now,
        int $hourlyCap,
        int $windowSeconds
    ): MailQueueClaimResult {
        $record = $this->records[$id] ?? null;

        if (
            $record === null
            || $record->status !== MailQueueStatus::QUEUED
            || ($record->nextAttemptAt !== null && $record->nextAttemptAt > $now)
        ) {
            return new MailQueueClaimResult(MailQueueClaimStatus::NOT_CLAIMABLE);
        }

        $cutoff = $now->modify('-' . $windowSeconds . ' seconds');
        $usage = count(array_filter(
            $this->records,
            static fn (MailQueueRecord $candidate): bool =>
                ($candidate->status === MailQueueStatus::SENT
                    && $candidate->sentAt !== null
                    && $candidate->sentAt > $cutoff)
                || ($candidate->status === MailQueueStatus::SENDING && $candidate->updatedAt > $cutoff)
        ));

        if ($usage >= $hourlyCap) {
            return new MailQueueClaimResult(MailQueueClaimStatus::CAP_REACHED);
        }

        $claimed = $record->claimed($now);
        $this->records[$id] = $claimed;

        return new MailQueueClaimResult(MailQueueClaimStatus::CLAIMED, $claimed);
    }

    public function markSent(MailQueueRecord $claim, DateTimeImmutable $sentAt): bool
    {
        if (! $this->isCurrentClaim($claim)) {
            return false;
        }

        $this->records[$claim->id] = new MailQueueRecord(
            $claim->id,
            $claim->email,
            MailQueueStatus::SENT,
            $claim->attempts,
            $claim->createdAt,
            $sentAt,
            null,
            $sentAt
        );

        return true;
    }

    public function markDeliveryFailure(
        MailQueueRecord $claim,
        DateTimeImmutable $failedAt,
        ?DateTimeImmutable $retryAt,
        string $errorCode
    ): bool {
        if (! $this->isCurrentClaim($claim)) {
            return false;
        }

        $this->records[$claim->id] = new MailQueueRecord(
            $claim->id,
            $claim->email,
            $retryAt === null ? MailQueueStatus::FAILED : MailQueueStatus::QUEUED,
            $claim->attempts,
            $claim->createdAt,
            $failedAt,
            $retryAt,
            null,
            $errorCode
        );

        return true;
    }

    public function markSuppressed(MailQueueRecord $queued, DateTimeImmutable $suppressedAt): bool
    {
        $current = $this->records[$queued->id] ?? null;

        if (
            $current === null
            || $current->status !== MailQueueStatus::QUEUED
            || $current->attempts !== $queued->attempts
            || $current->updatedAt != $queued->updatedAt
        ) {
            return false;
        }

        $this->records[$queued->id] = new MailQueueRecord(
            $queued->id,
            $queued->email,
            MailQueueStatus::SUPPRESSED,
            $queued->attempts,
            $queued->createdAt,
            $suppressedAt,
            null,
            null,
            'recipient_not_allowlisted'
        );

        return true;
    }

    public function markInterrupted(
        MailQueueRecord $claim,
        DateTimeImmutable $recoveredAt,
        ?DateTimeImmutable $retryAt
    ): bool {
        if (! $this->isCurrentClaim($claim)) {
            return false;
        }

        $this->records[$claim->id] = new MailQueueRecord(
            $claim->id,
            $claim->email,
            $retryAt === null ? MailQueueStatus::FAILED : MailQueueStatus::QUEUED,
            $claim->attempts,
            $claim->createdAt,
            $recoveredAt,
            $retryAt,
            null,
            'delivery_outcome_unknown_after_interruption'
        );

        return true;
    }

    public function stats(DateTimeImmutable $now, int $windowSeconds): MailQueueStats
    {
        $pending = array_values(array_filter(
            $this->records,
            static fn (MailQueueRecord $record): bool => $record->status->isPending()
        ));
        $oldest = null;

        foreach ($pending as $record) {
            if ($oldest === null || $record->createdAt < $oldest) {
                $oldest = $record->createdAt;
            }
        }

        $cutoff = $now->modify('-' . $windowSeconds . ' seconds');
        $sentCount = count(array_filter(
            $this->records,
            static fn (MailQueueRecord $record): bool => $record->status === MailQueueStatus::SENT
                && $record->sentAt !== null
                && $record->sentAt > $cutoff
        ));
        $failedCount = count(array_filter(
            $this->records,
            static fn (MailQueueRecord $record): bool => $record->status === MailQueueStatus::FAILED
        ));

        return new MailQueueStats(
            count($pending),
            $oldest === null ? null : max(0, $now->getTimestamp() - $oldest->getTimestamp()),
            $sentCount,
            $failedCount
        );
    }

    public function pruneSentBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        $removed = 0;

        foreach ($this->records as $id => $record) {
            if (
                $record->status === MailQueueStatus::SENT
                && $record->sentAt !== null
                && $record->sentAt < $cutoff
            ) {
                unset($this->records[$id]);
                ++$removed;

                if ($removed >= $limit) {
                    break;
                }
            }
        }

        return $removed;
    }

    private function isCurrentClaim(MailQueueRecord $claim): bool
    {
        $current = $this->records[$claim->id] ?? null;

        return $current !== null
            && $current->status === MailQueueStatus::SENDING
            && $current->attempts === $claim->attempts
            && $current->updatedAt == $claim->updatedAt;
    }
}
