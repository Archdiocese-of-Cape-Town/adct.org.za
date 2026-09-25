<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use ADCT\ParishIntake\Core\Events\ListingRange;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PublicEventListing
{
    private const PAGE_SIZE = 20;
    private const MAX_PAGE = 50;
    private const CACHE_VERSION = 'adct_pi_event_listing_generation';

    public function __construct(
        private ClockInterface $clock,
        private DateTimeZone $timezone,
        private string $pluginFile
    ) {
    }

    public function register(): void
    {
        add_shortcode('adct_events', [$this, 'shortcode']);
        wp_register_script(
            'adct-events-block',
            plugins_url('assets/events-block.js', $this->pluginFile),
            ['wp-blocks', 'wp-element', 'wp-server-side-render'],
            '1.0.0',
            true
        );
        register_block_type('adct/events', [
            'api_version' => 2,
            'editor_script' => 'adct-events-block',
            'attributes' => [
                'period' => ['type' => 'string', 'default' => 'upcoming'],
            ],
            'render_callback' => [$this, 'block'],
        ]);
    }

    public function styles(): void
    {
        wp_enqueue_style(
            'adct-events',
            plugins_url('assets/events.css', $this->pluginFile),
            [],
            '1.0.0'
        );
    }

    public function invalidate(int $postId = 0): void
    {
        if ($postId > 0 && get_post_type($postId) !== EventPostType::POST_TYPE) {
            return;
        }

        if (! update_option(self::CACHE_VERSION, bin2hex(random_bytes(16)), false)) {
            throw new RuntimeException('Could not invalidate the public event listing cache.');
        }
    }

    public function invalidateMeta(int|array $metaId, int $postId): void
    {
        $this->invalidate($postId);
    }

    public function invalidateTerms(int|\WP_Post $postId): void
    {
        $this->invalidate($postId instanceof \WP_Post ? (int) $postId->ID : $postId);
    }

    public function invalidateOnStatus(string $new, string $old, \WP_Post $post): void
    {
        $this->invalidateTerms($post);
    }

    public function shortcode(mixed $attributes = []): string
    {
        $attributes = shortcode_atts(['period' => 'upcoming'], is_array($attributes) ? $attributes : [], 'adct_events');

        return $this->render($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function block(array $attributes): string
    {
        return $this->render($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function render(array $attributes): string
    {
        try {
            $period = $this->input('adct_period', $attributes['period'] ?? 'upcoming');
            $from = $this->input('adct_from', '');
            $through = $this->input('adct_to', '');
            $page = $this->input('adct_page', '1');

            if (preg_match('/\A(?:[1-9]|[1-4][0-9]|50)\z/D', $page) !== 1) {
                throw new InvalidArgumentException('Choose a page between 1 and 50.');
            }

            $range = new ListingRange($period, $from, $through, $this->clock->now(), $this->timezone);
            $result = $this->rows($range, (int) $page);
        } catch (InvalidArgumentException $error) {
            return '<p role="alert">' . esc_html($error->getMessage()) . '</p>';
        } catch (Throwable $error) {
            error_log('[ADCT Parish Intake] Public event listing failed: ' . $error->getMessage());

            return '<p role="alert">Events are temporarily unavailable. Please try again later.</p>';
        }

        try {
            return $this->renderResults($period, $from, $through, (int) $page, $result);
        } catch (Throwable $error) {
            error_log('[ADCT Parish Intake] Public event rendering failed: ' . $error->getMessage());

            return '<p role="alert">Events are temporarily unavailable. Please try again later.</p>';
        }
    }

    /**
     * @param array{rows: array<int, array<string, mixed>>, more: bool} $result
     */
    private function renderResults(string $period, string $from, string $through, int $page, array $result): string
    {
        $html = '<section class="adct-events" aria-label="Upcoming events">'
            . '<form method="get" class="adct-events__filters">';
        foreach (['page_id', 'p'] as $queryKey) {
            if (isset($_GET[$queryKey]) && is_scalar($_GET[$queryKey]) && (int) $_GET[$queryKey] > 0) {
                $html .= '<input type="hidden" name="' . esc_attr($queryKey)
                    . '" value="' . esc_attr((string) absint($_GET[$queryKey])) . '">';
            }
        }
        $html .= '<label for="adct-period">Show events</label>'
            . '<select id="adct-period" name="adct_period">';

        foreach (['upcoming' => 'All upcoming', 'week' => 'This week', 'month' => 'This month', 'range' => 'Date range'] as $key => $label) {
            $html .= '<option value="' . esc_attr($key) . '"'
                . selected($period, $key, false) . '>' . esc_html($label) . '</option>';
        }

        $html .= '</select><label for="adct-from">From</label>'
            . '<input id="adct-from" type="date" name="adct_from" value="' . esc_attr($from) . '">'
            . '<label for="adct-to">Through</label>'
            . '<input id="adct-to" type="date" name="adct_to" value="' . esc_attr($through) . '">'
            . '<button type="submit">Apply dates</button></form>';

        $visible = [];
        foreach ($result['rows'] as $row) {
            $post = get_post((int) $row['event_id']);
            if (
                $post instanceof \WP_Post
                && $post->post_type === EventPostType::POST_TYPE
                && $post->post_status === 'publish'
                && (string) $row['start_utc'] >= $this->clock->now()
                    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            ) {
                $visible[] = [$row, $post];
            }
        }

        $eventIds = [];
        if ($visible !== []) {
            $eventIds = array_values(array_unique(array_map(
                static fn (array $item): int => (int) $item[0]['event_id'],
                $visible
            )));
            update_meta_cache('post', $eventIds);
        }

        $typeNames = $this->typeNames($eventIds);
        $venueIds = [];
        foreach ($visible as [$row, $post]) {
            $venueId = (int) get_post_meta($post->ID, 'venue_id', true);
            if ($venueId > 0) {
                $venueIds[] = $venueId;
            }
        }
        $venueNames = $this->venueNames(array_values(array_unique($venueIds)));
        $day = '';
        foreach ($visible as [$row, $post]) {
            $date = (string) $row['start_local_date'];
            if ($day !== $date) {
                if ($day !== '') {
                    $html .= '</ul></div>';
                }
                $day = $date;
                $heading = new \DateTimeImmutable($date, $this->timezone);
                $html .= '<div class="adct-events__day"><h2><time datetime="' . esc_attr($date) . '">'
                    . esc_html($heading->format('l j F Y')) . '</time></h2><ul class="adct-events__cards">';
            }

            $start = new \DateTimeImmutable((string) $row['start_utc'], new DateTimeZone('UTC'));
            $start = $start->setTimezone($this->timezone);
            $allDay = get_post_meta($post->ID, 'all_day', true);
            $timeLabel = in_array($allDay, [true, '1', 1], true) ? 'All day' : $start->format('H:i');
            if ($timeLabel !== 'All day' && ! empty($row['end_utc'])) {
                $endTime = (new \DateTimeImmutable((string) $row['end_utc'], new DateTimeZone('UTC')))
                    ->setTimezone($this->timezone);
                $timeLabel .= ' - ' . $endTime->format('H:i');
            }
            $type = $typeNames[(int) $post->ID] ?? '';
            $html .= '<li class="adct-events__card"><h3><a href="' . esc_url(get_permalink($post)) . '">'
                . esc_html(get_the_title($post)) . '</a></h3>'
                . '<p><time datetime="' . esc_attr($start->format('Y-m-d\TH:iP')) . '">'
                . esc_html($timeLabel) . '</time></p>';
            if (! empty($row['parish_name'])) {
                $html .= '<p>' . esc_html((string) $row['parish_name']) . '</p>';
            }
            $venueName = $venueNames[(int) get_post_meta($post->ID, 'venue_id', true)] ?? '';
            if ($venueName !== '') {
                $html .= '<p>' . esc_html($venueName) . '</p>';
            }
            if ($type !== '') {
                $html .= '<span class="adct-events__badge">' . esc_html($type) . '</span> ';
            }
            if (get_post_meta($post->ID, 'rrule', true) !== '') {
                $html .= '<span class="adct-events__badge">Recurring</span> ';
            }
            if (in_array(get_post_meta($post->ID, 'featured', true), [true, '1', 1], true)) {
                $html .= '<span class="adct-events__badge">Featured</span> ';
            }
            if ((int) $row['is_cancelled'] === 1) {
                $html .= '<span class="adct-events__badge">Cancelled</span>';
            } elseif (get_post_meta($post->ID, 'status_flag', true) === 'postponed') {
                $html .= '<span class="adct-events__badge">Postponed</span>';
            }
            $html .= '</li>';
        }

        if ($day !== '') {
            $html .= '</ul></div>';
        } else {
            $html .= '<p>No upcoming events found for these dates.</p>';
        }

        $base = remove_query_arg(['adct_page', 'adct_period', 'adct_from', 'adct_to']);
        $query = ['adct_period' => $period];
        if ($period === 'range') {
            $query['adct_from'] = $from;
            $query['adct_to'] = $through;
        }
        $html .= '<nav aria-label="Event pages">';
        if ((int) $page > 1) {
            $html .= '<a href="' . esc_url(add_query_arg($query + ['adct_page' => (int) $page - 1], $base))
                . '">Previous page</a> ';
        }
        if ($result['more'] && (int) $page < self::MAX_PAGE) {
            $html .= '<a href="' . esc_url(add_query_arg($query + ['adct_page' => (int) $page + 1], $base))
                . '">More events</a>';
        }

        return $html . '</nav></section>';
    }

    private function input(string $key, mixed $default): string
    {
        $value = isset($_GET[$key]) ? wp_unslash($_GET[$key]) : $default;
        if (! is_string($value) || strlen($value) > 32) {
            throw new InvalidArgumentException('Enter a valid event filter.');
        }

        return $value;
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, more: bool}
     */
    private function rows(ListingRange $range, int $page): array
    {
        global $wpdb;

        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:00');
        $from = $range->from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end = $range->through->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $from = max($from, $now);
        $generation = (string) get_option(self::CACHE_VERSION, '0');
        $key = 'adct_pi_list_' . md5(implode('|', [
            $generation,
            $from, $end, (string) $page,
        ]));
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['rows'], $cached['more'])) {
            return $cached;
        }

        $table = $wpdb->prefix . 'adct_pi_occurrences';
        $posts = $wpdb->posts;
        $parishes = $wpdb->prefix . 'adct_pi_parishes';
        $sql = $wpdb->prepare(
            "SELECT o.event_id, o.start_utc, o.end_utc, o.start_local_date, o.is_cancelled, "
            . "p.name AS parish_name "
            . "FROM {$table} o INNER JOIN {$posts} e ON e.ID = o.event_id AND e.post_type = %s AND e.post_status = %s "
            . "LEFT JOIN {$parishes} p ON p.id = o.parish_id "
            . "WHERE o.start_utc >= %s AND o.start_utc < %s "
            . "ORDER BY o.start_utc ASC, o.id ASC LIMIT %d OFFSET %d",
            EventPostType::POST_TYPE,
            'publish',
            $from,
            $end,
            self::PAGE_SIZE + 1,
            ($page - 1) * self::PAGE_SIZE
        );
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($rows) || $wpdb->last_error !== '') {
            throw new RuntimeException('The event occurrence query failed: ' . $wpdb->last_error);
        }
        $more = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        $result = ['rows' => $rows, 'more' => $more];
        if ((string) get_option(self::CACHE_VERSION, '0') === $generation) {
            if (! set_transient($key, $result, 60)) {
                error_log('[ADCT Parish Intake] Public event listing cache could not be written.');
            }
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function venueNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'adct_pi_venues';
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $wpdb->last_error = '';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, name FROM {$table} WHERE id IN ({$placeholders})", ...$ids),
            ARRAY_A
        );
        if (! is_array($rows) || $wpdb->last_error !== '') {
            throw new RuntimeException('The event venue query failed: ' . $wpdb->last_error);
        }

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * @param list<int> $eventIds
     * @return array<int, string>
     */
    private function typeNames(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $terms = wp_get_object_terms($eventIds, EventPostType::TAXONOMY, [
            'orderby' => 'term_id',
            'order' => 'ASC',
            'fields' => 'all_with_object_id',
        ]);
        if (is_wp_error($terms) || ! is_array($terms)) {
            throw new RuntimeException('The public event types could not be loaded.');
        }

        $names = [];
        foreach ($terms as $term) {
            if ($term instanceof \WP_Term && ! isset($names[(int) $term->object_id])) {
                $names[(int) $term->object_id] = $term->name;
            }
        }

        return $names;
    }
}
