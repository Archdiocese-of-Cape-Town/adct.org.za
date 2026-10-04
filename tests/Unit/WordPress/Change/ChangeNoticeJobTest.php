<?php

declare(strict_types=1);

namespace {

    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Change {

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Approval\ApprovalRouteSnapshot;
use ADCT\ParishIntake\Core\Approval\Approver;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Mail\MailQueueRecord;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Auth\RevertChangeHandler;
use ADCT\ParishIntake\WordPress\Auth\UnpublishEventHandler;
use ADCT\ParishIntake\WordPress\Change\ChangeNoticeJob;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeImmutable;
use OutOfBoundsException;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChangeNoticeJobTest extends TestCase
{
    private const DEAN = 'dean@example.test';
    private const REVIEWER = 'reviewer@example.test';
    private const DEAN_USER_ID = 101;
    private const PARISH_ID = 5;
    private const CHANGE_ID = 77;
    private const EVENT_ID = 900;

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
        parent::tearDown();
    }

    public function testRejectsADigestHourOutsideTheDay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->job(24);
    }

    public function testRefusesADigestHourBelowZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->job(-1);
    }

    public function testMailsTheApproverABeforeAndAfterSummaryWithBothRemedies(): void
    {
        $run = $this->noticeRun([
            'before_payload' => json_encode(['title' => 'Retreat day', 'meta' => ['parish_id' => '5']], JSON_THROW_ON_ERROR),
            'after_payload' => json_encode(['title' => 'Retreat day online', 'meta' => ['parish_id' => '5']], JSON_THROW_ON_ERROR),
        ]);

        self::assertCount(1, $run['enqueued'], 'the routed approver is told once');
                $mail = $run['enqueued'][0];
        self::assertSame(self::DEAN, $mail->recipient);
        self::assertStringContainsString('Retreat day -> Retreat day online', $mail->textBody);
        self::assertStringContainsString('Title: Retreat day -> Retreat day online', $mail->textBody);
                // The HTML part is escaped, so the arrow reads `-&gt;` rather than `->`.
                self::assertStringContainsString('Title: Retreat day -&gt; Retreat day online', $mail->htmlBody);
                self::assertSame(MailPriority::APPROVER_OR_CHANGE, $mail->priority);
            }

    public function testMintsBothRemediesForTheChangeItIsTellingThemAbout(): void
    {
        $run = $this->noticeRun();

        $purposes = [];
        foreach ($run['bindings'] as $binding) {
            $purposes[$binding->purpose->value] = $binding;
        }

        self::assertArrayHasKey(ActionTokenPurpose::REVERT_CHANGE->value, $purposes);
        self::assertArrayHasKey(ActionTokenPurpose::UNPUBLISH_EVENT->value, $purposes);

        foreach ($purposes as $purpose => $binding) {
            self::assertSame(self::CHANGE_ID, $binding->subjectId, $purpose . ' names the change');
            self::assertSame(self::DEAN, $binding->email, $purpose . ' names the recipient');
        }

        self::assertSame(
            RevertChangeHandler::SUBJECT_TYPE,
            $purposes[ActionTokenPurpose::REVERT_CHANGE->value]->subjectType
        );
        self::assertSame(
            UnpublishEventHandler::SUBJECT_TYPE,
            $purposes[ActionTokenPurpose::UNPUBLISH_EVENT->value]->subjectType
        );
        self::assertSame('event_change', $purposes[ActionTokenPurpose::REVERT_CHANGE->value]->subjectType);
    }

    public function testTheMailCarriesARevertLinkAndAnUnpublishLinkTheRecipientCanFollow(): void
    {
        $run = $this->noticeRun();

        $mail = $run['enqueued'][0];
        self::assertStringContainsString('Revert this change', $mail->textBody);
        self::assertStringContainsString('Unpublish this event', $mail->textBody);
        self::assertMatchesRegularExpression(
            '#Revert this change: https?://\S+#',
            $mail->textBody
        );
        self::assertMatchesRegularExpression(
            '#Unpublish this event: https?://\S+#',
            $mail->textBody
        );
        self::assertStringContainsString('>Revert this change</a>', $mail->htmlBody);
        self::assertStringContainsString('>Unpublish this event</a>', $mail->htmlBody);
    }

    public function testEscapesTheWhoAndWhatOfAChangeInTheHtmlBody(): void
    {
        $run = $this->noticeRun([
            'actor' => '<script>alert(1)</script>@example.test',
            'after_payload' => json_encode(['title' => '<b>Night of</b>'], JSON_THROW_ON_ERROR),
        ]);

        $mail = $run['enqueued'][0];
        self::assertStringNotContainsString('<script>', $mail->htmlBody);
        self::assertStringNotContainsString('<b>Night of</b>', $mail->htmlBody);
        self::assertStringContainsString('&lt;script&gt;', $mail->htmlBody);
        self::assertStringContainsString('&lt;b&gt;Night of&lt;/b&gt;', $mail->htmlBody);
    }

    public function testStampsNotifiedAtInUtcOnlyAfterTheMailIsQueued(): void
    {
        $run = $this->noticeRun();

        $stamps = $this->statementsMatching($run['statements'], 'UPDATE `wp_adct_pi_event_changes`');
        self::assertCount(1, $stamps, 'the change is stamped exactly once');
        $args = $this->argumentsOf($stamps[0]);
        // <prepared args>/*<json args>*/ -- the trailing JSON is what $wpdb
        // appends, and the placeholders are the values under test.
        self::assertSame(
                    ['2026-03-04 07:06:07', '2026-03-04 07:06:07', self::CHANGE_ID],
            $args,
                    'the stamp is the clock converted to UTC: 09:06:07+02:00 is 07:06:07Z'
        );
        $stampAt = array_search($stamps[0], $run['order'], true);
                self::assertIsInt($stampAt, 'the stamp reached the database');
                self::assertContains('enqueue ' . $run['enqueued'][0]->groupKey, array_slice($run['order'], 0, $stampAt), 'the stamp follows the enqueue');
            }

    public function testTheStampGuardsAgainstReStampingAnAlreadyNotifiedChange(): void
    {
        $run = $this->noticeRun();

        self::assertStringContainsString(
                    'WHERE id = %d AND notified_at IS NULL',
                    $this->statementsMatching($run['statements'], 'UPDATE `wp_adct_pi_event_changes`')[0]
                );
            }

    public function testDoesNotMailAnApproversOwnRevertOrUnpublish(): void
    {
        foreach (['revert', 'unpublish'] as $kind) {
            $run = $this->noticeRun(['kind' => $kind]);

            self::assertSame([], $run['enqueued'], $kind . ' carries no notice');
            // It is still stepped over, not re-offered on every future run.
            self::assertCount(
                1,
                $this->statementsMatching($run['statements'], 'UPDATE `wp_adct_pi_event_changes`'),
                $kind . ' is stamped so it is not offered again'
            );
            self::assertSame([], $run['bindings'], $kind . ' mints no credential');
        }
    }

    public function testDoesNotRewindTheCheckpointOverAChangeItSkippedOrDeferred(): void
    {
        $run = $this->noticeRun(['kind' => 'revert']);

        self::assertSame((string) self::CHANGE_ID, $run['result']->checkpoint(), 'the scan moves past the id it decided not to announce');
                self::assertFalse($run['result']->isComplete(), 'there may be more changes behind it');
    }

    public function testHoldsADigestModeChangeBackUntilTheDigestHour(): void
    {
        $early = $this->noticeRun(['mode' => Approver::NOTIFY_DIGEST], '2026-03-04T06:30:00+02:00');

        self::assertSame([], $early['enqueued'], 'too early for today\'s digest');
        self::assertSame([], $this->statementsMatching($early['statements'], 'UPDATE `wp_adct_pi_event_changes`'));
                self::assertTrue($early['result']->isComplete(), 'the change is offered again later');
                self::assertNull($early['result']->checkpoint(), 'and the scan starts again from the beginning');
    }

    public function testSendsTheDigestOnceTheDigestHourArrives(): void
    {
        $run = $this->noticeRun(['mode' => Approver::NOTIFY_DIGEST], '2026-03-04T07:00:00+02:00');

        self::assertCount(1, $run['enqueued']);
        self::assertSame(MailPriority::REMINDER_OR_DIGEST, $run['enqueued'][0]->priority);
        self::assertStringContainsString('Your daily event change notices', $run['enqueued'][0]->subject);
        self::assertCount(1, $this->statementsMatching($run['statements'], 'UPDATE `wp_adct_pi_event_changes`'));
    }

    public function testKeysTheDigestOnTheLocalDateAndTheRecipientSoOneDayIsOneMessage(): void
    {
        $run = $this->noticeRun(['mode' => Approver::NOTIFY_DIGEST], '2026-03-04T09:00:00+02:00');

        $expected = 'change-digest:20260304:' . substr(hash('sha256', self::DEAN), 0, 24);
        self::assertContains($expected, $run['queueKeys']);
        self::assertSame($expected, $run['enqueued'][0]->groupKey);
    }

    public function testKeysPerItemMailOnTheChangeSoTwoChangesAreTwoMessages(): void
    {
        $run = $this->noticeRun();

        $expected = 'change:' . self::CHANGE_ID . ':' . substr(hash('sha256', self::DEAN), 0, 24);
        self::assertContains($expected, $run['queueKeys']);
    }

    public function testDoesNotMailTheSameGroupKeyTwiceWhenTheQueueAlreadyHasIt(): void
    {
        $run = $this->noticeRun(['queuedGroups' => [
            self::DEAN => 'change:' . self::CHANGE_ID . ':' . substr(hash('sha256', self::DEAN), 0, 24),
        ]]);

        self::assertSame([], $run['enqueued'], 'the message is already queued');
        self::assertCount(1, $this->statementsMatching($run['statements'], 'UPDATE `wp_adct_pi_event_changes`'));
    }

    public function testSkipsAChangeWhoseParishNoLongerResolvesRatherThanAbandoningTheBatch(): void
    {
        $run = $this->noticeRun(['routeThrows' => true]);

        self::assertSame([], $run['enqueued']);
        self::assertSame((string) self::CHANGE_ID, $run['result']->checkpoint(), 'the batch still advances past the orphaned change');
                self::assertFalse($run['result']->isComplete());
        self::assertCount(1, $this->statementsMatching($run['statements'], 'UPDATE `wp_adct_pi_event_changes`'));
    }

    public function testReadsTheParishFromTheEventMetaWhenTheCandidateIsGone(): void
    {
        $run = $this->noticeRun(['candidate_parish_id' => null, 'event_parish_id' => (string) self::PARISH_ID]);

        self::assertCount(1, $run['enqueued'], 'the event meta still resolves the parish');
    }

    public function testSendsNothingWhenNeitherTheCandidateNorTheEventNamesAParish(): void
    {
        $run = $this->noticeRun(['candidate_parish_id' => null, 'event_parish_id' => '  ']);

        self::assertSame([], $run['enqueued']);
    }

    public function testTellsBothTheDeanAndTheArchdioceseReviewer(): void
    {
        $run = $this->noticeRun(['reviewer' => true]);

        $recipients = array_map(static fn (OutboundEmail $e): string => $e->recipient, $run['enqueued']);
        sort($recipients);
        self::assertSame([self::DEAN, self::REVIEWER], $recipients);
    }

    public function testMailsOneMessagePerRecipientEvenWhenTwoChangesArriveInOneBatch(): void
    {
        $run = $this->noticeRun(['second_change' => true]);

        // The dean is addressed once per run (ADR 0011); the reviewer's separate
        // key is unaffected by that.
        $recipients = array_map(static fn (OutboundEmail $e): string => $e->recipient, $run['enqueued']);
        self::assertSame(
            1,
            count(array_keys($recipients, self::DEAN, true)),
            'the dean gets one message, not one per change'
        );
        $stamps = $this->statementsMatching($run['statements'], 'UPDATE `wp_adct_pi_event_changes`');
                self::assertCount(1, $stamps, 'only the change that was announced is stamped');
                self::assertSame(self::CHANGE_ID, $this->argumentsOf($stamps[0])[2], 'and it is the first of the batch');
                self::assertTrue($run['result']->isComplete());
                self::assertNull($run['result']->checkpoint(), 'the deferred change is offered again from the start');
            }

    public function testSaysSoWhenTheStoredBeforeOrAfterCannotBeRead(): void
    {
        $run = $this->noticeRun(['before_payload' => 'not json at all']);

        self::assertCount(1, $run['enqueued'], 'an unreadable payload is still worth a notice');
        self::assertStringContainsString(
            'the before and after details of this change could not be read',
            $run['enqueued'][0]->textBody
        );
    }

    public function testAnnouncesACancellationLikeAnyOtherChange(): void
    {
        foreach (['cancel', 'postpone', 'update'] as $kind) {
            $run = $this->noticeRun(['kind' => $kind]);

            self::assertCount(1, $run['enqueued'], $kind . ' is announced');
        }
    }

    public function testOnlyAsksForChangesItHasNotAlreadyAnnounced(): void
    {
        $run = $this->noticeRun();

        self::assertStringContainsString('ch.notified_at IS NULL', $run['select']);
        self::assertStringContainsString('ch.id > %d', $run['select']);
        unset($run);
    }

    public function testRaisesWhenTheChangeLookupFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Change notice lookup failed.');

        $this->noticeRun(['lookupError' => true]);
    }

    public function testRaisesWhenTheStampCannotBeWritten(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A change notice could not be recorded.');

        $this->noticeRun(['queryFails' => true]);
    }

    public function testRefusesATablePrefixThatCouldNotBeQuotedSafely(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid change table prefix.');

        $this->noticeRun(['prefix' => 'wp_`; DROP TABLE x; --']);
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $overrides
     * @return array{
     *     enqueued: list<OutboundEmail>,
     *     bindings: list<ActionTokenBinding>,
     *     queueKeys: list<string>,
     *     statements: list<string>,
     *     result: JobStepResult|null,
     *     select: string,
     *     events: list<string>
     * }
     */
    private function noticeRun(array $overrides = [], string $now = '2026-03-04T09:06:07+02:00'): array
    {
        $mode = $overrides['mode'] ?? Approver::NOTIFY_EACH;
        $reviewer = (bool) ($overrides['reviewer'] ?? false);
                // array_key_exists, not ??: a test that passes a null parish means the
                // column is NULL, and ?? would quietly hand it the default parish back.
                $parishId = array_key_exists('candidate_parish_id', $overrides)
                    ? $overrides['candidate_parish_id']
                    : self::PARISH_ID;
                $eventParish = array_key_exists('event_parish_id', $overrides)
                    ? $overrides['event_parish_id']
                    : (string) self::PARISH_ID;

        $change = array_merge([
            'id' => self::CHANGE_ID,
            'event_id' => self::EVENT_ID,
            'candidate_id' => 44,
            'actor' => self::DEAN,
            'kind' => 'update',
            'before_payload' => json_encode(['title' => 'Retreat day', 'meta' => ['parish_id' => '5']], JSON_THROW_ON_ERROR),
            'after_payload' => json_encode(['title' => 'Retreat day online', 'meta' => ['parish_id' => '5']], JSON_THROW_ON_ERROR),
            'created_at' => '2026-03-04 07:06:07',
            'notified_at' => null,
            'candidate_parish_id' => $parishId,
            'event_parish_id' => $eventParish,
        ], array_diff_key($overrides, array_flip(['mode', 'reviewer', 'second_change', 'queuedGroups', 'routeThrows', 'lookupError', 'queryFails', 'prefix', 'candidate_parish_id', 'event_parish_id'])));

        $second = null;
        if ($overrides['second_change'] ?? false) {
            $second = array_merge($change, [
                'id' => self::CHANGE_ID + 1,
                'before_payload' => json_encode(['title' => 'Later day'], JSON_THROW_ON_ERROR),
                'after_payload' => json_encode(['title' => 'Later day online'], JSON_THROW_ON_ERROR),
            ]);
        }

        $rows = $second === null ? [$change] : [$change, $second];

        $select = '';
        $statements = [];
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn((string) ($overrides['prefix'] ?? 'wp_'));
        $database->method('lastError')->willReturn(($overrides['lookupError'] ?? false) ? 'boom' : '');
        $database->method('prepare')->willReturnCallback(
            static function (string $sql, mixed ...$args): string {
                return $args === [] ? $sql : $sql . '/*' . json_encode($args) . '*/';
            }
        );
        $database->method('getResults')->willReturnCallback(
                    static function (string $sql) use (&$select, $rows): array {
                        // Answered unconditionally: this job makes exactly one kind of
                        // read, and the prefix under test can legitimately change the
                        // quoted table name, so the SQL is matched by order instead.
                        $select = $sql;

                        return $rows;
                    }
                );
        $order = [];
                $database->method('query')->willReturnCallback(
                    static function (string $sql) use (&$statements, &$order, $overrides): int|false {
                        $statements[] = $sql;
                        $order[] = $sql;

                        return ($overrides['queryFails'] ?? false) ? false : 1;
                    }
                );

        $approvers = [
            new Approver(4, self::DEAN_USER_ID, self::DEAN, 'Dean Test', $mode, true, true),
        ];
        if ($reviewer) {
            $approvers[] = new Approver(9, self::DEAN_USER_ID + 1, self::REVIEWER, 'Reviewer Test', $mode, true, true);
        }

                // ApprovalRecipients::forParish() only lists a routed approver who still
                        // has a live account holding the approver capability, so the accounts
                        // are seeded here rather than assumed. The job itself never reads them.
                        foreach ($approvers as $approver) {
                            $GLOBALS['adct_test_wp_users'][$approver->wpUserId] = new \WP_User($approver->wpUserId, $approver->email);
                            $GLOBALS['adct_test_wp_caps'][$approver->wpUserId] = [Capabilities::APPROVE_DEANERY];
                        }

        $routes = $this->createMock(ApprovalRouteRepositoryInterface::class);
        $routes->method('findForParish')->willReturnCallback(
            static function (int $parishId) use ($approvers, $overrides): ApprovalRouteSnapshot {
                if ($overrides['routeThrows'] ?? false) {
                    throw new OutOfBoundsException('no such parish');
                }

                return new ApprovalRouteSnapshot($parishId, true, $approvers);
            }
        );

        $queueKeys = [];
        $queued = $overrides['queuedGroups'] ?? [];
        $queue = $this->createMock(MailQueueRepositoryInterface::class);
        $queue->method('findByRecipientAndGroupKey')->willReturnCallback(
            static function (string $recipient, string $key) use (&$queueKeys, $queued): ?MailQueueRecord {
                $queueKeys[] = $key;
                if (! isset($queued[$recipient]) || $queued[$recipient] !== $key) {
                    return null;
                }

                return self::queueRecord($key);
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
                    static function (OutboundEmail $email) use (&$enqueued, &$order): MailQueueEnqueueResult {
                $enqueued[] = $email;
                        // The enqueue is recorded alongside the SQL so a test can read
                        // the real order: the stamp must come after the message is
                        // queued, or a crash in between would swallow the notice.
                        $order[] = 'enqueue ' . $email->groupKey;

                        return new MailQueueEnqueueResult(1, MailQueueStatus::QUEUED, false);
                    }
                );

        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable($now));

        $job = new ChangeNoticeJob(
                    $database,
                    new ApprovalRecipients(new ApprovalRouteResolver($routes)),
                    new ActionTokenService($tokenStore, $clock),
                    $mailer,
                    $queue,
                    $clock
                );

                $result = $job->processNext(null);

                return [
                    'enqueued' => $enqueued,
                    'bindings' => $bindings,
                    'queueKeys' => $queueKeys,
                    'statements' => $statements,
                    'result' => $result,
                    'select' => $select,
                    'events' => $statements,
                    'order' => $order,
                ];
            }

    private function job(int $digestHour): ChangeNoticeJob
    {
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_');

        return new ChangeNoticeJob(
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
            $digestHour
        );
    }

    /**
         * The statements of one run that mention a given table.
         *
         * @param list<string> $statements
         * @return list<string>
         */
        private static function statementsMatching(array $statements, string $needle): array
        {
            return array_values(array_filter(
                $statements,
                static fn (string $sql): bool => str_contains($sql, $needle)
            ));
        }

        /**
     * The values $wpdb->prepare() was handed, read back off the JSON it appends.
     *
     * @return list<mixed>
     */
    private static function argumentsOf(string $prepared): array
    {
        $at = strrpos($prepared, '/*');
        self::assertNotFalse($at, 'the statement was prepared with arguments');

        return json_decode(substr($prepared, $at + 2, -2), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function queueRecord(string $groupKey): MailQueueRecord
    {
        return new MailQueueRecord(
            1,
                new OutboundEmail(
                    'dean@example.test',
                    'A subject',
                    '<p>already queued</p>',
                    'already queued',
                    MailPriority::APPROVER_OR_CHANGE,
                    $groupKey
                ),
                MailQueueStatus::QUEUED,
                0,
                new DateTimeImmutable('2026-03-04 07:00:00+00:00'),
                new DateTimeImmutable('2026-03-04 07:00:00+00:00')
            );
        }
}

}
