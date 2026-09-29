<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Approval;

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\WordPress\Approval\ApprovalNoticeJob;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApprovalNoticeJobTest extends TestCase
{
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
}
