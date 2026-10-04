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
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailComposer;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailRenderer;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendCooldownException;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendOutcome;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendResult;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendService;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendSlot;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Mail\MailQueueRecord;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\ConfirmationActionLinkProviderInterface;
use ADCT\ParishIntake\Core\Ports\ConfirmationEmailResendBookingInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * The resend path for issue #176.
 *
 * The cooldown is exercised through a fake booking that behaves like the real
 * transaction rather than by stubbing the service's own exception, because the
 * property worth proving is that the service *asks the booking on every call*
 * and never keeps its own answer. A service that cached "I already sent this" in
 * memory would pass against a mock that always throws and fail against a real
 * site, where two reviewers share one database.
 */
final class ConfirmationEmailResendServiceTest extends TestCase
{
    private const ZONE = 'Africa/Johannesburg';

    public function testQueuesTheConfirmationRenderedFromTheCandidatesCurrentFields(): void
    {
        $clock = $this->clock('2026-10-12 09:15:00');
        $mailer = new ResendMailer();
        $service = $this->service($clock, $mailer, $this->tokens(), $this->queueFor($mailer), new ResendBooking());

        $result = $service->resend(101, 'reviewer@example.test');

        self::assertSame(ConfirmationEmailResendOutcome::QUEUED, $result->outcome);
        self::assertSame('parish@example.test', $result->recipient);
        self::assertCount(1, $mailer->emails, 'Exactly one confirmation email per resend.');
        self::assertStringContainsString('Harvest lunch', $mailer->emails[0]->htmlBody);
        self::assertNotNull($mailer->emails[0]->payloadFingerprint);
    }

    /**
     * A parish whose original notice carried no usable `Message-ID` must still get
     * its preview resent.
     *
     * Without reply headers the email simply is not threaded; it is not a reason to
     * refuse the send. This is not hypothetical: the resend re-reads the header
     * block from the raw message, and a plain-text notice with no `Message-ID` line
     * is entirely ordinary.
     */
    public function testAMessageWithNoUsableMessageIdIsStillResentJustUnthreaded(): void
    {
        $clock = $this->clock('2026-10-12 09:15:00');
        $mailer = new ResendMailer();
        $booking = new ResendBooking();
        $booking->originalMessageId = 'not-a-message-id';
        $service = $this->service($clock, $mailer, $this->tokens(), $this->queueFor($mailer), $booking);

        $result = $service->resend(101, 'reviewer@example.test');

        self::assertSame(ConfirmationEmailResendOutcome::QUEUED, $result->outcome);
        self::assertCount(1, $mailer->emails, 'The parish still gets the preview.');
        self::assertNull(
            $mailer->emails[0]->threadHeaders,
            'With no usable Message-ID the email goes out unthreaded rather than not at all.'
        );
    }

    public function testTheConfirmationGoesThroughTheQueueRatherThanStraightOut(): void
    {
        $clock = $this->clock('2026-10-12 09:15:00');
        $mailer = new ResendMailer();
        $service = $this->service($clock, $mailer, $this->tokens(), $this->queueFor($mailer), new ResendBooking());

        $service->resend(101, 'reviewer@example.test');

        // ADR 0011: the 500-emails-per-hour account budget only holds while
        // nothing bypasses the queue, so the queued row is the only record.
        self::assertCount(1, $mailer->enqueued);
        self::assertSame(MailPriority::LOGIN_OR_CONFIRMATION, $mailer->emails[0]->priority);
    }

    public function testUsesAFreshGroupKeyPerPressSoTheQueueCannotSwallowAResendAsADuplicate(): void
    {
        $clock = $this->clock('2026-10-12 09:15:00');
        $mailer = new ResendMailer();
        $service = $this->service($clock, $mailer, $this->tokens(), $this->queueFor($mailer), new ResendBooking());

        $service->resend(101, 'reviewer@example.test');
        $clock->advance(1);
        $service->resend(101, 'reviewer@example.test');

        self::assertCount(2, $mailer->emails);
        self::assertNotSame(
            $mailer->emails[0]->groupKey,
            $mailer->emails[1]->groupKey,
            'A resend must not reuse a group key, or the queue treats it as the same email and drops it.'
        );
        self::assertMatchesRegularExpression('/\Aconfirmation-resend:101:\d{14}\z/D', $mailer->emails[0]->groupKey);
    }

