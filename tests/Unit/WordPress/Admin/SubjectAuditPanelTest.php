<?php

declare(strict_types=1);

namespace {
    require_once dirname(__DIR__, 3) . '/Support/WordPressStubs.php';
    require_once dirname(__DIR__, 3) . '/Support/WordPressCapabilityStubs.php';
    require_once dirname(__DIR__, 3) . '/Support/AdminWordPressStubs.php';
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {
    use ADCT\ParishIntake\Core\Audit\AuditEntry;
    use ADCT\ParishIntake\Core\Audit\AuditLogReader;
    use ADCT\ParishIntake\Core\Audit\AuditQuery;
    use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\WordPress\Admin\SubjectAuditPanel;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;

    /**
     * The shared audit panel: the read-only audit trail mounted on the
     * candidate, event and parish screens.
     */
    final class SubjectAuditPanelTest extends TestCase
    {
        private const NOW = '2026-10-12T09:30:00+02:00';

        public function testItReadsOnlyTheRequestedSubjectWithinTheRetentionWindow(): void
        {
            $reader = new PanelStubReader();
            $panel = $this->panel($reader);

            ob_start();

            try {
                $panel->render(AuditSubjectType::EVENT_CANDIDATE, 42, 'Activity');
            } finally {
                ob_end_clean();
            }

            self::assertCount(1, $reader->queries);
            $query = $reader->queries[0];
            self::assertSame(AuditSubjectType::EVENT_CANDIDATE, $query->subjectType);
            self::assertSame(42, $query->subjectId);
            // windowStart() subtracts from `now` and keeps its zone, exactly as
            // the global audit screen does; AuditLogRepository converts to UTC
            // before it binds the value, so the zone here is not load-bearing.
            self::assertSame(
                '2024-10-12 09:30:00 SAST',
                $query->since?->format('Y-m-d H:i:s T')
            );
            self::assertSame(0, $query->offset);
            // Pinned exactly rather than "<= MAXIMUM_LIMIT": that weaker form
            // would also pass at limit 1, so it would guard nothing about the
            // page size actually chosen.
            self::assertSame(25, $query->limit);
        }

        public function testItReadsEveryParishContactRatherThanOnlyOneRow(): void
        {
            $reader = new PanelStubReader();
            $panel = $this->panel($reader);

            ob_start();

            try {
                $panel->renderMany(AuditSubjectType::PARISH_CONTACT, [7, 8, 9], 'Activity');
            } finally {
                ob_end_clean();
            }

            self::assertCount(3, $reader->queries);
            self::assertSame([7, 8, 9], array_map(
                    static fn (AuditQuery $query): int => $query->subjectId,
                    $reader->queries
                ));
        }

        public function testItRendersActionLabelsLocalTimesAndDetails(): void
        {
            $reader = new PanelStubReader([
                    new AuditEntry(
                        1,
                    'dean@example.test',
                    'approver_approved',
                    AuditSubjectType::EVENT_CANDIDATE,
                    42,
                    '{"from_status":"awaiting_approval","to_status":"approved"}',
                    new DateTimeImmutable('2026-10-09T15:04:05+00:00')
                    ),
            ]);

            $markup = $this->render($this->panel($reader), AuditSubjectType::EVENT_CANDIDATE, 42);

            self::assertStringContainsString('Activity', $markup);
            self::assertStringContainsString('Approver approved', $markup);
            self::assertStringContainsString('09/10/2026 17:04', $markup);
            self::assertStringContainsString('from_status: awaiting_approval', $markup);
            self::assertStringContainsString('to_status: approved', $markup);
            self::assertStringNotContainsString('approver_approved', $markup);
        }

        public function testItEscapesEverythingThatCameFromAParsedEmail(): void
        {
            $reader = new PanelStubReader([
                    new AuditEntry(
                        1,
                    '<script>alert(1)</script>',
                    'parish_contact_linked',
                    AuditSubjectType::PARISH_CONTACT,
                    7,
                    '{"email":"<img src=x onerror=alert(2)>"}',
                    new DateTimeImmutable('2026-10-09T15:04:05+00:00')
                    ),
            ]);

            $markup = $this->render($this->panel($reader), AuditSubjectType::PARISH_CONTACT, 7);

            self::assertStringNotContainsString('<script>', $markup);
            self::assertStringNotContainsString('<img src=x', $markup);
            self::assertStringContainsString('&lt;script&gt;', $markup);
            self::assertStringContainsString('&lt;img src=x', $markup);
        }

        /**
         * A read-only panel that offered a form or a nonce would be offering an
         * action, and there is no action to take.
         */
        public function testItOffersNoFormAndNoNonceField(): void
        {
            $reader = new PanelStubReader([
                    $this->entry(),
                ]);

            $markup = $this->render($this->panel($reader), AuditSubjectType::EVENT_CANDIDATE, 42);

            self::assertStringNotContainsString('<form', $markup);
            self::assertStringNotContainsString('method="post"', $markup);
            self::assertStringNotContainsString('_wpnonce', $markup);
        }

        public function testItSaysSoPlainlyWhenThereIsNothingToShow(): void
        {
            $markup = $this->render($this->panel(new PanelStubReader([])), AuditSubjectType::EVENT_CANDIDATE, 42);

            self::assertStringContainsString('Activity', $markup);
            self::assertStringContainsString('No audit entries', $markup);
        }

        public function testAReadFailureDoesNotRenderAnEmptyTableAsIfNothingHappened(): void
        {
            $reader = new PanelStubReader();
            $reader->failure = new \RuntimeException('The audit table is unavailable.');

            $markup = $this->render($this->panel($reader), AuditSubjectType::EVENT_CANDIDATE, 42);

            self::assertStringContainsString('could not be read', $markup);
            self::assertStringNotContainsString('No audit entries', $markup);
        }

        public function testItRefusesASubjectTypeItDoesNotRecognise(): void
        {
            $this->expectException(\InvalidArgumentException::class);

            $this->panel(new PanelStubReader())->render('not_a_subject_type', 1, 'Activity');
        }

        private function entry(): AuditEntry
        {
            return new AuditEntry(
                1,
                'dean@example.test',
                'approver_approved',
                AuditSubjectType::EVENT_CANDIDATE,
                42,
                null,
                new DateTimeImmutable('2026-10-09T15:04:05+00:00')
            );
        }

        private function panel(AuditLogReader $reader): SubjectAuditPanel
        {
            return new SubjectAuditPanel(
                $reader,
                new PanelClock(),
                new DateTimeZone('Africa/Johannesburg')
            );
        }

        private function render(SubjectAuditPanel $panel, string $subjectType, int $subjectId): string
        {
            ob_start();

            try {
                $panel->render($subjectType, $subjectId, 'Activity');
            } finally {
                $markup = (string) ob_get_clean();
            }

            return $markup;
        }
    }

    final class PanelClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-12T09:30:00+02:00');
        }
    }

    final class PanelStubReader implements AuditLogReader
    {
        /** @var list<AuditQuery> */
        public array $queries = [];

        public ?\RuntimeException $failure = null;

        /**
         * @param list<AuditEntry> $entries
         */
        public function __construct(private array $entries = [])
        {
        }

        public function entries(AuditQuery $query): array
        {
            $this->queries[] = $query;

            if ($this->failure !== null) {
                throw $this->failure;
            }

            return $this->entries;
        }

        public function count(AuditQuery $query): int
        {
            $this->queries[] = $query;

            if ($this->failure !== null) {
                throw $this->failure;
            }

            return count($this->entries);
        }
    }
}