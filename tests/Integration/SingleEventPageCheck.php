<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Events\OccurrenceExpander;
use ADCT\ParishIntake\Core\Events\OccurrenceWindow;
use ADCT\ParishIntake\Core\Events\RRuleValidator;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Events\EventPostType;
use ADCT\ParishIntake\WordPress\Events\WordPressEventOccurrenceMaintenance;

final class SingleEventPageCheck
{
    public static function run(callable $fail): void
    {
        global $occurrenceVenue, $occurrenceType, $firstParishId;

        $timezone = wp_timezone();
        $clock = new SystemClock();
        $maintenance = new WordPressEventOccurrenceMaintenance(
            new ADCT\ParishIntake\WordPress\Database\Repository\OccurrenceRepository(
                new WordPressDatabaseConnection()
            ),
            new OccurrenceExpander($timezone, new RRuleValidator()),
            $clock,
            null
        );
        $window = OccurrenceWindow::rollingTwelveMonths($clock->now(), $timezone);
        $ownedIds = [];

        $createEvent = static function (
            string $title,
            string $content,
            array $meta,
            bool $withPoster = false,
            bool $private = false
        ) use (
            $maintenance,
            $window,
            $occurrenceType,
            $firstParishId,
            $occurrenceVenue,
            &$ownedIds,
            $fail
        ): int {
            $postId = wp_insert_post([
                'post_type' => EventPostType::POST_TYPE,
                'post_status' => $private ? 'private' : 'publish',
                'post_title' => $title,
                'post_content' => $content,
                'post_excerpt' => wp_strip_all_tags($content),
            ], true);

            if (is_wp_error($postId) || ! is_int($postId) || $postId < 1) {
                $fail('Could not create the fictional single-event page fixture.');
            }

            $ownedIds[] = $postId;
            wp_set_object_terms($postId, (int) $occurrenceType->term_id, EventPostType::TAXONOMY);

            foreach ($meta as $key => $value) {
                update_post_meta($postId, $key, $value);
            }

            if ($withPoster) {
                $poster = self::attachPoster($postId);
                if ($poster === null) {
                    $fail('The fictional poster could not be attached.');
                }
            }

            $maintenance->rebuildEvent($postId, $window);

            return $postId;
        };

        $onceOffId = $createEvent(
            'Fictional single event',
            '<p>Public details for the fictional single-event page.</p>',
            [
                'parish_id' => $firstParishId,
                'venue_id' => $occurrenceVenue->id,
                'start_local' => (new DateTimeImmutable('tomorrow', $timezone))->setTime(18, 0)->format('Y-m-d\TH:i'),
                'end_local' => (new DateTimeImmutable('tomorrow', $timezone))->setTime(19, 0)->format('Y-m-d\TH:i'),
                'all_day' => 0,
                'rrule' => '',
                'status_flag' => 'scheduled',
                'contact' => [
                    'name' => 'Public parish office',
                    'email' => 'events@example.test',
                    'phone' => '021 555 0101',
                ],
                'raw_mail' => 'private-candidate-body',
            ],
            true
        );

        $recurringId = $createEvent(
            'Fictional recurring event',
            '<p>Recurring public details.</p>',
            [
                'parish_id' => $firstParishId,
                'venue_id' => $occurrenceVenue->id,
                'start_local' => (new DateTimeImmutable('tomorrow', $timezone))->setTime(18, 0)->format('Y-m-d\TH:i'),
                'end_local' => (new DateTimeImmutable('tomorrow', $timezone))->setTime(19, 0)->format('Y-m-d\TH:i'),
                'all_day' => 0,
                'rrule' => 'FREQ=WEEKLY;COUNT=4',
                'status_flag' => 'scheduled',
                'contact' => [
                    'name' => 'Recurring parish desk',
                    'email' => 'repeat@example.test',
                    'phone' => '021 555 0199',
                ],
                'raw_mail' => 'private-recurring-body',
            ]
        );

        $cancelledId = $createEvent(
            'Fictional cancelled event',
            '<p>Cancelled public details.</p>',
            [
                'parish_id' => $firstParishId,
                'venue_id' => $occurrenceVenue->id,
                'start_local' => (new DateTimeImmutable('tomorrow', $timezone))->setTime(20, 0)->format('Y-m-d\TH:i'),
                'end_local' => (new DateTimeImmutable('tomorrow', $timezone))->setTime(21, 0)->format('Y-m-d\TH:i'),
                'all_day' => 0,
                'rrule' => '',
                'status_flag' => 'cancelled',
            ]
        );

        $postponedId = $createEvent(
            'Fictional postponed event',
            '<p>Postponed public details.</p>',
            [
                'parish_id' => $firstParishId,
                'venue_id' => $occurrenceVenue->id,
                'start_local' => (new DateTimeImmutable('tomorrow', $timezone))->setTime(21, 0)->format('Y-m-d\TH:i'),
                'end_local' => (new DateTimeImmutable('tomorrow', $timezone))->setTime(22, 0)->format('Y-m-d\TH:i'),
                'all_day' => 0,
                'rrule' => '',
                'status_flag' => 'postponed',
            ]
        );

        $noVenueId = $createEvent(
            'Fictional parish-only event',
            '<p>Public details without a selected venue.</p>',
            [
                'parish_id' => $firstParishId,
                'venue_id' => 0,
                'start_local' => (new DateTimeImmutable('tomorrow', $timezone))->setTime(17, 0)->format('Y-m-d\TH:i'),
                'end_local' => (new DateTimeImmutable('tomorrow', $timezone))->setTime(18, 0)->format('Y-m-d\TH:i'),
                'all_day' => 0,
                'rrule' => '',
                'status_flag' => 'scheduled',
            ]
        );

        $privateId = $createEvent(
            'Fictional private event',
            '<p>This private event should never render.</p>',
            [
                'parish_id' => $firstParishId,
                'venue_id' => 0,
                'start_local' => (new DateTimeImmutable('tomorrow', $timezone))->setTime(17, 0)->format('Y-m-d\TH:i'),
                'end_local' => (new DateTimeImmutable('tomorrow', $timezone))->setTime(18, 0)->format('Y-m-d\TH:i'),
                'all_day' => 0,
                'rrule' => '',
                'status_flag' => 'scheduled',
            ],
            false,
            true
        );

        self::assertPage($onceOffId, 200, [
            'title' => 'Fictional single event',
            'banner' => null,
            'recurrence' => null,
            'next_dates' => false,
            'poster' => true,
            'venue' => true,
            'private_string' => 'private-candidate-body',
            'ics_contains' => 'UID:adct-event-' . $onceOffId . '@adct.org.za',
            'status' => 'https://schema.org/EventScheduled',
        ], $fail);

        self::assertPage($recurringId, 200, [
            'title' => 'Fictional recurring event',
            'banner' => null,
            'recurrence' => 'Every week',
            'next_dates' => true,
            'poster' => false,
            'venue' => true,
            'private_string' => 'private-recurring-body',
            'ics_contains' => 'RRULE:FREQ=WEEKLY;COUNT=4',
            'status' => 'https://schema.org/EventScheduled',
        ], $fail);

        self::assertPage($cancelledId, 200, [
            'title' => 'Fictional cancelled event',
            'banner' => 'Cancelled',
            'recurrence' => null,
            'next_dates' => false,
            'poster' => false,
            'venue' => true,
            'private_string' => null,
            'ics_contains' => 'STATUS:CANCELLED',
            'status' => 'https://schema.org/EventCancelled',
        ], $fail);

        self::assertPage($postponedId, 200, [
            'title' => 'Fictional postponed event',
            'banner' => 'Postponed',
            'recurrence' => null,
            'next_dates' => false,
            'poster' => false,
            'venue' => true,
            'private_string' => null,
            'ics_contains' => 'UID:adct-event-' . $postponedId . '@adct.org.za',
            'status' => 'https://schema.org/EventPostponed',
        ], $fail);

        self::assertPage($noVenueId, 200, [
            'title' => 'Fictional parish-only event',
            'banner' => null,
            'recurrence' => null,
            'next_dates' => false,
            'poster' => false,
            'venue' => false,
            'private_string' => null,
            'ics_contains' => 'UID:adct-event-' . $noVenueId . '@adct.org.za',
            'status' => 'https://schema.org/EventScheduled',
        ], $fail);

        $privateResponse = wp_remote_get(get_permalink($privateId), [
            'timeout' => 20,
        ]);
        if (is_wp_error($privateResponse)) {
            $fail('The private event page request failed: ' . $privateResponse->get_error_message());
        }
        if ((int) wp_remote_retrieve_response_code($privateResponse) !== 404) {
            $fail('A private event remained publicly accessible.');
        }

        foreach ($ownedIds as $postId) {
            wp_delete_post($postId, true);
        }
    }

