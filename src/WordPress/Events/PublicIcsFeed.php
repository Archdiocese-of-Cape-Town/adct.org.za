<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use ADCT\ParishIntake\Core\Events\IcsCalendar;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Events\OccurrenceWindow;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PublicIcsFeed
{
    private const MAX_EVENTS = 500;

    public function __construct(
        private ClockInterface $clock,
        private EventListingGeneration $generation,
        private IcsCalendar $calendar
    ) {
    }

    public static function url(?int $parish = null, ?string $type = null): string
    {
        $query = ['adct_ics' => '1'];
        if ($parish !== null) {
            $query['parish'] = $parish;
        }
        if ($type !== null) {
            $query['type'] = $type;
        }
        return add_query_arg($query, home_url('/'));
    }

    public function handleRequest(): void
    {
        if (! isset($_GET['adct_ics'])) {
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
            header('Allow: GET, HEAD');
            status_header(405);
            exit;
        }
        try {
            $marker = wp_unslash($_GET['adct_ics']);
            if ($marker !== '1') {
                throw new InvalidArgumentException('Invalid calendar feed request.');
            }
            $response = $this->response(
                isset($_GET['parish']) ? wp_unslash($_GET['parish']) : null,
                isset($_GET['type']) ? wp_unslash($_GET['type']) : null
            );
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: inline; filename="adct-events.ics"');
            header('Cache-Control: public, max-age=60');
            header('ETag: ' . $response['etag']);
            header('Last-Modified: ' . $response['last_modified']);
            if (
                (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $response['etag'])
                || (! isset($_SERVER['HTTP_IF_NONE_MATCH'])
                    && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
                    && strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']) >= strtotime($response['last_modified']))
            ) {
                status_header(304);
                exit;
            }
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                echo $response['body'];
            }
        } catch (InvalidArgumentException $error) {
            status_header(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid calendar feed filter.';
        } catch (Throwable $error) {
            error_log('[ADCT Parish Intake] Calendar feed failed: ' . $error->getMessage());
            status_header(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Calendar feed temporarily unavailable.';
        }
        exit;
    }

    /**
     * @return array{body: string, etag: string, last_modified: string}
     */
    public function response(mixed $parish = null, mixed $type = null): array
    {
        if ($parish !== null && (! is_string($parish) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $parish) !== 1)) {
            throw new InvalidArgumentException('Invalid parish filter.');
        }
        if ($type !== null && (! is_string($type) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $type) !== 1
            || strlen($type) > 100)) {
            throw new InvalidArgumentException('Invalid event type filter.');
        }
        $parishId = $parish === null ? null : (int) $parish;
        if ($parishId !== null && $parishId > 2147483647) {
            throw new InvalidArgumentException('Invalid parish filter.');
        }
        $termId = null;
        if ($type !== null) {
            $term = ctype_digit($type)
                ? get_term_by('id', (int) $type, EventPostType::TAXONOMY)
                : get_term_by('slug', $type, EventPostType::TAXONOMY);
            if (! $term instanceof \WP_Term) {
                throw new InvalidArgumentException('Unknown event type.');
            }
            $termId = (int) $term->term_id;
        }

        $generation = $this->generation->current();
        $day = $this->clock->now()->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('Y-m-d');
        $key = 'adct_pi_ics_all';
        if ($parishId === null && $termId === null) {
            $cached = get_transient($key);
            if (is_array($cached) && ($cached['generation'] ?? null) === $generation
                && ($cached['day'] ?? null) === $day
                && isset($cached['body'], $cached['etag'], $cached['last_modified'])) {
                return [
                    'body' => $cached['body'],
                    'etag' => $cached['etag'],
                    'last_modified' => $cached['last_modified'],
                ];
            }
        }
        $events = $this->events($parishId, $termId);
        $body = $this->calendar->render($events);
        $lastModified = 0;
        foreach ($events as $event) {
            $lastModified = max($lastModified, strtotime($event['modified']));
        }
        $result = [
            'body' => $body,
            'etag' => '"' . hash('sha256', $generation . $day . $body) . '"',
            'last_modified' => gmdate('D, d M Y H:i:s', $lastModified ?: $this->clock->now()->getTimestamp()) . ' GMT',
        ];
        if ($parishId === null && $termId === null && $this->generation->current() === $generation
            && ! set_transient($key, $result + ['generation' => $generation, 'day' => $day], 60)) {
            error_log('[ADCT Parish Intake] Calendar feed cache could not be written.');
        }
        return $result;
    }

    /**
     * @return list<array{id: int, uid_domain: string, title: string, description: string, url: string, modified: string, start: string, end: string|null, all_day: bool, rrule: string, exdates: list<string>, rdates: list<string>, cancelled: bool}>
     */
    private function events(?int $parishId, ?int $termId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'adct_pi_occurrences';
        $window = OccurrenceWindow::rollingTwelveMonths(
            $this->clock->now(),
            new DateTimeZone('Africa/Johannesburg')
        );
        $utc = new DateTimeZone('UTC');
        $where = 'o.start_utc >= %s AND o.start_utc < %s '
            . 'AND e.post_type = %s AND e.post_status = %s';
        $args = [
            $window->startLocal->setTimezone($utc)->format('Y-m-d H:i:s'),
            $window->endLocal->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
            EventPostType::POST_TYPE,
            'publish',
        ];
        if ($parishId !== null) {
            $where .= ' AND o.parish_id = %d';
            $args[] = $parishId;
        }
        if ($termId !== null) {
            $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr "
                . "INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id "
                . 'WHERE tr.object_id = e.ID AND tt.taxonomy = %s AND tt.term_id = %d)';
            $args[] = EventPostType::TAXONOMY;
            $args[] = $termId;
        }
        $args[] = self::MAX_EVENTS + 1;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT o.event_id FROM {$table} o "
            . "INNER JOIN {$wpdb->posts} e ON e.ID = o.event_id "
            . "WHERE {$where} ORDER BY o.event_id ASC LIMIT %d",
            ...$args
        ), ARRAY_A);
        if (! is_array($rows) || $wpdb->last_error !== '') {
            throw new RuntimeException('Calendar event query failed: ' . $wpdb->last_error);
        }
        if (count($rows) > self::MAX_EVENTS) {
            throw new RuntimeException('Calendar feed exceeds its 500-event limit; use a filtered feed.');
        }
        $ids = array_map(static fn (array $row): int => (int) $row['event_id'], $rows);
        if ($ids === []) {
            return [];
        }
        $posts = get_posts([
            'post_type' => EventPostType::POST_TYPE,
            'post_status' => 'publish',
            'post__in' => $ids,
            'posts_per_page' => self::MAX_EVENTS,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
        ]);
        $events = [];
        foreach ($posts as $post) {
            if (! in_array((int) $post->ID, $ids, true) || $post->post_status !== 'publish') {
                continue;
            }
            $start = get_post_meta($post->ID, 'start_local', true);
            if (! is_string($start) || $start === '') {
                throw new RuntimeException('Published calendar event has no valid start date.');
            }
            $end = get_post_meta($post->ID, 'end_local', true);
            $exdates = get_post_meta($post->ID, 'exdates', true);
            $rdates = get_post_meta($post->ID, 'rdates', true);
            if (! in_array($end, ['', null], true) && ! is_string($end)
                || ! in_array($exdates, ['', null], true) && ! is_array($exdates)
                || ! in_array($rdates, ['', null], true) && ! is_array($rdates)) {
                throw new RuntimeException('Published calendar event metadata is invalid.');
            }
            $events[] = [
                'id' => (int) $post->ID,
                'uid_domain' => 'adct.org.za',
                'title' => html_entity_decode(wp_strip_all_tags($post->post_title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'description' => html_entity_decode(wp_strip_all_tags($post->post_excerpt), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url' => esc_url_raw(get_permalink($post)),
                'modified' => $post->post_modified_gmt !== '0000-00-00 00:00:00'
                    ? $post->post_modified_gmt . ' UTC' : $post->post_date_gmt . ' UTC',
                'start' => $start,
                'end' => $end === '' ? null : $end,
                'all_day' => in_array(get_post_meta($post->ID, 'all_day', true), [true, 1, '1'], true),
                'rrule' => (string) get_post_meta($post->ID, 'rrule', true),
                'exdates' => is_array($exdates) ? $exdates : [],
                'rdates' => is_array($rdates) ? $rdates : [],
                'cancelled' => get_post_meta($post->ID, 'status_flag', true) === 'cancelled',
            ];
        }
        return $events;
    }
}
