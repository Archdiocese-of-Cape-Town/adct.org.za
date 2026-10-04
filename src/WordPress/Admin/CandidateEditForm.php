<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Review\CandidateFieldSet;

/**
 * The reviewer-facing edit form for one candidate.
 *
 * The form posts to `admin-post.php` and never acts on GET. Dates are day-first
 * because the reviewer is writing for a South African parish. Every control name
 * matches a key {@see CandidateEditValidator} reads, so the validator stays the
 * only thing that decides what may be stored.
 */
final class CandidateEditForm
{
    /**
     * The recurrence presets the simple controls offer. `custom` reveals the raw
     * rule box; `none` clears the rule.
     *
     * @var array<string, string>
     */
    private const PRESETS = [
            'none' => 'Does not repeat',
            'weekly' => 'Weekly on a weekday',
            'monthly_ordinal' => 'Monthly on an ordinal weekday',
            'monthly_day' => 'Monthly on a day of the month',
            'custom' => 'Custom rule (RRULE)',
        ];

    /** @var array<string, string> */
    private const WEEKDAYS = [
        'MO' => 'Monday',
        'TU' => 'Tuesday',
        'WE' => 'Wednesday',
        'TH' => 'Thursday',
        'FR' => 'Friday',
        'SA' => 'Saturday',
        'SU' => 'Sunday',
    ];

    /** @var array<string, string> */
        private const ORDINALS = ['1' => 'first', '2' => 'second', '3' => 'third', '4' => 'fourth', '-1' => 'last'];

    /** @var array<string, string> */
    private const STATUS_FLAGS = [
        'scheduled' => 'Scheduled',
        'cancelled' => 'Cancelled',
        'postponed' => 'Postponed',
    ];

