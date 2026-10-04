<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Audit\AuditEntry;
use DateTimeImmutable;
use DateTimeZone;

/**
 * How one audit row reads on screen: local time, an action label, and the
 * details column as one line of plain text.
 *
 * This lives apart from AuditLogPage because the same row now appears in more
 * than one place: the global audit screen, and the per-subject panels mounted
 * on the candidate, event and parish screens. Two copies of these formatters
 * would drift, and a details column that escapes differently on two screens is
 * a vulnerability on one of them, so there is one implementation.
 *
 * Every value here is escaped by the caller. Nothing in this class emits
 * markup, and nothing here trusts the details column — it holds text that came
 * out of a parsed email body, so it is only ever handed to a text escaper.
 */
final class AuditEntryFormatter
{
    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    /**
     * The moment in the site's own timezone, day first.
     */
    public function timestamp(DateTimeImmutable $moment): string
    {
        return $moment
        ->setTimezone($this->timezone)
        ->format('d/m/Y H:i');
    }

    /**
     * The details column as one line of text.
     *
     * An entry whose details column is not valid JSON is shown raw rather than
     * hidden, because a row that cannot be read is exactly the row someone
     * reading an audit log is looking for.
     */
    public function details(AuditEntry $entry): string
    {
        if ($entry->details === null || trim($entry->details) === '') {
            return '—';
        }

        $decoded = $entry->decoded();

        if ($decoded === null) {
            return $entry->details;
        }

        $changed = $decoded['changed'] ?? null;

        if (is_array($changed)) {
            return $this->settingChanges($changed);
        }

        $parts = [];

        foreach ($decoded as $key => $value) {
            $parts[] = $key . ': ' . $this->describeValue($value);
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    /**
     * @param array<mixed> $changes
     */
    private function settingChanges(array $changes): string
    {
        $parts = [];

        foreach ($changes as $change) {
            if (! is_array($change)) {
                continue;
            }

            $option = is_scalar($change['option'] ?? null) ? (string) $change['option'] : '';
            $before = is_scalar($change['before'] ?? null) ? (string) $change['before'] : '';
            $after = is_scalar($change['after'] ?? null) ? (string) $change['after'] : '';

            if ($option === '') {
                continue;
            }

            $parts[] = sprintf('%s: %s → %s', $option, $before, $after);
        }

        return $parts === [] ? '—' : implode('; ', $parts);
    }

    private function describeValue(mixed $value): string
    {
        if ($value === null) {
            return 'none';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return sprintf('%d item(s)', count($value));
        }

        return 'value';
    }
}