    /**
     * @param array{
     *     title: string,
     *     banner: string|null,
     *     recurrence: string|null,
     *     next_dates: bool,
     *     poster: bool,
     *     venue: bool,
     *     private_string: string|null,
     *     ics_contains: string,
     *     status: string
     * } $expectations
     */
    private static function assertPage(int $postId, int $expectedStatus, array $expectations, callable $fail): void
    {
        $response = wp_remote_get(get_permalink($postId), [
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            $fail('The public event page request failed: ' . $response->get_error_message());
        }

        if ((int) wp_remote_retrieve_response_code($response) !== $expectedStatus) {
            $fail('Unexpected HTTP status for the public event page: ' . wp_remote_retrieve_response_code($response));
        }

        $html = (string) wp_remote_retrieve_body($response);
        $jsonLd = self::extractJsonLd($html);
        $calendarUrl = self::extractLink($html, 'Download ICS');

        if (! str_contains($html, $expectations['title'])) {
            $fail('The public event page did not render the title.');
        }
        if ($expectations['banner'] !== null && ! str_contains($html, $expectations['banner'])) {
            $fail('The public event page did not render the status banner.');
        }
        if ($expectations['banner'] === null && preg_match('/adct-event__banner/', $html) === 1) {
            $fail('The public event page rendered an unexpected status banner.');
        }
        if ($expectations['recurrence'] !== null && ! str_contains($html, $expectations['recurrence'])) {
            $fail('The public event page did not render the recurrence phrase.');
        }
        if ($expectations['next_dates'] && ! str_contains($html, 'Next dates')) {
            $fail('The recurring public event page did not render the next dates list.');
        }
        if (! $expectations['next_dates'] && str_contains($html, 'Next dates')) {
            $fail('The non-recurring public event page rendered an unexpected next dates list.');
        }
        if ($expectations['poster'] && ! str_contains($html, 'adct-event__poster')) {
            $fail('The public event page did not render the poster image.');
        }
        if (! $expectations['poster'] && str_contains($html, 'adct-event__poster')) {
            $fail('The public event page rendered an unexpected poster image.');
        }
        if ($expectations['venue'] && ! str_contains($html, '<dt>Venue</dt>')) {
            $fail('The public event page did not render the venue details.');
        }
        if (! $expectations['venue'] && str_contains($html, '<dt>Venue</dt>')) {
            $fail('The public event page rendered unexpected venue details.');
        }
        if ($expectations['private_string'] !== null && str_contains($html, $expectations['private_string'])) {
            $fail('The public event page leaked private candidate data.');
        }
        if (! str_contains($calendarUrl, 'event=' . $postId)) {
            $fail('The public event page did not point to the single-event ICS download.');
        }
        if (! is_array($jsonLd) || ($jsonLd['@type'] ?? null) !== 'Event') {
            $fail('The public event page JSON-LD did not describe an Event.');
        }
        if (($jsonLd['eventStatus'] ?? null) !== $expectations['status']) {
            $fail('The JSON-LD eventStatus value was incorrect.');
        }
        if (! isset($jsonLd['location']) && $expectations['venue']) {
            $fail('The JSON-LD location was missing.');
        }
        if (! isset($jsonLd['organizer'])) {
            $fail('The JSON-LD organizer was missing.');
        }
        if (($jsonLd['name'] ?? null) !== $expectations['title']) {
            $fail('The JSON-LD name did not match the page title.');
        }

        if ($expectations['ics_contains'] !== null) {
            $icsResponse = wp_remote_get($calendarUrl, [
                'timeout' => 20,
            ]);
            if (is_wp_error($icsResponse)) {
                $fail('The single-event ICS request failed: ' . $icsResponse->get_error_message());
            }
            if ((int) wp_remote_retrieve_response_code($icsResponse) !== 200) {
                $fail('The single-event ICS request did not return 200.');
            }
            $icsBody = (string) wp_remote_retrieve_body($icsResponse);
            if (! str_contains($icsBody, 'BEGIN:VCALENDAR')
                || ! str_contains($icsBody, 'BEGIN:VEVENT')
                || ! str_contains($icsBody, $expectations['ics_contains'])
                || str_contains($icsBody, 'private-candidate-body')) {
                $fail('The single-event ICS body did not validate privacy or expected content.');
            }
        }
    }

    private static function extractJsonLd(string $html): array
    {
        if (preg_match('/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s', $html, $matches) !== 1) {
            return [];
        }

        $decoded = json_decode((string) $matches[1], true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function extractLink(string $html, string $label): string
    {
        if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*' . preg_quote($label, '/') . '\s*<\/a>/i', $html, $matches) !== 1) {
            return '';
        }

        return html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function attachPoster(int $postId): ?int
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO3f3sEAAAAASUVORK5CYII=',
            true
        );
        if (! is_string($bytes)) {
            return null;
        }

        $upload = wp_upload_bits('fictional-poster.png', null, $bytes);
        if (! is_array($upload) || ! empty($upload['error']) || ! isset($upload['file'], $upload['url'])) {
            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title' => 'Fictional poster',
            'post_status' => 'inherit',
        ], $upload['file'], $postId);
        if (is_wp_error($attachmentId) || ! is_int($attachmentId) || $attachmentId < 1) {
            return null;
        }

        $metadata = wp_generate_attachment_metadata($attachmentId, $upload['file']);
        if (is_array($metadata)) {
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        if (! set_post_thumbnail($postId, $attachmentId)) {
            return null;
        }

        return $attachmentId;
    }
}
