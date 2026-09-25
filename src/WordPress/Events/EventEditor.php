<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use ADCT\ParishIntake\Core\Events\EventDetails;
use ADCT\ParishIntake\Core\Events\EventValidationResult;
use ADCT\ParishIntake\Core\Events\EventValidator;
use ADCT\ParishIntake\Core\Events\RRulePresetMapper;
use ADCT\ParishIntake\Core\Directory\Venue;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class EventEditor
{
    private const FORM_KEY = 'adct_event';
    private const NONCE_FIELD = 'adct_event_meta_nonce';
    private const NONCE_ACTION_PREFIX = 'adct_pi_save_event_meta_';
    private const VALIDATION_TRANSIENT_PREFIX = 'adct_pi_event_validation_';
    private const VALIDATION_TTL_SECONDS = 900;
    private const MAX_INPUT_LENGTH = 32768;
    private const REST_META_FIELDS = [
        'parish_id',
        'venue_id',
        'start_local',
        'end_local',
        'all_day',
        'rrule',
        'exdates',
        'rdates',
        'featured',
        'status_flag',
    ];

    /**
     * @var array<int, string>|null
     */
    private ?array $parishNames = null;

    public function __construct(
        private ParishRepository $parishes,
        private VenueRepository $venues,
        private EventValidator $validator,
        private RRulePresetMapper $presetMapper,
        private DateTimeZone $timezone
    ) {
    }

    public function registerMetaBox(): void
    {
        add_meta_box(
            'adct_event_details',
            'Event details',
            [$this, 'renderMetaBox'],
            EventPostType::POST_TYPE,
            'normal',
            'high'
        );

        foreach (['normal', 'advanced', 'side'] as $context) {
            remove_meta_box('postcustom', EventPostType::POST_TYPE, $context);
        }
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        if (! current_user_can('edit_post', $post->ID)) {
            return;
        }

        $values = $this->savedFormValues($post->ID);
        $state = $this->validationState($post->ID);

        if (is_array($state['input'] ?? null)) {
            $values = array_replace($values, $state['input']);
        }

        $parishes = $this->parishes->findForEventEditor();
        $venues = $this->venues->findForEventEditor();
        $this->parishNames = [];

        foreach ($parishes as $parish) {
            $this->parishNames[(int) $parish['id']] = (string) $parish['name'];
        }

        wp_nonce_field(
            self::NONCE_ACTION_PREFIX . $post->ID,
            self::NONCE_FIELD
        );
        ?>
        <div id="adct-event-details-box">
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="adct-event-parish">Parish</label></th>
                        <td>
                            <select id="adct-event-parish" name="adct_event[parish_id]">
                                <option value="" <?php selected($this->formValue($values, 'parish_id'), ''); ?>>
                                    No specific parish (archdiocese-wide)
                                </option>
                                <?php foreach ($parishes as $parish) : ?>
                                    <?php
                                    $parishId = (string) $parish['id'];
                                    $parishLabel = (string) $parish['name'];

                                    if ((string) $parish['status'] !== 'active') {
                                        $parishLabel .= ' (inactive)';
                                    }
                                    ?>
                                    <option
                                        value="<?php echo esc_attr($parishId); ?>"
                                        <?php selected($this->formValue($values, 'parish_id'), $parishId); ?>
                                    >
                                        <?php echo esc_html($parishLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adct-event-venue">Venue</label></th>
                        <td>
                            <select id="adct-event-venue" name="adct_event[venue_id]">
                                <option value="" data-parish-id="" <?php selected($this->formValue($values, 'venue_id'), ''); ?>>
                                    No venue selected
                                </option>
                                <?php foreach ($venues as $venue) : ?>
                                    <?php
                                    $venueLabel = $venue->name . ' — ' . ($this->parishLabel($venue->parishId) ?? 'Unknown parish');

                                    if ($venue->status !== Venue::ACTIVE) {
                                        $venueLabel .= ' (inactive)';
                                    }
                                    ?>
                                    <option
                                        value="<?php echo esc_attr((string) $venue->id); ?>"
                                        data-parish-id="<?php echo esc_attr((string) $venue->parishId); ?>"
                                        <?php selected($this->formValue($values, 'venue_id'), (string) $venue->id); ?>
                                    >
                                        <?php echo esc_html($venueLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adct-event-start">Start</label></th>
                        <td>
                            <input
                                id="adct-event-start"
                                name="adct_event[start_local]"
                                type="datetime-local"
                                value="<?php echo esc_attr($this->formValue($values, 'start_local')); ?>"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adct-event-end">End</label></th>
                        <td>
                            <input
                                id="adct-event-end"
                                name="adct_event[end_local]"
                                type="datetime-local"
                                value="<?php echo esc_attr($this->formValue($values, 'end_local')); ?>"
                            />
                            <p class="description">Times use the site's local timezone.</p>
                            <label>
                                <input
                                    name="adct_event[all_day]"
                                    type="checkbox"
                                    value="1"
                                    <?php checked($this->formValue($values, 'all_day'), '1'); ?>
                                />
                                All-day event
                            </label>
                            <p class="description">All-day events use the selected date at local midnight; the start and end dates are inclusive.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adct-event-recurrence">Recurrence</label></th>
                        <td>
                            <select id="adct-event-recurrence" name="adct_event[recurrence_preset]">
                                <option value="none" <?php selected($this->formValue($values, 'recurrence_preset'), 'none'); ?>>Does not repeat</option>
                                <option value="weekly" <?php selected($this->formValue($values, 'recurrence_preset'), 'weekly'); ?>>Weekly on a weekday</option>
                                <option value="monthly_ordinal" <?php selected($this->formValue($values, 'recurrence_preset'), 'monthly_ordinal'); ?>>Monthly on an ordinal weekday</option>
                                <option value="monthly_day" <?php selected($this->formValue($values, 'recurrence_preset'), 'monthly_day'); ?>>Monthly on a day of the month</option>
                                <option value="custom" <?php selected($this->formValue($values, 'recurrence_preset'), 'custom'); ?>>Custom RRULE</option>
                            </select>
                            <p id="adct-event-weekday-row">
                                <label for="adct-event-weekday">Weekday</label>
                                <select id="adct-event-weekday" name="adct_event[weekday]">
                                    <?php foreach ($this->weekdays() as $weekday => $label) : ?>
                                        <option value="<?php echo esc_attr($weekday); ?>" <?php selected($this->formValue($values, 'weekday'), $weekday); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <p id="adct-event-ordinal-row">
                                <label for="adct-event-ordinal">Monthly position</label>
                                <select id="adct-event-ordinal" name="adct_event[ordinal]">
                                    <option value="1" <?php selected($this->formValue($values, 'ordinal'), '1'); ?>>First</option>
                                    <option value="2" <?php selected($this->formValue($values, 'ordinal'), '2'); ?>>Second</option>
                                    <option value="3" <?php selected($this->formValue($values, 'ordinal'), '3'); ?>>Third</option>
                                    <option value="4" <?php selected($this->formValue($values, 'ordinal'), '4'); ?>>Fourth</option>
                                    <option value="-1" <?php selected($this->formValue($values, 'ordinal'), '-1'); ?>>Last</option>
                                </select>
                            </p>
                            <p id="adct-event-month-day-row">
                                <label for="adct-event-month-day">Day of month</label>
                                <input
                                    id="adct-event-month-day"
                                    name="adct_event[month_day]"
                                    type="number"
                                    min="1"
                                    max="31"
                                    value="<?php echo esc_attr($this->formValue($values, 'month_day')); ?>"
                                />
                            </p>
                            <p id="adct-event-custom-rule-row">
                                <label for="adct-event-custom-rule">RRULE</label><br />
                                <textarea
                                    id="adct-event-custom-rule"
                                    name="adct_event[rrule_custom]"
                                    rows="2"
                                    class="large-text code"
                                    maxlength="512"
                                ><?php echo esc_textarea($this->formValue($values, 'rrule_custom')); ?></textarea>
                                <span class="description">Supported parts: FREQ, INTERVAL, COUNT, UNTIL, BYDAY, BYMONTHDAY, BYMONTH and BYSETPOS.</span>
                            </p>
                            <p>RRULE: <code id="adct-event-rrule-preview"><?php echo esc_html($this->previewRule($values)); ?></code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adct-event-exdates">Excluded dates</label></th>
                        <td>
                            <textarea id="adct-event-exdates" name="adct_event[exdates]" rows="3" class="large-text code"><?php echo esc_textarea($this->formValue($values, 'exdates_text')); ?></textarea>
                            <p class="description">Enter one local date and time per line (YYYY-MM-DDTHH:MM).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adct-event-rdates">Additional dates</label></th>
                        <td>
                            <textarea id="adct-event-rdates" name="adct_event[rdates]" rows="3" class="large-text code"><?php echo esc_textarea($this->formValue($values, 'rdates_text')); ?></textarea>
                            <p class="description">Enter one local date and time per line (YYYY-MM-DDTHH:MM).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Publication</th>
                        <td>
                            <label>
                                <input
                                    name="adct_event[featured]"
                                    type="checkbox"
                                    value="1"
                                    <?php checked($this->formValue($values, 'featured'), '1'); ?>
                                />
                                Featured event
                            </label>
                            <p>
                                <label for="adct-event-status">Status</label>
                                <select id="adct-event-status" name="adct_event[status_flag]">
                                    <option value="scheduled" <?php selected($this->formValue($values, 'status_flag'), 'scheduled'); ?>>Scheduled</option>
                                    <option value="cancelled" <?php selected($this->formValue($values, 'status_flag'), 'cancelled'); ?>>Cancelled</option>
                                    <option value="postponed" <?php selected($this->formValue($values, 'status_flag'), 'postponed'); ?>>Postponed</option>
                                </select>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Contact details</th>
                        <td>
                            <p>
                                <label for="adct-event-contact-name">Name</label><br />
                                <input
                                    id="adct-event-contact-name"
                                    name="adct_event[contact][name]"
                                    type="text"
                                    maxlength="191"
                                    value="<?php echo esc_attr($this->contactValue($values, 'name')); ?>"
                                />
                            </p>
                            <p>
                                <label for="adct-event-contact-email">Email</label><br />
                                <input
                                    id="adct-event-contact-email"
                                    name="adct_event[contact][email]"
                                    type="email"
                                    maxlength="254"
                                    value="<?php echo esc_attr($this->contactValue($values, 'email')); ?>"
                                />
                            </p>
                            <p>
                                <label for="adct-event-contact-phone">Phone</label><br />
                                <input
                                    id="adct-event-contact-phone"
                                    name="adct_event[contact][phone]"
                                    type="tel"
                                    maxlength="64"
                                    value="<?php echo esc_attr($this->contactValue($values, 'phone')); ?>"
                                />
                            </p>
                            <p class="description">Contact details are visible only to users who can edit this event and are not exposed in the public REST API.</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <script>
        (function () {
            var box = document.getElementById('adct-event-details-box');
            if (!box) {
                return;
            }

            var parish = document.getElementById('adct-event-parish');
            var venue = document.getElementById('adct-event-venue');
            var preset = document.getElementById('adct-event-recurrence');
            var weekday = document.getElementById('adct-event-weekday');
            var ordinal = document.getElementById('adct-event-ordinal');
            var monthDay = document.getElementById('adct-event-month-day');
            var customRule = document.getElementById('adct-event-custom-rule');
            var preview = document.getElementById('adct-event-rrule-preview');

            function updateVenues() {
                Array.prototype.forEach.call(venue.options, function (option) {
                    if (!option.value) {
                        option.hidden = false;
                        return;
                    }

                    var visible = option.getAttribute('data-parish-id') === parish.value;
                    option.hidden = !visible;

                    if (!visible && option.selected) {
                        venue.value = '';
                    }
                });
            }

            function updateRecurrence() {
                var selected = preset.value;
                document.getElementById('adct-event-weekday-row').hidden =
                    selected !== 'weekly' && selected !== 'monthly_ordinal';
                document.getElementById('adct-event-ordinal-row').hidden = selected !== 'monthly_ordinal';
                document.getElementById('adct-event-month-day-row').hidden = selected !== 'monthly_day';
                document.getElementById('adct-event-custom-rule-row').hidden = selected !== 'custom';

                var rule = '';
                if (selected === 'weekly') {
                    rule = 'FREQ=WEEKLY;BYDAY=' + weekday.value;
                } else if (selected === 'monthly_ordinal') {
                    rule = 'FREQ=MONTHLY;BYDAY=' + ordinal.value + weekday.value;
                } else if (selected === 'monthly_day' && monthDay.value) {
                    rule = 'FREQ=MONTHLY;BYMONTHDAY=' + monthDay.value;
                } else if (selected === 'custom') {
                    rule = customRule.value.trim();
                }

                preview.textContent = rule || 'None';
            }

            parish.addEventListener('change', updateVenues);
            [preset, weekday, ordinal, monthDay, customRule].forEach(function (field) {
                field.addEventListener('change', updateRecurrence);
                field.addEventListener('input', updateRecurrence);
            });
            updateVenues();
            updateRecurrence();
        })();
        </script>
        <?php
    }

    public function handleSavePost(int $postId, \WP_Post $post, bool $update): void
    {
        if (
            $post->post_type !== EventPostType::POST_TYPE
            || wp_is_post_revision($postId)
            || wp_is_post_autosave($postId)
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || ! current_user_can('edit_post', $postId)
        ) {
            return;
        }

        $rawForm = $_POST[self::FORM_KEY] ?? null;
        $nonce = $_POST[self::NONCE_FIELD] ?? null;

        if (! is_array($rawForm) || ! is_scalar($nonce)) {
            return;
        }

        $nonce = (string) wp_unslash((string) $nonce);

        if (! wp_verify_nonce($nonce, self::NONCE_ACTION_PREFIX . $postId)) {
            return;
        }

        $form = wp_unslash($rawForm);
        $parsed = $this->detailsFromForm($postId, $form);
        $validation = $this->validator->validate($parsed['details']);
        $errors = array_merge($parsed['errors'], $validation->errors);
        array_push($errors, ...$this->relationshipErrors($validation));
        $errors = array_values(array_unique($errors));

        if ($errors !== []) {
            $this->rememberValidation($postId, $parsed['input'], $errors);

            return;
        }

        $this->persistMeta($postId, $validation);
        delete_transient($this->validationTransientKey($postId));
    }

    /**
     * @param mixed $preparedPost
     * @return mixed
     */
    public function validateRestRequest($preparedPost, \WP_REST_Request $request)
    {
        $incoming = $request->get_param('meta');

        if ($incoming === null || $incoming === []) {
            return $preparedPost;
        }

        if ($incoming instanceof \stdClass) {
            $incoming = get_object_vars($incoming);
        }

        if (! is_array($incoming)) {
            return new \WP_Error(
                'adct_event_invalid_meta',
                'Event metadata must be an object.',
                ['status' => 400]
            );
        }

        if (array_key_exists('contact', $incoming)) {
            return new \WP_Error(
                'adct_event_private_contact',
                'Contact details cannot be read or changed through the REST API.',
                ['status' => 400]
            );
        }

        if (array_key_exists('source_candidate_id', $incoming)) {
            return new \WP_Error(
                'adct_event_internal_source',
                'The source candidate link can only be set by the publishing workflow.',
                ['status' => 400]
            );
        }

        $postId = $preparedPost instanceof \WP_Post
            ? (int) $preparedPost->ID
            : absint($request->get_param('id'));
        $values = $this->storedMetaValues($postId);

        foreach (self::REST_META_FIELDS as $field) {
            if (array_key_exists($field, $incoming)) {
                $values[$field] = $incoming[$field];
            }
        }

        $preErrors = [];
        $details = $this->detailsFromMeta($postId, $values, $preErrors);
        $validation = $this->validator->validate($details);
        $errors = array_merge($preErrors, $validation->errors);
        array_push($errors, ...$this->relationshipErrors($validation));
        $errors = array_values(array_unique($errors));

        if ($errors !== []) {
            return new \WP_Error(
                'adct_event_invalid_meta',
                'Event details are invalid.',
                [
                    'status' => 400,
                    'errors' => $errors,
                ]
            );
        }

        $normalizedMeta = $incoming;

        foreach (self::REST_META_FIELDS as $field) {
            $value = $validation->values[$field];

            if ($field === 'parish_id' || $field === 'venue_id') {
                $value ??= 0;
            } elseif ($field === 'end_local' || $field === 'rrule') {
                $value ??= '';
            }

            $normalizedMeta[$field] = $value;
        }

        $request->set_param('meta', $normalizedMeta);

        return $preparedPost;
    }

    public function filterColumns(array $columns): array
    {
        return [
            'cb' => $columns['cb'] ?? '',
            'title' => $columns['title'] ?? 'Title',
            'event_start' => 'Start',
            'event_parish' => 'Parish',
            'event_type' => 'Type',
            'event_status' => 'Status',
            'event_featured' => 'Featured',
            'date' => $columns['date'] ?? 'Date',
        ];
    }

    public function renderColumn(string $column, int $postId): void
    {
        switch ($column) {
            case 'event_start':
                $start = get_post_meta($postId, 'start_local', true);

                if (! is_string($start) || $start === '') {
                    echo '&mdash;';
                    return;
                }

                $date = $this->parseLocalDateTime($start);

                if ($date === null) {
                    echo '&mdash;';
                    return;
                }

                $allDay = in_array(get_post_meta($postId, 'all_day', true), [true, 1, '1', 'on'], true);
                $format = (string) get_option('date_format');

                if (! $allDay) {
                    $format .= ' ' . (string) get_option('time_format');
                }

                echo esc_html(wp_date($format, $date->getTimestamp(), $this->timezone));
                return;

            case 'event_parish':
                $parishId = (int) get_post_meta($postId, 'parish_id', true);
                echo esc_html($parishId > 0 ? ($this->parishLabel($parishId) ?? 'Unknown parish') : 'Archdiocese-wide');
                return;

            case 'event_type':
                $terms = get_the_terms($postId, EventPostType::TAXONOMY);

                if (! is_array($terms)) {
                    echo '&mdash;';
                    return;
                }

                echo esc_html(implode(', ', array_map(
                    static fn (\WP_Term $term): string => $term->name,
                    $terms
                )));
                return;

            case 'event_status':
                $status = get_post_meta($postId, 'status_flag', true);
                $labels = [
                    'scheduled' => 'Scheduled',
                    'cancelled' => 'Cancelled',
                    'postponed' => 'Postponed',
                ];
                echo esc_html(is_string($status) ? ($labels[$status] ?? 'Unknown') : 'Unknown');
                return;

            case 'event_featured':
                $featured = in_array(get_post_meta($postId, 'featured', true), [true, 1, '1', 'on'], true);
                echo esc_html($featured ? 'Yes' : 'No');
                return;
        }
    }

    public function renderValidationNotice(): void
    {
        if (! is_admin() || ! isset($_GET['post'])) {
            return;
        }

        $rawPostId = $_GET['post'];
        $postId = is_scalar($rawPostId)
            ? absint(wp_unslash((string) $rawPostId))
            : 0;

        if (
            $postId < 1
            || get_post_type($postId) !== EventPostType::POST_TYPE
            || ! current_user_can('edit_post', $postId)
        ) {
            return;
        }

        $state = $this->validationState($postId);
        $errors = $state['errors'] ?? [];

        if (! is_array($errors) || $errors === []) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p><strong>Event details were not saved. Correct the following and save again:</strong></p>
            <ul>
                <?php foreach ($errors as $error) : ?>
                    <li><?php echo esc_html((string) $error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $form
     * @return array{details: EventDetails, errors: list<string>, input: array<string, mixed>}
     */
    private function detailsFromForm(int $postId, array $form): array
    {
        $errors = [];
        $contactInput = $form['contact'] ?? [];
        $contact = [];

        if (! is_array($contactInput)) {
            $errors[] = 'Event contact details must be entered as text.';
        } else {
            foreach (['name', 'email', 'phone'] as $field) {
                $contact[$field] = $this->inputText(
                    $contactInput[$field] ?? '',
                    'Event contact details',
                    $errors
                );
            }
        }

        $input = [
            'parish_id' => $this->inputText($form['parish_id'] ?? '', 'Parish', $errors),
            'venue_id' => $this->inputText($form['venue_id'] ?? '', 'Venue', $errors),
            'start_local' => $this->inputText($form['start_local'] ?? '', 'Start', $errors),
            'end_local' => $this->inputText($form['end_local'] ?? '', 'End', $errors),
            'all_day' => $this->checkboxText($form, 'all_day', $errors),
            'recurrence_preset' => $this->inputText($form['recurrence_preset'] ?? 'none', 'Recurrence', $errors),
            'weekday' => $this->inputText($form['weekday'] ?? 'MO', 'Weekday', $errors),
            'ordinal' => $this->inputText($form['ordinal'] ?? '1', 'Monthly position', $errors),
            'month_day' => $this->inputText($form['month_day'] ?? '1', 'Day of month', $errors),
            'rrule_custom' => $this->inputText($form['rrule_custom'] ?? '', 'Custom RRULE', $errors),
            'exdates_text' => $this->inputText($form['exdates'] ?? '', 'Excluded dates', $errors),
            'rdates_text' => $this->inputText($form['rdates'] ?? '', 'Additional dates', $errors),
            'featured' => $this->checkboxText($form, 'featured', $errors),
            'status_flag' => $this->inputText($form['status_flag'] ?? 'scheduled', 'Status', $errors),
            'contact' => $contact,
        ];

        $parishId = $this->nullableInputId($input['parish_id'], 'parish', $errors);
        $venueId = $this->nullableInputId($input['venue_id'], 'venue', $errors);
        $exdates = $this->dateListFromText($input['exdates_text'], 'exception', $errors);
        $rdates = $this->dateListFromText($input['rdates_text'], 'additional', $errors);
        $rrule = null;

        try {
            $rrule = $this->presetMapper->toRRule(
                $input['recurrence_preset'],
                $input['weekday'],
                $input['ordinal'],
                $input['month_day'],
                $input['rrule_custom']
            );
        } catch (InvalidArgumentException) {
            $errors[] = 'Choose valid recurrence settings.';
        }

        $sourceCandidateId = $this->storedNullableId($postId, 'source_candidate_id');
        $details = new EventDetails(
            $parishId,
            $venueId,
            $input['start_local'],
            $input['end_local'] === '' ? null : $input['end_local'],
            $input['all_day'] === '1',
            $rrule,
            $exdates,
            $rdates,
            $input['featured'] === '1',
            $input['status_flag'],
            $sourceCandidateId,
            $contact
        );

        return [
            'details' => $details,
            'errors' => array_values(array_unique($errors)),
            'input' => $input,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<string> $errors
     */
    private function detailsFromMeta(int $postId, array $meta, array &$errors): EventDetails
    {
        $parishId = $this->metaNullableId($meta['parish_id'] ?? 0, 'parish', $errors);
        $venueId = $this->metaNullableId($meta['venue_id'] ?? 0, 'venue', $errors);
        $start = $this->metaText($meta['start_local'] ?? '', 'start', $errors);
        $end = $this->metaText($meta['end_local'] ?? '', 'end', $errors);
        $allDay = $this->metaBoolean($meta['all_day'] ?? false, 'all-day setting', $errors);
        $rrule = $this->metaText($meta['rrule'] ?? '', 'RRULE', $errors);
        $exdates = $this->metaDateList($meta['exdates'] ?? [], 'exception', $errors);
        $rdates = $this->metaDateList($meta['rdates'] ?? [], 'additional', $errors);
        $featured = $this->metaBoolean($meta['featured'] ?? false, 'featured setting', $errors);
        $status = $this->metaText($meta['status_flag'] ?? 'scheduled', 'status', $errors);
        $sourceCandidateId = $this->metaNullableId(
            $meta['source_candidate_id'] ?? $this->storedNullableId($postId, 'source_candidate_id') ?? 0,
            'source candidate',
            $errors
        );
        $contact = $this->metaContact($meta['contact'] ?? get_post_meta($postId, 'contact', true), $errors);

        return new EventDetails(
            $parishId,
            $venueId,
            $start,
            $end === '' ? null : $end,
            $allDay,
            $rrule === '' ? null : $rrule,
            $exdates,
            $rdates,
            $featured,
            $status,
            $sourceCandidateId,
            $contact
        );
    }

    /**
     * @return array{parish_id: mixed, venue_id: mixed, start_local: mixed, end_local: mixed, all_day: mixed, rrule: mixed, exdates: mixed, rdates: mixed, featured: mixed, status_flag: mixed, source_candidate_id: mixed, contact: mixed}
     */
    private function storedMetaValues(int $postId): array
    {
        $keys = [
            'parish_id',
            'venue_id',
            'start_local',
            'end_local',
            'all_day',
            'rrule',
            'exdates',
            'rdates',
            'featured',
            'status_flag',
            'source_candidate_id',
            'contact',
        ];
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $postId > 0 ? get_post_meta($postId, $key, true) : null;
        }

        if ($values['status_flag'] === '' || $values['status_flag'] === null) {
            $values['status_flag'] = 'scheduled';
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function savedFormValues(int $postId): array
    {
        $meta = $this->storedMetaValues($postId);
        $recurrence = $this->presetMapper->fromRRule(
            is_scalar($meta['rrule']) ? (string) $meta['rrule'] : null
        );
        $contact = is_array($meta['contact']) ? $meta['contact'] : [];

        return [
            'parish_id' => $this->formMetaId($meta['parish_id']),
            'venue_id' => $this->formMetaId($meta['venue_id']),
            'start_local' => is_scalar($meta['start_local']) ? (string) $meta['start_local'] : '',
            'end_local' => is_scalar($meta['end_local']) ? (string) $meta['end_local'] : '',
            'all_day' => $this->storedBoolean($meta['all_day']) ? '1' : '',
            'recurrence_preset' => $recurrence['preset'],
            'weekday' => $recurrence['weekday'],
            'ordinal' => $recurrence['ordinal'],
            'month_day' => $recurrence['month_day'],
            'rrule_custom' => $recurrence['custom_rule'],
            'exdates_text' => $this->dateListToText($meta['exdates']),
            'rdates_text' => $this->dateListToText($meta['rdates']),
            'featured' => $this->storedBoolean($meta['featured']) ? '1' : '',
            'status_flag' => is_scalar($meta['status_flag']) ? (string) $meta['status_flag'] : 'scheduled',
            'contact' => [
                'name' => is_scalar($contact['name'] ?? null) ? (string) $contact['name'] : '',
                'email' => is_scalar($contact['email'] ?? null) ? (string) $contact['email'] : '',
                'phone' => is_scalar($contact['phone'] ?? null) ? (string) $contact['phone'] : '',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function persistMeta(int $postId, EventValidationResult $validation): void
    {
        $values = $validation->values;
        $this->persistNullableMeta($postId, 'parish_id', $values['parish_id']);
        $this->persistNullableMeta($postId, 'venue_id', $values['venue_id']);
        update_post_meta($postId, 'start_local', $values['start_local']);
        $this->persistNullableMeta($postId, 'end_local', $values['end_local']);
        update_post_meta($postId, 'all_day', $values['all_day']);
        $this->persistNullableMeta($postId, 'rrule', $values['rrule']);
        update_post_meta($postId, 'exdates', $values['exdates']);
        update_post_meta($postId, 'rdates', $values['rdates']);
        update_post_meta($postId, 'featured', $values['featured']);
        update_post_meta($postId, 'status_flag', $values['status_flag']);

        $contact = [
            'name' => sanitize_text_field($values['contact']['name']),
            'email' => sanitize_email($values['contact']['email']),
            'phone' => sanitize_text_field($values['contact']['phone']),
        ];

        if ($contact['name'] === '' && $contact['email'] === '' && $contact['phone'] === '') {
            delete_post_meta($postId, 'contact');
        } else {
            update_post_meta($postId, 'contact', $contact);
        }
    }

    /**
     * @return list<string>
     */
    private function relationshipErrors(EventValidationResult $validation): array
    {
        $values = $validation->values;
        $errors = [];
        $parishId = $values['parish_id'];
        $venueId = $values['venue_id'];

        if ($parishId !== null && $this->parishes->findById($parishId) === null) {
            $errors[] = 'Choose an existing parish.';
        }

        if ($venueId !== null) {
            if ($parishId === null) {
                $errors[] = 'Choose a parish before selecting a venue.';
            } else {
                $venue = $this->venues->findVenue($venueId);

                if ($venue === null || $venue->parishId !== $parishId) {
                    $errors[] = 'Choose a venue that belongs to the selected parish.';
                }
            }
        }

        return $errors;
    }

    /**
     * @param list<string> $errors
     */
    private function rememberValidation(int $postId, array $input, array $errors): void
    {
        $state = [
            'input' => $input,
            'errors' => array_values(array_unique($errors)),
        ];
        $key = $this->validationTransientKey($postId);

        if (! set_transient($key, $state, self::VALIDATION_TTL_SECONDS) && get_transient($key) === false) {
            error_log('[ADCT Parish Intake] Could not retain invalid event input for the editing user.');
        }
    }

    private function validationState(int $postId): array
    {
        $state = get_transient($this->validationTransientKey($postId));

        return is_array($state) ? $state : [];
    }

    private function validationTransientKey(int $postId): string
    {
        return self::VALIDATION_TRANSIENT_PREFIX . get_current_user_id() . '_' . $postId;
    }

    /**
     * @param array<string, mixed> $form
     * @param list<string> $errors
     */
    private function checkboxText(array $form, string $field, array &$errors): string
    {
        if (! array_key_exists($field, $form)) {
            return '';
        }

        $value = $form[$field];

        if (in_array($value, [true, 1, '1', 'on', 'true'], true)) {
            return '1';
        }

        if (in_array($value, [false, 0, '0', '', 'false', 'off'], true)) {
            return '';
        }

        $errors[] = 'The ' . str_replace('_', ' ', $field) . ' setting must be checked or unchecked.';

        return '';
    }

    /**
     * @param list<string> $errors
     */
    private function inputText(mixed $value, string $label, array &$errors): string
    {
        if (! is_scalar($value)) {
            $errors[] = $label . ' must be text.';

            return '';
        }

        $text = trim((string) $value);

        if (strlen($text) > self::MAX_INPUT_LENGTH) {
            $errors[] = $label . ' is too long.';

            return substr($text, 0, self::MAX_INPUT_LENGTH);
        }

        return $text;
    }

    /**
     * @param list<string> $errors
     */
    private function nullableInputId(string $value, string $label, array &$errors): ?int
    {
        if ($value === '' || (preg_match('/^\d+$/', $value) === 1 && (int) $value === 0)) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            $errors[] = 'Choose a valid ' . $label . ' from the list.';

            return null;
        }

        return (int) $value;
    }

    /**
     * @param list<string> $errors
     * @return list<string>
     */
    private function dateListFromText(string $value, string $kind, array &$errors): array
    {
        if ($value === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $value);

        if (! is_array($lines)) {
            $errors[] = 'The ' . $this->dateListLabel($kind) . ' could not be read.';

            return [];
        }

        $dates = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '') {
                $dates[] = $line;
            }
        }

        if (count($dates) > 500) {
            $errors[] = 'Enter no more than 500 ' . strtolower($this->dateListLabel($kind)) . '.';
        }

        return $dates;
    }

    private function dateListLabel(string $kind): string
    {
        return $kind === 'exception' ? 'exception dates' : 'additional dates';
    }

    /**
     * @param list<string> $errors
     */
    private function metaNullableId(mixed $value, string $label, array &$errors): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        if (! is_scalar($value) || ! is_numeric($value) || (int) $value < 1) {
            $errors[] = 'The saved ' . $label . ' ID is invalid.';

            return null;
        }

        return (int) $value;
    }

    /**
     * @param list<string> $errors
     */
    private function metaText(mixed $value, string $label, array &$errors): string
    {
        if ($value === null) {
            return '';
        }

        if (! is_scalar($value)) {
            $errors[] = 'The saved event ' . $label . ' must be text.';

            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param list<string> $errors
     */
    private function metaBoolean(mixed $value, string $label, array &$errors): bool
    {
        if (in_array($value, [true, 1, '1', 'on', 'true'], true)) {
            return true;
        }

        if (in_array($value, [false, 0, '0', '', null, 'false', 'off'], true)) {
            return false;
        }

        $errors[] = 'The saved event ' . $label . ' must be true or false.';

        return false;
    }

    /**
     * @param list<string> $errors
     * @return list<string>
     */
    private function metaDateList(mixed $value, string $kind, array &$errors): array
    {
        if (! is_array($value)) {
            $errors[] = 'The saved ' . $this->dateListLabel($kind) . ' must be a list.';

            return [];
        }

        $dates = [];

        foreach ($value as $date) {
            if (! is_string($date)) {
                $errors[] = 'Each saved ' . $kind . ' date must be text.';
                continue;
            }

            $dates[] = trim($date);
        }

        return $dates;
    }

    /**
     * @param list<string> $errors
     * @return array{name: string, email: string, phone: string}
     */
    private function metaContact(mixed $value, array &$errors): array
    {
        $contact = [
            'name' => '',
            'email' => '',
            'phone' => '',
        ];

        if ($value === null || $value === '') {
            return $contact;
        }

        if (! is_array($value)) {
            $errors[] = 'The saved event contact details must be an object.';

            return $contact;
        }

        foreach (array_keys($contact) as $field) {
            if (isset($value[$field]) && ! is_string($value[$field])) {
                $errors[] = 'The saved event contact details must be text.';
            } elseif (isset($value[$field])) {
                $contact[$field] = $value[$field];
            }
        }

        return $contact;
    }

    private function storedNullableId(int $postId, string $key): ?int
    {
        $value = get_post_meta($postId, $key, true);

        return is_scalar($value) && is_numeric($value) && (int) $value > 0
            ? (int) $value
            : null;
    }

    private function persistNullableMeta(int $postId, string $key, mixed $value): void
    {
        if ($value === null || $value === '') {
            delete_post_meta($postId, $key);

            return;
        }

        update_post_meta($postId, $key, $value);
    }

    private function storedBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on'], true);
    }

    private function formMetaId(mixed $value): string
    {
        return is_scalar($value) && (int) $value > 0 ? (string) (int) $value : '';
    }

    private function dateListToText(mixed $value): string
    {
        if (! is_array($value)) {
            return '';
        }

        $dates = array_values(array_filter(
            $value,
            static fn ($date): bool => is_string($date) && $date !== ''
        ));

        return implode("\n", $dates);
    }

    private function formValue(array $values, string $key): string
    {
        $value = $values[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function contactValue(array $values, string $key): string
    {
        $contact = $values['contact'] ?? [];
        $value = is_array($contact) ? ($contact[$key] ?? '') : '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function previewRule(array $values): string
    {
        try {
            $rule = $this->presetMapper->toRRule(
                $this->formValue($values, 'recurrence_preset'),
                $this->formValue($values, 'weekday'),
                $this->formValue($values, 'ordinal'),
                $this->formValue($values, 'month_day'),
                $this->formValue($values, 'rrule_custom')
            );
        } catch (InvalidArgumentException) {
            $rule = null;
        }

        return $rule ?? 'None';
    }

    /**
     * @return array<string, string>
     */
    private function weekdays(): array
    {
        return [
            'MO' => 'Monday',
            'TU' => 'Tuesday',
            'WE' => 'Wednesday',
            'TH' => 'Thursday',
            'FR' => 'Friday',
            'SA' => 'Saturday',
            'SU' => 'Sunday',
        ];
    }

    private function parishLabel(int $parishId): ?string
    {
        if ($this->parishNames === null) {
            $this->parishNames = [];

            foreach ($this->parishes->findForEventEditor() as $parish) {
                $this->parishNames[(int) $parish['id']] = (string) $parish['name'];
            }
        }

        return $this->parishNames[$parishId] ?? null;
    }

    private function parseLocalDateTime(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i') !== $value
        ) {
            return null;
        }

        return $date;
    }
}
