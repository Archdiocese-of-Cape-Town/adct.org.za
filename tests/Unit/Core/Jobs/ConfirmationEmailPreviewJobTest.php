<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Jobs;

use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Ingestion\PermanentInboundHeaderReadException;
use ADCT\ParishIntake\Core\Jobs\ConfirmationEmailPreviewJob;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailBatch;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailCandidate;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailHeaderUnavailableException;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailOutcome;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailPreviewService;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailReason;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailRenderer;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResult;
use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Mail\MailQueueRecord;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\ConfirmationActionLinkProviderInterface;
use ADCT\ParishIntake\Core\Ports\ConfirmationEmailJobSourceInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\Core\Support\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfirmationEmailPreviewJobTest extends TestCase
{
    public function testPermanentHeaderFailureIsRecordedAndDoesNotStarveLaterMessages(): void
    {
        $source = new SequenceConfirmationEmailJobSource([
            new ConfirmationEmailHeaderUnavailableException(
                901,
                new PermanentInboundHeaderReadException('Stored raw message is missing.')
            ),
            $this->batch(902, SenderTrust::BLOCKED),
        ]);
        $job = new ConfirmationEmailPreviewJob(
            $source,
            $this->previewService(),
            new SystemClock()
        );

        self::assertNotNull($job->processNext(null));
        self::assertNotNull($job->processNext(null));
        self::assertNull($job->processNext(null));

        self::assertSame([901, 902], array_column($source->recordedResults, 'messageId'));
        self::assertSame(
            ConfirmationEmailOutcome::FAILED,
            $source->recordedResults[0]['result']->outcome
        );
        self::assertSame(
            ConfirmationEmailReason::RAW_MESSAGE_UNAVAILABLE,
            $source->recordedResults[0]['result']->reason
        );
        self::assertSame(
            ConfirmationEmailReason::BLOCKED_SENDER,
            $source->recordedResults[1]['result']->reason
        );
    }

    public function testChangedReplyToTrustAfterMarkerFailureRecordsConflictWithoutSecondEnqueue(): void
    {
        $stored = null;
        $queue = $this->createMock(MailQueueRepositoryInterface::class);
        $queue->method('findAllByGroupKey')->willReturnCallback(
            static function (string $groupKey) use (&$stored): array {
                return $stored !== null && $stored->email->groupKey === $groupKey
                    ? [$stored]
                    : [];
            }
        );
        $tokenStore = $this->createMock(ActionTokenStoreInterface::class);
        $tokenStore->expects(self::exactly(4))->method('create');
        $mailer = $this->createMock(MailerInterface::class);
        $now = new DateTimeImmutable('2026-10-01 10:00:00', new DateTimeZone('UTC'));
        $mailer->expects(self::once())
            ->method('enqueue')
            ->willReturnCallback(static function (OutboundEmail $email) use (&$stored, $now): MailQueueEnqueueResult {
                $stored = new MailQueueRecord(
                    17,
                    $email,
                    MailQueueStatus::QUEUED,
                    0,
                    $now,
                    $now
                );

                return new MailQueueEnqueueResult(17, MailQueueStatus::QUEUED, false);
            });
        $linkProvider = $this->createMock(ConfirmationActionLinkProviderInterface::class);
        $linkProvider->method('urlForToken')->willReturn('https://adct.example.test/action');
        $previews = new ConfirmationEmailPreviewService(
            new ActionTokenService($tokenStore, new SystemClock()),
            $linkProvider,
            $mailer,
            $queue,
            new ConfirmationEmailRenderer()
        );
        $source = new SequenceConfirmationEmailJobSource(
            [
                $this->batch(
                    903,
                    SenderTrust::UNKNOWN,
                    replyToTrust: SenderTrust::VERIFIED
                ),
                $this->batch(
                    903,
                    SenderTrust::UNKNOWN,
                    replyToTrust: SenderTrust::UNKNOWN
                ),
            ],
            failFirstRecord: true
        );
        $job = new ConfirmationEmailPreviewJob($source, $previews, new SystemClock());

        try {
            $job->processNext(null);
            self::fail('The first inbound confirmation marker write should be uncertain.');
        } catch (RuntimeException $failure) {
            self::assertSame('Simulated inbound marker write failure.', $failure->getMessage());
        }

        self::assertNotNull($job->processNext(null));
        self::assertCount(1, $source->recordedResults);
        self::assertSame(903, $source->recordedResults[0]['messageId']);
        self::assertSame(ConfirmationEmailOutcome::FAILED, $source->recordedResults[0]['result']->outcome);
        self::assertSame(ConfirmationEmailReason::QUEUE_CONFLICT, $source->recordedResults[0]['result']->reason);
        self::assertSame('parish-contact@example.test', $stored?->email->recipient);
    }

    public function testExistingQueueIsRecoveredBeforeBlockedSenderSuppressionAfterMarkerFailure(): void
    {
        $this->assertExistingQueueIsRecoveredAfterPolicyChange(
            SenderTrust::BLOCKED,
            false
        );
    }

    public function testExistingQueueIsRecoveredBeforeAutomatedMailSuppressionAfterMarkerFailure(): void
    {
        $this->assertExistingQueueIsRecoveredAfterPolicyChange(
            SenderTrust::UNKNOWN,
            true
        );
    }

    private function batch(
        int $messageId,
        string $senderTrust,
        string $replyToTrust = SenderTrust::UNKNOWN,
        bool $automatedOrList = false
    ): ConfirmationEmailBatch {
        return new ConfirmationEmailBatch(
            $messageId,
            4,
            'sender@example.test',
            'Example Sender',
            'Event notice',
            new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg')),
            'parish-contact@example.test',
            $senderTrust,
            $replyToTrust,
            $automatedOrList,
            '<inbound-' . $messageId . '@example.test>',
            [
                new ConfirmationEmailCandidate(
                    101,
                    [
                        'title' => 'Example event',
                        'event_date' => '2026-10-12',
                        'event_time' => '18:30',
                    ],
                    [],
                    0.9,
                    []
                ),
            ]
        );
    }

    private function assertExistingQueueIsRecoveredAfterPolicyChange(
        string $retrySenderTrust,
        bool $retryAutomatedOrList
    ): void {
        $stored = null;
        $queue = $this->createMock(MailQueueRepositoryInterface::class);
        $queue->method('findAllByGroupKey')->willReturnCallback(
            static function (string $groupKey) use (&$stored): array {
                return $stored !== null && $stored->email->groupKey === $groupKey
                    ? [$stored]
                    : [];
            }
        );
        $tokenStore = $this->createMock(ActionTokenStoreInterface::class);
        $tokenStore->expects(self::exactly(4))->method('create');
        $mailer = $this->createMock(MailerInterface::class);
        $now = new DateTimeImmutable('2026-10-01 10:00:00', new DateTimeZone('UTC'));
        $mailer->expects(self::once())
            ->method('enqueue')
            ->willReturnCallback(static function (OutboundEmail $email) use (&$stored, $now): MailQueueEnqueueResult {
                $stored = new MailQueueRecord(
                    17,
                    $email,
                    MailQueueStatus::QUEUED,
                    0,
                    $now,
                    $now
                );

                return new MailQueueEnqueueResult(17, MailQueueStatus::QUEUED, false);
            });
        $linkProvider = $this->createMock(ConfirmationActionLinkProviderInterface::class);
        $linkProvider->method('urlForToken')->willReturn('https://adct.example.test/action');
        $previews = new ConfirmationEmailPreviewService(
            new ActionTokenService($tokenStore, new SystemClock()),
            $linkProvider,
            $mailer,
            $queue,
            new ConfirmationEmailRenderer()
        );
        $source = new SequenceConfirmationEmailJobSource(
            [
                $this->batch(904, SenderTrust::UNKNOWN),
                $this->batch(
                    904,
                    $retrySenderTrust,
                    automatedOrList: $retryAutomatedOrList
                ),
            ],
            failFirstRecord: true
        );
        $job = new ConfirmationEmailPreviewJob($source, $previews, new SystemClock());

        try {
            $job->processNext(null);
            self::fail('The first inbound confirmation marker write should be uncertain.');
        } catch (RuntimeException $failure) {
            self::assertSame('Simulated inbound marker write failure.', $failure->getMessage());
        }

        self::assertNotNull($job->processNext(null));
        self::assertCount(1, $source->recordedResults);
        self::assertSame(904, $source->recordedResults[0]['messageId']);
        self::assertSame(ConfirmationEmailOutcome::QUEUED, $source->recordedResults[0]['result']->outcome);
        self::assertSame(17, $source->recordedResults[0]['result']->queueId);
        self::assertSame(MailQueueStatus::QUEUED, $stored?->status);
    }

    private function previewService(): ConfirmationEmailPreviewService
    {
        return new ConfirmationEmailPreviewService(
            new ActionTokenService($this->createMock(ActionTokenStoreInterface::class), new SystemClock()),
            $this->createMock(ConfirmationActionLinkProviderInterface::class),
            $this->createMock(MailerInterface::class),
            $this->createMock(MailQueueRepositoryInterface::class),
            new ConfirmationEmailRenderer()
        );
    }
}

