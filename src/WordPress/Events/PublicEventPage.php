<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use ADCT\ParishIntake\Core\Events\EventPresentation;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;
use ADCT\ParishIntake\Core\Publishing\SourceAttachment;
use ADCT\ParishIntake\WordPress\Database\Repository\OccurrenceRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WP_Post;

final class PublicEventPage
{
    private const MAX_NEXT_DATES = 5;

    /**
     * How each promotion role reads on the public page (issue #172).
     *
     * A publisher chose these words when they promoted the file, so the public
     * page reuses them rather than inventing its own wording for the same state.
     *
     * @var array<string, string>
     */
    private const ROLE_LABELS = [
        SourceAttachment::ROLE_POSTER => 'Poster',
        SourceAttachment::ROLE_BULLETIN => 'Bulletin',
        SourceAttachment::ROLE_DOCUMENT => 'Document',
    ];

    public function __construct(
        private ClockInterface $clock,
        private DateTimeZone $timezone,
        private ParishRepository $parishes,
        private VenueRepository $venues,
        private OccurrenceRepository $occurrences,
                private string $pluginFile,
        private ?SourceMaterialStoreInterface $sourceMaterial = null
    ) {
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'guardVisibility'], 0);
        add_filter('single_template', [$this, 'filterTemplate'], 10, 1);
    }

    public function filterTemplate(string $template): string
    {
        if (! is_singular(EventPostType::POST_TYPE)) {
            return $template;
        }

        $post = get_queried_object();
        if (! $post instanceof WP_Post || $post->post_type !== EventPostType::POST_TYPE
            || ($post->post_status !== 'publish' && ! is_preview())) {
            return $template;
        }

        $themeTemplate = locate_template(['single-adct_event.php']);
        if (is_string($themeTemplate) && $themeTemplate !== '') {
            return $themeTemplate;
        }

        $pluginTemplate = plugin_dir_path($this->pluginFile) . 'templates/single-adct_event.php';
        if (! is_file($pluginTemplate)) {
            return $template;
        }

        return $pluginTemplate;
    }

    public function guardVisibility(): void
    {
        if (! is_singular(EventPostType::POST_TYPE) || is_preview()) {
            return;
        }

        $post = get_queried_object();
        if (! $post instanceof WP_Post || $post->post_type !== EventPostType::POST_TYPE) {
            return;
        }

        if ($post->post_status === 'publish') {
            return;
        }

        global $wp_query;
        if ($wp_query instanceof \WP_Query) {
            $wp_query->set_404();
        }
        status_header(404);
        nocache_headers();
    }

    /**
     * @return array{
     *     title: string,
     *     description_html: string,
     *     description_text: string,
     *     date_iso: string,
     *     date_label: string,
     *     time_label: string,
     *     recurrence_phrase: string,
     *     next_dates: list<array{label: string, datetime: string}>,
     *     parish: array{name: string, address: string, website: string, phone: string},
     *     venue: array{name: string, address: string, suburb: string},
     *     map_url: string|null,
     *     contact: array{name: string, email: string, phone: string},
     *     poster: array{url: string, width: int, height: int, alt: string}|null,
     *     source_material: list<array{role: string, role_label: string, filename: string, url: string, media_id: int}>,
     *     source_material_has_poster: bool,
     *     calendar_url: string,
     *     google_calendar_url: string|null,
     *     json_ld: array<string, mixed>,
     *     status_banner: string,
     *     status_class: string
     * }
     */
    public function viewForPost(WP_Post $post): array
    {
        if ($post->post_type !== EventPostType::POST_TYPE) {
            throw new RuntimeException('The event page can only render event posts.');
        }

        $startLocal = $this->localDateTime($this->metaText($post->ID, 'start_local'));
        $endLocalRaw = $this->metaText($post->ID, 'end_local');
        $endLocal = $endLocalRaw === '' ? null : $this->localDateTime($endLocalRaw);
        $allDay = $this->metaBoolean($post->ID, 'all_day');
        $rrule = $this->metaText($post->ID, 'rrule');
        $exdates = $this->metaList($post->ID, 'exdates');
        $rdates = $this->metaList($post->ID, 'rdates');
        $statusFlag = $this->metaText($post->ID, 'status_flag');
        $description = (string) ($post->post_content ?? '');
        if (trim($description) === '') {
            $description = (string) ($post->post_excerpt ?? '');
        }

        $parishId = $this->metaId($post->ID, 'parish_id');
        $venueId = $this->metaId($post->ID, 'venue_id');
        $parish = $this->parishData($parishId);
        $venue = $this->venueData($venueId);
        $mapUrl = $this->mapUrlForLocation($parish, $venue, $parishId, $venueId);
        $contact = $this->contactData($post->ID);
        $nextDates = $this->nextDates($post->ID, $allDay, $rrule !== '');
        $recurrencePhrase = EventPresentation::recurrencePhrase($rrule === '' ? null : $rrule);
        $calendarUrl = PublicIcsFeed::url(null, null, $post->ID);
        $address = $venue['address'] !== '' ? $venue['address'] : $parish['address'];
        $googleCalendarUrl = $this->googleCalendarUrl(
            (string) $post->post_title,
            $description,
            $address,
            $startLocal,
            $endLocal,
            $allDay,
            $rrule,
            $exdates,
            $rdates
        );
        $poster = $this->poster($post);
        $sourceMaterial = $this->sourceMaterial($post, $poster);

        return [
            'title' => wp_strip_all_tags((string) $post->post_title),
            'description_html' => wp_kses_post($description),
            'description_text' => trim(wp_strip_all_tags($description)),
            'date_iso' => $startLocal->setTimezone($this->timezone)->format('c'),
            'date_label' => $startLocal->setTimezone($this->timezone)->format('l j F Y'),
            'time_label' => $this->timeLabel($startLocal, $endLocal, $allDay),
            'recurrence_phrase' => $recurrencePhrase,
            'next_dates' => $nextDates,
            'parish' => $parish,
            'venue' => $venue,
            'map_url' => $mapUrl,
            'contact' => $contact,
            'poster' => $poster,
            'source_material' => $sourceMaterial['items'],
            'source_material_has_poster' => $sourceMaterial['has_poster'],
            'calendar_url' => $calendarUrl,
            'google_calendar_url' => $googleCalendarUrl,
            'json_ld' => $this->jsonLd(
                $post,
                $startLocal,
                $endLocal,
                $allDay,
                $statusFlag,
                $description,
                $parish,
                $venue,
                $mapUrl,
                $poster
            ),
            'status_banner' => $this->statusBanner($statusFlag),
            'status_class' => $this->statusClass($statusFlag),
        ];
    }

    public function viewCurrentPost(): array
    {
        $post = get_queried_object();
        if (! $post instanceof WP_Post) {
            throw new RuntimeException('The current event page has no post.');
        }

        return $this->viewForPost($post);
    }

    /**
     * @return array{name: string, address: string, website: string, phone: string}
     */
    private function parishData(?int $parishId): array
    {
        if ($parishId === null) {
            return [
                'name' => '',
                'address' => '',
                'website' => '',
                'phone' => '',
            ];
        }

        $parish = $this->parishes->findWithRelations($parishId);
        if ($parish === null) {
            return [
                'name' => '',
                'address' => '',
                'website' => '',
                'phone' => '',
            ];
        }

        return [
            'name' => trim((string) ($parish['name'] ?? '')),
            'address' => $this->joinAddress(
                (string) ($parish['address'] ?? ''),
                (string) ($parish['suburb'] ?? '')
            ),
            'website' => trim((string) ($parish['website'] ?? '')),
            'phone' => trim((string) ($parish['phone'] ?? '')),
        ];
    }

    /**
     * @return array{name: string, address: string, suburb: string}
     */
    private function venueData(?int $venueId): array
    {
        if ($venueId === null) {
            return [
                'name' => '',
                'address' => '',
                'suburb' => '',
                'latitude' => null,
                'longitude' => null,
            ];
        }

        $venue = $this->venues->findVenue($venueId);
        if ($venue === null) {
            return [
                'name' => '',
                'address' => '',
                'suburb' => '',
                'latitude' => null,
                'longitude' => null,
            ];
        }

        return [
            'name' => trim($venue->name),
            'address' => trim($venue->address),
            'suburb' => trim($venue->suburb),
            'latitude' => $venue->latitude,
            'longitude' => $venue->longitude,
        ];
    }

    /**
     * @return array{name: string, email: string, phone: string}
     */
    private function contactData(int $postId): array
    {
        $contact = get_post_meta($postId, 'contact', true);
        if (! is_array($contact)) {
            return [
                'name' => '',
                'email' => '',
                'phone' => '',
            ];
        }

        return [
            'name' => trim(sanitize_text_field((string) ($contact['name'] ?? ''))),
            'email' => trim(sanitize_email((string) ($contact['email'] ?? ''))),
            'phone' => trim(sanitize_text_field((string) ($contact['phone'] ?? ''))),
        ];
    }

    /**
     * @return list<string>
     */
    private function metaList(int $postId, string $metaKey): array
    {
        $value = get_post_meta($postId, $metaKey, true);
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== ''
        ));
    }

    /**
     * @param array{name: string, address: string, website: string, phone: string} $parish
     * @param array{name: string, address: string, suburb: string, latitude: float|null, longitude: float|null} $venue
     */
    private function mapUrlForLocation(array $parish, array $venue, ?int $parishId, ?int $venueId): ?string
    {
        if ($venue['latitude'] !== null && $venue['longitude'] !== null) {
            return EventPresentation::mapUrl($venue['latitude'], $venue['longitude'], $venue['address']);
        }

        if ($venue['address'] !== '') {
            return EventPresentation::mapUrl(null, null, $venue['address']);
        }

        $coordinates = $this->occurrences->locationForEvent($parishId, $venueId);
        $address = $parish['address'];

        return EventPresentation::mapUrl($coordinates['latitude'], $coordinates['longitude'], $address);
    }

    /**
     * @param list<string> $exdates
     * @param list<string> $rdates
     */
    private function googleCalendarUrl(
        string $title,
        string $description,
        string $location,
        DateTimeImmutable $startLocal,
        ?DateTimeImmutable $endLocal,
        bool $allDay,
        string $rrule,
        array $exdates,
        array $rdates
    ): ?string {
        if ($rrule !== '' && ($exdates !== [] || $rdates !== [])) {
            return null;
        }

        return EventPresentation::googleCalendarUrl(
            wp_strip_all_tags($title),
            wp_strip_all_tags($description),
            $location,
            $startLocal,
            $endLocal,
            $allDay,
            $rrule === '' ? null : $rrule
        );
    }

    /**
     * @return list<array{label: string, datetime: string}>
     */
    private function nextDates(int $postId, bool $allDay, bool $recurring): array
    {
        if (! $recurring) {
            return [];
        }

        $rows = $this->occurrences->upcomingForEvent($postId, $this->clock->now(), self::MAX_NEXT_DATES);
        $dates = [];

        foreach ($rows as $row) {
            $start = new DateTimeImmutable((string) $row['start_utc'], new DateTimeZone('UTC'));
            $local = $start->setTimezone($this->timezone);
            $dates[] = [
                'label' => $allDay
                    ? $local->format('l j F Y')
                    : $local->format('l j F Y H:i'),
                'datetime' => $allDay
                    ? $local->format('Y-m-d')
                    : $local->format('Y-m-d\TH:iP'),
            ];
        }

        return $dates;
    }

    /**
     * @param array{name: string, address: string, website: string, phone: string} $parish
     * @param array{name: string, address: string, suburb: string} $venue
     * @param array{url: string, width: int, height: int, alt: string}|null $poster
     * @return array<string, mixed>
     */
    private function jsonLd(
        WP_Post $post,
        DateTimeImmutable $startLocal,
        ?DateTimeImmutable $endLocal,
        bool $allDay,
        string $statusFlag,
        string $description,
        array $parish,
        array $venue,
        ?string $mapUrl,
        ?array $poster
    ): array {
        $start = $allDay
            ? $startLocal->setTimezone($this->timezone)->format('Y-m-d')
            : $startLocal->setTimezone($this->timezone)->format('c');
        $end = $endLocal === null
            ? null
            : ($allDay
                ? $endLocal->modify('+1 day')->setTimezone($this->timezone)->format('Y-m-d')
                : $endLocal->setTimezone($this->timezone)->format('c'));
        $locationName = $venue['name'] !== '' ? $venue['name'] : $parish['name'];
        $address = $venue['address'] !== '' ? $venue['address'] : $parish['address'];
        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => trim((string) $post->post_title),
            'description' => trim(wp_strip_all_tags($description)),
            'url' => get_permalink($post),
            'startDate' => $start,
            'eventStatus' => $this->eventStatus($statusFlag),
            'isAccessibleForFree' => true,
        ];

        if ($end !== null) {
            $json['endDate'] = $end;
        }

        if ($poster !== null) {
            $json['image'] = [$poster['url']];
        }

        if ($locationName !== '' || $address !== '') {
            $location = ['@type' => 'Place'];
            if ($locationName !== '') {
                $location['name'] = $locationName;
            }
            if ($address !== '') {
                $location['address'] = [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $address,
                    'addressCountry' => 'ZA',
                ];
            }
            if ($mapUrl !== null) {
                $location['url'] = $mapUrl;
            }
            $json['location'] = $location;
        }

        if ($parish['name'] !== '') {
            $organizer = [
                '@type' => 'Organization',
                'name' => $parish['name'],
            ];
            if ($parish['website'] !== '') {
                $organizer['url'] = $parish['website'];
            }
            $json['organizer'] = $organizer;
        }

        return $json;
    }

    private function poster(WP_Post $post): ?array
    {
        if (! has_post_thumbnail($post)) {
            return null;
        }

        $thumbnailId = get_post_thumbnail_id($post);
        if (! is_int($thumbnailId) || $thumbnailId < 1) {
            return null;
        }

        $image = wp_get_attachment_image_src($thumbnailId, 'full');
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
     * The source material a publisher promoted onto this event.
     *
     * Read through `SourceMaterialStoreInterface::forEvent()` and nothing else,
     * because that method answers from the ordered post meta. A promoted file is
     * a child of the event in WordPress (that is how `post_parent` records the
     * association), so a broad `get_children()` over the event would also return
     * anything that ended up parented to it for any other reason and publish it
     * without a person having chosen to. There is no "copied but not yet public"
     * state to get wrong: the copy is created by the promotion, so what is not
     * in the meta has no file and no URL.
     *
     * @param array{url: string, width: int, height: int, alt: string}|null $poster
     * @return array{items: list<array{role: string, role_label: string, filename: string, url: string, media_id: int}>, has_poster: bool}
     */
    private function sourceMaterial(WP_Post $post, ?array $poster): array
    {
        if ($this->sourceMaterial === null) {
            return ['items' => [], 'has_poster' => false];
        }

        $items = [];
        $hasPoster = false;

        foreach ($this->sourceMaterial->forEvent((int) $post->ID) as $source) {
            if ($source->role === SourceAttachment::ROLE_POSTER) {
                $hasPoster = true;
            }

            // The poster already has its own figure above the description, driven
            // by has_post_thumbnail(). Listing it a second time under "source
            // material" would show the same image twice on one page.
            $thumbnailId = (int) get_post_thumbnail_id($post);
            if ($source->role === SourceAttachment::ROLE_POSTER && $poster !== null && $thumbnailId === $source->mediaId) {
                continue;
            }

            $url = wp_get_attachment_url($source->mediaId);
            if (! is_string($url) || $url === '') {
                continue;
            }

            $items[] = [
                'role' => $source->role,
                'role_label' => self::ROLE_LABELS[$source->role] ?? 'File',
                // The parish's own filename, carried as text. It is attacker
                // controlled and is escaped on render; the URL above is built
                // from the stored name and cannot contain it.
                'filename' => $source->originalFilename,
                'url' => $url,
                'media_id' => $source->mediaId,
            ];
        }

        return ['items' => $items, 'has_poster' => $hasPoster];
    }

    private function statusBanner(string $statusFlag): string
    {
        return match ($statusFlag) {
            'cancelled' => 'Cancelled',
            'postponed' => 'Postponed',
            default => '',
        };
    }

    private function statusClass(string $statusFlag): string
    {
        return match ($statusFlag) {
            'cancelled' => 'is-cancelled',
            'postponed' => 'is-postponed',
            default => 'is-scheduled',
        };
    }

    private function eventStatus(string $statusFlag): string
    {
        return match ($statusFlag) {
            'cancelled' => 'https://schema.org/EventCancelled',
            'postponed' => 'https://schema.org/EventPostponed',
            default => 'https://schema.org/EventScheduled',
        };
    }

    private function timeLabel(DateTimeImmutable $startLocal, ?DateTimeImmutable $endLocal, bool $allDay): string
    {
        if ($allDay) {
            return 'All day';
        }

        $label = $startLocal->setTimezone($this->timezone)->format('H:i');
        if ($endLocal instanceof DateTimeImmutable) {
            $label .= ' – ' . $endLocal->setTimezone($this->timezone)->format('H:i');
        }

        return $label;
    }

    private function metaText(int $postId, string $metaKey): string
    {
        $value = get_post_meta($postId, $metaKey, true);
        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }

    private function metaBoolean(int $postId, string $metaKey): bool
    {
        $value = get_post_meta($postId, $metaKey, true);
        return in_array($value, [true, 1, '1'], true);
    }

    private function metaId(int $postId, string $metaKey): ?int
    {
        $value = get_post_meta($postId, $metaKey, true);
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            $id = (int) $value;
            return $id > 0 ? $id : null;
        }

        return null;
    }

    private function localDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException('The event start/end metadata is invalid.');
        }

        return $date;
    }

    private function joinAddress(string ...$parts): string
    {
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
        return implode(', ', $parts);
    }
}
