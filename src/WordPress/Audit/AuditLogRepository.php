<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Audit;

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditEntry;
use ADCT\ParishIntake\Core\Audit\AuditLogReader;
use ADCT\ParishIntake\Core\Audit\AuditQuery;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\Core\Audit\AuditWriter;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * The audit trail in storage.
 *
 * This is the reader and the append path for `adct_pi_audit_log`. Several older
 * write sites (the review queue repository, the emailed action handlers) still
 * insert their own rows inline so they can share a transaction with the change
 * they describe; those are deliberate, and their "exactly one row affected"
 * invariant is covered by their own tests. Anything new goes through write().
 */
final class AuditLogRepository implements AuditLogReader, AuditWriter
{
    /** The actor recorded for an action taken by an unattended job. */
    public const SYSTEM_ACTOR = 'system';

    /** Never read more than this many rows for one screen, whatever the filters. */
    public const MAXIMUM_LIMIT = 200;

    /** The largest offset a screen may page to. */
    public const MAXIMUM_OFFSET = 10000;

    private ?string $audit = null;

    /**
     * @param Closure(): (string|null)|null $actorResolver Reads the signed-in
     *        user. Injected so the repository can be built without WordPress
     *        loaded and so the fallback is testable; null uses WordPress itself.
     */
    public function __construct(
        private readonly DatabaseConnectionInterface $database,
        private readonly ClockInterface $clock,
        private readonly ?Closure $actorResolver = null
    ) {
    }

    /**
     * The resolver behind actorForCurrentUser(), for injection into screens.
     *
     * A screen that writes a row and the repository that stores it must agree on
     * who the actor is, or the log would answer "who" two different ways. Both
     * therefore go through this one object.
     */
    public function actorResolver(): ActorResolver
    {
        return new WordPressActorResolver($this->actorResolver);
    }

    /**
     * The audit table, quoted once the prefix has been read.
     *
     * Reading the prefix needs a live database connection, which does not exist
     * while the plugin is being constructed during install, so it is deferred to
     * the first query rather than done in the constructor.
     */
    private function table(): string
    {
        if ($this->audit !== null) {
            return $this->audit;
        }

        $prefix = $this->database->prefix();

        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new InvalidArgumentException('The database prefix is invalid.');
        }