/**
 * @internal
 */
final class SequenceConfirmationEmailJobSource implements ConfirmationEmailJobSourceInterface
{
    /**
     * @var list<ConfirmationEmailBatch|ConfirmationEmailHeaderUnavailableException>
     */
    private array $pending;

    /**
     * @var list<array{messageId: int, result: ConfirmationEmailResult, recordedAt: DateTimeImmutable}>
     */
    public array $recordedResults = [];

    private bool $failFirstRecord;

    /**
     * @param list<ConfirmationEmailBatch|ConfirmationEmailHeaderUnavailableException> $pending
     */
    public function __construct(array $pending, bool $failFirstRecord = false)
    {
        $this->pending = $pending;
        $this->failFirstRecord = $failFirstRecord;
    }

    public function nextPending(): ?ConfirmationEmailBatch
    {
        $next = array_shift($this->pending);

        if ($next instanceof ConfirmationEmailHeaderUnavailableException) {
            throw $next;
        }

        return $next;
    }

    public function recordResult(
        int $messageId,
        ConfirmationEmailResult $result,
        DateTimeImmutable $recordedAt
    ): void {
        if ($this->failFirstRecord) {
            $this->failFirstRecord = false;
            throw new RuntimeException('Simulated inbound marker write failure.');
        }

        $this->recordedResults[] = [
            'messageId' => $messageId,
            'result' => $result,
            'recordedAt' => $recordedAt,
        ];
    }
}