    public function testMintsFreshActionTokensForEveryResend(): void
    {
        $clock = $this->clock('2026-10-12 09:15:00');
        $mailer = new ResendMailer();
        $tokens = $this->tokens();
        $service = $this->service($clock, $mailer, $tokens, $this->queueFor($mailer), new ResendBooking());

        $service->resend(101, 'reviewer@example.test');
        $afterFirst = array_keys($tokens->records);
        $clock->advance(2);
        $service->resend(101, 'reviewer@example.test');
                $newlyIssued = array_slice(array_keys($tokens->records), count($afterFirst));

                // One message-level confirm link plus confirm/deny/edit per candidate.
                self::assertCount(4, $afterFirst);
                self::assertCount(4, $newlyIssued);
                // The store is append-only, so the second batch must be disjoint from the
                // first: nothing is reused, so a link copied out of the older email cannot
                // be replayed against the newer one, and each token stays single-use itself.
                self::assertSame([], array_intersect($afterFirst, $newlyIssued));

        $purposes = array_map(
            static fn (ActionTokenRecord $record): ActionTokenPurpose => $record->binding->purpose,
            array_values($tokens->records)
        );

        self::assertContains(ActionTokenPurpose::CONFIRM, $purposes);
        self::assertContains(ActionTokenPurpose::DENY, $purposes);
        self::assertContains(ActionTokenPurpose::EDIT, $purposes);
    }

    public function testEveryResentTokenIsBoundToTheParishAndExpires(): void
    {
        $clock = $this->clock('2026-10-12 09:15:00');
        $mailer = new ResendMailer();
        $tokens = $this->tokens();
        $service = $this->service($clock, $mailer, $tokens, $this->queueFor($mailer), new ResendBooking());

        $service->resend(101, 'reviewer@example.test');

        foreach (array_values($tokens->records) as $record) {
            self::assertSame('parish@example.test', $record->binding->email);
            self::assertGreaterThan(
                $record->createdAt->getTimestamp(),
                $record->expiresAt->getTimestamp(),
                'A confirmation action token must expire in the future.'
            );
            self::assertNull($record->usedAt);
        }
    }

    public function testNothingIsQueuedWhenTheBookingRefuses(): void
    {
        $clock = $this->clock('2026-10-12 09:15:00');
        $mailer = new ResendMailer();
        $tokens = $this->tokens();
        $booking = new ResendBooking();
        $booking->refuseWith = new DomainException('This candidate has no saved details yet.');
        $service = $this->service($clock, $mailer, $tokens, $this->queueFor($mailer), $booking);

        try {
            $service->resend(101, 'reviewer@example.test');
            self::fail('A refused booking must not produce a resend.');
        } catch (DomainException $refusal) {
            self::assertSame('This candidate has no saved details yet.', $refusal->getMessage());
        }

        self::assertSame([], $mailer->emails, 'Nothing may be queued when the slot was refused.');
        self::assertSame([], $tokens->records, 'No token may be issued when the slot was refused.');
    }

    public function testACooldownRefusalCarriesThePreviousAndRetryTimesAndSendsNothing(): void
    {
        $clock = $this->clock('2026-10-12 11:00:00');
        $mailer = new ResendMailer();
        $booking = new ResendBooking();
        $booking->cooldownFor = new DateTimeImmutable('2026-10-12 10:00:00', new DateTimeZone(self::ZONE));
        $service = $this->service($clock, $mailer, $this->tokens(), $this->queueFor($mailer), $booking);

        try {
            $service->resend(101, 'reviewer@example.test');
            self::fail('The cooldown should have refused the resend.');
        } catch (ConfirmationEmailResendCooldownException $refusal) {
            self::assertSame(
                '2026-10-12 10:00:00',
                $refusal->lastResentAt->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d H:i:s')
            );
            self::assertSame(
                '2026-10-12 11:00:00',
                $refusal->retryAfter->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d H:i:s')
            );
        }

        self::assertSame([], $mailer->emails);
    }

