<?php

declare(strict_types=1);

namespace {

    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
}


namespace ADCT\ParishIntake\Tests\Unit\WordPress\Approval {

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Approval\ApprovalRouteSnapshot;
use ADCT\ParishIntake\Core\Approval\Approver;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\WordPress\Auth\NotifyModeChangeHandler;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Parsing\UnparsedDateTimeCandidate;
use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\WordPress\Approval\ApprovalNoticeJob;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryApproverRepository;
use ADCT\ParishIntake\Tests\Support\NotifyModeClock;
use ADCT\ParishIntake\Tests\Support\NotifyModeDatabase;
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
            $this->createMock(ClockInterface::class),
            new DeaneryApproverRepository($database)
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
            $this->createMock(ClockInterface::class),
            new DeaneryApproverRepository($database)
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
            $clock,
            new DeaneryApproverRepository($database)
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

        /**
         * A parish row can disappear between intake and the notice run - an operator
         * merges or deletes it, or a partially restored backup brings back the
         * candidate without its parish. Every other candidate in the batch still
         * needs its notice, so the orphan must be stepped over rather than thrown.
         */
        public function testACandidateWhoseParishNoLongerExistsIsSkippedWithoutStoppingTheRun(): void
        {
            $database = $this->createMock(DatabaseConnectionInterface::class);
            $database->method('prefix')->willReturn('wp_');
            $database->method('lastError')->willReturn('');
            $database->method('prepare')->willReturnCallback(static fn (string $sql, mixed ...$args): string => $sql);
            $database->method('getResults')->willReturnOnConsecutiveCalls(
                [],
                [
                    ['id' => 12, 'fields' => '{"title":"Orphaned"}', 'match_kind' => 'new',
                        'status' => 'awaiting_approval', 'parish_id' => 5, 'notes' => '[]'],
                    ['id' => 13, 'fields' => '{"title":"Still here"}', 'match_kind' => 'new',
                        'status' => 'awaiting_approval', 'parish_id' => 6, 'notes' => '[]'],
                ]
            );

            $routes = $this->createMock(ApprovalRouteRepositoryInterface::class);
            $routes->method('findForParish')->willReturnCallback(
                static fn (int $parishId): ?ApprovalRouteSnapshot => $parishId === 5
                    ? null
                    : new ApprovalRouteSnapshot(1, true, [])
            );

            $job = new ApprovalNoticeJob(
                $database,
                new ApprovalRecipients(new ApprovalRouteResolver($routes)),
                new ActionTokenService($this->createMock(ActionTokenStoreInterface::class), $this->createMock(ClockInterface::class)),
                $this->createMock(MailerInterface::class),
                $this->createMock(MailQueueRepositoryInterface::class),
                $this->createMock(ClockInterface::class),
                new DeaneryApproverRepository($database)
            );

            $result = $job->processNext(null);

            self::assertNotNull($result);
            self::assertSame('13', $result->checkpoint());
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
            new DeaneryApproverRepository($this->createMock(DatabaseConnectionInterface::class)),
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
            new DeaneryApproverRepository($this->createMock(DatabaseConnectionInterface::class)),
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

    public function testThePerItemMailOffersThePreferenceLinkToADeanWhoseApprovalAddressDiffersFromTheirAccount(): void
    {
        $job = $this->runJobForApprover(
            Approver::NOTIFY_EACH,
            'dean-office@example.test',
            new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg')),
            [['account' => 'dean.personal@example.test', 'email' => 'dean-office@example.test']]
        );

        $binding = self::notifyModeBinding($job['bindings']);
        self::assertNotNull($binding, 'The mail must offer the preference link to a dean.');
        // The address the mail went to is the approval address. The address the
        // token is bound to has to be the one on the account, because that is
        // what the handler re-resolves before it will change anything. Binding
        // the notice address would hand out a token the holder cannot use.
        self::assertSame('dean-office@example.test', $job['enqueued'][0]->recipient);
        self::assertSame('dean.personal@example.test', $binding->email);
        self::assertSame(NotifyModeDatabase::DEAN_USER_ID, $binding->subjectId);
        self::assertSame(NotifyModeChangeHandler::SUBJECT_TYPE, $binding->subjectType);
    }

    public function testThePreferenceLinkResolvesToTheSameLiveAccountTheHandlerWillReResolve(): void
    {
        $job = $this->runJobForApprover(
            Approver::NOTIFY_EACH,
            'dean-office@example.test',
            new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg')),
            [['account' => 'dean.personal@example.test', 'email' => 'dean-office@example.test']]
        );

        $binding = self::notifyModeBinding($job['bindings']);
        self::assertNotNull($binding);

        // End to end, through the real handler and the real repository: the
        // binding the mail carries must be one the handler accepts. If this
        // fails, the dean has a link that opens a refusal page.
        $handler = new NotifyModeChangeHandler(
            new NotifyModeDatabase(),
            new DeaneryApproverRepository(
                $this->approverDatabaseFor(
                    [['account' => 'dean.personal@example.test', 'email' => 'dean-office@example.test']]
                )
            ),
            new NotifyModeClock('2026-09-24 10:15:00')
        );

        self::assertNull(
            $handler->preview($binding),
            'The mailed binding must not be refused as unresolvable.'
        );
    }

    public function testThePreferenceLinkIsNotOfferedWhenTheNoticeAddressIsNotAnyLiveApprovers(): void
    {
        // No live assignment carries the notice address, so there is nobody to
        // bind a token to. The mail is still the ordinary approval mail.
        $job = $this->runJobForApprover(
            Approver::NOTIFY_EACH,
            'dean-office@example.test',
            new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg')),
            []
        );

        self::assertCount(1, $job['enqueued']);
        self::assertNull(
            self::notifyModeBinding($job['bindings']),
            'A notice address belonging to nobody live must not carry a preference link.'
        );

        // Non-vacuity: the same pass with a live approver at that address does
        // mint the link, so the refusal above is the lookup and not the fixture.
        $offered = $this->runJobForApprover(
            Approver::NOTIFY_EACH,
            'dean-office@example.test',
            new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg'))
        );
        self::assertNotNull(self::notifyModeBinding($offered['bindings']));
    }

    public function testThePreferenceLinkIsNotOfferedWhenTwoLiveApproversShareTheNoticeAddress(): void
    {
        // A shared address has no single right answer for who is choosing, so
        // the plugin offers no link rather than guessing between them.
        $job = $this->runJobForApprover(
            Approver::NOTIFY_EACH,
            'shared-desk@example.test',
            new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg')),
            [
                ['account' => 'first.dean@example.test', 'email' => 'shared-desk@example.test'],
                ['account' => 'second.dean@example.test', 'email' => 'shared-desk@example.test'],
            ]
        );

        // Both are still notified about the event itself.
        self::assertCount(1, $job['enqueued']);
        self::assertNull(
            self::notifyModeBinding($job['bindings']),
            'A notice address shared by two live approvers must not carry a preference link.'
        );
    }

    public function testTheDailyDigestMailCarriesNoPreferenceLink(): void
    {
        $job = $this->runJobForApprover(
            Approver::NOTIFY_DIGEST,
            'dean-digest@example.test',
            new DateTimeImmutable('2026-09-24 07:05:00', new DateTimeZone('Africa/Johannesburg'))
        );

        self::assertCount(1, $job['enqueued']);
        self::assertNull(
            self::notifyModeBinding($job['bindings']),
            'Someone already on the daily digest has nothing to switch away from.'
        );
    }

    /**
         * #170: the approver is asked to judge one submission from an email, so
         * the mail has to say who it came from and, separately, how much that
         * name is worth. These tests pin the two apart: a name is identity only,
         * and the warnings stay the sole trust signal.
         */
        public function testTheMailShowsTheSubmitterNameWithTheAddress(): void
        {
            $job = $this->runJobForApprover(
                Approver::NOTIFY_EACH,
                'dean-office@example.test',
                new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg')),
                null,
                ['sender_name' => 'St Anne Parish Office']
            );

            self::assertCount(1, $job['enqueued']);
            // Both bodies carry the name, and the address stays the stable
            // identifier beside it: the name is free text from a header and can be
            // misspelled, generic or spoofed, so it is never the only identifier.
            self::assertStringContainsString('St Anne Parish Office', $job['enqueued'][0]->textBody);
            self::assertStringContainsString('St Anne Parish Office', $job['enqueued'][0]->htmlBody);
            self::assertStringContainsString('parish-office@example.test', $job['enqueued'][0]->textBody);
            self::assertStringContainsString('parish-office@example.test', $job['enqueued'][0]->htmlBody);
        }

        /**
         * A name is not a trust signal. A sender the parser could not learn has to
         * still be flagged, and the flag has to stand on a line of its own so the
         * warning cannot be read as part of the name.
         */
        public function testANameNeverReadsAsEvidenceThatTheSenderIsTrusted(): void
        {
            $job = $this->runJobForApprover(
                Approver::NOTIFY_EACH,
                'dean-office@example.test',
                new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg')),
                null,
                [
                    'sender_name' => 'St Anne Parish Office',
                    'notes' => json_encode(['unknown_sender', 'dmarc_fail'], JSON_THROW_ON_ERROR),
                ]
            );

            self::assertCount(1, $job['enqueued']);
            $lines = self::previewLines($job['enqueued'][0]);

            $nameLine = self::lineContaining($lines, 'St Anne Parish Office');
            self::assertNotNull($nameLine, 'The name must be shown on a line the approver can read.');
            self::assertStringNotContainsStringIgnoringCase('verified', $nameLine);
            self::assertStringNotContainsStringIgnoringCase('trusted', $nameLine);
            self::assertStringNotContainsStringIgnoringCase('confirmed', $nameLine);
            self::assertStringNotContainsStringIgnoringCase('authentic', $nameLine);

            // The warnings survive alongside the name, on their own line. This is
            // the whole of the trust signal: a name never adds to it.
                        $warningLine = self::lineContaining($lines, 'Unknown sender');
                        self::assertNotNull($warningLine, 'The sender-trust warning must still reach the approver.');
                        self::assertStringContainsString('DMARC', $warningLine);
                        self::assertStringContainsString('WARNING', $warningLine);
                        self::assertNotSame($nameLine, $warningLine);
        }

        /**
         * The unreadable date/time warning added by #167 is part of the same
         * trust signal, and a name must not push it out of the mail.
         */
        public function testANameDoesNotDisplaceTheUnreadableDateWarning(): void
        {
            $job = $this->runJobForApprover(
                Approver::NOTIFY_EACH,
                'dean-office@example.test',
                new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg')),
                null,
                [
                    'sender_name' => 'St Anne Parish Office',
                    'notes' => json_encode(
                        [UnparsedDateTimeCandidate::DATE_REASON . ':32 October 2026'],
                        JSON_THROW_ON_ERROR
                    ),
                ]
            );

            self::assertCount(1, $job['enqueued']);
            $body = $job['enqueued'][0]->textBody;

            self::assertStringContainsString('St Anne Parish Office', $body);
            self::assertStringContainsString('could not be read', $body);
            self::assertStringContainsString('32 October 2026', $body);
        }

        /**
         * sender_name is nullable, so most real notices have none. A blank line
         * would read as a rendering bug; the approver is told the name is absent.
         * "Unknown" is not an acceptable substitute for either value: it is
         * already the word the trust warning uses for a sender we could not learn.
         */
        public function testAMissingNameSaysSoRatherThanLeavingAGap(): void
        {
            foreach (['', '   ', "\r\n\t"] as $absent) {
                $job = $this->runJobForApprover(
                    Approver::NOTIFY_EACH,
                    'dean-office@example.test',
                    new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg')),
                    null,
                    ['sender_name' => $absent]
                );

                self::assertCount(1, $job['enqueued']);
                $body = $job['enqueued'][0]->textBody;

                self::assertStringContainsString('(no name supplied)', $body);
                self::assertStringNotContainsString('Submitted by: Unknown', $body);
                // The address is still there, and is still the identifier.
                self::assertStringContainsString('parish-office@example.test', $body);
                self::assertStringNotContainsString('(no name supplied)@', $body);
            }
        }

        /**
         * The escaping regression. sender_name is free text read off a message
         * header, so it arrives from the internet and must never reach the HTML
         * body as markup. Both assertions matter: dropping the escaping entirely
         * trips the first, escaping somewhere other than the render path trips
         * the second. This is the same pattern the front-end approval queue test
         * uses, applied to the mail body.
                  *
                  * The plain-text alternative is checked for fidelity, not for absence of
                  * markup: a text/plain part is never rendered as HTML, and escaping it
                  * would make the approver read "&lt;script&gt;" instead of the header that
                  * was actually sent. tests/fixtures/confirmation-email/preview.txt sets the
                  * precedent for that surface.
                  */
                 public function testANameCarryingHtmlIsEscapedInBothBodies(): void
                 {
                     $job = $this->runJobForApprover(
                         Approver::NOTIFY_EACH,
                         'dean-office@example.test',
                         new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg')),
                         null,
                         ['sender_name' => '<script>alert(1)</script>Parish Office']
                     );

                     self::assertCount(1, $job['enqueued']);
                     $html = $job['enqueued'][0]->htmlBody;
                     $text = $job['enqueued'][0]->textBody;

                     self::assertStringNotContainsString('<script>', $html);
                     self::assertStringContainsString('&lt;script&gt;', $html);
                     // A mangled entity is as wrong as a raw tag: the approver must be able
                     // to read what the header actually said.
                     self::assertStringContainsString('&amp;', $html);
                     // The text part carries the header verbatim, on the sender line.
                     self::assertStringContainsString('<script>alert(1)</script>', $text);
                     self::assertStringContainsString('Submitted by: parish-office@example.test (', $text);
        }

        /**
         * A display name is chosen by whoever sent the message, and a real bulletin
         * wraps its header. A newline inside one would break the text body's line
         * structure and make the rest of the preview read as part of the name.
         */
        public function testANameCannotInjectALineIntoThePreview(): void
        {
            $job = $this->runJobForApprover(
                Approver::NOTIFY_EACH,
                'dean-office@example.test',
                new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg')),
                null,
                ['sender_name' => "Parish Office\r\nSubmitted by: someone-else@example.test"]
            );

            self::assertCount(1, $job['enqueued']);
            $lines = self::previewLines($job['enqueued'][0]);

            // The injected second line is dropped rather than rendered.
            self::assertStringNotContainsString('someone-else@example.test', implode("\n", $lines));
            // Exactly one line claims to be the sender, and it is the real one.
            $claims = array_values(array_filter(
                $lines,
                static fn (string $line): bool => str_starts_with($line, 'Submitted by: ')
            ));
            self::assertCount(1, $claims);
            self::assertStringContainsString('parish-office@example.test', $claims[0]);
        }

        /**
         * The address is normalised for display so an approver can match it
         * against the parish's known contact. Normalising the display only is
         * safe: nothing downstream keys off it, and the warnings above already
         * carry the trust signal, so there is no machine decision riding on the
         * casing. An address that is not a valid address is shown as parsed
         * rather than dropped -- it is evidence, not decoration.
         */
        public function testTheSubmittedAddressIsNormalisedForDisplayOnly(): void
        {
            $job = $this->runJobForApprover(
                Approver::NOTIFY_EACH,
                'dean-office@example.test',
                new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg')),
                null,
                ['sender_email' => '  Parish.Office@Example.TEST ', 'sender_name' => 'Parish Office']
            );

            self::assertCount(1, $job['enqueued']);
            $body = $job['enqueued'][0]->textBody;

            self::assertStringContainsString('parish.office@example.test', $body);
            self::assertStringNotContainsString('Parish.Office@Example.TEST', $body);

            // Non-vacuity: an address we cannot parse is still shown, because the
            // approver needs to see what actually arrived.
            $unparseable = $this->runJobForApprover(
                Approver::NOTIFY_EACH,
                'dean-office@example.test',
                new DateTimeImmutable('2026-09-24 03:15:00', new DateTimeZone('Africa/Johannesburg')),
                null,
                ['sender_email' => 'Parish Office <not-an-address>', 'sender_name' => 'Parish Office']
            );

            self::assertStringContainsString(
                'not-an-address',
                $unparseable['enqueued'][0]->textBody
            );
        }

        /**
         * A digest batches several notices into one mail. The name must be shown
         * for each of them, so the approver can tell the submissions apart.
         */
        public function testTheDigestShowsTheNameForEveryNoticeInTheBatch(): void
        {
            $job = $this->runJobForApprover(
                Approver::NOTIFY_DIGEST,
                'dean-digest@example.test',
                new DateTimeImmutable('2026-09-24 07:05:00', new DateTimeZone('Africa/Johannesburg')),
                null,
                ['sender_name' => 'St Anne Parish Office']
            );

            self::assertCount(1, $job['enqueued']);
            self::assertSame('Your daily event approvals', $job['enqueued'][0]->subject);
            self::assertStringContainsString('St Anne Parish Office', $job['enqueued'][0]->textBody);
            self::assertStringContainsString('St Anne Parish Office', $job['enqueued'][0]->htmlBody);
        }

        /**
         * @return list<string> The non-empty lines of a body.
         */
        private static function previewLines(OutboundEmail $mail): array
        {
            $lines = preg_split('/\R/u', $mail->textBody);
            self::assertNotFalse($lines);

            return array_values(array_filter(
                array_map('trim', $lines),
                static fn (string $line): bool => $line !== ''
            ));
        }

        private static function lineContaining(array $lines, string $needle): ?string
        {
            foreach ($lines as $line) {
                if (str_contains($line, $needle)) {
                    return $line;
                }
            }

            return null;
        }

        /**
         * @param array<int, array{account: string, email: string}> $approvers
         *        The WordPress accounts and the live deanery assignments behind the
     *        notice addresses. Real parishes use three shapes: the approval
     *        address is the account address; it is deliberately a different
     *        address (the operator guide allows it); or it belongs to nobody
     *        live. An empty list models the third: the mail still goes out,
     *        because it is driven by the route, but there is nobody to bind a
     *        preference token to.
     *
     * @param array<string, mixed> $noticeOverrides
          *        Column values for the one approval notice row, merged over the
          *        defaults. The sender identity columns are the ones that vary per
          *        parish, so the tests that care about what the approver is shown
          *        change only those and leave the rest of the pass alone.
          *
          * @return array{
          *     statements: list<string>,
          *     enqueued: list<OutboundEmail>,
          *     queueKey: ?string,
          *     result: JobStepResult,
          *     bindings: list<ActionTokenBinding>,
          *     approvers: array<int, array{account: string, email: string}>
          * }
          */
         private function runJobForApprover(
             string $mode,
             string $email,
             DateTimeImmutable $localNow,
             ?array $approvers = null,
             array $noticeOverrides = []
         ): array {
             $approvers ??= [['account' => $email, 'email' => $email]];

             $fake = new NotifyModeDatabase();
             $wpUserId = NotifyModeDatabase::DEAN_USER_ID;
             $notice = [array_merge([
                 'candidate_id' => 44,
                 'notify_mode' => $mode,
                 'fields' => json_encode(['title' => 'Retreat day'], JSON_THROW_ON_ERROR),
                 'notes' => '[]',
                 'message_id' => null,
                 'sender_email' => 'parish-office@example.test',
                 'sender_name' => '',
                 'parish_name' => 'St Anne',
             ], $noticeOverrides)];
        $GLOBALS['adct_test_wp_users'] = [];
        $GLOBALS['adct_test_wp_caps'] = [];

        // The routed approver and the live assignment both follow the notice
        // address, but they are seeded separately: the route decides who is
        // notified about the event, the assignment table decides who may change
        // their own notification frequency. Letting them drift apart is the
        // point of these tests, so they must not be built from one another.
        $fake->setVisibleAssignments([]);

        // The routed approver always has an account, even when the caller says
        // no live assignment carries the notice address: the mail is still sent
        // because the route decides that, not the assignment table.
        $GLOBALS['adct_test_wp_users'][$wpUserId] = new \WP_User($wpUserId, $email);
        $GLOBALS['adct_test_wp_caps'][$wpUserId] = [Capabilities::APPROVE_DEANERY];

        foreach (array_values($approvers) as $index => $approver) {
            $userId = $wpUserId + $index;
            $GLOBALS['adct_test_wp_users'][$userId] = new \WP_User($userId, $approver['account']);
            $GLOBALS['adct_test_wp_caps'][$userId] = [Capabilities::APPROVE_DEANERY];

            $assignmentId = NotifyModeDatabase::ASSIGNMENT_ID + $index;
            $fake->setAssignmentEmail($assignmentId, $approver['email']);
            $fake->setAssignmentWpUserId($assignmentId, $userId);
            $fake->addVisibleAssignment($assignmentId);
        }

        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_');
        $database->method('lastError')->willReturn('');
        // Faithful enough for the fakes that read prepared arguments back out
        // of the trailing JSON $wpdb->prepare appends, without interpolating the
        // placeholders themselves.
        $database->method('prepare')->willReturnCallback(
            static function (string $sql, mixed ...$args): string {
                return $args === [] ? $sql : $sql . '/*' . json_encode($args) . '*/';
            }
        );
        // Answered by the shape of the query rather than by call order: a new
        // read (the notice-address lookup behind the preference link) must not
        // silently shift the rows the rest of the pass depends on.
        $database->method('getResults')->willReturnCallback(
            static function (string $sql) use ($fake, $notice): array {
                if (str_contains($sql, 'SELECT n.candidate_id')) {
                    return $notice;
                }
                if (str_contains($sql, 'adct_pi_deanery_approvers')) {
                    return $fake->getResults($sql);
                }
                if (str_contains($sql, 'adct_pi_event_candidates')) {
                    return [[
                        'id' => 44,
                        'fields' => json_encode(['title' => 'Retreat day'], JSON_THROW_ON_ERROR),
                        'match_kind' => 'new',
                        'status' => 'awaiting_approval',
                        'parish_id' => 5,
                        'notes' => '[]',
                    ]];
                }

                // The pending-notice probe and noticeExists() read the notices
                // table; both are empty for a fresh candidate.
                return [];
            }
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
            [new Approver(4, $wpUserId, $email, 'Dean Test', $mode, true, true)]
        ));

        $queueKey = null;
        $queue = $this->createMock(MailQueueRepositoryInterface::class);
        $queue->method('findByRecipientAndGroupKey')->willReturnCallback(
            static function (string $recipient, string $key) use (&$queueKey): null {
                $queueKey = $key;
                return null;
            }
        );

        $bindings = [];
        $tokenStore = $this->createMock(ActionTokenStoreInterface::class);
        $tokenStore->method('create')->willReturnCallback(
            static function (ActionTokenRecord $record) use (&$bindings): void {
                $bindings[] = $record->binding;
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
            new ActionTokenService($tokenStore, $clock),
            $mailer,
            $queue,
            $clock,
            new DeaneryApproverRepository($database)
        ))->processNext(null);

        self::assertNotNull($result);

        return [
            'statements' => $statements,
            'enqueued' => $enqueued,
            'queueKey' => $queueKey,
            'result' => $result,
            'bindings' => $bindings,
            'approvers' => $approvers,
        ];
    }

    /**
     * A database whose live assignments carry exactly the given approval
     * addresses, for the tests that check what the handler makes of a binding
     * the mail produced.
     *
     * @param array<int, array{account: string, email: string}> $approvers
     */
    private function approverDatabaseFor(array $approvers): DatabaseConnectionInterface
    {
        $database = new NotifyModeDatabase();
        $database->setVisibleAssignments([]);
        foreach (array_values($approvers) as $index => $approver) {
            $assignmentId = NotifyModeDatabase::ASSIGNMENT_ID + $index;
            $database->setAssignmentEmail($assignmentId, $approver['email']);
            $database->setAssignmentWpUserId($assignmentId, NotifyModeDatabase::DEAN_USER_ID + $index);
            $database->addVisibleAssignment($assignmentId);
        }

        return $database;
    }

    /**
     * The binding for the one token issued for the notification preference, or
     * null when the mail offered no such link.
     *
     * @param list<ActionTokenBinding> $bindings
     */
    private static function notifyModeBinding(array $bindings): ?ActionTokenBinding
    {
        foreach ($bindings as $binding) {
            if ($binding->purpose === ActionTokenPurpose::CHANGE_NOTIFY_MODE) {
                return $binding;
            }
        }

        return null;
    }
}

}
