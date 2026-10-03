<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Events\IcsCalendar;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\WordPress\Events\EventListingGeneration;
use ADCT\ParishIntake\WordPress\Events\EventPostType;
use ADCT\ParishIntake\WordPress\Events\PublicIcsFeed;

$feedParish = $wpdb->insert($wpdb->prefix . 'adct_pi_parishes', [
    'name' => 'Fictional feed parish',
    'slug' => 'fictional-feed-parish',
    'status' => 'active',
    'created_at' => gmdate('Y-m-d H:i:s'),
    'updated_at' => gmdate('Y-m-d H:i:s'),
]);
if ($feedParish !== 1) {
    $fail('Could not create the fictional feed parish.');
}
$feedParishId = (int) $wpdb->insert_id;
$feedStart = (new DateTimeImmutable('today', new DateTimeZone('Africa/Johannesburg')))
    ->modify('+5 days')->setTime(18, 0);
$feedId = wp_insert_post([
    'post_type' => EventPostType::POST_TYPE,
    'post_status' => 'publish',
    'post_title' => 'Fictional feed gathering',
    'post_excerpt' => 'Public fictional description.',
], true);
if (is_wp_error($feedId) || ! is_int($feedId)) {
    $fail('Could not create the fictional feed event.');
}
update_post_meta($feedId, 'parish_id', $feedParishId);
update_post_meta($feedId, 'start_local', $feedStart->format('Y-m-d\TH:i'));
update_post_meta($feedId, 'end_local', $feedStart->modify('+1 hour')->format('Y-m-d\TH:i'));
update_post_meta($feedId, 'rrule', 'FREQ=WEEKLY;COUNT=4');
update_post_meta($feedId, 'exdates', [$feedStart->modify('+1 week')->format('Y-m-d\TH:i')]);
update_post_meta($feedId, 'contact', ['email' => 'hidden@example.test']);
wp_set_object_terms($feedId, (int) $occurrenceType->term_id, EventPostType::TAXONOMY);
wp_update_post(['ID' => $feedId, 'post_title' => 'Fictional feed gathering']);
$feed = new PublicIcsFeed(new SystemClock(), new EventListingGeneration(), new IcsCalendar());
delete_transient('adct_pi_ics_all');
$unfilteredCold = $feed->response();
if (! str_contains($unfilteredCold['body'], 'Fictional feed gathering')
    || str_contains($unfilteredCold['body'], 'hidden@example.test')) {
    $fail('The unfiltered cold-cache calendar feed omitted public events or exposed private contact details.');
}
$feedQueryStart = $wpdb->num_queries;
$filtered = $feed->response((string) $feedParishId, 'social');
$feedQueries = $wpdb->num_queries - $feedQueryStart;
$body = str_replace("\r\n ", '', $filtered['body']);
if (! str_contains($body, 'UID:adct-event-' . $feedId . '@adct.org.za')
    || ! str_contains($body, 'RRULE:FREQ=WEEKLY;COUNT=4')
    || ! str_contains($body, 'EXDATE;TZID=Africa/Johannesburg:')
    || ! str_contains($body, 'TZID:Africa/Johannesburg')
    || str_contains($body, 'hidden@example.test')
    || ! str_starts_with($filtered['etag'], '"')
    || ! str_ends_with($filtered['last_modified'], 'GMT')
    || $feedQueries > 15
    || $filtered !== $feed->response((string) $feedParishId, 'social')
    || ! str_contains($feed->response((string) $feedParishId, (string) $occurrenceType->term_id)['body'], 'Fictional feed gathering')
    || str_contains($feed->response((string) $feedParishId, 'formation')['body'], 'Fictional feed gathering')) {
    $fail('Installed ZIP calendar feed failed recurrence, filtering, caching or privacy checks.');
}
$oldEtag = $filtered['etag'];
update_post_meta($feedId, 'status_flag', 'cancelled');
$cancelled = $feed->response((string) $feedParishId, 'social');
if ($cancelled['etag'] === $oldEtag || ! str_contains($cancelled['body'], 'STATUS:CANCELLED')) {
    $fail('Calendar cancellation did not invalidate its ETag and status.');
}
wp_update_post(['ID' => $feedId, 'post_status' => 'private']);
if (str_contains($feed->response((string) $feedParishId, 'social')['body'], 'Fictional feed gathering')) {
    $fail('A private event leaked into the calendar feed.');
}
foreach ([['1[]', null], ['0', null], [null, ['social']], [null, 'missing-type']] as [$parish, $type]) {
    try {
        $feed->response($parish, $type);
        $fail('The calendar feed accepted an invalid filter.');
    } catch (InvalidArgumentException) {
    }
}