    public function testTheCooldownWindowIsOneHour(): void
    {
        self::assertSame(3600, ConfirmationEmailResendService::COOLDOWN_SECONDS);
    }

    public function testTheCooldownIsDelegatedToTheBookingOnEveryCall(): void
    {
        $clock = $this->clock('2026-10-12 09:15:00');
        $mailer = new ResendMailer();
        $booking = new ResendBooking();
        $service = $this->service($clock, $mailer, $this->tokens(), $this->queueFor($mailer), $booking);

        $service->resend(101, 'reviewer@example.test');

                // The second press arrives inside the same second, so the booking is asked
                // again and this time refuses: that is the real transaction, which has
                // already written the audit row the first press produced.
                $booking->refuseWith = new ConfirmationEmailResendCooldownException(
                    new DateTimeImmutable('2026-10-12 09:15:00', new DateTimeZone(self::ZONE)),
                    new DateTimeImmutable('2026-10-12 10:15:00', new DateTimeZone(self::ZONE)),
                    101
                );

                try {
                    $service->resend(101, 'reviewer@example.test');
                    self::fail('The booking should have refused the second press.');
                } catch (ConfirmationEmailResendCooldownException) {
                    // Expected: the guard is not this class's to apply.
                }

                self::assertSame(
                    [
                        ['candidateId' => 101, 'actor' => 'reviewer@example.test', 'cooldownSeconds' => 3600],
                        ['candidateId' => 101, 'actor' => 'reviewer@example.test', 'cooldownSeconds' => 3600],
                    ],
                    $booking->claims
                );
                self::assertCount(1, $mailer->emails, 'The refused press queues nothing.');
            }

    public function testReportsWhenTheNextResendIsAllowed(): void
    {
        $booking = new ResendBooking();
        $booking->historyFor = new DateTimeImmutable('2026-10-12 08:00:00', new DateTimeZone(self::ZONE));
        $service = $this->service(
            $this->clock('2026-10-12 09:15:00'),
            new ResendMailer(),
            $this->tokens(),
            null,
            $booking
        );

        self::assertSame(
            '2026-10-12 09:00:00',
            $service->nextAllowedAt(101)?->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-10-12 08:00:00',
            $service->lastResentAt(101)?->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d H:i:s')
        );
    }

    public function testANeverResentCandidateHasNoNextAllowedTime(): void
    {
        $service = $this->service(
            $this->clock('2026-10-12 09:15:00'),
            new ResendMailer(),
            $this->tokens(),
            null,
            new ResendBooking()
        );

        self::assertNull($service->nextAllowedAt(101));
        self::assertNull($service->lastResentAt(101));
    }

    public function testTheInjectedClockIsWhatTheScreenComparesAgainst(): void
    {
        $clock = $this->clock('2026-10-12 09:15:00');
        $service = $this->service($clock, new ResendMailer(), $this->tokens(), null, new ResendBooking());

        self::assertSame('2026-10-12 09:15:00', $service->now()->format('Y-m-d H:i:s'));
        $clock->advance(7200);
        self::assertSame('2026-10-12 11:15:00', $service->now()->format('Y-m-d H:i:s'));
    }

    public function testTheReviewerNoticeUsesDayFirstDatesAndTheSuppliedTimezone(): void
    {
        $timezone = new DateTimeZone(self::ZONE);
        $result = new ConfirmationEmailResendResult(
            ConfirmationEmailResendOutcome::QUEUED,
            101,
            'parish@example.test',
            17,
            new DateTimeImmutable('2026-10-12 08:00:00', $timezone),
            new DateTimeImmutable('2026-10-12 10:15:00', $timezone),
            new DateTimeImmutable('2026-10-12 09:15:00', $timezone)
        );

        $notice = $result->notice($timezone);

        self::assertStringContainsString('parish@example.test', $notice);
        self::assertStringContainsString('12/10/2026 09:15', $notice);
        self::assertStringContainsString('12/10/2026 10:15', $notice);
    }

