<?php

/**
 * Fakes for issue #169, the deanery-approver notification-mode link.
 *
 * Kept out of tests/Support/WordPressStubs.php deliberately (#216 tracks that
 * file's growth): these describe one feature's rows, not WordPress itself, and
 * only the #169 tests want them.
 */

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Support {

    use ADCT\ParishIntake\Core\Approval\Approver;
    use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
    use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
    use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use DateTimeImmutable;
    use DateTimeZone;

    final class NotifyModeClock implements ClockInterface
    {
        public function __construct(private string $moment)
        {
        }

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->moment, new DateTimeZone('Africa/Johannesburg'));
        }
    }

    /**
     * A stand-in for the deanery approver assignments, the deaneries and the
     * audit log — deliberately also the token store.
     *
     * Combining them is what makes the most important assertion in the #169
     * tests possible: that a rollback un-consumes the token, so a refused change
     * does not burn the link. The real plugin gets that for free because both go
     * through the same $wpdb transaction; a fake that kept them apart would let
     * a genuine bug — consuming the token on a refused save — pass unnoticed.
     */
    final class NotifyModeDatabase implements DatabaseConnectionInterface, ActionTokenStoreInterface
    {
        public const DEAN = 'dean@example.test';
        public const DEAN_USER_ID = 101;
        public const FIRST_DEANERY_ID = 31;
        public const SECOND_DEANERY_ID = 32;
        public const ASSIGNMENT_ID = 7;
        public const SECOND_ASSIGNMENT_ID = 8;
        public const OTHER_DEAN_USER_ID = 202;

        /**
         * Somebody else's live assignment, so a refusal aimed at them cannot
         * be masked by the assignment lookup also coming back empty.
         */
        public const OTHER_ASSIGNMENT_ID = 9;

        /**
         * @var array<int, array<string, mixed>>
         */
        private array $assignments;

        /**
         * @var array<int, string>
         */
        private array $deaneryStatus;

        /**
         * The assignment rows as they stood when the open transaction began.
         *
         * @var array<int, array<string, mixed>>
         */
        private array $assignmentSnapshot = [];

        /**
         * @var array<string, ActionTokenRecord>
         */
        private array $records = [];

        /**
         * The token table as it stood when the open transaction began.
         *
         * @var array<string, ActionTokenRecord>
         */
        private array $tokenSnapshot = [];

        /**
         * @var list<array<string, mixed>>
         */
        private array $auditSnapshot = [];

        /**
         * @var list<array<string, mixed>>
         */
        private array $pendingAudit = [];

        /**
         * Writes run outside a transaction, so "a refusal wrote nothing" is checkable.
         *
         * @var list<string>
         */
        public array $writes = [];

        /**
         * @var list<string>
         */
        public array $transactions = [];

        /**
         * @var list<array<string, mixed>>
         */
        public array $auditRows = [];

        /**
         * Columns written by updates still inside the open transaction.
         *
         * @var array<string, array<string, string>>
         */
        private array $pending = [];

        /**
         * Assignment ids the lookups will see, or null for all of them.
         *
         * @var list<int>|null
         */
        private ?array $visible = null;

        public function __construct()
        {
            $this->assignments = [
                self::ASSIGNMENT_ID => $this->assignment(self::ASSIGNMENT_ID, self::FIRST_DEANERY_ID, self::DEAN_USER_ID),
                self::SECOND_ASSIGNMENT_ID => $this->assignment(
                    self::SECOND_ASSIGNMENT_ID,
                    self::SECOND_DEANERY_ID,
                    self::DEAN_USER_ID
                ),
                // The other dean's live assignment. Seeding it by default is what
                // lets a test point a binding at the other dean and get past the
                // assignment lookup, so the only thing left to refuse them is
                // the guard actually under test.
                self::OTHER_ASSIGNMENT_ID => $this->assignment(
                    self::OTHER_ASSIGNMENT_ID,
                    self::FIRST_DEANERY_ID,
                    self::OTHER_DEAN_USER_ID
                ),
            ];
            $this->deaneryStatus = [
                self::FIRST_DEANERY_ID => 'active',
                self::SECOND_DEANERY_ID => 'active',
            ];
        }

        /**
         * @return array<string, mixed>|null
         */
        public function assignmentRow(int $id): ?array
        {
            return $this->assignments[self::key($id)] ?? null;
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        public function assignmentRows(): array
        {
            return $this->assignments;
        }

        public function setNotifyMode(int $assignmentId, string $mode): void
        {
            $this->assignments[self::key($assignmentId)]['notify_mode'] = $mode;
        }

        /**
         * Gives an assignment a different approval address, which the operator
         * guide allows: the address a dean is notified at does not have to be
         * the one on their WordPress account.
         */
        public function setAssignmentEmail(int $assignmentId, string $email): void
        {
            $this->assignments[self::key($assignmentId)]['email'] = strtolower(trim($email));
        }

        /**
         * Puts an assignment with a different WordPress user, so a test can model
         * two live approvers who both notify the same shared address.
         */
        public function setAssignmentWpUserId(int $assignmentId, int $wpUserId): void
        {
            $this->assignments[self::key($assignmentId)]['wp_user_id'] = $wpUserId;
        }

        /**
         * Starts the fake off seeing none of the seeded rows, so a test has to
         * say which assignments are live. Without this the "the notice address
         * belongs to nobody live" case would silently inherit the seeded dean.
         */
        public function setVisibleAssignments(array $ids): void
        {
            $this->visible = $ids;
        }

        /**
         * Adds one assignment id to what the lookups can see.
         */
        public function addVisibleAssignment(int $assignmentId): void
        {
            $this->visible ??= [];
            $this->visible[] = $assignmentId;
        }

        public function deactivateDeanery(int $deaneryId): void
        {
            $this->deaneryStatus[$deaneryId] = 'inactive';
        }

        public function deactivateAssignment(int $assignmentId): void
        {
            $this->assignments[self::key($assignmentId)]['active'] = '0';
        }

        private function assignment(int $id, int $deaneryId, int $wpUserId): array
        {
            return [
                'id' => (string) $id,
                'deanery_id' => (string) $deaneryId,
                'wp_user_id' => (string) $wpUserId,
                'email' => self::emailForWpUser((int) $wpUserId),
                'label' => 'Dean',
                'notify_mode' => Approver::NOTIFY_EACH,
                'reminders_enabled' => '1',
                'active' => '1',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ];
        }

        /**
         * @param int $wpUserId
         */
        private static function emailForWpUser(int $wpUserId): string
        {
            return $wpUserId === self::DEAN_USER_ID ? self::DEAN : 'other@example.test';
        }


        private static function key(int $id): string
        {
            return (string) $id;
        }

        public function prefix(): string
        {
            return 'wp_';
        }

        /**
         * Arguments are appended so the handler still has to supply every one of
         * them, and nothing can be interpolated raw.
         */
        public function prepare(string $query, mixed ...$arguments): string
        {
            return $query . ' /* ' . (string) json_encode($arguments, JSON_THROW_ON_ERROR) . ' */';
        }

        public function query(string $query): int|false
        {
            $trimmed = trim($query);
            $upper = strtoupper($trimmed);

            if ($upper === 'START TRANSACTION') {
                $this->transactions[] = $upper;
                $this->assignmentSnapshot = $this->assignments;
                $this->tokenSnapshot = $this->records;
                $this->auditSnapshot = $this->auditRows;

                return 0;
            }

            if ($upper === 'COMMIT') {
                $this->transactions[] = $upper;
                $this->pendingAssignments();
                $this->auditRows = array_merge($this->auditRows, $this->pendingAudit);
                $this->pendingAudit = [];

                return 0;
            }

            if ($upper === 'ROLLBACK') {
                $this->transactions[] = $upper;
                $this->assignments = $this->assignmentSnapshot;
                $this->records = $this->tokenSnapshot;
                $this->auditRows = $this->auditSnapshot;
                $this->pendingAudit = [];

                return 0;
            }

            $this->writes[] = $trimmed;
            $values = self::argumentsOf($trimmed);

            if (str_contains($trimmed, 'UPDATE wp_adct_pi_deanery_approvers')) {
                $this->pending[(string) ($values[2] ?? '')] = [
                    'notify_mode' => (string) ($values[0] ?? ''),
                    'updated_at' => (string) ($values[1] ?? ''),
                ];

                return 1;
            }

            if (str_contains($trimmed, 'INSERT INTO `wp_adct_pi_audit_log`')) {
                $this->pendingAudit[] = [
                    'actor' => (string) ($values[0] ?? ''),
                    'action' => (string) ($values[1] ?? ''),
                    'subjectType' => (string) ($values[2] ?? ''),
                    'subjectId' => (int) ($values[3] ?? 0),
                    'details' => json_decode(
                        (string) ($values[4] ?? '{}'),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    ),
                    'createdAt' => (string) ($values[5] ?? ''),
                ];

                return 1;
            }

            return 1;
        }

        private function pendingAssignments(): void
        {
            foreach ($this->pending as $id => $columns) {
                foreach ($columns as $column => $value) {
                    $this->assignments[(string) $id][$column] = $value;
                }
            }

            $this->pending = [];
        }

        public function getRow(string $query): ?array
        {
            return null;
        }

        /**
         * Only the two live-assignment reads are served; nothing else in this
         * feature reads rows through getResults().
         *
         * @return list<mixed>
         */
        public function getResults(string $query): array
        {
            if (! str_contains($query, 'adct_pi_deanery_approvers')) {
                return [];
            }

            $arguments = self::argumentsOf($query);

            if (str_contains($query, 'SELECT a.wp_user_id')) {
                return $this->liveWpUserIdsByEmail(
                    (string) ($arguments[0] ?? ''),
                    (int) ($arguments[1] ?? 0),
                    (string) ($arguments[2] ?? '')
                );
            }

            $wpUserId = (int) ($arguments[0] ?? 0);
            $active = (int) ($arguments[1] ?? 0);
            $status = (string) ($arguments[2] ?? '');

            $rows = [];

            foreach ($this->visibleAssignments() as $id => $assignment) {
                if ((int) $assignment['wp_user_id'] !== $wpUserId
                    || $active !== (int) $assignment['active']
                    || ($this->deaneryStatus[(int) $assignment['deanery_id']] ?? null) !== $status
                ) {
                    continue;
                }

                $rows[] = [
                    'id' => $id,
                    'deanery_id' => $assignment['deanery_id'],
                    'notify_mode' => $assignment['notify_mode'],
                ];
            }

            usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

            return $rows;
        }

        /**
         * @return array<string, array<string, mixed>>
         */
        private function visibleAssignments(): array
        {
            if ($this->visible === null) {
                return $this->assignments;
            }

            $rows = [];
            foreach ($this->assignments as $id => $assignment) {
                if (in_array((int) $id, $this->visible, true)) {
                    $rows[$id] = $assignment;
                }
            }

            return $rows;
        }

        /**
         * The notice-address lookup the approval mail uses to find who a
         * recipient is, so a test can give an approver an approval address that
         * differs from the one on their WordPress account.
         *
         * @return list<array<string, mixed>>
         */
        private function liveWpUserIdsByEmail(string $email, int $active, string $status): array
        {
            $email = strtolower(trim($email));
            $rows = [];

            foreach ($this->visibleAssignments() as $id => $assignment) {
                if (strtolower((string) $assignment['email']) !== $email
                    || $active !== (int) $assignment['active']
                    || ($this->deaneryStatus[(int) $assignment['deanery_id']] ?? null) !== $status
                ) {
                    continue;
                }

                $rows[] = ['wp_user_id' => (string) $assignment['wp_user_id']];
            }

            return $rows;
        }

        public function escapeLike(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function insertId(): int
        {
            return 1;
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
            return '';
        }

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

            if ($record === null || ! $record->binding->equals($binding)) {
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

        /**
         * @return list<mixed>
         */
        private static function argumentsOf(string $prepared): array
        {
            $position = strrpos($prepared, '/*');

            if ($position === false) {
                return [];
            }

            $decoded = json_decode(substr($prepared, $position + 2, -2), true);

            return is_array($decoded) ? $decoded : [];
        }
    }
}
