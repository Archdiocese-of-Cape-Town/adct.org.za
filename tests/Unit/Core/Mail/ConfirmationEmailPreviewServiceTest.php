<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Mail;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailBatch;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailCandidate;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailOutcome;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailPreviewService;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailQueueConflictException;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailReason;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailRenderer;
use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Mail\MailQueueRecord;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\ConfirmationActionLinkProviderInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\Core\Support\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ConfirmationEmailPreviewServiceTest extends TestCase
{
    public function testQueuesOneEmailWithEveryCandidateAndDistinctBoundActionTokens(): void
    {
        $stored = null;
        $queue = $this->queueMock(static function () use (&$stored): ?MailQueueRecord {
            return $stored;
        });
        $mailer = new RecordingConfirmationMailer();
        $mailer->afterEnqueue = static function (OutboundEmail $email, MailQueueEnqueueResult $result) use (&$stored): void {
            $stored = new MailQueueRecord(
                $result->id,
                $email,
                $result->status,
                0,
                new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg')),
                new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg'))
            );
        };
        $tokens = new InMemoryConfirmationActionTokenStore();
        $service = $this->service($queue, $mailer, $tokens);
        $batch = $this->batch([
            $this->candidate(101, 'Harvest lunch'),
            $this->candidate(102, 'Evening prayer'),
        ]);

        $result = $service->enqueuePreview($batch);

        self::assertSame(ConfirmationEmailOutcome::QUEUED, $result->outcome);
        self::assertSame(17, $result->queueId);
        self::assertCount(1, $mailer->emails);
        self::assertStringContainsString('Harvest lunch', $mailer->emails[0]->htmlBody);
        self::assertStringContainsString('Evening prayer', $mailer->emails[0]->htmlBody);
        self::assertSame('confirmation:901', $mailer->emails[0]->groupKey);
        self::assertNotNull($mailer->emails[0]->payloadFingerprint);

        $bindings = array_map(
            static fn (ActionTokenRecord $record): array => [
                $record->binding->purpose,
                $record->binding->subjectType,
                $record->binding->subjectId,
                $record->binding->email,
            ],
            array_values($tokens->records)
        );

        self::assertCount(7, $bindings);
        self::assertCount(3, array_filter(
            $bindings,
            static fn (array $binding): bool => $binding[1] === 'event_candidate' && $binding[2] === 101
        ));
        self::assertCount(3, array_filter(
            $bindings,
            static fn (array $binding): bool => $binding[1] === 'event_candidate' && $binding[2] === 102
        ));
        self::assertContains(
            [ActionTokenPurpose::CONFIRM, 'inbound_message', 901, 'sender@example.test'],
            $bindings
        );

        foreach ($bindings as $binding) {
            self::assertSame('sender@example.test', $binding[3]);
        }
    }

    public function testUsesThePersistedQueueStatusAfterImmediateDispatch(): void
    {
        $stored = null;
        $queue = $this->queueMock(static function () use (&$stored): ?MailQueueRecord {
            return $stored;
        });
        $mailer = new RecordingConfirmationMailer();
        $this->recordEnqueuedMail($mailer, $stored, MailQueueStatus::SENT);
        $service = $this->service($queue, $mailer, new InMemoryConfirmationActionTokenStore());

        $result = $service->enqueuePreview($this->batch([
            $this->candidate(101, 'Harvest lunch'),
        ]));

        self::assertSame(ConfirmationEmailOutcome::SENT, $result->outcome);
        self::assertSame(17, $result->queueId);
        self::assertCount(1, $mailer->emails);
    }

    public function testExistingMatchingGroupKeyDoesNotIssueMoreTokensOrEnqueueAnotherEmail(): void
    {
        $stored = null;
        $queue = $this->queueMock(static function () use (&$stored): ?MailQueueRecord {
            return $stored;
        });
        $mailer = new RecordingConfirmationMailer();
        $mailer->afterEnqueue = static function (OutboundEmail $email, MailQueueEnqueueResult $result) use (&$stored): void {
            $stored = new MailQueueRecord(
                $result->id,
                $email,
                $result->status,
                0,
                new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg')),
                new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg'))
            );
        };
        $tokens = new InMemoryConfirmationActionTokenStore();
        $service = $this->service($queue, $mailer, $tokens);
        $batch = $this->batch([$this->candidate(101, 'Harvest lunch')]);

        $first = $service->enqueuePreview($batch);
        $tokenCount = count($tokens->records);
        $second = $service->enqueuePreview($batch);

        self::assertSame(ConfirmationEmailOutcome::QUEUED, $first->outcome);
        self::assertSame(ConfirmationEmailOutcome::QUEUED, $second->outcome);
        self::assertSame($first->queueId, $second->queueId);
        self::assertCount(1, $mailer->emails);
        self::assertSame($tokenCount, count($tokens->records));
    }

    public function testExistingGroupKeyWithMissingOrChangedFingerprintIsAnExplicitConflict(): void
    {
        $existingEmail = $this->existingEmail(null);
        $existing = $this->record($existingEmail);
        $queue = $this->queueMock(static fn (): MailQueueRecord => $existing);
        $mailer = new RecordingConfirmationMailer();
        $tokens = new InMemoryConfirmationActionTokenStore();
        $service = $this->service($queue, $mailer, $tokens);

        $this->expectException(ConfirmationEmailQueueConflictException::class);
        $service->enqueuePreview($this->batch([$this->candidate(101, 'Changed title')]));
    }

    public function testBlockedAutomatedAndUnsafeSendersAreSuppressedBeforeTokensOrQueueing(): void
    {
        $queue = $this->queueMock(static fn (): ?MailQueueRecord => null);
        $mailer = new RecordingConfirmationMailer();
        $tokens = new InMemoryConfirmationActionTokenStore();
        $service = $this->service($queue, $mailer, $tokens);

        $blocked = $service->enqueuePreview($this->batch(
            [$this->candidate(101, 'Harvest lunch')],
            senderTrust: SenderTrust::BLOCKED
        ));
        $automated = $service->enqueuePreview($this->batch(
            [$this->candidate(102, 'Evening prayer')],
            automatedOrList: true
        ));
        $unsafe = $service->enqueuePreview($this->batch(
            [$this->candidate(103, 'Retreat')],
            senderEmail: 'no-reply@example.test'
        ));

        self::assertSame(ConfirmationEmailReason::BLOCKED_SENDER, $blocked->reason);
        self::assertSame(ConfirmationEmailReason::AUTOMATED_OR_LIST, $automated->reason);
        self::assertSame(ConfirmationEmailReason::NO_SAFE_RECIPIENT, $unsafe->reason);
        self::assertSame(ConfirmationEmailOutcome::SUPPRESSED, $blocked->outcome);
        self::assertSame(ConfirmationEmailOutcome::SUPPRESSED, $automated->outcome);
        self::assertSame(ConfirmationEmailOutcome::SUPPRESSED, $unsafe->outcome);
        self::assertSame([], $mailer->emails);
        self::assertSame([], $tokens->records);
    }

    public function testOnlyVerifiedSafeReplyToReplacesTheSenderRecipient(): void
    {
        $stored = null;
        $queue = $this->queueMock(static function () use (&$stored): ?MailQueueRecord {
            return $stored;
        });
        $mailer = new RecordingConfirmationMailer();
        $this->recordEnqueuedMail($mailer, $stored);
        $tokens = new InMemoryConfirmationActionTokenStore();
        $service = $this->service($queue, $mailer, $tokens);
        $batch = $this->batch(
            [$this->candidate(101, 'Harvest lunch')],
            replyToEmail: 'parish-contact@example.test',
            replyToTrust: SenderTrust::VERIFIED
        );

        $result = $service->enqueuePreview($batch);

        self::assertSame(ConfirmationEmailOutcome::QUEUED, $result->outcome);
        self::assertSame('parish-contact@example.test', $mailer->emails[0]->recipient);

        foreach ($tokens->records as $record) {
            self::assertSame('parish-contact@example.test', $record->binding->email);
        }
    }

    public function testUnverifiedReplyToFallsBackToSenderAndTestModeSuppressionIsRecorded(): void
    {
        $stored = null;
        $queue = $this->queueMock(static function () use (&$stored): ?MailQueueRecord {
            return $stored;
        });
        $mailer = new RecordingConfirmationMailer(MailQueueStatus::SUPPRESSED);
        $this->recordEnqueuedMail($mailer, $stored);
        $tokens = new InMemoryConfirmationActionTokenStore();
        $service = $this->service($queue, $mailer, $tokens);
        $batch = $this->batch(
            [$this->candidate(101, 'Harvest lunch')],
            replyToEmail: 'unverified@example.test',
            replyToTrust: SenderTrust::UNKNOWN
        );

        $result = $service->enqueuePreview($batch);

        self::assertSame(ConfirmationEmailOutcome::SUPPRESSED, $result->outcome);
        self::assertSame(ConfirmationEmailReason::TEST_MODE, $result->reason);
        self::assertSame('sender@example.test', $mailer->emails[0]->recipient);
        self::assertCount(4, $tokens->records);
    }

    /**
     * @param list<ConfirmationEmailCandidate> $candidates
     */
    private function batch(
        array $candidates,
        ?string $senderEmail = 'sender@example.test',
        string $senderTrust = SenderTrust::UNKNOWN,
        ?string $replyToEmail = null,
        string $replyToTrust = SenderTrust::UNKNOWN,
        bool $automatedOrList = false
    ): ConfirmationEmailBatch {
        return new ConfirmationEmailBatch(
            901,
            4,
            $senderEmail,
            'Example Sender',
            'Event notice',
            new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg')),
            $replyToEmail,
            $senderTrust,
            $replyToTrust,
            $automatedOrList,
            '<original-901@example.test>',
            $candidates
        );
    }

    private function candidate(int $id, string $title): ConfirmationEmailCandidate
    {
        return new ConfirmationEmailCandidate(
            $id,
            [
                'title' => $title,
                'event_date' => '2026-10-12',
                'event_time' => '18:30',
                'venue' => 'St Example Hall',
                'parish_name' => 'St Example Parish',
                'description' => 'A parish gathering.',
            ],
            [],
            0.91,
            []
        );
    }

    private function service(
        MailQueueRepositoryInterface $queue,
        RecordingConfirmationMailer $mailer,
        InMemoryConfirmationActionTokenStore $tokens
    ): ConfirmationEmailPreviewService {
        return new ConfirmationEmailPreviewService(
            new ActionTokenService($tokens, new SystemClock()),
            new TestConfirmationActionLinkProvider(),
            $mailer,
            $queue,
            new ConfirmationEmailRenderer()
        );
    }

    private function recordEnqueuedMail(
        RecordingConfirmationMailer $mailer,
        ?MailQueueRecord &$stored,
        ?MailQueueStatus $status = null
    ): void {
        $mailer->afterEnqueue = static function (
            OutboundEmail $email,
            MailQueueEnqueueResult $result
        ) use (&$stored, $status): void {
            $now = new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg'));
            $stored = new MailQueueRecord(
                $result->id,
                $email,
                $status ?? $result->status,
                0,
                $now,
                $now
            );
        };
    }

    private function queueMock(callable $lookup): MailQueueRepositoryInterface
    {
        $queue = $this->createMock(MailQueueRepositoryInterface::class);
        $queue->method('findByRecipientAndGroupKey')->willReturnCallback($lookup);

        return $queue;
    }

    private function existingEmail(?string $fingerprint): OutboundEmail
    {
        return new OutboundEmail(
            'sender@example.test',
            'Existing preview',
            '<p>Existing preview</p>',
            'Existing preview',
            \ADCT\ParishIntake\Core\Mail\MailPriority::LOGIN_OR_CONFIRMATION,
            'confirmation:901',
            null,
            $fingerprint
        );
    }

    private function record(OutboundEmail $email): MailQueueRecord
    {
        $now = new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg'));

        return new MailQueueRecord(
            18,
            $email,
            MailQueueStatus::QUEUED,
            0,
            $now,
            $now
        );
    }
}

