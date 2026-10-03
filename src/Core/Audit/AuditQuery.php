<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Audit;

use DateTimeImmutable;

/**
 * A bounded, validated read of the audit log.
 *
 * The admin screen can see up to 24 months of history (E2.7), which on shared
 * hosting is far more than a 90 second request can page through. Two limits
 * keep the screen safe:
 *
 * - `limit` is clamped by the repository, never trusted from the query string.
 * - `since` is required. The caller picks a recent window by default; the
 *   repository refuses a window older than the retention horizon so a screen
 *   cannot be pointed at data the plugin is about to delete.
 */
final class AuditQuery
{
    /** The oldest window the screen may ask for, matching E2.7 retention. */
    public const MAXIMUM_WINDOW_MONTHS = 24;

    /** A hard ceiling on rows read for one screen, whatever the filters. */
    public const MAXIMUM_LIMIT = 200;

    public function __construct(
        public readonly string $action = '',
        public readonly string $subjectType = '',
        public readonly int $subjectId = 0,
        public readonly string $actor = '',
        public readonly ?DateTimeImmutable $since = null,
        public readonly ?DateTimeImmutable $until = null,
        public readonly int $limit = 50,
        public readonly int $offset = 0
    ) {
        if ($action !== '' && ! in_array($action, AuditAction::values(), true)) {
            throw new \InvalidArgumentException('Unknown audit action.');
        }

        if ($subjectType !== '' && ! in_array($subjectType, AuditSubjectType::values(), true)) {
            throw new \InvalidArgumentException('Unknown audit subject type.');
        }

        if ($subjectId < 0) {
            throw new \InvalidArgumentException('The audit subject ID cannot be negative.');
        }

        if ($since === null) {
            throw new \InvalidArgumentException('An audit query needs a start of the window.');
        }

        if ($until !== null && $until < $since) {
            throw new \InvalidArgumentException('The audit window ends before it starts.');
        }

        if ($this->spansMoreThanRetention($since, $until)) {
            throw new \InvalidArgumentException('The audit window is longer than the retention period.');
        }

        if ($limit < 1 || $limit > self::MAXIMUM_LIMIT) {
            throw new \InvalidArgumentException('The audit page size is out of range.');
        }

        if ($offset < 0) {
            throw new \InvalidArgumentException('The audit offset cannot be negative.');
        }
    }

    /**
     * The same filters with different paging, so a count and a page of rows
     * are guaranteed to describe the same set of entries.
     */
    public function withPaging(int $limit, int $offset): self
    {
        return new self(
            $this->action,
            $this->subjectType,
            $this->subjectId,
            $this->actor,
            $this->since,
            $this->until,
            $limit,
            $offset
        );
    }

    /**
     * A query for the most recent `months` of history, which is what the
     * screen falls back to when the visitor has not chosen a window.
     */
    public static function recent(
        DateTimeImmutable $now,
        int $months = 3,
        int $limit = 50,
        int $offset = 0,
        string $action = '',
        string $subjectType = '',
        int $subjectId = 0,
        string $actor = ''
    ): self {
        return new self(
            $action,
            $subjectType,
            $subjectId,
            $actor,
                        self::windowStart($now, $months),
            null,
            $limit,
            $offset
        );
    }

    /**
         * The start of a window `months` back from now, clamped to the range the
         * screen offers. A nonsense window from a hand-edited query string is
         * pulled into range rather than refused: the visitor still gets a log to
         * read, and the clamp is to a range retention keeps.
         */
        public static function windowStart(DateTimeImmutable $now, int $months): DateTimeImmutable
        {
            return $now->modify(sprintf(
                '-%d months',
                max(1, min(self::MAXIMUM_WINDOW_MONTHS, $months))
            ));
        }

        /**
         * Whether the window is longer than the plugin keeps data.
         *
         * A window cannot reach further back than retention deletes, so a window
         * longer than the retention period would only ever show empty leading space
         * and imply history the plugin no longer has. The bound is expressed on the
         * length of the window rather than on its start, because a query carries no
         * clock and "24 months ago" is only meaningful relative to one.
         */
        private function spansMoreThanRetention(DateTimeImmutable $since, ?DateTimeImmutable $until): bool
        {
            if ($until === null) {
                return false;
            }

            return $until > $since->modify('+' . self::MAXIMUM_WINDOW_MONTHS . ' months');
                }
            }