// The listing must offer feeds that match the filters a visitor actually applied, and the
// one-click webcal variant must point at the same feed over a scheme calendar clients accept.
$listingGet = $_GET;
$listingUser = get_current_user_id();
wp_set_current_user(0);
$feedDay = (new DateTimeImmutable('today', new DateTimeZone('Africa/Johannesburg')))
    ->modify('+5 days')->format('Y-m-d');
$_GET = ['adct_period' => 'range', 'adct_from' => $feedDay, 'adct_to' => $feedDay];
$unfilteredSubscribe = do_shortcode('[adct_events]');
$parishName = 'Fictional feed parish';
$_GET['adct_parish'] = (string) $feedParishId;
$_GET['adct_types'] = [(string) $occurrenceType->term_id];
$scopedSubscribe = do_shortcode('[adct_events]');
$expectedScoped = 'adct_ics=1&#038;parish=' . $feedParishId . '&#038;type='
    . rawurlencode((string) $occurrenceType->slug);
$_GET = $listingGet;
wp_set_current_user($listingUser);

$subscribeBlock = static function (string $html): string {
    $start = strpos($html, '<nav class="adct-events__subscribe"');
    if ($start === false) {
        return '';
    }
    $end = strpos($html, '</nav>', $start);

    return $end === false ? '' : substr($html, $start, $end - $start);
};
$allBlock = $subscribeBlock($unfilteredSubscribe);
$scopedBlock = $subscribeBlock($scopedSubscribe);
if (! str_contains($allBlock, 'Subscribe to all events')
    || str_contains($allBlock, 'parish=')
    || ! str_contains($allBlock, 'webcal://')) {
    $fail('The unfiltered public listing did not offer a whole-feed subscribe link with a webcal variant.');
}
if (! str_contains($scopedBlock, $expectedScoped)
    || ! str_contains($scopedBlock, 'Fictional feed parish')
    || ! str_contains($scopedBlock, 'webcal://')
    || str_contains($scopedBlock, 'Subscribe to all events')) {
    $fail('The filtered public listing did not offer subscribe links scoped to the selected parish and type.');
}
// Each feed is offered twice: the plain URL and the webcal one-click variant.
foreach ([$allBlock, $scopedBlock] as $block) {
    if (substr_count($block, 'href="') % 2 !== 0 || substr_count($block, 'href="') < 2) {
        $fail('Subscribe links were not rendered in plain and webcal pairs.');
    }
}
// A calendar subscribe marker on the listing page must never leak into filter or pagination links.
$_GET = ['adct_period' => 'upcoming', 'adct_ics' => '1'];
$leak = do_shortcode('[adct_events]');
$_GET = $listingGet;
if (str_contains($leak, 'adct_ics=1&amp;adct_page') || str_contains($leak, 'adct_ics=1&#038;adct_period')) {
    $fail('The calendar subscribe marker leaked into listing filter links.');
}
WP_CLI::log('Installed ZIP calendar feed filtering, recurrence, privacy and invalidation checks passed.');
WP_CLI::log('Installed ZIP listing subscribe links, webcal variants and scope checks passed.');
