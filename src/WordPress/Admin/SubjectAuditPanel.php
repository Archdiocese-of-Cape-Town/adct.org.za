<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Audit\AuditLogReader;
use ADCT\ParishIntake\Core\Audit\AuditQuery;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * The audit trail for one subject, mounted on the candidate, event and parish
 * screens.
 *
 * Issue #58 asked for an audit tab on candidates, events and parishes. One
 * renderer mounted three times rather than three tabs that look the same and
 * then diverge: the row formatting, the window and the escaping are the same
 * problem in all three places, and a details column that escapes correctly on
 * one screen and not the other two is a vulnerability, not a cosmetic bug.
 *
 * Read-only, like the global audit screen, and for the same reason. There is no
 * form, no POST handler and no nonce field here, because there is no action to
 * take: the panel renders rows and nothing else. A GET cannot change anything,
 * so there is nothing to protect with a nonce and nothing to check a capability
 * against beyond the screen that mounted it.
 *
 * The window is the whole retention horizon, 24 months, rather than the three
 * the global screen defaults to. This panel answers "what has happened to
 * this one thing", and a candidate or parish that has been quiet for a year
 * would otherwise read as "nothing ever happened to it".
 *
 * Like the global screen, a read failure is said out loud. Rendering an empty
 * table would be indistinguishable from "nothing happened", which for an audit
 * trail is the one wrong answer available.
 */
final class SubjectAuditPanel
{
    /**
     * Rows per panel. Small enough that a panel renders well inside the 90
     * second request limit, large enough to cover the life of one subject.
     */
    private const PANEL_LIMIT = 25;

    /**
     * The whole retention horizon (E2.7), which is the most this panel can
     * honestly show: anything older has already been deleted by the job.
     */
    private const WINDOW_MONTHS = AuditQuery::MAXIMUM_WINDOW_MONTHS;

    private const ERROR_SENTENCE = 'The audit trail could not be read. The entries exist but the screen could not load them.';

    public function __construct(
        private readonly AuditLogReader $audit,
        private readonly ClockInterface $clock,
        private readonly DateTimeZone $timezone
    ) {
    }

    /**
     * The audit trail for one row.
     */
    public function render(string $subjectType, int $subjectId, string $heading = 'Audit trail'): void
    {
        $this->renderMany($subjectType, $subjectId > 0 ? [$subjectId] : [], $heading);
    }

    /**
     * The audit trail for several rows of the same subject type.
     *
     * A parish's trail is not one row: it is the trail of every contact linked
     * to it, because the questions an auditor asks about a parish are questions
     * about its people and their addresses, not about the parish record, which
     * nothing writes audit rows for.
     *
     * @param list<int> $subjectIds
     */
    public function renderMany(string $subjectType, array $subjectIds, string $heading = 'Audit trail'): void
    {
        $this->requireKnownSubjectType($subjectType);

        $subjectIds = array_values(array_unique(array_filter(
                    array_map('intval', $subjectIds),
                    static fn (int $id): bool => $id > 0
                )));

        $entries = [];
        $failed = false;

        foreach ($subjectIds as $subjectId) {
            try {
                foreach ($this->audit->entries($this->query($subjectType, $subjectId)) as $entry) {
                    $entries[] = $entry;
                }
            } catch (Throwable $failure) {
                // Logged and then reported below rather than swallowed: a
                // partial trail presented as a complete one is worse than an
                // honest failure, so nothing is rendered as though it were whole.
                error_log(
                    '[ADCT Parish Intake] A subject audit panel could not be read ('
                    . get_class($failure) . ').'
                );
                $failed = true;
                $entries = [];
                break;
            }
        }

        $formatter = new AuditEntryFormatter($this->timezone);
        ?>
        <section class="adct-pi-subject-audit" id="adct-pi-subject-audit">
        <h2><?php echo esc_html($heading); ?></h2>
        <?php if ($failed) : ?>
        <p class="adct-pi-audit-error"><?php echo esc_html(self::ERROR_SENTENCE); ?></p>
        <?php elseif ($entries === []) : ?>
        <p><?php echo esc_html('No audit entries are recorded for this in the last 24 months.'); ?></p>
        <?php else : ?>
        <table class="widefat striped">
        <thead>
        <tr>
        <th scope="col">When</th>
        <th scope="col">Who</th>
        <th scope="col">Action</th>
        <th scope="col">Details</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $entry) : ?>
        <tr>
        <td><?php echo esc_html($formatter->timestamp($entry->createdAt)); ?></td>
        <td><?php echo esc_html($entry->actor); ?></td>
        <td><?php echo esc_html($entry->actionLabel()); ?></td>
        <td><?php echo esc_html($formatter->details($entry)); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        <p class="description">
        Read-only. The complete log, with filters, is on the
        <?php echo esc_html('Audit log screen'); ?>.
        </p>
        <?php endif; ?>
        </section>
        <?php
    }

    /**
     * The bounded query for one subject row.
     *
     * The window and the page size are fixed here rather than read from the
     * request: a panel is a fixed amount of evidence on a screen that is already
     * doing something else, and there is no reason for a link to be able to make
     * it read more.
     */
    private function query(string $subjectType, int $subjectId): AuditQuery
    {
        return AuditQuery::recent(
            $this->now(),
            self::WINDOW_MONTHS,
            self::PANEL_LIMIT,
            0,
            '',
            $subjectType,
            $subjectId
        );
    }

    private function now(): DateTimeImmutable
    {
        // wp_timezone() is the site's configured zone, which is not necessarily
        // this plugin's. Falling back keeps the window correct either way.
        $zone = function_exists('wp_timezone') ? wp_timezone() : $this->timezone;

        return (new DateTimeImmutable('@' . $this->clock->now()->getTimestamp()))
        ->setTimezone($zone instanceof DateTimeZone ? $zone : $this->timezone);
    }

    /**
     * Refuses a subject type the enum does not name.
     *
     * Without this a typo or a hand-edited call would reach the repository as a
     * subject filter that matches nothing, and the panel would read as "nothing
     * ever happened" — the one wrong answer an audit trail can give.
     */
    private function requireKnownSubjectType(string $subjectType): void
    {
        if (! in_array($subjectType, AuditSubjectType::values(), true)) {
            throw new InvalidArgumentException('Unknown audit subject type.');
        }
    }
}