    public function testTheNoticeExplainsEveryOutcomeToTheReviewer(): void
    {
        $timezone = new DateTimeZone(self::ZONE);
        $sentAt = new DateTimeImmutable('2026-10-12 09:15:00', $timezone);
        $next = new DateTimeImmutable('2026-10-12 10:15:00', $timezone);
        $notices = [];

        foreach (ConfirmationEmailResendOutcome::cases() as $outcome) {
                    $notices[$outcome->name] = (new ConfirmationEmailResendResult(
                $outcome,
                101,
                'parish@example.test',
                17,
                null,
                $next,
                $sentAt
            ))->notice($timezone);

            self::assertStringContainsString(
                '12/10/2026 10:15',
                        $notices[$outcome->name],
                'A reviewer must always be told when they may resend again.'
            );
        }

                self::assertStringContainsString('queued', $notices['QUEUED']);
                self::assertStringContainsString('sent to', $notices['SENT']);
                self::assertStringContainsString('test mode', $notices['SUPPRESSED']);
                self::assertStringContainsString('could not be delivered', $notices['FAILED']);
    }

    public function testTheOutcomeMirrorsTheStoredQueueRow(): void
    {
        self::assertSame(
            ConfirmationEmailResendOutcome::SENT,
            ConfirmationEmailResendOutcome::fromQueueStatus(MailQueueStatus::SENT)
        );
        self::assertSame(
            ConfirmationEmailResendOutcome::SUPPRESSED,
            ConfirmationEmailResendOutcome::fromQueueStatus(MailQueueStatus::SUPPRESSED)
        );
                self::assertSame(
                    ConfirmationEmailResendOutcome::FAILED,
                    ConfirmationEmailResendOutcome::fromQueueStatus(MailQueueStatus::FAILED)
                );
                self::assertSame(
                    ConfirmationEmailResendOutcome::QUEUED,
                    ConfirmationEmailResendOutcome::fromQueueStatus(MailQueueStatus::SENDING)
                );
            }

            public function testOnlyAnOutcomeThatLeavesTheQueueReachedTheParish(): void
            {
                // A suppressed test-mode row and a failed row never arrive; a queued row
                // is on its way, which is why a queued resend still counts as reaching the
                // parish and can be reported as such rather than as a warning.
                self::assertTrue(ConfirmationEmailResendOutcome::QUEUED->reachedParish());
                self::assertTrue(ConfirmationEmailResendOutcome::SENT->reachedParish());
                self::assertFalse(ConfirmationEmailResendOutcome::SUPPRESSED->reachedParish());
                self::assertFalse(ConfirmationEmailResendOutcome::FAILED->reachedParish());
            }

    private function clock(string $time): ResendClock
    {
        return new ResendClock(new DateTimeImmutable($time, new DateTimeZone(self::ZONE)));
    }

    private function tokens(): ResendTokenStore
    {
        return new ResendTokenStore();
    }

    private function service(
        ResendClock $clock,
        MailerInterface $mailer,
        ResendTokenStore $tokens,
        ?MailQueueRepositoryInterface $queue,
        ConfirmationEmailResendBookingInterface $booking
    ): ConfirmationEmailResendService {
        $composer = new ConfirmationEmailComposer(
            new ActionTokenService($tokens, $clock),
            new ResendLinkProvider(),
            new ConfirmationEmailRenderer()
        );

        return new ConfirmationEmailResendService(
            $clock,
            $mailer,
            $composer,
            $queue ?? $this->queueFor($mailer),
            $booking,
            new DateTimeZone(self::ZONE)
        );
    }

    /**
     * A queue that hands back the row the real repository would have written for
     * whatever the mailer recorded.
     */
    private function queueFor(MailerInterface $mailer): MailQueueRepositoryInterface
    {
        $queue = $this->createMock(MailQueueRepositoryInterface::class);
        $queue->method('findByRecipientAndGroupKey')->willReturnCallback(
            static fn (string $recipient, string $groupKey): ?MailQueueRecord => $mailer->rowFor(
                $recipient,
                $groupKey
            )
        );

        return $queue;
    }
}

final class ResendClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $current)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(int $seconds): void
    {
        $this->current = $this->current->modify('+' . $seconds . ' seconds');
    }
}

