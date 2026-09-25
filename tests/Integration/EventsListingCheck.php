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
    update_post_meta($seedIds[0], 'raw_mail', 'fictional-raw-message-body');

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
    $transientCount = static function () use ($wpdb): int {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_adct_pi_list_') . '%'
        ));
    };
    $beforeRangeCache = $transientCount();
    $html = do_shortcode('[adct_events]');
    $rangeRepeat = do_shortcode('[adct_events]');
    if ($transientCount() !== $beforeRangeCache || $rangeRepeat !== $html) {
        $fail('Custom date ranges wrote listing transients or changed between reads.');
    }
    if (
        ! str_contains($html, 'Fictional listing event 0')
        || ! str_contains($html, 'Fictional listing parish 5')
        || ! str_contains($html, 'More events')
        || ! str_contains($html, 'Featured')
        || ! str_contains($html, 'Recurring')
        || ! str_contains($html, 'Social')
        || ! str_contains($html, 'Occurrence integration hall')
        || str_contains($html, 'private-contact@example.test')
        || str_contains($html, 'fictional-raw-message-body')
    ) {
        $fail('The public date-range listing omitted required cards or exposed private contact details.');
    }
    if (! str_contains($html, 'adct-events__card--featured')
        || ! str_contains($html, 'adct-events__card--recurring')
        || ! str_contains($html, 'Every month')) {
        $fail('Featured and recurring events were not visually distinguished or summarized.');
    }
    $_GET = ['adct_period' => 'upcoming', 'adct_parish' => (string) $parishIds[0]];
    $expandedSeries = do_shortcode('[adct_events]');
    $_GET['adct_collapse'] = '1';
    $collapsedSeries = do_shortcode('[adct_events]');
    if (
        substr_count($expandedSeries, 'Fictional listing event 0</a>') < 2
        || substr_count($collapsedSeries, 'Fictional listing event 0</a>') !== 1
        || ! str_contains($collapsedSeries, 'Every month - next:')
        || ! str_contains($collapsedSeries, 'name="adct_collapse" value="1" checked=')
    ) {
        $fail('Collapsing a recurring series did not keep only its next occurrence.');
    }
    update_post_meta($seedIds[9], 'featured', '1');
    wp_set_object_terms($seedIds[1], (int) $occurrenceType->term_id, EventPostType::TAXONOMY);
    wp_set_object_terms($seedIds[9], (int) $occurrenceType->term_id, EventPostType::TAXONOMY);
    $_GET = [
        'adct_period' => 'range',
        'adct_from' => $listingStart->format('Y-m-d'),
        'adct_to' => $listingStart->modify('+4 days')->format('Y-m-d'),
        'adct_types' => [(string) $occurrenceType->term_id],
    ];
    $chronological = do_shortcode('[adct_events]');
    $_GET['adct_pin'] = '1';
    $pinned = do_shortcode('[adct_events]');
    if (
        strpos($chronological, 'Fictional listing event 1</a>')
            >= strpos($chronological, 'Fictional listing event 9</a>')
        || strpos($pinned, 'Fictional listing event 9</a>')
            >= strpos($pinned, 'Fictional listing event 1</a>')
        || ! str_contains($pinned, 'name="adct_pin" value="1" checked=')
    ) {
        $fail('Optional featured pinning did not preserve chronological default and put featured first.');
    }
    $_GET = $listingPeriod;

    $spiritual = get_term_by('slug', 'spiritual', EventPostType::TAXONOMY);
    if (! $spiritual instanceof WP_Term) {
        $fail('The second event type was not installed.');
    }
    wp_set_object_terms($seedIds[0], [(int) $occurrenceType->term_id, (int) $spiritual->term_id], EventPostType::TAXONOMY);
    wp_set_object_terms($seedIds[5], (int) $spiritual->term_id, EventPostType::TAXONOMY);
    $deaneriesTable = $wpdb->prefix . 'adct_pi_deaneries';
    if ($wpdb->insert($deaneriesTable, [
        'name' => 'Fictional filter deanery',
        'slug' => 'fictional-filter-deanery',
        'status' => 'active',
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]) !== 1) {
        $fail('Could not seed a listing deanery.');
    }
    $listingDeanery = (int) $wpdb->insert_id;
    if ($wpdb->update($wpdb->prefix . 'adct_pi_parishes', ['deanery_id' => $listingDeanery], [
        'id' => $parishIds[5],
    ]) !== 1) {
        $fail('Could not associate the parish with the listing deanery.');
    }
    $beforeFilteredCache = $transientCount();
    $_GET = $listingPeriod + [
        'adct_types' => [(string) $spiritual->term_id, (string) $occurrenceType->term_id],
        'adct_parish' => (string) $parishIds[5],
        'adct_deanery' => (string) $listingDeanery,
    ];
    $filtered = do_shortcode('[adct_events]');
    if (
        ! str_contains($filtered, 'Fictional listing event 5')
        || str_contains($filtered, 'Fictional listing event 0')
        || $transientCount() !== $beforeFilteredCache
    ) {
        $fail('Combined multi-type, parish/deanery selection or cache bypass failed.');
    }
    $_GET = ['adct_period' => 'upcoming', 'adct_types' => [
        (string) $spiritual->term_id, (string) $occurrenceType->term_id,
    ]];
    $multiPage = do_shortcode('[adct_events]');
    if (! preg_match('/href="([^"]+)">More events<\/a>/', $multiPage, $matches)) {
        $fail('The type-filtered result has no next-page URL.');
    }
    $nextUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    parse_str((string) wp_parse_url($nextUrl, PHP_URL_QUERY), $sharedFilters);
    if (
        ($sharedFilters['adct_page'] ?? null) !== '2'
        || count($sharedFilters['adct_types'] ?? []) !== 2
    ) {
        $fail('Paging dropped the shareable multi-type selection.');
    }
    $_GET = $sharedFilters;
    if (! str_contains(do_shortcode('[adct_events]'), 'Previous page')) {
        $fail('A shared filtered URL did not reproduce the second page.');
    }
    $_GET = $listingPeriod + [
        'adct_types' => [(string) $spiritual->term_id],
    ];
    $_GET['adct_types'] = [(string) $spiritual->term_id];
    $_GET['adct_parish'] = (string) $parishIds[0];
    unset($_GET['adct_deanery']);
    if (! str_contains(do_shortcode('[adct_events]'), 'Fictional listing event 0')) {
        $fail('A second assigned type did not match an event whose stored occurrence type differs.');
    }
    $_GET['adct_deanery'] = (string) $listingDeanery;
    if (str_contains(do_shortcode('[adct_events]'), 'Fictional listing event 0')) {
        $fail('A parish outside the chosen deanery matched both filters.');
    }
    $_GET['adct_parish'] = (string) $parishIds[5];
    $filterBlock = do_blocks('<!-- wp:adct/events /-->');
    if (! str_contains($filterBlock, 'Fictional listing event 5')) {
        $fail('The event block did not apply the same filters as the shortcode.');
    }

    $restListing = static function (array $params): WP_REST_Response|WP_Error {
        $request = new WP_REST_Request('GET', '/adct-parish-intake/v1/events');
        $request->set_query_params($params + ['page_url' => home_url('/events/')]);
        return rest_do_request($request);
    };
    $collapsedRest = $restListing([
        'adct_period' => 'upcoming',
        'adct_parish' => (string) $parishIds[0],
        'adct_collapse' => '1',
    ]);
    if ($collapsedRest->get_status() !== 200
        || substr_count((string) ($collapsedRest->get_data()['html'] ?? ''), 'Fictional listing event 0</a>') !== 1
        || str_contains((string) ($collapsedRest->get_data()['html'] ?? ''), 'private-contact@example.test')) {
        $fail('The public REST collapse mode failed or exposed private event data.');
    }
    $restParams = $listingPeriod + [
        'adct_types' => [(string) $spiritual->term_id],
        'adct_parish' => (string) $parishIds[5],
        'adct_deanery' => (string) $listingDeanery,
    ];
    $restResult = $restListing($restParams);
    if (
        $restResult->get_status() !== 200
        || ! str_contains((string) ($restResult->get_data()['html'] ?? ''), 'Fictional listing event 5')
        || str_contains((string) ($restResult->get_data()['html'] ?? ''), 'private-contact@example.test')
        || str_contains((string) ($restResult->get_data()['html'] ?? ''), 'fictional-raw-message-body')
    ) {
        $fail('The public REST selection did not match the listing or leaked a private contact.');
    }
    foreach (['page_id', 'p'] as $pageKey) {
        $plainResult = $restListing([
            'page_url' => home_url('/?' . $pageKey . '=123'),
            'adct_period' => 'upcoming',
            'adct_types' => [(string) $spiritual->term_id, (string) $occurrenceType->term_id],
        ]);
        $plainHtml = (string) ($plainResult->get_data()['html'] ?? '');
        if (
            $plainResult->get_status() !== 200
            || ! str_contains($plainHtml, 'name="' . $pageKey . '" value="123"')
            || ! preg_match('/href="([^"]+)">More events<\/a>/', $plainHtml, $plainMatch)
        ) {
            $fail('The progressive listing dropped the plain-permalink page selector.');
        }
        parse_str((string) wp_parse_url(html_entity_decode($plainMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_QUERY), $plainQuery);
        if (
            ($plainQuery[$pageKey] ?? null) !== '123'
            || ($plainQuery['adct_page'] ?? null) !== '2'
            || count($plainQuery['adct_types'] ?? []) !== 2
        ) {
            $fail('The progressive listing lost the plain permalink or selection when paging.');
        }
    }
    foreach ([
        ['adct_types' => [['1']]],
        ['adct_types' => range(1, 21)],
        ['adct_parish' => ['1']],
        ['adct_page' => '101'],
        ['page_url' => 'https://outside.example.test/events/'],
        ['page_url' => home_url('/?page_id=0')],
        ['page_url' => home_url('/?page_id=123&private=1')],
    ] as $invalid) {
        if ($restListing($invalid)->get_status() !== 400) {
            $fail('The public REST listing accepted malformed or oversized input.');
        }
    }
    $_GET = $listingPeriod;

    $_GET = ['adct_period' => 'upcoming'];
    wp_cache_flush();
    $coldStart = microtime(true);
    $queryStart = $wpdb->num_queries;
    $upcoming = do_shortcode('[adct_events]');
    $coldSeconds = microtime(true) - $coldStart;
    $coldQueries = $wpdb->num_queries - $queryStart;
    $warmStart = microtime(true);
    $cached = do_shortcode('[adct_events]');
    $warmSeconds = microtime(true) - $warmStart;
    if (
        ! str_contains($upcoming, 'More events')
        || $cached !== $upcoming
        || $coldSeconds > 5.0
        || $warmSeconds > 2.0
        || $coldQueries > 100
    ) {
        $fail(sprintf(
            'Public listing load/contents failed: cold %.3fs/%d queries; warm %.3fs; HTML: %s',
            $coldSeconds,
            $coldQueries,
            $warmSeconds,
            substr($upcoming, 0, 700)
        ));
    }
    $_GET['adct_page'] = '90';
    if (! str_contains(do_shortcode('[adct_events]'), 'More events')) {
        $fail('The yearly listing cannot navigate past the 1,000th occurrence.');
    }
    $_GET['adct_page'] = '91';
    if (! str_contains(do_shortcode('[adct_events]'), 'Fictional listing event')) {
        $fail('The yearly listing did not reach the last seeded occurrences.');
    }

    $_GET = $listingPeriod;
    $block = do_blocks('<!-- wp:adct/events /-->');
    if (! str_contains($block, 'Fictional listing event 0')) {
        $fail('The server-rendered event block did not show the same public occurrences.');
    }

    $oldRequestUri = $_SERVER['REQUEST_URI'] ?? null;
    try {
        $_SERVER['REQUEST_URI'] = '/?page_id=123&adct_period=upcoming';
        $_GET = ['page_id' => '123', 'adct_period' => 'upcoming'];
        $noJsPlain = do_shortcode('[adct_events]');
        if (
            ! str_contains($noJsPlain, 'name="page_id" value="123"')
            || ! preg_match('/href="([^"]+)">More events<\/a>/', $noJsPlain, $noJsMatch)
            || ! str_contains(html_entity_decode($noJsMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'page_id=123')
        ) {
            $fail('The no-JavaScript listing lost a plain-permalink page selector.');
        }
    } finally {
        if ($oldRequestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $oldRequestUri;
        }
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
    $_GET['adct_page'] = '101';
    if (! str_contains(do_shortcode('[adct_events]'), 'role="alert"')) {
        $fail('The listing accepted a page past its cost limit.');
    }
    $_GET['adct_page'] = ['1'];
    if (! str_contains(do_shortcode('[adct_events]'), 'role="alert"')) {
        $fail('The listing accepted an array-valued page.');
    }
    foreach ([
        ['adct_types' => ['999999999999999999999']],
        ['adct_types' => range(1, 21)],
        ['adct_parish' => ['1']],
    ] as $invalid) {
        $_GET = $listingPeriod + $invalid;
        if (! str_contains(do_shortcode('[adct_events]'), 'role="alert"')) {
            $fail('The no-JavaScript listing accepted an invalid event selection.');
        }
    }
    $_GET = $listingPeriod;
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
    if (str_contains((string) ($restListing($listingPeriod)->get_data()['html'] ?? ''), 'Fictional listing event 0')) {
        $fail('The public REST listing exposed a newly private event.');
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
        if (str_contains((string) ($restListing($listingPeriod)->get_data()['html'] ?? ''), 'Hidden fictional ' . $status . ' event')) {
            $fail('The public REST listing exposed a stale ' . $status . ' occurrence.');
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
