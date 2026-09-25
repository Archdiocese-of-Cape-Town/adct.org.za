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
WP_CLI::log('Installed ZIP calendar feed filtering, recurrence, privacy and invalidation checks passed.');