    /**
     * @param array<string, mixed> $inputs current values, from `CandidateFieldSet::toInputs()`
     * @param array<string, string> $errors field name to reviewer-facing message
     * @param list<array<string, mixed>> $parishes
          * @param string|null $unparsedDateSentence #167: the warning that the notice's date could not
          *        be read, rendered as an acknowledgement on any approval that has not answered it
          */
         public function render(
             array $inputs,
             array $errors,
             array $parishes,
             int $candidateId,
             string $tab,
             string $search,
             bool $editable,
             bool $canApprove,
             ?string $unparsedDateSentence = null
         ): void {
        $contact = $this->contact($inputs);
        ?>
        <h2>Event details</h2>
        <?php if (! $editable) : ?>
            <div class="notice notice-info inline"><p>This candidate has already been decided, so its details are read-only.</p></div>
        <?php elseif ($errors !== []) : ?>
            <div class="notice notice-error inline"><p>The form could not be saved. Fix the highlighted fields and try again.</p></div>
        <?php else : ?>
            <p class="description">
                Dates are day-first: 12/10/2026 is 12 October 2026. Leave a time blank for an all-day
                event, or tick the box to drop the times deliberately.
            </p>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="adct-pi-edit-form">
            <input type="hidden" name="action" value="<?php echo esc_attr(ReviewQueuePage::SAVE_ACTION); ?>" />
            <input type="hidden" name="candidate_id" value="<?php echo esc_attr((string) $candidateId); ?>" />
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />
            <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>" />
            <?php wp_nonce_field(ReviewQueuePage::SAVE_ACTION, ReviewQueuePage::SAVE_NONCE); ?>
            <table class="form-table" role="presentation"><tbody>
                <?php $this->openRow('title', 'Event title', $errors); ?>
                    <input type="text" name="title" id="adct-pi-field-title" value="<?php echo esc_attr($this->str($inputs, 'title')); ?>"
                        maxlength="200" required <?php $this->readonly($editable); ?>
                        <?php $this->invalid('title', $errors); ?> />
                    <?php $this->errorText('title', $errors); ?>
                </td></tr>
                <?php $this->openRow('event_date', 'Date', $errors); ?>
                    <input type="text" name="event_date" id="adct-pi-field-event_date" value="<?php echo esc_attr($this->str($inputs, 'event_date')); ?>"
                        inputmode="numeric" placeholder="12/10/2026" size="12" <?php $this->readonly($editable); ?>
                        <?php $this->invalid('event_date', $errors); ?> />
                    <?php $this->errorText('event_date', $errors); ?>
                    <p class="description">Day first, for example 12/10/2026.</p>
                </td></tr>
                <?php $this->openRow('all_day', 'All day'); ?>
                    <label><input type="checkbox" name="all_day" id="adct-pi-field-all_day" value="1" <?php $this->disabled($editable); ?>
                        <?php $this->checked($inputs, 'all_day'); ?> /> All-day event (no start or end time)</label>
                </td></tr>
                <?php $this->openRow('event_time', 'Start time', $errors); ?>
                    <input type="text" name="event_time" id="adct-pi-field-event_time" value="<?php echo esc_attr($this->str($inputs, 'event_time')); ?>"
                        inputmode="numeric" placeholder="18:00" size="6" <?php $this->readonly($editable); ?>
                        <?php $this->invalid('event_time', $errors); ?> />
                    <?php $this->errorText('event_time', $errors); ?>
                </td></tr>
                <?php $this->openRow('event_end_date', 'End date', $errors); ?>
                    <input type="text" name="event_end_date" id="adct-pi-field-event_end_date" value="<?php echo esc_attr($this->str($inputs, 'event_end_date')); ?>"
                        inputmode="numeric" placeholder="14/10/2026" size="12" <?php $this->readonly($editable); ?>
                        <?php $this->invalid('event_end_date', $errors); ?> />
                    <?php $this->errorText('event_end_date', $errors); ?>
                    <p class="description">Optional. An end time without an end date means the event ends on the start date.</p>
                </td></tr>
                <?php $this->openRow('event_end_time', 'End time', $errors); ?>
                    <input type="text" name="event_end_time" id="adct-pi-field-event_end_time" value="<?php echo esc_attr($this->str($inputs, 'event_end_time')); ?>"
                        inputmode="numeric" placeholder="20:00" size="6" <?php $this->readonly($editable); ?>
                        <?php $this->invalid('event_end_time', $errors); ?> />
                    <?php $this->errorText('event_end_time', $errors); ?>
                </td></tr>
                <?php $this->openRow('parish_id', 'Parish'); ?>
                    <select name="parish_id" id="adct-pi-field-parish_id" <?php $this->disabled($editable); ?>>
                        <?php $this->parishOptions($parishes, $this->str($inputs, 'parish_id')); ?>
                    </select>
                </td></tr>
                <?php $this->openRow('venue_id', 'Venue'); ?>
                    <input type="number" name="venue_id" id="adct-pi-field-venue_id" min="1" class="small-text"
                        value="<?php echo esc_attr($this->str($inputs, 'venue_id')); ?>" <?php $this->disabled($editable); ?> />
                    <p class="description">A venue belonging to the parish above. Leave it blank to use the venue named in the description.</p>
                </td></tr>
                <?php $this->openRow('event_type', 'Event type', $errors); ?>
                    <input type="text" name="event_type" id="adct-pi-field-event_type" value="<?php echo esc_attr($this->str($inputs, 'event_type')); ?>"
                        maxlength="50" <?php $this->readonly($editable); ?> <?php $this->invalid('event_type', $errors); ?> />
                    <?php $this->errorText('event_type', $errors); ?>
                </td></tr>
                <?php $this->openRow('description', 'Description', $errors); ?>
                    <textarea name="description" id="adct-pi-field-description" rows="5" cols="60"
                        <?php $this->readonly($editable); ?> <?php $this->invalid('description', $errors); ?>><?php
                        echo esc_textarea($this->str($inputs, 'description')); ?></textarea>
                    <?php $this->errorText('description', $errors); ?>
                </td></tr>
                <?php $this->openRow('contact_name', 'Contact name', $errors); ?>
                    <input type="text" name="contact_name" id="adct-pi-field-contact_name" value="<?php echo esc_attr($contact['name']); ?>"
                        maxlength="100" <?php $this->readonly($editable); ?> />
                    <?php $this->errorText('contact_name', $errors); ?>
                </td></tr>
                <?php $this->openRow('contact_email', 'Contact email', $errors); ?>
                    <input type="email" name="contact_email" id="adct-pi-field-contact_email" value="<?php echo esc_attr($contact['email']); ?>"
                        maxlength="191" <?php $this->readonly($editable); ?> />
                    <?php $this->errorText('contact_email', $errors); ?>
                </td></tr>
                <?php $this->openRow('contact_phone', 'Contact phone', $errors); ?>
                    <input type="text" name="contact_phone" id="adct-pi-field-contact_phone" value="<?php echo esc_attr($contact['phone']); ?>"
                        maxlength="40" <?php $this->readonly($editable); ?> />
                    <?php $this->errorText('contact_phone', $errors); ?>
                </td></tr>
                <?php $this->openRow('featured', 'Featured'); ?>
                    <label><input type="checkbox" name="featured" id="adct-pi-field-featured" value="1" <?php $this->disabled($editable); ?>
                        <?php $this->checked($inputs, 'featured'); ?> /> Show this event prominently</label>
                </td></tr>
                <?php $this->openRow('status_flag', 'Status'); ?>
                    <select name="status_flag" id="adct-pi-field-status_flag" <?php $this->disabled($editable); ?>>
                        <?php $this->options(self::STATUS_FLAGS, $this->str($inputs, 'status_flag', 'scheduled')); ?>
                    </select>
                </td></tr>
                <?php $this->recurrenceRows($inputs, $errors, $editable); ?>
                <?php $this->openRow('exdates', 'Excluded dates', $errors); ?>
                    <textarea name="exdates" id="adct-pi-field-exdates" rows="3" cols="30"
                        <?php $this->readonly($editable); ?> <?php $this->invalid('exdates', $errors); ?>><?php
                        echo esc_textarea(implode("\n", CandidateFieldSet::stringList($inputs['exdates'] ?? []))); ?></textarea>
                    <?php $this->errorText('exdates', $errors); ?>
                    <p class="description">One day-first date per line, for example 12/10/2026.</p>
                </td></tr>
                <?php $this->openRow('rdates', 'Extra dates', $errors); ?>
                    <textarea name="rdates" id="adct-pi-field-rdates" rows="3" cols="30"
                        <?php $this->readonly($editable); ?> <?php $this->invalid('rdates', $errors); ?>><?php
                        echo esc_textarea(implode("\n", CandidateFieldSet::stringList($inputs['rdates'] ?? []))); ?></textarea>
                    <?php $this->errorText('rdates', $errors); ?>
                    <p class="description">One day-first date per line, for a series that adds to its pattern.</p>
                </td></tr>
                <?php $this->openRow('reason', 'Reason (rejection only)'); ?>
                    <input type="text" name="reason" id="adct-pi-field-reason" maxlength="500" />
                    <p class="description">Recorded on the candidate and in the audit log when you reject.</p>
                </td></tr>
            </tbody></table>
                        <?php // #167: inside the form, because the box only counts once it submits with it. ?>
                        <?php if ($editable && $canApprove) : ?>
                            <?php $this->renderUnparsedDateAcknowledgement($unparsedDateSentence); ?>
                        <?php endif; ?>
                        <?php if ($editable) : ?>
                            <p class="submit">
                                <button type="submit" class="button button-primary" name="save_mode" value="save">Save changes</button>
                    <?php if ($canApprove) : ?>
                        <button type="submit" class="button button-primary" name="save_mode" value="approve">Save and approve</button>
                    <?php endif; ?>
                    <button type="submit" class="button" name="save_mode" value="reject">Reject</button>
                </p>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * #167: the reviewer's acknowledgement, rendered only when there is something to answer.
     *
     * A candidate whose date could not be read carries this forward on every approval, so the box
     * asks again each time rather than being remembered against the row. It sits outside the table
     * and below the buttons so it reads as a question about approving, not as another field of the
     * event.
     */
    public function renderUnparsedDateAcknowledgement(?string $sentence): void
    {
        if ($sentence === null || $sentence === '') {
            return;
        }
        ?>
        <div class="notice notice-warning inline">
            <p><?php echo esc_html($sentence); ?></p>
            <p>
                <label>
                    <input type="checkbox"
                        name="<?php echo esc_attr(ReviewQueuePage::UNPARSED_DATE_FIELD); ?>"
                        value="1" />
                    I have read this and am approving the event without a date the notice stated clearly.
                </label>
            </p>
            <p class="description">
                Or enter the correct date above and press Save and approve; that answers this as well.
            </p>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $inputs
     * @param array<string, string> $errors
     */
    private function recurrenceRows(array $inputs, array $errors, bool $editable): void
    {
        $preset = $this->str($inputs, 'recurrence_preset', 'none');
        ?>
        <?php $this->openRow('recurrence_preset', 'Repeats'); ?>
            <select name="recurrence_preset" id="adct-pi-field-recurrence_preset" <?php $this->disabled($editable); ?>>
                <?php $this->options(self::PRESETS, $preset); ?>
            </select>
        </td></tr>
        <?php $this->openRow('recurrence_weekday', 'Repeats on'); ?>
            <select name="recurrence_weekday" id="adct-pi-field-recurrence_weekday" <?php $this->disabled($editable); ?>>
                <option value="">Not specified</option>
                <?php $this->options(self::WEEKDAYS, $this->str($inputs, 'recurrence_weekday')); ?>
            </select>
        </td></tr>
        <?php $this->openRow('recurrence_ordinal', 'Monthly on'); ?>
            <select name="recurrence_ordinal" id="adct-pi-field-recurrence_ordinal" <?php $this->disabled($editable); ?>>
                <option value="">Not specified</option>
                <?php $this->options(self::ORDINALS, $this->str($inputs, 'recurrence_ordinal')); ?>
            </select>
        </td></tr>
        <?php $this->openRow('recurrence_month_day', 'Monthly on day'); ?>
            <input type="number" name="recurrence_month_day" id="adct-pi-field-recurrence_month_day"
                min="1" max="31" class="small-text" value="<?php echo esc_attr($this->str($inputs, 'recurrence_month_day')); ?>"
                <?php $this->disabled($editable); ?> />
        </td></tr>
        <?php $this->openRow('recurrence_custom', 'Custom rule', $errors); ?>
            <textarea name="recurrence_custom" id="adct-pi-field-recurrence_custom" rows="3" cols="60"
                <?php $this->readonly($editable); ?> <?php $this->invalid('recurrence_custom', $errors); ?>><?php
                echo esc_textarea($this->str($inputs, 'recurrence_custom')); ?></textarea>
            <?php $this->errorText('recurrence_custom', $errors); ?>
            <p class="description">Full RRULE, for example FREQ=WEEKLY;BYDAY=TH. Only needed for a pattern the options above cannot express.</p>
            <?php if ($preset !== 'custom') : ?>
                <p class="description">Ignored unless you choose "Custom rule (RRULE)" above.</p>
            <?php endif; ?>
        </td></tr>
        <?php
    }

    /**
     * Opens a form-table row and its heading. The caller closes the `<td>` and
     * `<tr>`, which keeps the error styling in one place.
     *
     * @param array<string, string> $errors
     */
    private function openRow(string $name, string $label, array $errors = []): void
    {
        $hasError = isset($errors[$name]);
        printf(
            '<tr class="%s"><th scope="row"><label for="adct-pi-field-%s">%s%s</label></th><td>',
            $hasError ? 'adct-pi-row-error' : '',
            esc_attr($name),
            esc_html($label),
            $hasError ? ' <span class="required">*</span>' : ''
        );
    }

    /** @param array<string, string> $errors */
    private function errorText(string $name, array $errors): void
    {
        $error = $errors[$name] ?? null;
        if (is_string($error) && $error !== '') {
            printf('<span class="adct-pi-field-error-text">%s</span>', esc_html($error));
        }
    }

    /** @param array<string, string> $errors */
    private function invalid(string $name, array $errors): void
    {
        echo isset($errors[$name]) ? 'class="adct-pi-field-error" aria-invalid="true"' : '';
    }

    private function readonly(bool $editable): void
    {
        echo $editable ? '' : 'readonly';
    }

    private function disabled(bool $editable): void
    {
        echo $editable ? '' : 'disabled';
    }

    /** @param array<string, mixed> $inputs */
    private function checked(array $inputs, string $name): void
    {
        echo ($inputs[$name] ?? false) ? 'checked' : '';
    }

    /** @param array<string, string> $choices */
    private function options(array $choices, string $selected): void
    {
        foreach ($choices as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $value),
                (string) $value === $selected ? ' selected' : '',
                esc_html((string) $label)
            );
        }
    }

