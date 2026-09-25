<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\WordPress\Auth\WordPressDeaneryApprovalChecker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WordPressDeaneryApprovalCheckerTest extends TestCase
{
    private bool $hadDatabase;
    private mixed $previousDatabase;
    private FakeDeaneryApprovalDatabase $database;

    protected function setUp(): void
    {
        $this->hadDatabase = array_key_exists('wpdb', $GLOBALS);
        $this->previousDatabase = $GLOBALS['wpdb'] ?? null;
        $this->database = new FakeDeaneryApprovalDatabase();
        $GLOBALS['wpdb'] = $this->database;
    }

    protected function tearDown(): void
    {
        if ($this->hadDatabase) {
            $GLOBALS['wpdb'] = $this->previousDatabase;
            return;
        }

        unset($GLOBALS['wpdb']);
    }

    public function testReadsActiveAssignmentsUsingAPreparedQuery(): void
    {
        $this->database->deaneryIds = ['12'];
        $checker = new WordPressDeaneryApprovalChecker();

        self::assertTrue($checker->canApprove(42, 12, [Capabilities::APPROVE_DEANERY]));
        self::assertFalse($checker->canApprove(42, 13, [Capabilities::APPROVE_DEANERY]));
        self::assertSame(
            'SELECT a.deanery_id FROM wp_adct_pi_deanery_approvers a '
            . 'INNER JOIN wp_adct_pi_deaneries d ON d.id = a.deanery_id '
            . 'WHERE a.wp_user_id = %d AND a.active = %d AND d.status = %s',
            $this->database->preparedSql
        );
        self::assertSame([42, 1, 'active'], $this->database->preparedArguments);
        self::assertSame('prepared-query', $this->database->selectedQuery);
    }

    public function testReviewerDoesNotNeedAnAssignmentQuery(): void
    {
        $checker = new WordPressDeaneryApprovalChecker();

        self::assertTrue($checker->canApprove(42, null, [Capabilities::REVIEW]));
        self::assertSame(0, $this->database->prepareCalls);
    }

    public function testAssignmentQueryFailuresAreNotTreatedAsMissingAssignments(): void
    {
        $this->database->last_error = 'database failure';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The deanery approver assignments could not be read.');

        (new WordPressDeaneryApprovalChecker())->canApprove(
            42,
            12,
            [Capabilities::APPROVE_DEANERY]
        );
    }
}

final class FakeDeaneryApprovalDatabase
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $deaneryIds = [];
    public ?string $preparedSql = null;
    public array $preparedArguments = [];
    public ?string $selectedQuery = null;
    public int $prepareCalls = 0;

    public function prepare(string $query, ...$arguments): string
    {
        ++$this->prepareCalls;
        $this->preparedSql = $query;
        $this->preparedArguments = $arguments;

        return 'prepared-query';
    }

    public function get_col(string $query): array
    {
        $this->selectedQuery = $query;

        return $this->deaneryIds;
    }
}
