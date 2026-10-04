<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Change;

use ADCT\ParishIntake\Core\Events\ChangeDiff;
use ADCT\ParishIntake\Core\Ports\ChangeTrailReaderInterface;
use DateTimeImmutable;
use DateTimeZone;
use WP_Post;

/**
 * The change-history box on the event edit screen: who changed what on this
 * event, when, and what it changed it from.
 *
 * Read-only by design. Reverting and unpublishing both run over the mailed
 * token flow, which is permissioned, audited and audited separately
 * ([ADR 0008](0008-approval-by-dean-or-archdiocese-reviewer.md) point 4). A
 * button here would be a second path to the same state transitions, reachable
 * by anyone who can edit the event and unaudited, so there is none: the box
 * reads, and the trail points at the mails that act.
 */
final class ChangeHistoryBox
{
    public const BOX_ID = 'adct_event_change_history';

    public function __construct(private readonly ChangeTrailReaderInterface $trail)
    {
    }

    public function register(): void
    {
        add_meta_box(
            self::BOX_ID,
            __('Change history', 'adct-parish-intake'),
            [$this, 'renderMetaBox'],
            'adct_event',
            'normal',
            'high'
        );
    }

    /**
     * A meta box is registered for the whole screen, so the capability check
     * belongs here rather than in register(): the trail names the person and
     * the address that made each change, which is not something to show a user
     * who has reached the screen for another reason.
     */
    public function renderMetaBox(WP_Post $post): void
    {
        if (! current_user_can('edit_post', $post->ID)) {
            return;
        }

        $rows = $this->trail->forEvent((int) $post->ID);

        echo '<div class="adct-change-history">';

        if ($rows === []) {
            echo '<p>' . esc_html__(
                'No changes have been recorded for this event since it arrived in WordPress.',
                'adct-parish-intake'
            ) . '</p></div>';

            return;
        }

        echo '<p>' . esc_html__(
            'The most recent change first. Reverting or taking an event off the events page is done from the change notice mail, which is permissioned and recorded.',
            'adct-parish-intake'
        ) . '</p>';
        echo '<table class="widefat striped adct-change-history__table"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Change', 'adct-parish-intake') . '</th>';
        echo '<th scope="col">' . esc_html__('When', 'adct-parish-intake') . '</th>';
        echo '<th scope="col">' . esc_html__('Recorded by', 'adct-parish-intake') . '</th>';
        echo '<th scope="col">' . esc_html__('What changed', 'adct-parish-intake') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $this->renderRow($row);
        }

        echo '</tbody></table></div>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renderRow(array $row): void
    {
        $before = ChangeDiff::decode($row['before_payload'] ?? null);
        $after = ChangeDiff::decode($row['after_payload'] ?? null);

        echo '<tr>';
        echo '<td><span class="adct-change-history__id">'
            . esc_html__('Change', 'adct-parish-intake') . ' #' . esc_html($this->id($row)) . '</span> '
            . esc_html($this->kind($row)) . '</td>';
        echo '<td>' . esc_html($this->localDate($row['created_at'] ?? null));
        $reverted = $this->revertedBy($row);

        if ($reverted !== '') {
            echo '<br><span class="adct-change-history__reverted">'
                . esc_html__('Reverted by', 'adct-parish-intake') . ' ' . esc_html($reverted)
                . '</span>';
        }

        echo '</td>';
        echo '<td>' . esc_html($this->actor($row)) . '</td>';
        echo '<td>';

        // ChangeDiff reads a missing key as "nothing", so diffing against a
        // side that will not decode does not error -- it confidently reports
        // every differing field as having been cleared. Both sides must be
        // readable before anything is diffed, exactly as the change notice mail
        // and the revert confirmation page do.
        if (! $before['ok'] || ! $after['ok']) {
            echo '<em>' . esc_html__(
                'Note: the before and after details of this change could not be read.',
                'adct-parish-intake'
            ) . '</em>';
        } else {
            $lines = ChangeDiff::rows($before['snapshot'], $after['snapshot']);

            if ($lines === []) {
                echo '<em>' . esc_html__(
                    'No field-level difference was recorded.',
                    'adct-parish-intake'
                ) . '</em>';
            } else {
                echo '<ul class="adct-change-history__diff">';

                foreach ($lines as $line) {
                    echo '<li>' . esc_html($line['label'] . ': ' . $line['before'] . ' -> ' . $line['after'])
                        . '</li>';
                }

                echo '</ul>';
            }
        }

        echo '</td></tr>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function id(array $row): string
    {
        return is_scalar($row['id'] ?? null) ? (string) $row['id'] : '?';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function actor(array $row): string
    {
        $actor = $row['actor'] ?? '';

        return is_scalar($actor) && (string) $actor !== '' ? (string) $actor : __('unknown', 'adct-parish-intake');
    }

    /**
     * The recorded kind is a small closed vocabulary; an unexpected value is
     * shown as itself rather than dropped, so a trail is never silently
     * incomplete.
     *
     * @param array<string, mixed> $row
     */
    private function kind(array $row): string
    {
        $kind = $row['kind'] ?? '';

        return is_scalar($kind) && (string) $kind !== '' ? (string) $kind : 'change';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function revertedBy(array $row): string
    {
        $by = $row['reverted_by'] ?? null;

        return is_scalar($by) && (string) $by !== '' ? (string) $by : '';
    }

    /**
     * `created_at` is stored in UTC. The trail is read by a person deciding
     * whether something happened before or after a phone call, so it is shown
     * in local time, day-first. An unreadable value is shown as stored rather
     * than guessed at: a timestamp nobody can parse is still evidence.
     */
    private function localDate(mixed $stored): string
    {
        if (! is_string($stored) || trim($stored) === '') {
            return __('unknown', 'adct-parish-intake');
        }

        $date = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $stored,
                    new DateTimeZone('UTC')
        );

        if ($date === false) {
            return $stored;
        }

        return $date
                    ->setTimezone(new DateTimeZone('Africa/Johannesburg'))
            ->format('d/m/Y H:i');
    }
}