<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
use ADCT\ParishIntake\Core\Events\GeoDistance;
use ADCT\ParishIntake\Core\Events\IcsFeedLinks;
use ADCT\ParishIntake\Core\Events\ListingRange;
use ADCT\ParishIntake\Core\Events\ListingSelection;
use ADCT\ParishIntake\Core\Events\NearMePoint;
use ADCT\ParishIntake\Core\Events\RecurrenceSummary;
use ADCT\ParishIntake\Core\Events\SuburbResolver;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PublicEventListing
{
    private const PAGE_SIZE = 20;
    private const MAX_PAGE = 100;

    /**
     * The haversine distance in kilometres from the visitor to an occurrence, as SQL.
     *
     * Every function used here is present on both MySQL 8 and MariaDB 10.11, which are the pair of
     * servers this plugin has to run on. The radius of the earth is the same figure the PHP
     * GeoDistance class uses, so the distance shown on a card and the distance a visitor sorts by
     * are the same number.
     *
     * The three `%f` placeholders are latitude, latitude, longitude; NearMePoint::distanceArguments()
     * returns those three floats in that order.
     */
    private function distanceSql(NearMePoint $point): string
    {
        return '(6371.0088 * 2 * ASIN(LEAST(1, SQRT('
            . 'POWER(SIN(RADIANS(o.latitude - %f) / 2), 2) + COS(RADIANS(%f)) * COS(RADIANS(o.latitude))'
            . ' * POWER(SIN(RADIANS(o.longitude - %f) / 2), 2)))))';
    }

    /**
     * @param SuburbResolver|null $suburbs Resolves a typed suburb to a point. Null only in tests
     *                                     that never exercise the near-me path; a request with
     *                                     `adct_suburb` set is refused when it is missing, so the
     *                                     listing can never silently fall back to a wrong place.
     */
    public function __construct(
        private ClockInterface $clock,
        private DateTimeZone $timezone,
        private string $pluginFile,
        private ?EventListingGeneration $generation = null,
        private ?SuburbResolver $suburbs = null,
        private ?SourceMaterialStoreInterface $sourceMaterial = null
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
            '1.1.0',
            true
        );
    }

    public function styles(): void
    {
        wp_enqueue_style(
            'adct-events',
            plugins_url('assets/events.css', $this->pluginFile),
            [],
            '1.1.0'
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
                'adct_pin', 'adct_collapse', 'adct_ics',
                'adct_lat', 'adct_lng', 'adct_radius_km', 'adct_suburb',
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

                    $nearMe = $this->nearMePoint($selection);
        $result = $this->rows($range, $selection, $nearMe);

        return $this->renderResults($selection, $result, $types, $parishes, $deaneries, $base, $nearMe);
    }

    /**
     * Where the visitor asked to sort from, or null when they did not ask.
     *
     * The browser case is the coordinates in the URL, which arrive only because the visitor pressed the
     * button (ADR 0020). The suburb case is a lookup against our own parish and venue coordinates, never
     * a geocoding service, and an unknown suburb is reported rather than silently ignored: showing every
     * event in date order because a suburb name was spelled differently would be worse than saying so.
     */
    private function nearMePoint(ListingSelection $selection): ?NearMePoint
    {
        $radiusKm = $selection->radiusKm ?? NearMePoint::DEFAULT_RADIUS_KM;

        if ($selection->hasNearMePoint()) {
            return NearMePoint::fromBrowserLocation(
                (float) $selection->latitude,
                (float) $selection->longitude,
                $radiusKm
            );
        }

        if ($selection->suburb === null) {
            return null;
        }

        if ($this->suburbs === null) {
            throw new RuntimeException('The suburb lookup is not available.');
        }

        $point = $this->suburbs->resolve($selection->suburb, $radiusKm);

        if ($point === null) {
            throw new InvalidArgumentException(
                'We do not have a location for that suburb. Choose one from the list, '
                . 'or use the Near me button instead.'
            );
        }

        return $point;
    }

    /**
     * The `page_id` or `p` that identifies the page holding the listing, so the plain GET form
     * posts back to the same page. WordPress Pages are reached by either name.
     */
    private function pageIdentityInputs(string $base): string
    {
        $html = '';
        $baseQuery = wp_parse_url($base, PHP_URL_QUERY);
        if (! is_string($baseQuery)) {
            return $html;
        }

        parse_str($baseQuery, $pageQuery);
        foreach (['page_id', 'p'] as $queryKey) {
            if (isset($pageQuery[$queryKey]) && is_scalar($pageQuery[$queryKey])
                && (int) $pageQuery[$queryKey] > 0) {
                $html .= '<input type="hidden" name="' . esc_attr($queryKey)
                    . '" value="' . esc_attr((string) absint($pageQuery[$queryKey])) . '">';
            }
        }

        return $html;
    }

    /**
     * The opt-in "Near me" controls.
     *
     * Nothing here asks for a location on its own: the browser prompt appears only when a visitor
     * presses the button, and the suburb box is there from the start so the feature is usable
     * without ever granting permission (ADR 0020). The radius select only appears once a sort is on,
     * because before then it would mean nothing to a visitor.
     */
    private function nearMeControls(ListingSelection $selection, ?NearMePoint $nearMe): string
    {
        $html = '<section class="adct-nearme" aria-labelledby="adct-nearme-heading">'
            . '<h2 id="adct-nearme-heading">Find events near me</h2>'
            . '<p class="adct-nearme__note">We only use your location to sort this list. '
            . 'Nothing is saved, and the page is not shared with anyone.</p>';

        if ($nearMe === null) {
            $html .= '<button type="button" class="adct-nearme__button" data-adct-nearme-button>'
                . 'Use my location</button>';
        } else {
            $html .= '<p class="adct-nearme__active">Sorted by distance from <strong>'
                . esc_html($nearMe->describe()) . '</strong>. '
                . '<a href="' . esc_url(add_query_arg(
                    ['adct_lat' => false, 'adct_lng' => false, 'adct_radius_km' => false, 'adct_suburb' => false],
                    add_query_arg($selection->query(), remove_query_arg(
                        ['adct_page', 'adct_period', 'adct_from', 'adct_to', 'adct_types',
                            'adct_parish', 'adct_deanery', 'adct_pin', 'adct_collapse', 'adct_ics',
                            'adct_lat', 'adct_lng', 'adct_radius_km', 'adct_suburb'],
                        $this->currentUrl()
                    ))
                )) . '">Show all events by date instead</a></p>';
        }

        $html .= '<p class="adct-nearme__fallback" data-adct-nearme-fallback '
            . ($nearMe === null ? ' hidden' : '') . '>'
            . '<label for="adct-suburb">Or type your suburb</label>'
            . '<input id="adct-suburb" name="adct_suburb" type="text" maxlength="100" '
            . 'list="adct-suburb-options" autocomplete="address-level2" '
            . 'value="' . esc_attr($selection->suburb ?? '') . '">'
            . '<datalist id="adct-suburb-options">';
        foreach ($this->suburbSuggestions() as $name) {
            $html .= '<option value="' . esc_attr($name) . '"></option>';
        }
        $html .= '</datalist><button type="submit" class="adct-nearme__suburb">'
            . 'Sort by this suburb</button></p></section>';

        if ($nearMe === null) {
            return $html;
        }

        $radius = (float) ($selection->radiusKm ?? NearMePoint::DEFAULT_RADIUS_KM);
        $html .= '<p class="adct-nearme__radius"><label for="adct-radius">How far are you willing to travel?</label>'
            . '<select id="adct-radius" name="adct_radius_km">';
        foreach (NearMePoint::RADIUS_CHOICES_KM as $choice) {
            $html .= '<option value="' . esc_attr(ListingSelection::formatNumber($choice)) . '"'
                . ($choice === $radius ? ' selected="selected"' : '') . '>'
                . esc_html(NearMePoint::radiusLabel($choice)) . '</option>';
        }

        return $html . '</select></p>';
    }

    /**
     * The suburb names to offer, or an empty list when the lookup fails. A listing that cannot read
     * the lookup should still render its events, so this never throws.
     *
     * @return list<string>
     */
    private function suburbSuggestions(): array
    {
        static $names = null;

        if ($names === null) {
            try {
                $names = $this->suburbs === null
                    ? []
                    : $this->suburbs->suggestions();
            } catch (Throwable $error) {
                error_log('[ADCT Parish Intake] Suburb suggestions unavailable: ' . $error->getMessage());
                $names = [];
            }
        }

        return $names;
    }

    /** The current request URL, used to build the link that turns the near-me sort back off. */
    private function currentUrl(): string
    {
        $path = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? (string) wp_unslash($_SERVER['REQUEST_URI'])
            : '/';

        return home_url($path);
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
        string $base,
        ?NearMePoint $nearMe = null
    ): string {
        $html = '<section class="adct-events" aria-label="Upcoming events" data-endpoint="'
            . esc_url(rest_url('adct-parish-intake/v1/events')) . '">'
            . '<form method="get" class="adct-events__filters">'
                        . $this->pageIdentityInputs($base);
        if ($selection->hasNearMePoint()) {
            /** Keeps the sort when a visitor changes another filter on the same page. */
            $html .= '<input type="hidden" name="adct_lat" value="'
                . esc_attr(ListingSelection::formatNumber((float) $selection->latitude)) . '">'
                . '<input type="hidden" name="adct_lng" value="'
                . esc_attr(ListingSelection::formatNumber((float) $selection->longitude)) . '">'
                . '<input type="hidden" name="adct_radius_km" value="'
                . esc_attr(ListingSelection::formatNumber(
                    (float) ($selection->radiusKm ?? NearMePoint::DEFAULT_RADIUS_KM)
                )) . '">';
        } elseif ($selection->suburb !== null) {
            $html .= '<input type="hidden" name="adct_suburb" value="' . esc_attr($selection->suburb) . '">';
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
        $html .= '</select><label><input type="checkbox" name="adct_collapse" value="1"'
            . checked($selection->collapse, true, false) . '> Show only the next date of each recurring event</label>'
            . '<label><input type="checkbox" name="adct_pin" value="1"'
            . checked($selection->pin, true, false) . '> Show featured events first</label>'
            . '<button type="submit">Apply filters</button></form>'
            . $this->nearMeControls($selection, $nearMe);

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
            . count($visible) . ' matching events on this page'
            . ($nearMe === null
                ? '.'
                : ', closest to ' . esc_html($nearMe->describe())
                    . ' first. Turn Near me off to see them by date instead.')
            . '</p>';
        $html .= $this->subscribe($selection, $parishes, $types);

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
            $featured = in_array(get_post_meta($post->ID, 'featured', true), [true, '1', 1], true);
            $rrule = get_post_meta($post->ID, 'rrule', true);
            $html .= '<li class="adct-events__card'
                . ($featured ? ' adct-events__card--featured' : '')
                . (is_string($rrule) && $rrule !== '' ? ' adct-events__card--recurring' : '')
                . '"><h3><a href="' . esc_url(get_permalink($post)) . '">'
                . esc_html(get_the_title($post)) . '</a></h3>'
                . '<p><time datetime="' . esc_attr($start->format('Y-m-d\TH:iP')) . '">'
                . esc_html($timeLabel) . '</time></p>';
            if (is_string($rrule) && $rrule !== '') {
                $html .= '<p class="adct-events__recurrence">'
                    . esc_html(RecurrenceSummary::describe($rrule)
                        . ($selection->collapse ? ' - next: ' . $start->format('j M') : ''))
                    . '</p>';
            }
            if (! empty($row['parish_name'])) {
                $html .= '<p>' . esc_html((string) $row['parish_name']) . '</p>';
            }
            $venueName = $venueNames[(int) get_post_meta($post->ID, 'venue_id', true)] ?? '';
            if ($venueName !== '') {
                $html .= '<p>' . esc_html($venueName) . '</p>';
            }
            if ($nearMe !== null && is_numeric($row['distance_km'] ?? null)) {
                $html .= '<p class="adct-events__distance">' . esc_html(
                    GeoDistance::formatKilometres((float) $row['distance_km'])
                ) . '</p>';
            }
            if ($type !== '') {
                $html .= '<span class="adct-events__badge">' . esc_html($type) . '</span> ';
            }
            if (is_string($rrule) && $rrule !== '') {
                $html .= '<span class="adct-events__badge">Recurring</span> ';
            }
            if ($featured) {
                $html .= '<span class="adct-events__badge">Featured</span> ';
            }
            if ((int) $row['is_cancelled'] === 1) {
                $html .= '<span class="adct-events__badge">Cancelled</span>';
            } elseif (get_post_meta($post->ID, 'status_flag', true) === 'postponed') {
                $html .= '<span class="adct-events__badge">Postponed</span>';
            }
            $html .= $this->cardSourceMaterial($post);
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

    /**
     * The card's source material: the poster if the event has a featured image,
     * otherwise a link to the first promoted document (issue #172, AC6).
     *
     * This asks the promotion store and nothing else, for the same reason the
     * single event page does: an attachment sitting in the media library that
     * nobody promoted must not become reachable by being a child of the event.
     * A null store is the "no port wired" case and renders nothing, rather than
     * falling back to a broad `get_children()`, which would be the opposite of
     * the guarantee.
     *
     * The poster is the featured image rather than a role lookup, so that
     * removing a source item's featured-image role empties the card the same way
     * it empties the single-event figure. A promoted poster that was never set
     * as the featured image must not sneak back in through this path.
     *
     * A reference whose URL will not resolve is skipped, not emptied: an empty
     * `href` reads as "the bulletin is here" when it is not.
     */
    private function cardSourceMaterial(\WP_Post $post): string
    {
        $poster = $this->cardPoster((int) $post->ID);
        if ($poster !== null) {
            return '<figure class="adct-events__poster">'
        . '<img src="' . esc_url($poster['url']) . '"'
        . ' width="' . esc_attr((string) $poster['width']) . '"'
        . ' height="' . esc_attr((string) $poster['height']) . '"'
        . ' alt="' . esc_attr($poster['alt']) . '" loading="lazy">'
        . '</figure>';
        }

        if ($this->sourceMaterial === null) {
            return '';
        }

        foreach ($this->sourceMaterial->forEvent((int) $post->ID) as $reference) {
            $url = wp_get_attachment_url($reference->attachmentId);
            if (! is_string($url) || $url === '') {
                continue;
            }

            return '<p class="adct-events__source"><a href="' . esc_url($url) . '" rel="noopener noreferrer">'
        . esc_html(SourceMaterialRole::label($reference->role)) . '</a></p>';
        }

        return '';
    }

    /**
     * The event's featured image, or null when it has none that can be shown.
     *
     * The same answer `PublicEventPage::poster()` gives, by the same route, so
     * that clearing the featured-image role empties the card and the single
     * event figure together. The alt text is the attachment's own alt, and
     * empty when there is none: an empty alt is right for a poster that repeats
     * the event's title, which the card already shows beside it, and inventing
     * text from the filename would only be a worse version of it.
     *
     * @return array{url: string, width: int, height: int, alt: string}|null
     */
    private function cardPoster(int $postId): ?array
    {
        if ($postId < 1 || ! has_post_thumbnail($postId)) {
            return null;
        }

        $thumbnailId = get_post_thumbnail_id($postId);
        if (! is_int($thumbnailId) || $thumbnailId < 1) {
            return null;
        }

        $image = wp_get_attachment_image_src($thumbnailId, 'medium');
        if (! is_array($image) || ! isset($image[0], $image[1], $image[2])) {
            return null;
        }

        return [
            'url' => (string) $image[0],
            'width' => (int) $image[1],
            'height' => (int) $image[2],
            'alt' => trim((string) get_post_meta($thumbnailId, '_wp_attachment_image_alt', true)),
        ];
    }

    /**
     * Subscribe links for the feeds matching the filters currently applied.
     *
     * The listing can filter by several event types at once but the feed endpoint takes one, so
     * each selected type is offered as its own feed instead of silently widening to every event.
     *
     * @param array<int, string> $parishes
     * @param array<int, \WP_Term> $types
     */
    private function subscribe(ListingSelection $selection, array $parishes, array $types): string
    {
            $catalogue = [];
            foreach ($types as $type) {
                $catalogue[(int) $type->term_id] = ['name' => $type->name, 'slug' => $type->slug];
            }

            $links = (new IcsFeedLinks($catalogue))->links(
                $selection->parish,
                $selection->parish === null ? null : ($parishes[$selection->parish] ?? null),
                $selection->types
            );

            $html = '<nav class="adct-events__subscribe" aria-label="Subscribe to a calendar">';
            foreach ($links as $link) {
                $url = PublicIcsFeed::url($link['parish'], $link['type']);
                $html .= '<a class="adct-events__subscribe-link" href="' . esc_url($url) . '">'
                    . esc_html($link['label']) . '</a> '
                    . '<a class="adct-events__subscribe-webcal" href="' . esc_url(IcsFeedLinks::webcal($url))
                    . '">Add to calendar app</a> ';
            }

            return $html . '</nav>';
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
    private function rows(ListingRange $range, ListingSelection $selection, ?NearMePoint $nearMe): array
    {
        global $wpdb;

        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:00');
        $from = $range->from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end = $range->through->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $from = max($from, $now);
        $cacheable = $selection->period !== 'range'
            && $selection->types === [] && $selection->parish === null && $selection->deanery === null
            && $nearMe === null;
        $generation = '';
        $key = 'adct_pi_list_v3_' . $selection->period . '_' . $selection->page
            . '_' . (int) $selection->collapse . (int) $selection->pin;
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
        $postmeta = $wpdb->postmeta;
        $parishes = $wpdb->prefix . 'adct_pi_parishes';
        $where = '';
        $joins = '';
        $order = 'o.start_utc ASC, o.id ASC';
        // $wpdb->prepare() pairs arguments with placeholders in the textual order of the finished
        // SQL, not in the order the fragments were assembled, so the arguments for the SELECT list
        // go in first: the distance expression sits ahead of every other placeholder in the query.
        $args = $nearMe === null
            ? []
            : $nearMe->distanceArguments();
        $args[] = EventPostType::POST_TYPE;
        $args[] = 'publish';
        $args[] = $from;
        $args[] = $end;
        if ($selection->collapse) {
            $joins .= " LEFT JOIN {$postmeta} rm ON rm.post_id = o.event_id AND rm.meta_key = 'rrule'";
            $where .= " AND (rm.meta_value IS NULL OR rm.meta_value = '' OR NOT EXISTS "
                . "(SELECT 1 FROM {$table} earlier WHERE earlier.event_id = o.event_id "
                . "AND earlier.start_utc >= %s AND (earlier.start_utc < o.start_utc "
                . "OR (earlier.start_utc = o.start_utc AND earlier.id < o.id))))";
            $args[] = $from;
        }
        if ($selection->pin) {
            $joins .= " LEFT JOIN {$postmeta} fm ON fm.post_id = o.event_id AND fm.meta_key = 'featured'";
            $order = "CASE WHEN fm.meta_value = '1' THEN 0 ELSE 1 END, " . $order;
        }
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

        $select = 'o.event_id, o.start_utc, o.end_utc, o.start_local_date, o.is_cancelled, '
            . 'p.name AS parish_name ';

        if ($nearMe !== null) {
            /**
             * Occurrences without a pin cannot be placed on the map, so they are left out of a
             * distance sort rather than shown at the bottom pretending to be far away. The radius
             * is a real filter, not just a hint: it decides which events the visitor is willing to
             * travel to see.
             *
             * The distance expression is repeated for the radius filter, the SELECT and the ORDER
             * BY, and the whole statement is assembled before a single prepare() call. Its own
             * arguments are therefore pushed in the order those placeholders actually appear in
             * the finished SQL — SELECT (seeded above), then WHERE, then ORDER BY — while the
             * LIMIT and OFFSET pair goes last, because $wpdb->prepare() pairs arguments with
             * placeholders positionally and not by name. All three runs come from
             * NearMePoint::distanceArguments(), which is what keeps the number a visitor reads the
             * same as the number the rows were ordered by.
             */
            $where .= ' AND o.latitude IS NOT NULL AND o.longitude IS NOT NULL';
            $where .= ' AND ' . $this->distanceSql($nearMe) . ' <= %f';
            array_push($args, ...$nearMe->distanceArguments());
            $args[] = $nearMe->radiusKm;
            $select .= ', ' . $this->distanceSql($nearMe) . ' AS distance_km ';
            $order = $this->distanceSql($nearMe) . ' ASC, o.start_utc ASC, o.id ASC';
            array_push($args, ...$nearMe->distanceArguments());
        }

        $args[] = self::PAGE_SIZE + 1;
        $args[] = ($selection->page - 1) * self::PAGE_SIZE;
        $sql = $wpdb->prepare(
            "SELECT {$select}"
            . "FROM {$table} o INNER JOIN {$posts} e ON e.ID = o.event_id AND e.post_type = %s AND e.post_status = %s "
            . "LEFT JOIN {$parishes} p ON p.id = o.parish_id {$joins} "
            . "WHERE o.start_utc >= %s AND o.start_utc < %s {$where} "
            . "ORDER BY {$order} LIMIT %d OFFSET %d",
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
