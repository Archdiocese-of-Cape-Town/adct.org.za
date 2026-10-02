<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth {

    /**
     * Stand-ins so ActionTokenEndpoint::urlForToken() can build links in a
     * test without a WordPress runtime.
     */
    function home_url(string $path = ''): string
    {
        return 'https://adct.example.test' . $path;
    }

    function add_query_arg(string $key, string $value, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . rawurlencode($value);
    }
}

namespace {

    /**
     * Real escaping, not identity, so a regression that drops escaping in the
     * approver email would show up here rather than pass silently.
     */
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    function esc_url(string $url): string
    {
        return str_replace(['"', "'", '<', '>'], ['%22', '%27', '%3C', '%3E'], $url);
    }
}

namespace ADCT\ParishIntake\WordPress\Approval {

    /**
     * Minimal stand-ins for the WordPress user helpers ApprovalRecipients
     * calls. Each test drives them through the globals below and restores
     * them in tearDown.
     */
    function get_users(array $args = []): array
    {
        return $GLOBALS['adct_test_wp_users'] ?? [];
    }

    function get_userdata(int $userId): ?\WP_User
    {
        return ($GLOBALS['adct_test_wp_users'] ?? [])[$userId] ?? null;
    }

    function user_can(\WP_User $user, string $capability): bool
    {
        return in_array($capability, $GLOBALS['adct_test_wp_caps'][$user->ID] ?? [], true);
    }

    function get_user_meta(int $userId, string $key, bool $single = false): string
    {
        return $GLOBALS['adct_test_wp_meta'][$userId][$key] ?? '';
    }
}

namespace {

    /**
     * Stand-in for the WordPress user class ApprovalRecipients type-checks.
     */
    class WP_User
    {
        public int $ID = 0;
        public string $user_email = '';
        public int $user_status = 0;

        public function __construct(int $id, string $email, int $userStatus = 0)
        {
            $this->ID = $id;
            $this->user_email = $email;
            $this->user_status = $userStatus;
        }
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Approval {

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Approval\ApprovalRouteSnapshot;
use ADCT\ParishIntake\Core\Approval\Approver;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\WordPress\Approval\ApprovalNoticeJob;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApprovalNoticeJobTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['adct_test_wp_users', 'adct_test_wp_caps', 'adct_test_wp_meta'] as $key) {
            $this->globalsBackup[$key] = $GLOBALS[$key] ?? null;
            unset($GLOBALS[$key]);
        }
        $GLOBALS['adct_test_wp_users'] = [];
        $GLOBALS['adct_test_wp_caps'] = [];
        $GLOBALS['adct_test_wp_meta'] = [];
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

            public function testCorruptCandidateFieldsFailTheJobInsteadOfBeingSilentlySkipped(): void
    {
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_');
        $database->method('lastError')->willReturn('');
        $database->method('prepare')->willReturnCallback(static fn (string $sql): string => $sql);
        $database->method('getResults')->willReturnOnConsecutiveCalls(
            [],
            [['id' => 12, 'fields' => '{invalid json']]
        );
        $job = new ApprovalNoticeJob(
            $database,
            new ApprovalRecipients(new ApprovalRouteResolver(
                $this->createMock(ApprovalRouteRepositoryInterface::class)
            )),
            new ActionTokenService(
                $this->createMock(ActionTokenStoreInterface::class),
                $this->createMock(ClockInterface::class)
            ),
            $this->createMock(MailerInterface::class),
            $this->createMock(MailQueueRepositoryInterface::class),
            $this->createMock(ClockInterface::class)
        );

        $this->expectException(RuntimeException::class);
        $job->processNext(null);
    }

    public function testScansOnlyAwaitingUndecidedCandidatesInBoundedBatches(): void
    {
        $queries = [];
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_');
        $database->method('lastError')->willReturn('');
        $database->method('prepare')->willReturnCallback(
            static function (string $sql, mixed ...$args) use (&$queries): string {
                $queries[] = [$sql, $args];
                return $sql;
            }
        );
        $database->method('getResults')->willReturn([]);
        $job = new ApprovalNoticeJob(
            $database,
            new ApprovalRecipients(new ApprovalRouteResolver(
                $this->createMock(ApprovalRouteRepositoryInterface::class)
            )),
            new ActionTokenService(
                $this->createMock(ActionTokenStoreInterface::class),
                $this->createMock(ClockInterface::class)
            ),
            $this->createMock(MailerInterface::class),
            $this->createMock(MailQueueRepositoryInterface::class),
            $this->createMock(ClockInterface::class)
        );

        self::assertNull($job->processNext(null));
        self::assertTrue($job->processNext('99')->isComplete());
        self::assertSame([0, 'awaiting_approval', 'new', 20], $queries[0][1]);
        self::assertSame([99, 'awaiting_approval', 'new', 20], $queries[1][1]);
        self::assertStringContainsString('approved_by IS NULL', $queries[0][0]);
    }