/**
 * Stands in for the booking transaction.
 *
 * Records every claim, so a test can prove the service asked with the right window
 * on every call, and can be told to refuse so the refusal path is exercised
 * without re-implementing the repository's cooldown here.
 */
final class ResendBooking implements ConfirmationEmailResendBookingInterface
{
    /**
     * @var list<array{candidateId: int, actor: string, cooldownSeconds: int}>
     */
    public array $claims = [];

    public ?DateTimeImmutable $historyFor = null;
    public ?DateTimeImmutable $cooldownFor = null;
    public ?DomainException $refuseWith = null;
    public ?ConfirmationEmailBatch $batch = null;
    public ?string $originalMessageId = null;

    public function claimResendSlot(
        int $candidateId,
        string $actor,
        DateTimeImmutable $now,
        int $cooldownSeconds
    ): ConfirmationEmailResendSlot {
        $this->claims[] = [
            'candidateId' => $candidateId,
            'actor' => $actor,
            'cooldownSeconds' => $cooldownSeconds,
        ];

        if ($this->refuseWith !== null) {
            throw $this->refuseWith;
        }

        if ($this->cooldownFor !== null) {
            throw new ConfirmationEmailResendCooldownException(
                $this->cooldownFor,
                $this->cooldownFor->modify('+' . $cooldownSeconds . ' seconds'),
                $candidateId
            );
        }

        return new ConfirmationEmailResendSlot(
            $this->batch ?? $this->defaultBatch($this->originalMessageId),
            $this->historyFor
        );
    }

    public function lastResentAt(int $candidateId): ?DateTimeImmutable
    {
        return $this->historyFor;
    }

    private function defaultBatch(?string $originalMessageId = null): ConfirmationEmailBatch
    {
        $originalMessageId ??= '<original-901@example.test>';

        $timezone = new DateTimeZone('Africa/Johannesburg');

        return new ConfirmationEmailBatch(
            901,
            55,
            'parish@example.test',
            'St Example Parish',
            'Event notice',
            new DateTimeImmutable('2026-10-01 12:00:00', $timezone),
            null,
            SenderTrust::VERIFIED,
            SenderTrust::UNKNOWN,
            false,
            $originalMessageId,
            [
                new ConfirmationEmailCandidate(
                    101,
                    [
                        'title' => 'Harvest lunch',
                        'event_date' => '2026-10-12',
                        'event_time' => '18:30',
                        'venue' => 'St Example Hall',
                        'parish_name' => 'St Example Parish',
                        'description' => 'A parish gathering.',
                    ],
                    [],
                    0.91,
                    []
                ),
            ]
        );
    }
}

final class ResendTokenStore implements ActionTokenStoreInterface
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

    public function consume(string $tokenHash, ActionTokenBinding $binding, DateTimeImmutable $now): bool
    {
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

final class ResendLinkProvider implements ConfirmationActionLinkProviderInterface
{
    public function urlForToken(string $token): string
    {
        return 'https://adct.example.test/action?token=' . rawurlencode($token);
    }
}

final class ResendMailer implements MailerInterface
{
    /**
     * @var list<OutboundEmail>
     */
    public array $emails = [];

    /**
     * @var list<MailQueueEnqueueResult>
     */
    public array $enqueued = [];

    public function __construct(private readonly MailQueueStatus $status = MailQueueStatus::QUEUED)
    {
    }

    public function enqueue(OutboundEmail $email): MailQueueEnqueueResult
    {
        $this->emails[] = $email;
        $result = new MailQueueEnqueueResult(16 + count($this->emails), $this->status, false);
        $this->enqueued[] = $result;

        return $result;
    }

    public function rowFor(string $recipient, string $groupKey): ?MailQueueRecord
    {
        foreach ($this->emails as $index => $email) {
            if ($email->recipient !== $recipient || $email->groupKey !== $groupKey) {
                continue;
            }

            $now = new DateTimeImmutable('2026-10-12 09:15:00', new DateTimeZone('Africa/Johannesburg'));

            return new MailQueueRecord(17 + $index, $email, $this->status, 0, $now, $now);
        }

        return null;
    }
}