final class InMemoryConfirmationActionTokenStore implements ActionTokenStoreInterface
{
    /**
     * @var array<string, ActionTokenRecord>
     */
    public array $records = [];

    public function create(ActionTokenRecord $record): void
    {
        $this->records[$record->tokenHash] = $record;
    }

    public function findByHash(string $tokenHash): ?ActionTokenRecord
    {
        return $this->records[$tokenHash] ?? null;
    }

    public function consume(
        string $tokenHash,
        ActionTokenBinding $binding,
        DateTimeImmutable $now
    ): bool {
        $record = $this->records[$tokenHash] ?? null;

        if ($record === null || ! $record->binding->equals($binding) || $record->usedAt !== null) {
            return false;
        }

        $this->records[$tokenHash] = new ActionTokenRecord(
            $record->tokenHash,
            $record->binding,
            $record->expiresAt,
            $now,
            $record->createdAt
        );

        return true;
    }
}

final class TestConfirmationActionLinkProvider implements ConfirmationActionLinkProviderInterface
{
    public function urlForToken(string $token): string
    {
        return 'https://adct.example.test/action?token=' . rawurlencode($token);
    }
}

final class RecordingConfirmationMailer implements MailerInterface
{
    /**
     * @var list<OutboundEmail>
     */
    public array $emails = [];

    /** @var null|callable(OutboundEmail, MailQueueEnqueueResult): void */
    public $afterEnqueue = null;

    private int $nextId = 17;

    public function __construct(private readonly MailQueueStatus $status = MailQueueStatus::QUEUED)
    {
    }

    public function enqueue(OutboundEmail $email): MailQueueEnqueueResult
    {
        $this->emails[] = $email;
        $result = new MailQueueEnqueueResult($this->nextId++, $this->status, false);

        if (is_callable($this->afterEnqueue)) {
            ($this->afterEnqueue)($email, $result);
        }

        return $result;
    }
}
