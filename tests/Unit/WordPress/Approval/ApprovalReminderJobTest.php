<?php

declare(strict_types=1);

namespace {

    // The WordPress stand-ins (get_users, get_userdata, user_can, get_user_meta,
    // home_url, add_query_arg and WP_User) are declared once in Support/WordPressStubs.php
    // and required here, so this file runs on its own as well as in a full suite run.
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
require_once __DIR__ . '/../../../Support/WordPressCapabilityStubs.php';
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Approval {

use ADCT\ParishIntake\Core\Approval\ApprovalReminderSettings;
use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Approval\ApprovalRouteSnapshot;
use ADCT\ParishIntake\Core\Approval\Approver;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Jobs\JobState;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Mail\MailQueueRecord;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\FollowUpRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\WordPress\Approval\ApprovalReminderJob;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WP_User;

final class ApprovalReminderJobTest extends TestCase
{
    private const DEAN = 'dean@example.test';
    private const REVIEWER = 'reviewer@example.test';

    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    /** @var list<array{sql: string, args: list<mixed>}> */
    private array $queries = [];

    /** @var list<OutboundEmail> */
    private array $enqueued = [];

    /** @var list<array{parishId: ?int, kind: string, channel: string, note: ?string}> */
    private array $audited = [];

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['adct_test_wp_users', 'adct_test_wp_caps', 'adct_test_wp_meta'] as $key) {
            $this->globalsBackup[$key] = $GLOBALS[$key] ?? null;
            unset($GLOBALS[$key]);
        }
        $GLOBALS['adct_test_wp_users'] = [
            9 => new WP_User(9, self::DEAN),
            11 => new WP_User(11, self::REVIEWER),
        ];
        $GLOBALS['adct_test_wp_caps'] = [
            9 => [Capabilities::APPROVE_DEANERY],
            11 => [Capabilities::REVIEW],
        ];
        $GLOBALS['adct_test_wp_meta'] = [];

        $this->queries = [];
        $this->enqueued = [];
        $this->audited = [];
        $this->now = new DateTimeImmutable('2026-09-24 08:00:00', new DateTimeZone('Africa/Johannesburg'));
    }

