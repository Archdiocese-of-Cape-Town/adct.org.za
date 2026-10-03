<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\FollowUpRepositoryInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\FollowUpRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FollowUpRepositoryTest extends TestCase
{
    /** @var list<array{sql: string, args: list<mixed>}> */
    private array $prepared = [];

    public function testAReminderIsAppendedToTheFollowUpTable(): void
    {
        $this->record(5, 'candidate 12');

        self::assertCount(1, $this->prepared);
        self::assertStringContainsString(
            'INSERT INTO `wp_adct_pi_follow_ups`',
            $this->prepared[0]['sql']
        );
        self::assertSame(
            [5, 'approval_reminder', 'email'],
            array_slice($this->prepared[0]['args'], 0, 3)
        );
        self::assertContains('candidate 12', $this->prepared[0]['args']);
    }

    public function testTheRecordedTimestampComesFromTheInjectedClockInUtc(): void
    {
        $this->record(5);

        // 08:00 Africa/Johannesburg is 06:00 UTC.
        self::assertContains('2026-09-24 06:00:00', $this->prepared[0]['args']);
    }

    public function testAnExplicitSentTimeAndOutcomeAreStored(): void
    {
        $this->record(5, 'candidate 12', '2026-09-24 05:59:00', 'queued');

        self::assertContains('2026-09-24 05:59:00', $this->prepared[0]['args']);
        self::assertContains('queued', $this->prepared[0]['args']);
    }

    public function testAFollowUpWithoutAParishIsStoredAsNullRatherThanAsZero(): void
    {
        (new FollowUpRepository($this->database(), $this->clock()))->record(
            null,
            FollowUpRepositoryInterface::KIND_APPROVAL_REMINDER,
            FollowUpRepositoryInterface::CHANNEL_EMAIL,
            'candidate 12'
        );

        // A %d placeholder would have $wpdb substitute 0 for the null and
        // attribute the follow-up to a parish that does not exist.
        self::assertStringContainsString(
            'VALUES (NULL,%s,%s,%s,%s,%s,%s,%s)',
            $this->prepared[0]['sql']
        );
        self::assertStringNotContainsString('%d', $this->prepared[0]['sql']);
        self::assertSame(
                    ['approval_reminder', 'email', '2026-09-24 06:00:00', null, 'candidate 12'],
                    array_slice($this->prepared[0]['args'], 0, 5)
        );
    }

    public function testAPrishedFollowUpNeverSubstitutesZeroForTheParishColumn(): void
    {
        $this->record(5);

        self::assertStringContainsString(
            'VALUES (%d,%s,%s,%s,%s,%s,%s,%s)',
            $this->prepared[0]['sql']
        );
        self::assertSame(5, $this->prepared[0]['args'][0]);
    }

    #[DataProvider('invalidRows')]
    public function testAnUnusableRowIsRejectedBeforeItReachesTheDatabase(
        int $parishId,
        string $kind,
        string $channel
    ): void {
        $database = $this->database();
        $database->expects(self::never())->method('query');
        $database->expects(self::never())->method('prepare');

        $this->expectException(InvalidArgumentException::class);

        (new FollowUpRepository($database, $this->clock()))->record($parishId, $kind, $channel);
    }

    public static function invalidRows(): iterable
    {
        yield 'zero parish' => [0, FollowUpRepositoryInterface::KIND_APPROVAL_REMINDER, 'email'];
        yield 'negative parish' => [-3, FollowUpRepositoryInterface::KIND_APPROVAL_REMINDER, 'email'];
        yield 'unknown kind' => [5, 'parish_nudge', 'email'];
        yield 'unknown channel' => [5, FollowUpRepositoryInterface::KIND_APPROVAL_REMINDER, 'sms'];
    }

    public function testAFailedWriteIsReportedRatherThanSwallowed(): void
    {
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_');
        $database->method('prepare')->willReturnCallback(static fn (string $sql): string => $sql);
        $database->method('query')->willReturn(false);
        $database->method('lastError')->willReturn('table is full');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The follow-up could not be recorded.');

        (new FollowUpRepository($database, $this->clock()))->record(
            5,
            FollowUpRepositoryInterface::KIND_APPROVAL_REMINDER,
            FollowUpRepositoryInterface::CHANNEL_EMAIL
        );
    }

    public function testTheTablePrefixIsValidatedBeforeItReachesTheStatement(): void
    {
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_; DROP TABLE');
        $database->expects(self::never())->method('query');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The database prefix is invalid.');

        (new FollowUpRepository($database, $this->clock()))->record(
            5,
            FollowUpRepositoryInterface::KIND_APPROVAL_REMINDER,
            FollowUpRepositoryInterface::CHANNEL_EMAIL
        );
    }

    public function testTheConstructorReachesForNoDatabaseSoItBootsWithoutWordPress(): void
    {
        // The release bootstrap check constructs the plugin with no WordPress
        // loaded, so building the repository must not touch the database.
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->expects(self::never())->method('prefix');

        new FollowUpRepository($database, $this->clock());
    }

    private function record(int $parishId, ?string $note = null, ?string $sentAt = null, ?string $outcome = null): void
    {
        (new FollowUpRepository($this->database(), $this->clock()))->record(
            $parishId,
            FollowUpRepositoryInterface::KIND_APPROVAL_REMINDER,
            FollowUpRepositoryInterface::CHANNEL_EMAIL,
            $note,
            $sentAt,
            $outcome
        );
    }

    private function database(): DatabaseConnectionInterface
    {
        $this->prepared = [];

        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_');
        $database->method('lastError')->willReturn('');
        $database->method('prepare')->willReturnCallback(
            function (string $sql, mixed ...$args): string {
                $this->prepared[] = ['sql' => $sql, 'args' => array_values($args)];

                return $sql;
            }
        );
        $database->method('query')->willReturn(1);

        return $database;
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(
            new DateTimeImmutable('2026-09-24 08:00:00', new DateTimeZone('Africa/Johannesburg'))
        );

        return $clock;
    }
}