    #[DataProvider('manualReviewFields')]
    public function testCandidatesWithManualMatchReviewFieldsDoNotCreateNoticesOrTokens(array $fields): void
    {
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_');
        $database->method('lastError')->willReturn('');
        $database->method('prepare')->willReturnCallback(static fn (string $sql, mixed ...$args): string => $sql);
        $database->method('getResults')->willReturnOnConsecutiveCalls(
            [],
            [[
                'id' => 12,
                'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
                'match_kind' => 'new',
                'status' => 'awaiting_approval',
                'parish_id' => 5,
                'notes' => '[]',
            ]]
        );
        $database->expects(self::never())->method('query');

        $routes = $this->createMock(ApprovalRouteRepositoryInterface::class);
        $routes->expects(self::never())->method('findForParish');
        $tokenStore = $this->createMock(ActionTokenStoreInterface::class);
        $tokenStore->expects(self::never())->method('create');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('enqueue');
        $queue = $this->createMock(MailQueueRepositoryInterface::class);
        $queue->expects(self::never())->method('findByRecipientAndGroupKey');
        $clock = $this->createMock(ClockInterface::class);
        $job = new ApprovalNoticeJob(
            $database,
            new ApprovalRecipients(new ApprovalRouteResolver($routes)),
            new ActionTokenService($tokenStore, $clock),
            $mailer,
            $queue,
            $clock
        );

        $result = $job->processNext(null);

        self::assertNotNull($result);
        self::assertSame('12', $result->checkpoint());
        self::assertFalse($result->isComplete());
    }

    public static function manualReviewFields(): iterable
    {
        yield 'pending candidate id in fields' => [['matched_candidate_id' => 42]];
        yield 'manual review flag in fields' => [['match_review_required' => true]];
    }

    #[DataProvider('digestHours')]
    public function testDigestGateOpensAtTheConfiguredLocalHour(int $hour, int $digestHour, bool $open): void
    {
        $job = new ApprovalNoticeJob(
            $this->createMock(DatabaseConnectionInterface::class),
            new ApprovalRecipients(new ApprovalRouteResolver(
                $this->createMock(ApprovalRouteRepositoryInterface::class)
            )),
            new ActionTokenService(
                $this->createMock(ActionTokenStoreInterface::class),
                $this->createMock(ClockInterface::class)
            ),
            $this->createMock(MailerInterface::class),
            $this->createMock(MailQueueRepositoryInterface::class),
            $this->createMock(ClockInterface::class),
            $digestHour
        );

        $localNow = new DateTimeImmutable('2026-09-24 ' . str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':30:00');

        self::assertSame($open, $job->digestOpen($localNow));
    }

    public static function digestHours(): iterable
    {
        yield 'well before the default hour' => [2, ApprovalNoticeJob::DEFAULT_DIGEST_HOUR, false];
        yield 'one hour before the default hour' => [6, ApprovalNoticeJob::DEFAULT_DIGEST_HOUR, false];
        yield 'exactly at the default hour' => [7, ApprovalNoticeJob::DEFAULT_DIGEST_HOUR, true];
        yield 'after the default hour' => [14, ApprovalNoticeJob::DEFAULT_DIGEST_HOUR, true];
        yield 'before a configured hour' => [5, 6, false];
        yield 'at a configured hour' => [6, 6, true];
        yield 'a configured midnight is always open' => [0, 0, true];
    }

    public function testDigestHourOutsideTheDayIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApprovalNoticeJob(
            $this->createMock(DatabaseConnectionInterface::class),
            new ApprovalRecipients(new ApprovalRouteResolver(
                $this->createMock(ApprovalRouteRepositoryInterface::class)
            )),
            new ActionTokenService(
                $this->createMock(ActionTokenStoreInterface::class),
                $this->createMock(ClockInterface::class)
            ),
            $this->createMock(MailerInterface::class),
            $this->createMock(MailQueueRepositoryInterface::class),
            $this->createMock(ClockInterface::class),
            24
        );
    }

    public function testPerItemApproversAreNotifiedRegardlessOfTheDigestHour(): void
    {
        $job = $this->runJobForApprover(
            Approver::NOTIFY_EACH,
            'dean-each@example.test',
            new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg'))
        );

        self::assertCount(2, $job['statements']);
        self::assertStringContainsString('INSERT INTO', $job['statements'][0]);
        self::assertStringContainsString('UPDATE', $job['statements'][1]);
        self::assertCount(1, $job['enqueued']);
        self::assertSame('Events awaiting your approval', $job['enqueued'][0]->subject);
    }