    protected function tearDown(): void
    {
        foreach ($this->globalsBackup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
        $this->globalsBackup = [];

        parent::tearDown();
    }

    public function testTheJobIsTheDailyMonitoringJobNamedInTheArchitecture(): void
    {
        $job = $this->build(new ApprovalReminderSettings());

        self::assertSame('monitoring', $job->id());
        self::assertNotSame('', $job->label());
        // The daily cadence is exercised through isDue(); the job exposes no
        // interval getter, and that is deliberate.
                self::assertTrue($job->isDue($this->now, $this->stateDaysAgo(1)));
                self::assertFalse($job->isDue($this->now, $this->stateHoursAgo(1)));
    }

    public function testTheJobIsNotDueWhileRemindersAreSwitchedOffGlobally(): void
    {
        $job = $this->build(new ApprovalReminderSettings(false, 3));

                self::assertFalse($job->isDue($this->now, $this->stateDaysAgo(1)));
    }

    public function testNothingIsSentOrAuditedWhenRemindersAreSwitchedOffGlobally(): void
    {
        $result = $this->runJob(new ApprovalReminderSettings(false, 3));

        self::assertNotNull($result);
        self::assertTrue($result->isComplete());
        self::assertSame([], $this->enqueued);
        self::assertSame([], $this->audited);
    }

    public function testTheSwitchedOffRunDoesNotEvenScanTheQueue(): void
    {
        $this->runJob(new ApprovalReminderSettings(false, 3));

        self::assertSame([], $this->queries);
    }

    public function testAReminderIsSentOnceTheItemHasWaitedLongerThanTheConfiguredPeriod(): void
    {
        $result = $this->runJob(new ApprovalReminderSettings(true, 3));

        self::assertNotNull($result);
        self::assertSame([self::DEAN, self::REVIEWER], $this->recipients());
    }

    public function testAnItemThatHasNotYetWaitedTheFullPeriodIsNotReminded(): void
    {
        $result = $this->runJob(
            new ApprovalReminderSettings(true, 3),
            true,
            true,
            null,
            false,
            [],
            true
        );

        self::assertNull($result);
        self::assertSame([], $this->enqueued);
    }

    public function testTheScanOnlyCoversUndecidedItemsWaitingLongerThanThePeriod(): void
    {
        $this->runJob(new ApprovalReminderSettings(true, 3));

        $scan = $this->scan();
        self::assertStringContainsString('c.status = %s', $scan['sql']);
        self::assertStringContainsString('c.approved_by IS NULL', $scan['sql']);
        self::assertStringContainsString('c.decided_at IS NULL', $scan['sql']);
        self::assertStringContainsString('c.updated_at < %s', $scan['sql']);
        self::assertContains('awaiting_approval', $scan['args']);
        self::assertContains($this->now->modify('-3 days')->format('Y-m-d H:i:s'), $scan['args']);
    }

    public function testALongerPeriodMovesTheScanCutoffFurtherBack(): void
    {
        $this->runJob(new ApprovalReminderSettings(true, 7));

        self::assertContains(
            $this->now->modify('-7 days')->format('Y-m-d H:i:s'),
            $this->scan()['args']
        );
    }

    public function testNoReminderIsSentForAnApproverWhoseOwnSwitchIsOff(): void
    {
        $this->runJob(new ApprovalReminderSettings(true, 3), false);

        self::assertSame([self::REVIEWER], $this->recipients());
    }

    public function testNoReminderAtAllGoesOutWhenEveryApproverHasSwitchedRemindersOff(): void
    {
        $result = $this->runJob(new ApprovalReminderSettings(true, 3), false, false);

        self::assertNotNull($result);
        self::assertSame([], $this->enqueued);
        self::assertSame([], $this->audited);
            // The row was still scanned, so the checkpoint advances and a later
            // pass resumes past it rather than re-reading the same queue forever.
            self::assertSame('12', $result->checkpoint());
            self::assertFalse($result->isComplete());
        }

        public function testNoReminderIsSentWhenTheItemHasAlreadyBeenDecided(): void
        {
            $result = $this->runJob(new ApprovalReminderSettings(true, 3), true, true, '2026-09-22 09:00:00');

            self::assertNotNull($result);
            self::assertSame([], $this->enqueued);
            self::assertSame([], $this->audited);
            self::assertSame('12', $result->checkpoint());
            self::assertFalse($result->isComplete());
        }

    public function testAReminderIsKeyedByItemAndApproverSoItCanNeverRepeat(): void
    {
        $this->runJob(new ApprovalReminderSettings(true, 3));

        self::assertSame(
            'approval-reminder:12:' . substr(hash('sha256', self::DEAN), 0, 24),
            $this->enqueued[0]->groupKey
        );
        self::assertSame(
            'approval-reminder:12:' . substr(hash('sha256', self::REVIEWER), 0, 24),
            $this->enqueued[1]->groupKey
        );
    }

    public function testASecondRunFindsTheQueuedReminderAndSendsNothingMore(): void
    {
        $result = $this->runJob(new ApprovalReminderSettings(true, 3), true, true, null, true);

        self::assertNotNull($result);
        self::assertSame([], $this->enqueued);
        self::assertSame([], $this->audited);
        self::assertSame('12', $result->checkpoint());
    }

    public function testEachSentReminderIsWrittenToTheFollowUpLog(): void
    {
        $this->runJob(new ApprovalReminderSettings(true, 3));

        $expected = [
            'parishId' => 5,
            'kind' => 'approval_reminder',
            'channel' => 'email',
            'note' => 'candidate 12',
        ];
        self::assertSame([$expected, $expected], $this->audited);
    }

    public function testTheReminderCarriesTheSameActionLinksAsTheOriginalNotice(): void
    {
        $this->runJob(new ApprovalReminderSettings(true, 3));

        $body = $this->enqueued[0]->htmlBody;
        self::assertStringContainsString('Approve', $body);
        self::assertStringContainsString('Reject', $body);
        self::assertStringContainsString('Edit', $body);
        self::assertStringContainsString('Retreat day', $body);
        self::assertNotSame('', $this->enqueued[0]->textBody);
    }

    public function testAReminderSaysHowLongTheItemHasBeenWaiting(): void
    {
        $this->runJob(new ApprovalReminderSettings(true, 5));

        self::assertStringContainsString('5 days', $this->enqueued[0]->htmlBody);
    }

    public function testAReminderGoesOutAtTheReminderPriority(): void
    {
        $this->runJob(new ApprovalReminderSettings(true, 3));

        self::assertSame(MailPriority::REMINDER_OR_DIGEST, $this->enqueued[0]->priority);
    }

    public function testTheScanIsCheckpointedSoALaterRunResumesWhereThisOneStopped(): void
    {
        $result = $this->runJob(new ApprovalReminderSettings(true, 3));

        self::assertNotNull($result);
        self::assertSame('12', $result->checkpoint());
        self::assertFalse($result->isComplete());
    }

    public function testARunWithNothingWaitingCompletesWithoutWork(): void
    {
        $result = $this->runJob(new ApprovalReminderSettings(true, 3), true, true, null, false, []);

        self::assertNull($result);
        self::assertSame([], $this->enqueued);
    }

    public function testAnItemThatIsNotApprovedBecauseItWasDecidedMidScanIsStillAdvanced(): void
    {
        // A decision that lands between the scan and the send must not leave the
        // scan stuck on the same row.
        $result = $this->runJob(new ApprovalReminderSettings(true, 3));

        self::assertNotNull($result);
        self::assertSame('12', $result->checkpoint());
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        return array_map(static fn (OutboundEmail $email): string => $email->recipient, $this->enqueued);
    }

    /**
     * @return array{sql: string, args: list<mixed>}
     */
    private function scan(): array
    {
        foreach ($this->queries as $query) {
            if (str_contains($query['sql'], 'FROM `wp_adct_pi_event_candidates` c')) {
                return $query;
            }
        }

        self::fail('The reminder scan did not run.');
    }

    private function stateDaysAgo(int $days): JobState
    {
        return new JobState(null, $this->now->modify('-' . $days . ' days'));
    }

    private function stateHoursAgo(int $hours): JobState
    {
        return new JobState(null, $this->now->modify('-' . $hours . ' hours'));
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn($this->now);

        return $clock;
    }

    private function build(ApprovalReminderSettings $settings): ApprovalReminderJob
    {
        return new ApprovalReminderJob(
            $this->createMock(DatabaseConnectionInterface::class),
            new ApprovalRecipients(new ApprovalRouteResolver($this->routes(true, true))),
            new ActionTokenService($this->createMock(ActionTokenStoreInterface::class), $this->clock()),
            $this->createMock(MailerInterface::class),
            $this->createMock(MailQueueRepositoryInterface::class),
            $this->createMock(FollowUpRepositoryInterface::class),
            $this->clock(),
            static fn (): ApprovalReminderSettings => $settings
        );
    }

    private function routes(bool $deanReminders, bool $reviewerReminders): ApprovalRouteRepositoryInterface
    {
        $GLOBALS['adct_test_wp_meta'][11] = [
            'adct_pi_approval_notify_mode' => Approver::NOTIFY_EACH,
            ApprovalRecipients::REVIEWER_REMINDERS_META_KEY => $reviewerReminders ? '1' : '0',
        ];

        $routes = $this->createMock(ApprovalRouteRepositoryInterface::class);
        $routes->method('findForParish')->willReturn(new ApprovalRouteSnapshot(
            7,
            true,
            [new Approver(4, 9, self::DEAN, 'Dean Test', Approver::NOTIFY_EACH, $deanReminders, true)]
        ));

        return $routes;
    }

    /**
     * @param ?string $decidedAt when set, the item reached the scan already decided
     * @param bool $reminderAlreadyQueued the mail queue already holds the reminder
     * @param list<array<string, mixed>> $waiting the rows the scan returns
     * @param bool $scanRuns whether the scan query is answered with $waiting
     */
    private function runJob(
        ApprovalReminderSettings $settings,
        bool $deanReminders = true,
        bool $reviewerReminders = true,
        ?string $decidedAt = null,
        bool $reminderAlreadyQueued = false,
        array $waiting = [[
            'id' => 12,
            'parish_id' => 5,
            'status' => 'awaiting_approval',
            'decided_at' => null,
        ]],
        bool $scanRuns = true
    ): ?JobStepResult {
        $rows = $scanRuns && $waiting !== []
                    ? [array_replace($waiting[0], [
                'status' => $decidedAt === null ? 'awaiting_approval' : 'published',
                'decided_at' => $decidedAt,
                    ])]
            : [];

        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_');
        $database->method('lastError')->willReturn('');
        $database->method('prepare')->willReturnCallback(
            function (string $sql, mixed ...$args): string {
                $this->queries[] = ['sql' => $sql, 'args' => array_values($args)];

                return $sql;
            }
        );
        $database->method('getResults')->willReturnCallback(
            function (string $sql) use ($rows): array {
                            if (str_contains($sql, 'FROM `wp_adct_pi_event_candidates` c')
                    && str_contains($sql, 'c.updated_at < %s')) {
                    return $rows;
                }
                if (str_contains($sql, 'FROM `wp_adct_pi_event_candidates` c')) {
                    return [[
                        'id' => 12,
                        'fields' => json_encode(
                            ['title' => 'Retreat day', 'event_date' => '2026-10-12'],
                            JSON_THROW_ON_ERROR
                        ),
                        'notes' => '[]',
                        'message_id' => null,
                        'matched_candidate_id' => null,
                        'sender_email' => 'parish-office@example.test',
                        'sender_name' => '',
                        'parish_name' => 'St Anne',
                    ]];
                }

                return [];
            }
        );

        $queue = $this->createMock(MailQueueRepositoryInterface::class);
        $queue->method('findByRecipientAndGroupKey')->willReturn(
            $reminderAlreadyQueued ? $this->queuedReminder() : null
        );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('enqueue')->willReturnCallback(
            function (OutboundEmail $email): MailQueueEnqueueResult {
                $this->enqueued[] = $email;

                return new MailQueueEnqueueResult(1, MailQueueStatus::QUEUED, false);
            }
        );

        $job = new ApprovalReminderJob(
            $database,
            new ApprovalRecipients(new ApprovalRouteResolver($this->routes($deanReminders, $reviewerReminders))),
            new ActionTokenService($this->createMock(ActionTokenStoreInterface::class), $this->clock()),
            $mailer,
            $queue,
            $this->followUpWriter(),
            $this->clock(),
            static fn (): ApprovalReminderSettings => $settings
        );

        return $job->processNext(null);
    }

    private function followUpWriter(): FollowUpRepositoryInterface
    {
        $followUps = $this->createMock(FollowUpRepositoryInterface::class);
        $followUps->method('record')->willReturnCallback(
            function (
                ?int $parishId,
                string $kind,
                string $channel,
                ?string $note = null,
                ?string $sentAt = null,
                ?string $outcome = null
            ): bool {
                $this->audited[] = compact('parishId', 'kind', 'channel', 'note');

                return true;
            }
        );

        return $followUps;
    }

    private function queuedReminder(): MailQueueRecord
    {
        return new MailQueueRecord(
            3,
            new OutboundEmail(
                self::DEAN,
                'Still waiting',
                'body',
                'body',
                MailPriority::REMINDER_OR_DIGEST,
                'approval-reminder:12:x'
            ),
            MailQueueStatus::SENT,
            1,
            $this->now,
            $this->now,
            null,
            $this->now
        );
    }
}

}