    /** @param list<array<string, mixed>> $parishes */
    private function parishOptions(array $parishes, string $selected): void
    {
        printf('<option value="">%s</option>', esc_html('Not assigned'));
        $offered = [];
        foreach ($parishes as $parish) {
            $id = (int) ($parish['id'] ?? 0);
            $offered[(string) $id] = true;
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $id),
                (string) $id === $selected ? ' selected' : '',
                esc_html((string) ($parish['name'] ?? ''))
            );
        }
        // A candidate can still point at a parish that is no longer active.
        // Rendering it keeps an unrelated save from silently reassigning the
        // event to a different parish.
        if ($selected !== '' && ! isset($offered[$selected])) {
            printf(
                '<option value="%s" selected>%s</option>',
                esc_attr($selected),
                esc_html('Parish ' . $selected . ' (not active)')
            );
        }
    }

    /**
     * @param array<string, mixed> $inputs
     * @return array{name: string, email: string, phone: string}
     */
    private function contact(array $inputs): array
    {
        $contact = $inputs['contact'] ?? [];
        $part = static fn (string $key): string => CandidateFieldSet::stringValue(
            is_array($contact) ? ($contact[$key] ?? '') : ''
        );

        return ['name' => $part('name'), 'email' => $part('email'), 'phone' => $part('phone')];
    }

    /** @param array<string, mixed> $inputs */
    private function str(array $inputs, string $key, string $default = ''): string
    {
        $value = $inputs[$key] ?? null;
        if (is_bool($value)) {
            return $value ? '1' : $default;
        }
        $text = CandidateFieldSet::stringValue($value);

        return $text === '' ? $default : $text;
    }
}
