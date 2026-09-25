<?php

declare(strict_types=1);

use ADCT\ParishIntake\WordPress\Events\EventPostType;

$listingPreviousUser = get_current_user_id();
wp_set_current_user(0);
$listingStart = (new DateTimeImmutable('today', wp_timezone()))->modify('+10 days')->setTime(11, 0);
$listingPeriod = [
    'adct_period' => 'range',
    'adct_from' => $listingStart->format('Y-m-d'),
    'adct_to' => $listingStart->format('Y-m-d'),
];
$oldGet = $_GET;

try {
    $parishIds = [$firstParishId];
    for ($i = 1; $i < 150; $i++) {
        $inserted = $wpdb->insert($wpdb->prefix . 'adct_pi_parishes', [
            'name' => 'Fictional listing parish ' . $i,
            'slug' => 'fictional-listing-parish-' . $i,
            'status' => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($inserted !== 1) {
            $fail('Could not seed 150 fictional listing parishes: ' . $wpdb->last_error);
        }
        $parishIds[] = (int) $wpdb->insert_id;
    }

    $seedIds = [];
    foreach ($parishIds as $index => $parishId) {
        $id = wp_insert_post([
            'post_type' => EventPostType::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Fictional listing event ' . $index,
        ], true);
        if (is_wp_error($id) || (int) $id < 1) {
            $fail('Could not seed a public listing event.');
        }
        $seedIds[] = (int) $id;
        if ($index === 0 || $index === 5) {
            update_post_meta((int) $id, 'start_local', $listingStart->format('Y-m-d\TH:i'));
        }
    }
    update_post_meta($seedIds[0], 'featured', '1');
    update_post_meta($seedIds[0], 'rrule', 'FREQ=MONTHLY;COUNT=12');
    update_post_meta($seedIds[0], 'venue_id', $occurrenceVenue->id);
    wp_set_object_terms($seedIds[0], (int) $occurrenceType->term_id, EventPostType::TAXONOMY);
    update_post_meta($seedIds[0], 'contact', [
        'name' => 'Private fictional contact',
        'email' => 'private-contact@example.test',
    ]);

    $placeholderGroups = [];
    $values = [];
    foreach ($seedIds as $index => $id) {
        foreach (range(0, 11) as $month) {
            $start = $listingStart->modify('+' . (($index % 5) + $month * 28) . ' days');
            $utc = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $placeholderGroups[] = '(%d, %s, %s, %d, %s, %s)';
            array_push($values, $id, $utc, $start->format('Y-m-d'), $parishIds[$index], $utc, $utc);
        }
    }
    foreach (array_chunk($placeholderGroups, 150) as $chunkIndex => $chunk) {
        $chunkValues = array_slice($values, $chunkIndex * 900, count($chunk) * 6);
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$occurrencesTable} "
            . '(event_id, start_utc, start_local_date, parish_id, created_at, updated_at) VALUES '
            . implode(', ', $chunk),
            ...$chunkValues
        ));
        if ($inserted !== count($chunk)) {
            $fail('Could not seed 1,800 occurrence rows: ' . $wpdb->last_error);
        }
    }

    $_GET = $listingPeriod;
    wp_cache_flush();
    $coldStart = microtime(true);
    $queryStart = $wpdb->num_queries;
    $html = do_shortcode('[adct_events]');
    $coldSeconds = microtime(true) - $coldStart;
    $coldQueries = $wpdb->num_queries - $queryStart;
    $warmStart = microtime(true);
    $cached = do_shortcode('[adct_events]');
    $warmSeconds = microtime(true) - $warmStart;
    if (
        ! str_contains($html, 'Fictional listing event 0')
        || ! str_contains($html, 'Fictional listing parish 5')
        || ! str_contains($html, 'More events')
        || ! str_contains($html, 'Featured')
        || ! str_contains($html, 'Recurring')
        || ! str_contains($html, 'Social')
        || ! str_contains($html, 'Occurrence integration hall')
        || str_contains($html, 'private-contact@example.test')
        || $cached !== $html
        || $coldSeconds > 5.0
        || $warmSeconds > 2.0
        || $coldQueries > 100
    ) {
        $fail(sprintf(
            'Public listing load/contents failed: cold %.3fs/%d queries; warm %.3fs; HTML: %s',
            $coldSeconds,
            $coldQueries,
            $warmSeconds,
            substr($html, 0, 700)
        ));
    }

    $block = do_blocks('<!-- wp:adct/events /-->');
    if (! str_contains($block, 'Fictional listing event 0')) {
        $fail('The server-rendered event block did not show the same public occurrences.');
    }

    foreach (['week', 'month'] as $preset) {
        $_GET = ['adct_period' => $preset];
        $presetHtml = do_shortcode('[adct_events]');
        if (
            ! str_contains($presetHtml, '<section class="adct-events"')
            || ! str_contains($presetHtml, 'value="' . $preset . '" selected=')
        ) {
            $fail('The public listing did not accept the ' . $preset . ' preset.');
        }
    }
    $_GET = $listingPeriod;
    $_GET['adct_page'] = '2';
    if (! str_contains(do_shortcode('[adct_events]'), 'Previous page')) {
        $fail('The non-JavaScript listing did not provide a second page.');
    }
    $_GET['adct_page'] = '51';
    if (! str_contains(do_shortcode('[adct_events]'), 'role="alert"')) {
        $fail('The listing accepted a page past its cost limit.');
    }
    $_GET['adct_page'] = ['1'];
    if (! str_contains(do_shortcode('[adct_events]'), 'role="alert"')) {
        $fail('The listing accepted an array-valued page.');
    }
    unset($_GET['adct_page']);
    $_GET['adct_from'] = '2026-02-30';
    if (! str_contains(do_shortcode('[adct_events]'), 'role="alert"')) {
        $fail('The listing accepted an invalid calendar date.');
    }
    $_GET = $listingPeriod;

    $privateId = $seedIds[0];
    wp_update_post(['ID' => $privateId, 'post_status' => 'private']);
    if (str_contains(do_shortcode('[adct_events]'), 'Fictional listing event 0')) {
        $fail('A cached public page exposed an event after it became private.');
    }
    $beforeTitleGeneration = get_option('adct_pi_event_listing_generation');
    $movedStart = $listingStart->modify('-4 days');
    update_post_meta($seedIds[5], 'start_local', $movedStart->format('Y-m-d\TH:i'));
    $titleUpdate = wp_update_post(['ID' => $seedIds[5], 'post_title' => 'Updated fictional listing event'], true);
    $_GET['adct_from'] = $movedStart->format('Y-m-d');
    $_GET['adct_to'] = $movedStart->format('Y-m-d');
    $updatedHtml = do_shortcode('[adct_events]');
    if (! str_contains($updatedHtml, 'Updated fictional listing event')) {
        $fail(sprintf(
            'Updating a published post did not refresh its listing (id %d, result %s, generation %s -> %s, status %s, rows %s, stored title %s): %s',
            $seedIds[5],
            is_wp_error($titleUpdate) ? $titleUpdate->get_error_message() : (string) $titleUpdate,
            (string) $beforeTitleGeneration,
            (string) get_option('adct_pi_event_listing_generation'),
            (string) get_post_status($seedIds[5]),
            wp_json_encode($fetchOccurrenceRows($seedIds[5])),
            (string) get_post($seedIds[5])->post_title,
            substr(strip_tags($updatedHtml), 0, 2000)
        ));
    }
    $generationBeforeTerms = get_option('adct_pi_event_listing_generation');
    wp_set_object_terms($seedIds[5], (int) $occurrenceType->term_id, EventPostType::TAXONOMY);
    if (
        $generationBeforeTerms === get_option('adct_pi_event_listing_generation')
        || ! str_contains(do_shortcode('[adct_events]'), 'Social')
    ) {
        $fail('Assigning event types did not rotate the listing generation.');
    }
    $generationBeforeMeta = get_option('adct_pi_event_listing_generation');
    update_post_meta($seedIds[5], 'featured', '1');
    if (
        $generationBeforeMeta === get_option('adct_pi_event_listing_generation')
        || ! str_contains(do_shortcode('[adct_events]'), 'Featured')
    ) {
        $fail('Changing event metadata did not rotate the listing generation.');
    }

    foreach (['draft', 'pending', 'private'] as $status) {
        $hiddenId = wp_insert_post([
            'post_type' => EventPostType::POST_TYPE,
            'post_status' => $status,
            'post_title' => 'Hidden fictional ' . $status . ' event',
        ], true);
        if (is_wp_error($hiddenId) || (int) $hiddenId < 1) {
            $fail('Could not create the unpublished listing fixture.');
        }
        $hiddenStart = $listingStart->modify('-4 days');
        $utc = $hiddenStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        if ($wpdb->insert($occurrencesTable, [
            'event_id' => (int) $hiddenId,
            'start_utc' => $utc,
            'start_local_date' => $hiddenStart->format('Y-m-d'),
            'created_at' => $utc,
            'updated_at' => $utc,
        ]) !== 1) {
            $fail('Could not create a stale non-public occurrence fixture.');
        }
        $_GET['adct_from'] = $hiddenStart->format('Y-m-d');
        $_GET['adct_to'] = $hiddenStart->format('Y-m-d');
        if (str_contains(do_shortcode('[adct_events]'), 'Hidden fictional ' . $status . ' event')) {
            $fail('The public listing exposed a stale ' . $status . ' occurrence.');
        }
    }

    WP_CLI::log(sprintf(
        'Listing load check: 150 parishes, 150 posts, 1,800 yearly occurrences; cold %.3fs/%d queries, warm %.3fs.',
        $coldSeconds,
        $coldQueries,
        $warmSeconds
    ));
} finally {
    $_GET = $oldGet;
    wp_set_current_user($listingPreviousUser);
}
