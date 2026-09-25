<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use ADCT\ParishIntake\Core\Events\ListingRange;
use ADCT\ParishIntake\Core\Events\ListingSelection;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PublicEventListing
{
    private const PAGE_SIZE = 20;
    private const MAX_PAGE = 100;
    public function __construct(
        private ClockInterface $clock,
        private DateTimeZone $timezone,
        private string $pluginFile,
        private ?EventListingGeneration $generation = null
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
        wp_register_script(
            'adct-events-filters',
            plugins_url('assets/events-filters.js', $this->pluginFile),
            [],
            '1.0.0',
            true
        );
    }

    public function styles(): void
    {
        wp_enqueue_style(
            'adct-events',
            plugins_url('assets/events.css', $this->pluginFile),
            [],
            '1.0.0'
        );
        wp_enqueue_script('adct-events-filters');
    }

    public function registerRestRoute(): void
    {
        register_rest_route('adct-parish-intake/v1', '/events', [
            'methods' => \WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'rest'],
        ]);
    }

    public function rest(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $base = $request->get_param('page_url');
            if (! is_string($base) || strlen($base) > 1024 || ! $this->isPublicPageUrl($base)) {
                throw new InvalidArgumentException('Enter a valid page URL.');
            }
            $selection = new ListingSelection($request->get_query_params());

            return new \WP_REST_Response(['html' => $this->listing($selection, $base)]);
        } catch (InvalidArgumentException $error) {
            return new \WP_Error('adct_invalid_filter', $error->getMessage(), ['status' => 400]);
        } catch (Throwable $error) {
            error_log('[ADCT Parish Intake] Public event REST listing failed: ' . $error->getMessage());

            return new \WP_Error('adct_listing_unavailable', 'Events are temporarily unavailable.', ['status' => 503]);
        }
    }

    private function isPublicPageUrl(string $url): bool
    {
        $page = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        $query = $page['query'] ?? '';

        return is_array($page) && is_array($home)
            && ($page['scheme'] ?? null) === ($home['scheme'] ?? null)
            && ($page['host'] ?? null) === ($home['host'] ?? null)
            && ($page['port'] ?? null) === ($home['port'] ?? null)
            && ! isset($page['user']) && ! isset($page['pass'])
            && ! isset($page['fragment'])
            && isset($page['path']) && str_starts_with($page['path'], '/')
            && ($query === '' || (
                preg_match('/\A(?:page_id|p)=([1-9][0-9]{0,9})\z/D', $query, $matches) === 1
                && (float) $matches[1] <= 2147483647
            ));
    }

    public function invalidate(int $postId = 0): void
    {
        if (EventOccurrenceHooks::isPublishingCandidate()) {
            return;
        }

        if ($postId > 0 && get_post_type($postId) !== EventPostType::POST_TYPE) {
            return;
        }

        $this->generation()->bump();
    }

    private function generation(): EventListingGeneration
    {
        return $this->generation ?? new EventListingGeneration();
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
            $selection = new ListingSelection(wp_unslash($_GET), $attributes['period'] ?? 'upcoming');
            return $this->listing($selection, remove_query_arg([
                'adct_page', 'adct_period', 'adct_from', 'adct_to',
                'adct_types', 'adct_parish', 'adct_deanery',
            ]));
        } catch (InvalidArgumentException $error) {
            return '<p role="alert">' . esc_html($error->getMessage()) . '</p>';
        } catch (Throwable $error) {
            error_log('[ADCT Parish Intake] Public event listing failed: ' . $error->getMessage());

            return '<p role="alert">Events are temporarily unavailable. Please try again later.</p>';
        }
    }

    private function listing(ListingSelection $selection, string $base): string
    {
        $range = new ListingRange(
            $selection->period, $selection->from, $selection->through, $this->clock->now(), $this->timezone
        );
        $types = get_terms(['taxonomy' => EventPostType::TAXONOMY, 'hide_empty' => false]);
        if (is_wp_error($types) || ! is_array($types)) {
            throw new RuntimeException('The event types could not be loaded.');
        }
        $parishes = $this->options('adct_pi_parishes');
        $deaneries = $this->options('adct_pi_deaneries');
        if ($selection->types !== [] && array_diff($selection->types, array_map(
            static fn (\WP_Term $term): int => (int) $term->term_id, $types
        )) !== []) {
            throw new InvalidArgumentException('Choose an available event type.');
        }
        if ($selection->parish !== null && ! isset($parishes[$selection->parish])) {
            throw new InvalidArgumentException('Choose an available parish.');
        }
        if ($selection->deanery !== null && ! isset($deaneries[$selection->deanery])) {
            throw new InvalidArgumentException('Choose an available deanery.');
        }
        $result = $this->rows($range, $selection);

        return $this->renderResults($selection, $result, $types, $parishes, $deaneries, $base);
    }

    /**
     * @param array{rows: array<int, array<string, mixed>>, more: bool} $result
     */
    private function renderResults(
        ListingSelection $selection,
        array $result,
        array $types,
        array $parishes,
        array $deaneries,
        string $base
    ): string
    {
        $html = '<section class="adct-events" aria-label="Upcoming events" data-endpoint="'
            . esc_url(rest_url('adct-parish-intake/v1/events')) . '">'
            . '<form method="get" class="adct-events__filters">';
        $baseQuery = wp_parse_url($base, PHP_URL_QUERY);
        if (is_string($baseQuery)) {
            parse_str($baseQuery, $pageQuery);
            foreach (['page_id', 'p'] as $queryKey) {
                if (isset($pageQuery[$queryKey]) && is_scalar($pageQuery[$queryKey])
                    && (int) $pageQuery[$queryKey] > 0) {
                    $html .= '<input type="hidden" name="' . esc_attr($queryKey)
                        . '" value="' . esc_attr((string) absint($pageQuery[$queryKey])) . '">';
                }
            }
        }
        $html .= '<label for="adct-period">Show events</label>'
            . '<select id="adct-period" name="adct_period">';

        foreach (['upcoming' => 'All upcoming', 'week' => 'This week', 'month' => 'This month', 'range' => 'Date range'] as $key => $label) {
            $html .= '<option value="' . esc_attr($key) . '"'
                . selected($selection->period, $key, false) . '>' . esc_html($label) . '</option>';
        }

        $html .= '</select><label for="adct-from">From</label>'
            . '<input id="adct-from" type="date" name="adct_from" value="' . esc_attr($selection->from) . '">'
            . '<label for="adct-to">Through</label>'
            . '<input id="adct-to" type="date" name="adct_to" value="' . esc_attr($selection->through) . '">'
            . '<label for="adct-types">Event types (choose several with Ctrl or Command)</label>'
            . '<select id="adct-types" name="adct_types[]" multiple size="5">';
        foreach ($types as $type) {
            $id = (int) $type->term_id;
            $html .= '<option value="' . esc_attr((string) $id) . '"'
                . (in_array($id, $selection->types, true) ? ' selected="selected"' : '')
                . '>' . esc_html($type->name) . '</option>';
        }
        $html .= '</select><label for="adct-deanery">Deanery</label>'
            . '<select id="adct-deanery" name="adct_deanery"><option value="">All deaneries</option>';
        foreach ($deaneries as $id => $name) {
            $html .= '<option value="' . esc_attr((string) $id) . '"'
                . selected($selection->deanery, $id, false) . '>' . esc_html($name) . '</option>';
        }
        $html .= '</select><label for="adct-parish">Parish</label>'
            . '<select id="adct-parish" name="adct_parish"><option value="">All parishes</option>';
        foreach ($parishes as $id => $name) {
            $html .= '<option value="' . esc_attr((string) $id) . '"'
                . selected($selection->parish, $id, false) . '>' . esc_html($name) . '</option>';
        }
        $html .= '</select><button type="submit">Apply filters</button></form>';

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
        $html .= '<p class="adct-events__status" tabindex="-1">Showing '
            . count($visible) . ' matching events on this page.</p>';

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

        $html .= '<nav aria-label="Event pages">';
        if ($selection->page > 1) {
            $html .= '<a href="' . esc_url(add_query_arg($selection->query($selection->page - 1), $base))
                . '">Previous page</a> ';
        }
        if ($result['more'] && $selection->page < self::MAX_PAGE) {
            $html .= '<a href="' . esc_url(add_query_arg($selection->query($selection->page + 1), $base))
                . '">More events</a>';
        } elseif ($result['more']) {
            $html .= '<p>Narrow the date range to see more events.</p>';
        }

        return $html . '</nav></section>';
    }

    /** @return array<int, string> */
    private function options(string $suffix): array
    {
        global $wpdb;
        $table = $wpdb->prefix . $suffix;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results(
            "SELECT id, name FROM {$table} WHERE status = 'active' ORDER BY name ASC LIMIT 500",
            ARRAY_A
        );
        if (! is_array($rows) || $wpdb->last_error !== '') {
            throw new RuntimeException('The public event directory could not be loaded: ' . $wpdb->last_error);
        }

        $options = [];
        foreach ($rows as $row) {
            $options[(int) $row['id']] = (string) $row['name'];
        }

        return $options;
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, more: bool}
     */
    private function rows(ListingRange $range, ListingSelection $selection): array
    {
        global $wpdb;

        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:00');
        $from = $range->from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end = $range->through->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $from = max($from, $now);
        $cacheable = $selection->period !== 'range'
            && $selection->types === [] && $selection->parish === null && $selection->deanery === null;
        $generation = '';
        $key = 'adct_pi_list_v2_' . $selection->period . '_' . $selection->page;
        if ($cacheable) {
            $generation = $this->generation()->current();
            $cached = get_transient($key);
            if (
                is_array($cached)
                && ($cached['generation'] ?? null) === $generation
                && ($cached['from'] ?? null) === $from
                && ($cached['end'] ?? null) === $end
                && isset($cached['rows'], $cached['more'])
            ) {
                return ['rows' => $cached['rows'], 'more' => $cached['more']];
            }
        }

        $table = $wpdb->prefix . 'adct_pi_occurrences';
        $posts = $wpdb->posts;
        $parishes = $wpdb->prefix . 'adct_pi_parishes';
        $where = '';
        $args = [EventPostType::POST_TYPE, 'publish', $from, $end];
        if ($selection->parish !== null) {
            $where .= ' AND o.parish_id = %d';
            $args[] = $selection->parish;
        }
        if ($selection->deanery !== null) {
            $where .= ' AND p.deanery_id = %d';
            $args[] = $selection->deanery;
        }
        if ($selection->types !== []) {
            $relationships = $wpdb->term_relationships;
            $taxonomy = $wpdb->term_taxonomy;
            $placeholders = implode(', ', array_fill(0, count($selection->types), '%d'));
            $where .= " AND EXISTS (SELECT 1 FROM {$relationships} tr "
                . "INNER JOIN {$taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id "
                . "WHERE tr.object_id = o.event_id AND tt.taxonomy = %s AND tt.term_id IN ({$placeholders}))";
            $args[] = EventPostType::TAXONOMY;
            array_push($args, ...$selection->types);
        }
        $args[] = self::PAGE_SIZE + 1;
        $args[] = ($selection->page - 1) * self::PAGE_SIZE;
        $sql = $wpdb->prepare(
            "SELECT o.event_id, o.start_utc, o.end_utc, o.start_local_date, o.is_cancelled, "
            . "p.name AS parish_name "
            . "FROM {$table} o INNER JOIN {$posts} e ON e.ID = o.event_id AND e.post_type = %s AND e.post_status = %s "
            . "LEFT JOIN {$parishes} p ON p.id = o.parish_id "
            . "WHERE o.start_utc >= %s AND o.start_utc < %s {$where} "
            . "ORDER BY o.start_utc ASC, o.id ASC LIMIT %d OFFSET %d",
            ...$args
        );
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($rows) || $wpdb->last_error !== '') {
            throw new RuntimeException('The event occurrence query failed: ' . $wpdb->last_error);
        }
        $more = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        $result = ['rows' => $rows, 'more' => $more];
        if ($cacheable && $this->generation()->current() === $generation) {
            if (! set_transient($key, $result + [
                'generation' => $generation,
                'from' => $from,
                'end' => $end,
            ], 60)) {
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