        return $this->audit = "`{$prefix}adct_pi_audit_log`";
    }

    /**
     * @return list<AuditEntry>
     */
    public function entries(AuditQuery $query): array
    {
        [$where, $arguments] = $this->conditions($query);
        $limit = min($query->limit, self::MAXIMUM_LIMIT);
        $offset = min($query->offset, self::MAXIMUM_OFFSET);
        $entries = [];

        foreach ($this->rows($this->prepared(
                    "SELECT id, actor, action, subject_type, subject_id, details, created_at FROM {$this->table()}"
            . " WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            [...$arguments, $limit, $offset]
        )) as $row) {
            $createdAt = $this->timestamp($row['created_at'] ?? null);

            if ($createdAt === null) {
                // A row we cannot place in time is not worth showing in a
                // time-ordered log; skipping it beats rendering a blank cell.
                continue;
            }

            $details = $row['details'] ?? null;

            $entries[] = new AuditEntry(
                (int) ($row['id'] ?? 0),
                (string) ($row['actor'] ?? ''),
                (string) ($row['action'] ?? ''),
                (string) ($row['subject_type'] ?? ''),
                (int) ($row['subject_id'] ?? 0),
                is_string($details) && $details !== '' ? $details : null,
                $createdAt
            );
        }

        return $entries;
    }

    public function count(AuditQuery $query): int
    {
        [$where, $arguments] = $this->conditions($query);
        $rows = $this->rows($this->prepared(
                    "SELECT COUNT(*) AS total FROM (SELECT id FROM {$this->table()} WHERE {$where}"
            . ' LIMIT ' . self::MAXIMUM_OFFSET . ') counted',
            $arguments
        ));
        $total = $rows[0]['total'] ?? 0;

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * @param array<string, mixed> $details
     */
    public function write(
        string $actor,
        AuditAction $action,
        string $subjectType,
        int $subjectId,
        array $details
    ): int {
        if ($actor === '') {
            throw new InvalidArgumentException('An audit entry needs an actor.');
        }

        if (! in_array($subjectType, AuditSubjectType::values(), true)) {
            throw new InvalidArgumentException('Unknown audit subject type.');
        }

        if ($subjectId < 0) {
            throw new InvalidArgumentException('The audit subject ID cannot be negative.');
        }

        $now = $this->now();
        $inserted = $this->execute($this->database->prepare(
                    "INSERT INTO {$this->table()} (actor, action, subject_type, subject_id, details, created_at, updated_at)"
            . ' VALUES (%s, %s, %s, %d, %s, %s, %s)',
            $actor,
            $action->value,
            $subjectType,
            $subjectId,
            $this->encode($details),
            $now,
            $now
        ));

        if ($inserted !== 1) {
            throw new RuntimeException('The audit record could not be saved.');
        }

        return $this->database->insertId();
    }

    /**
     * The actor recorded for an action taken in a browser session.
     *
     * The column holds an email address at every other write site, so a screen
     * action records the signed-in user's address too. One shape of value in
     * one column is what makes a "who did this" LIKE filter work at all.
     */
    public function actorForCurrentUser(): string
    {
        return $this->actorResolver()->actor();
    }

    /**
     * The WHERE clause and its arguments.
     *
     * The window is always bounded and each filter matches an indexed column,
     * so the existing actor, subject and created_at indexes stay usable and the
     * query stays inside the 90 second limit. The actor filter is a substring
     * match on purpose — a partial address is how an operator remembers one —
     * and escapeLike stops a stray % or _ from widening it.
     *
     * @return array{0: string, 1: list<string|int>}
     */
    private function conditions(AuditQuery $query): array
    {
        $clauses = ['created_at >= %s'];
        $arguments = [$this->format($query->since->setTimezone(new DateTimeZone('UTC')))];

        if ($query->until !== null) {
            $clauses[] = 'created_at <= %s';
            $arguments[] = $this->format($query->until->setTimezone(new DateTimeZone('UTC')));
        }

        if ($query->action !== '') {
            $clauses[] = 'action = %s';
            $arguments[] = $query->action;
        }

        if ($query->subjectType !== '') {
            $clauses[] = 'subject_type = %s';
            $arguments[] = $query->subjectType;
        }

        if ($query->subjectId > 0) {
            $clauses[] = 'subject_id = %d';
            $arguments[] = $query->subjectId;
        }

        if ($query->actor !== '') {
            $clauses[] = 'actor LIKE %s';
            $arguments[] = '%' . $this->database->escapeLike($query->actor) . '%';
        }

        return [implode(' AND ', $clauses), $arguments];
    }

    private function format(DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $details
     */
    private function encode(array $details): string
    {
        try {
            return json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException('The audit details could not be encoded.', 0, $failure);
        }
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        if ($moment === false || $moment->format('Y-m-d H:i:s') !== $value) {
            return null;
        }

        return $moment;
    }

    private function now(): string
    {
        return $this->format($this->clock->now()->setTimezone(new DateTimeZone('UTC')));
    }

    /**
     * @param list<string|int> $arguments
     */
    private function prepared(string $query, array $arguments): string
    {
        return $arguments === [] ? $query : $this->database->prepare($query, ...$arguments);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $query): array
    {
        $this->database->clearLastError();
        $rows = $this->database->getResults($query);

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The audit log could not be read: ' . $this->database->lastError());
        }

        return $rows;
    }

    private function execute(string $query): int|false
    {
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The audit log could not be written: ' . $this->database->lastError());
        }

        return $result;
    }
}