    public function testDigestApproversAreHeldUntilTheDigestHour(): void
    {
        $job = $this->runJobForApprover(
            Approver::NOTIFY_DIGEST,
            'dean-digest@example.test',
            new DateTimeImmutable('2026-09-24 05:40:00', new DateTimeZone('Africa/Johannesburg'))
        );

        // No notice row, no mail, and the run ends so the candidate is read
        // again on a later run rather than skipped past.
        self::assertSame([], $job['statements']);
        self::assertSame([], $job['enqueued']);
        self::assertTrue($job['result']->isComplete());
        self::assertNull($job['result']->checkpoint());
    }

    public function testDigestApproversAreNotifiedOnceTheDigestHourHasPassed(): void
    {
        $job = $this->runJobForApprover(
            Approver::NOTIFY_DIGEST,
            'dean-digest@example.test',
            new DateTimeImmutable('2026-09-24 07:05:00', new DateTimeZone('Africa/Johannesburg'))
        );

        self::assertCount(2, $job['statements']);
        self::assertCount(1, $job['enqueued']);
        self::assertSame('Your daily event approvals', $job['enqueued'][0]->subject);

        // The digest is keyed by local date and recipient, so a repeat run on
        // the same day reuses the same queue row instead of mailing twice.
        self::assertSame(
            'approval-digest:20260924:' . substr(hash('sha256', 'dean-digest@example.test'), 0, 24),
            $job['queueKey']
        );
    }

    /**
     * Runs one approval-notice pass for a single active dean on a parish.
     *
     * @return array{
     *     statements: list<string>,
     *     enqueued: list<OutboundEmail>,
     *     queueKey: ?string,
     *     result: JobStepResult
     * }
     */
    private function runJobForApprover(
        string $mode,
        string $email,
        DateTimeImmutable $localNow
    ): array {
        $GLOBALS['adct_test_wp_users'] = [9 => new \WP_User(9, $email)];
        $GLOBALS['adct_test_wp_caps'] = [9 => [Capabilities::APPROVE_DEANERY]];

        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_');
        $database->method('lastError')->willReturn('');
        $database->method('prepare')->willReturnCallback(static fn (string $sql, mixed ...$args): string => $sql);
        $database->method('getResults')->willReturnOnConsecutiveCalls(
            [],
            [[
                'id' => 44,
                'fields' => json_encode(['title' => 'Retreat day'], JSON_THROW_ON_ERROR),
                'match_kind' => 'new',
                'status' => 'awaiting_approval',
                'parish_id' => 5,
                'notes' => '[]',
            ]],
            [],
            [[
                'candidate_id' => 44,
                'notify_mode' => $mode,
                'fields' => json_encode(['title' => 'Retreat day'], JSON_THROW_ON_ERROR),
                'notes' => '[]',
                'message_id' => null,
                'sender_email' => 'parish-office@example.test',
                'sender_name' => '',
                'parish_name' => 'St Anne',
            ]]
        );

        $statements = [];
        $database->method('query')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;
                return 1;
            }
        );

        $routes = $this->createMock(ApprovalRouteRepositoryInterface::class);
        $routes->method('findForParish')->willReturn(new ApprovalRouteSnapshot(
            7,
            true,
            [new Approver(4, 9, $email, 'Dean Test', $mode, true, true)]
        ));

        $queueKey = null;
        $queue = $this->createMock(MailQueueRepositoryInterface::class);
        $queue->method('findByRecipientAndGroupKey')->willReturnCallback(
            static function (string $recipient, string $key) use (&$queueKey): null {
                $queueKey = $key;
                return null;
            }
        );

        $enqueued = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('enqueue')->willReturnCallback(
            static function (OutboundEmail $email) use (&$enqueued): MailQueueEnqueueResult {
                $enqueued[] = $email;
                return new MailQueueEnqueueResult(1, MailQueueStatus::QUEUED, false);
            }
        );

        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn($localNow);

        $result = (new ApprovalNoticeJob(
            $database,
            new ApprovalRecipients(new ApprovalRouteResolver($routes)),
            new ActionTokenService(
                $this->createMock(ActionTokenStoreInterface::class),
                $clock
            ),
            $mailer,
            $queue,
            $clock
        ))->processNext(null);

        self::assertNotNull($result);

        return [
            'statements' => $statements,
            'enqueued' => $enqueued,
            'queueKey' => $queueKey,
            'result' => $result,
        ];
    }
}

}
