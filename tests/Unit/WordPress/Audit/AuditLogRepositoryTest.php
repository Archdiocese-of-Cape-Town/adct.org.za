<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Audit;

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditEntry;
use ADCT\ParishIntake\Core\Audit\AuditQuery;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Audit\AuditLogRepository;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditLogRepositoryTest extends TestCase
{
    private const NOW = '2026-10-12 07:00:00';

    private FakeDatabaseConnection $database;

    protected function setUp(): void
    {
        $this->database = new FakeDatabaseConnection();
    }

    public function testRejectsADatabasePrefixThatIsNotAnIdentifier(): void
    {
        $this->database->tablePrefix = 'wp_; DROP TABLE x; --';
            $repository = new AuditLogRepository($this->database, $this->clock());

            $this->expectException(InvalidArgumentException::class);

            $repository->entries($this->query());
        }

        /**
         * The prefix is only read when a query runs, not when the repository is
         * built: the plugin builds its whole object graph during install, before
         * there is a database connection to read the prefix from.
         */
    public function testCanBeBuiltBeforeThereIsADatabaseConnection(): void
        {
            self::assertInstanceOf(
                AuditLogRepository::class,
                new AuditLogRepository(new FakeDatabaseConnection(), $this->clock())
            );
        }

    public function testReadsThePrefixOnceAndReusesIt(): void
        {
            $repository = new AuditLogRepository($this->database, $this->clock());

            $repository->entries($this->query());
            $this->database->tablePrefix = 'changed_';
            $repository->entries($this->query());

            self::assertSame(1, $this->database->prefixReads);
            self::assertStringContainsString(
                '`wp_adct_pi_audit_log`',
                $this->database->preparedQueries[1]['query']
            );
        }

    public function testRejectsAnEmptyActor(): void
    {
        $repository = new AuditLogRepository($this->database, $this->clock());

        $this->expectException(InvalidArgumentException::class);

        $repository->write('', AuditAction::APPROVER_APPROVED, 'event_candidate', 1, []);
    }

    public function testRejectsAnUnknownSubjectType(): void
    {
        $repository = new AuditLogRepository($this->database, $this->clock());

        $this->expectException(InvalidArgumentException::class);

        $repository->write('dean@example.test', AuditAction::APPROVER_APPROVED, 'invoice', 1, []);
    }

    public function testRejectsANegativeSubjectId(): void
    {
        $repository = new AuditLogRepository($this->database, $this->clock());

        $this->expectException(InvalidArgumentException::class);

        $repository->write('dean@example.test', AuditAction::APPROVER_APPROVED, 'event_candidate', -1, []);
    }

    /**
     * The same invariant the older inline write sites keep: an audit row that
     * was not written is a silent gap in the record, so a partial insert has to
     * be loud.
     */
    public function testFailsWhenTheRowDidNotLand(): void
    {
        $this->database->affectedRows = 0;
        $repository = new AuditLogRepository($this->database, $this->clock());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The audit record could not be saved.');

        $repository->write('dean@example.test', AuditAction::APPROVER_APPROVED, 'event_candidate', 1, []);
    }

    public function testFailsWhenMoreThanOneRowLanded(): void
    {
        $this->database->affectedRows = 2;
        $repository = new AuditLogRepository($this->database, $this->clock());

        $this->expectException(RuntimeException::class);

        $repository->write('dean@example.test', AuditAction::APPROVER_APPROVED, 'event_candidate', 1, []);
    }

    public function testReportsAWriteFailureAsAFailedWrite(): void
    {
        $this->database->error = 'Table is full';
        $repository = new AuditLogRepository($this->database, $this->clock());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The audit log could not be written: Table is full');

        $repository->write('dean@example.test', AuditAction::APPROVER_APPROVED, 'event_candidate', 1, []);
    }

    public function testWritesTheActorActionSubjectAndJsonDetails(): void
    {
        $this->database->nextInsertId = 77;
        $repository = new AuditLogRepository($this->database, $this->clock());

        $id = $repository->write(
            'dean@example.test',
            AuditAction::CONTACT_BLOCKED,
            'parish_contact',
            9,
            ['email' => 'someone@example.test', 'trust' => 'blocked']
        );

        self::assertSame(77, $id);
        self::assertSame(
            'INSERT INTO `wp_adct_pi_audit_log` (actor, action, subject_type, subject_id, details, created_at, updated_at)'
            . ' VALUES (%s, %s, %s, %d, %s, %s, %s)',
            $this->database->preparedQueries[0]['query']
        );
        self::assertSame([
            'dean@example.test',
            'contact_blocked',
            'parish_contact',
            9,
            '{"email":"someone@example.test","trust":"blocked"}',
            self::NOW,
            self::NOW,
        ], $this->database->preparedQueries[0]['arguments']);
    }

    /**
     * A row timestamp has to be UTC regardless of where the site is configured,
     * because every existing row is and a mixed set is unreadable.
     */
    public function testWritesTheTimestampInUtc(): void
    {
        $clock = new FakeClock(new DateTimeImmutable('2026-10-12 09:00:00', new DateTimeZone('Africa/Johannesburg')));
        $repository = new AuditLogRepository($this->database, $clock);

        $repository->write('dean@example.test', AuditAction::APPROVER_APPROVED, 'event_candidate', 1, []);

        self::assertSame(self::NOW, $this->database->preparedQueries[0]['arguments'][5]);
    }

    public function testFailsWhenTheDetailsCannotBeEncoded(): void
    {
        $repository = new AuditLogRepository($this->database, $this->clock());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The audit details could not be encoded.');

        $repository->write('dean@example.test', AuditAction::APPROVER_APPROVED, 'event_candidate', 1, [
            'bad' => "\xB1\x31",
        ]);
    }

    public function testReadsEntriesNewestFirst(): void
    {
        $this->database->resultRows = [$this->row()];
        $repository = new AuditLogRepository($this->database, $this->clock());

        $entries = $repository->entries($this->query());

        self::assertCount(1, $entries);
            $prepared = $this->database->preparedQueries[0];
            self::assertStringContainsString(
                'ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
                $prepared['query']
            );
            self::assertSame([$this->since()->format('Y-m-d H:i:s'), 25, 0], $prepared['arguments']);

        $entry = $entries[0];
        self::assertInstanceOf(AuditEntry::class, $entry);
        self::assertSame(11, $entry->id);
        self::assertSame('dean@example.test', $entry->actor);
        self::assertSame('approver_approved', $entry->action);
        self::assertSame('event_candidate', $entry->subjectType);
        self::assertSame(42, $entry->subjectId);
        self::assertSame('{"title":"Alpha"}', $entry->details);
        self::assertSame(self::NOW, $entry->createdAt->format('Y-m-d H:i:s'));
    }

    public function testSkipsARowWithNoUsableTimestamp(): void
    {
        $row = $this->row();
        $row['created_at'] = '0000-00-00 00:00:00';
        $this->database->resultRows = [$row, $this->row()];
        $repository = new AuditLogRepository($this->database, $this->clock());

        $entries = $repository->entries($this->query());

        self::assertCount(1, $entries);
        self::assertSame(11, $entries[0]->id);
    }

    public function testTreatsEmptyDetailsAsAbsent(): void
    {
        $row = $this->row();
        $row['details'] = '';
        $this->database->resultRows = [$row];
        $repository = new AuditLogRepository($this->database, $this->clock());

        self::assertNull($repository->entries($this->query())[0]->details);
    }

    public function testFiltersOnEveryColumnTheScreenOffers(): void
    {
        $repository = new AuditLogRepository($this->database, $this->clock());

        $repository->entries(new AuditQuery(
            AuditAction::APPROVER_APPROVED->value,
            'event_candidate',
            42,
            'dean@example.test',
            $this->since(),
            $this->since()->modify('+1 day'),
            25,
            50
        ));

        $prepared = $this->database->preparedQueries[0];

        self::assertStringContainsString('created_at >= %s', $prepared['query']);
        self::assertStringContainsString('created_at <= %s', $prepared['query']);
        self::assertStringContainsString('action = %s', $prepared['query']);
        self::assertStringContainsString('subject_type = %s', $prepared['query']);
        self::assertStringContainsString('subject_id = %d', $prepared['query']);
        self::assertStringContainsString('actor LIKE %s', $prepared['query']);
        self::assertSame([
            '2026-09-12 07:00:00',
            '2026-09-13 07:00:00',
            'approver_approved',
            'event_candidate',
            42,
            '%dean@example.test%',
            25,
            50,
        ], $prepared['arguments']);
    }

    #[DataProvider('unsetFilters')]
        public function testLeavesOutTheClauseForAFilterThatIsNotSet(
        string $clause,
        callable $build
    ): void {
        $repository = new AuditLogRepository($this->database, $this->clock());

        $repository->entries($build());

        $prepared = $this->database->preparedQueries[0];

        self::assertStringNotContainsString($clause, $prepared['query']);
    }

    /**
     * @return array<string, array{string, callable}>
     */
    public static function unsetFilters(): array
    {
        return [
            'no end of window' => ['created_at <= %s', static fn (): AuditQuery => new AuditQuery(since: new DateTimeImmutable('2026-09-12 07:00:00', new DateTimeZone('UTC')))],
            'no action' => ['action = %s', static fn (): AuditQuery => new AuditQuery(since: new DateTimeImmutable('2026-09-12 07:00:00', new DateTimeZone('UTC')))],
            'no subject type' => ['subject_type = %s', static fn (): AuditQuery => new AuditQuery(since: new DateTimeImmutable('2026-09-12 07:00:00', new DateTimeZone('UTC')))],
            'no subject id' => ['subject_id = %d', static fn (): AuditQuery => new AuditQuery(since: new DateTimeImmutable('2026-09-12 07:00:00', new DateTimeZone('UTC')))],
            'no actor' => ['actor LIKE %s', static fn (): AuditQuery => new AuditQuery(since: new DateTimeImmutable('2026-09-12 07:00:00', new DateTimeZone('UTC')))],
        ];
    }

    /**
         * A partial address is how an operator remembers one, but a stray wildcard
         * in what they typed must not turn the filter into a table scan of
         * everyone. Both the typed % and _ are escaped, and only the wildcards the
         * repository itself adds stay unescaped.
                  */
                 #[DataProvider('wildcardAddresses')]
                 public function testEscapesBothLikeWildcardsInTheActorFilter(string $typed): void
        {
            $repository = new AuditLogRepository($this->database, $this->clock());

            $repository->entries($this->query(actor: $typed));

            self::assertSame(
                '%' . str_replace(['%', '_'], ['\\%', '\\_'], $typed) . '%',
                $this->database->preparedQueries[0]['arguments'][1]
            );
        }

        /**
         * @return array<string, list<string>>
         */
        public static function wildcardAddresses(): array
        {
            return [
                'percent' => ['50%'],
                'underscore' => ['s_mith'],
                'both' => ['%_everyone'],
            ];
        }

    public function testReadsWithNoFiltersAtAllRatherThanPreparingNothing(): void
    {
        $repository = new AuditLogRepository($this->database, $this->clock());

        $repository->entries($this->query());

        self::assertStringContainsString(
                'WHERE created_at >= %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
            $this->database->preparedQueries[0]['query']
        );
    }

    public function testCountsTheRowsTheScreenWillPageThrough(): void
    {
        $this->database->resultRows = [['total' => '137']];
        $repository = new AuditLogRepository($this->database, $this->clock());

        self::assertSame(137, $repository->count($this->query()));
        self::assertStringContainsString(
            'SELECT COUNT(*) AS total FROM (SELECT id FROM `wp_adct_pi_audit_log` WHERE created_at >= %s'
            . ' LIMIT ' . AuditLogRepository::MAXIMUM_OFFSET . ') counted',
            $this->database->preparedQueries[0]['query']
        );
    }

    public function testACountIsNeverInfinite(): void
    {
        $this->database->resultRows = [];
        $repository = new AuditLogRepository($this->database, $this->clock());

        self::assertSame(0, $repository->count($this->query()));
    }

    public function testReportsAReadFailureRatherThanShowingAnEmptyLog(): void
    {
        $this->database->error = 'Deadlock found';
        $repository = new AuditLogRepository($this->database, $this->clock());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The audit log could not be read: Deadlock found');

        $repository->entries($this->query());
    }

    /**
     * Names the signed-in user by address, because that is what every other
     * write site puts in the actor column. A login with no address is recorded
     * as the system rather than as an empty string, so it is still attributable.
     */
    public function testNamesTheActorAfterTheSignedInUser(): void
        {
            $repository = $this->repositoryWithActor(static fn (): ?string => 'dean@example.test');

            self::assertSame('dean@example.test', $repository->actorForCurrentUser());
        }

    public function testASignedInUserWithNoAddressIsRecordedAsTheSystem(): void
        {
            $repository = $this->repositoryWithActor(static fn (): ?string => '');

            self::assertSame(AuditLogRepository::SYSTEM_ACTOR, $repository->actorForCurrentUser());
        }

    public function testAnUnattendedActorIsTheSystem(): void
        {
            $repository = $this->repositoryWithActor(static fn (): ?string => null);

            self::assertSame('system', $repository->actorForCurrentUser());
        }

    private function query(
        string $action = '',
        string $subjectType = '',
        int $subjectId = 0,
        string $actor = ''
    ): AuditQuery {
        return new AuditQuery($action, $subjectType, $subjectId, $actor, $this->since(), null, 25, 0);
    }

    private function since(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-12 07:00:00', new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, string>
     */
    private function row(): array
    {
        return [
            'id' => '11',
            'actor' => 'dean@example.test',
            'action' => 'approver_approved',
            'subject_type' => 'event_candidate',
            'subject_id' => '42',
            'details' => '{"title":"Alpha"}',
            'created_at' => self::NOW,
        ];
    }

    private function clock(): ClockInterface
    {
        return new FakeClock(new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
    }

    private function repositoryWithActor(\Closure $actor): AuditLogRepository
        {
            return new AuditLogRepository($this->database, $this->clock(), $actor);
        }
    }

final class FakeClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $moment)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->moment;
    }
}

final class FakeDatabaseConnection implements DatabaseConnectionInterface
{
    public string $tablePrefix = 'wp_';

    /**
     * @var array<int, array{query: string, arguments: array<int, mixed>}>
     */
    public array $preparedQueries = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $resultRows = [];

    public int $affectedRows = 1;

    public int $nextInsertId = 1;

    public string $error = '';

        public int $prefixReads = 0;

    public function prefix(): string
        {
            $this->prefixReads++;

            return $this->tablePrefix;
        }

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->preparedQueries[] = ['query' => $query, 'arguments' => $arguments];

        return $query;
    }

    public function query(string $query): int|false
    {
        return $this->affectedRows;
    }

    public function getRow(string $query): ?array
    {
        return $this->resultRows[0] ?? null;
    }

    public function getResults(string $query): array
    {
        return $this->resultRows;
    }

    public function escapeLike(string $text): string
    {
            return addcslashes($text, '_%\\');
    }

    public function insertId(): int
    {
        return $this->nextInsertId;
    }

    public function charsetCollate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function clearLastError(): void
    {
    }

    public function lastError(): string
    {
        return $this->error;
    }
}
