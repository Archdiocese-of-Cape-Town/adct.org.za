<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Events;

require_once __DIR__ . '/../../../Support/WordPressMediaStubs.php';
require_once __DIR__ . '/../../../Support/WordPressPublicEventStubs.php';

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;
use ADCT\ParishIntake\Core\Publishing\SourceAttachment;
use ADCT\ParishIntake\WordPress\Database\Repository\OccurrenceRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
use ADCT\ParishIntake\WordPress\Events\PublicEventPage;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * The public event page's half of #172.
 *
 * Three properties, and the third is the one a plausible shortcut breaks:
 *
 *  1. Promoted material is visible: the page offers the URL a browser can open,
 *     the parish's own filename as display text, and a role a human reads.
 *  2. Unpromoted material is not merely unlisted, it is *unreachable*. A
 *     promoted file is copied into the media library; an unpromoted one has no
 *     copy and so has no URL. The page must learn what to show from the ordered
 *     post meta and never from a query over the event's children, because a
 *     broad query would find a file WordPress happened to parent to the event
 *     and publish it without anybody having decided to.
 *  3. A page with nothing promoted says nothing at all about it. There is no
 *     "no attachments" heading, no count, and no hint that an intake mailbox
 *     ever held a poster -- an empty state that renders `0 files` has already
 *     leaked the existence of the unpromoted material.
 */
final class PublicEventSourceMaterialTest extends TestCase
{
    private const EVENT = 640;

    /**
     * @var list<string>
     */
    private array $globals = [
        'adct_test_sideloads',
        'adct_test_media_posts',
        'adct_test_children_queries',
        'adct_publishing_meta',
        'adct_publishing_featured',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->globals as $global) {
            unset($GLOBALS[$global]);
        }

        $GLOBALS['adct_publishing_meta'] = [];
        $GLOBALS['adct_publishing_featured'] = [];
                // A real event has times; without them the page refuses to build a view,
                // and a test about source material should not also be about that.
                $GLOBALS['adct_publishing_meta'][self::EVENT]['start_local'] = '2026-10-12T09:00';
                $GLOBALS['adct_publishing_meta'][self::EVENT]['end_local'] = '2026-10-12T16:00';
            }

    protected function tearDown(): void
    {
        foreach ($this->globals as $global) {
            unset($GLOBALS[$global]);
        }

        parent::tearDown();
    }

    public function testAnEventWithNothingPromotedHasNoSourceMaterial(): void
    {
        $view = $this->page()->viewForPost($this->eventPost());

        self::assertSame([], $view['source_material']);
    }

    public function testPromotedMaterialIsOfferedWithItsUrlItsParishFilenameAndItsRole(): void
    {
        $store = $this->storeWith([
            new SourceAttachment(31, 901, SourceAttachment::ROLE_BULLETIN, 'parish bulletin october.pdf'),
        ]);

        $view = $this->page($store)->viewForPost($this->eventPost());

        self::assertCount(1, $view['source_material']);
        self::assertSame(SourceAttachment::ROLE_BULLETIN, $view['source_material'][0]['role']);
        self::assertSame('parish bulletin october.pdf', $view['source_material'][0]['filename']);
        self::assertNotSame('', $view['source_material'][0]['url']);
        self::assertSame('Bulletin', $view['source_material'][0]['role_label']);
    }

    public function testTheOrderAPublisherArrangedIsTheOrderThePageOffers(): void
    {
        $store = $this->storeWith([
            new SourceAttachment(31, 901, SourceAttachment::ROLE_BULLETIN, 'october bulletin.pdf'),
            new SourceAttachment(32, 902, SourceAttachment::ROLE_DOCUMENT, 'consent form.pdf'),
        ]);

        $view = $this->page($store)->viewForPost($this->eventPost());

        self::assertSame(
            [SourceAttachment::ROLE_BULLETIN, SourceAttachment::ROLE_DOCUMENT],
            array_column($view['source_material'], 'role')
        );
    }

    public function testAPosterIsOfferedExactlyOnceAndIsNotRepeatedInTheList(): void
    {
        $store = $this->storeWith([
            new SourceAttachment(32, 902, SourceAttachment::ROLE_POSTER, 'poster.png'),
        ]);

        $view = $this->page($store)->viewForPost($this->eventPost());

        // The poster already has its own figure above the description, driven by
        // has_post_thumbnail(). Listing it again under "source material" would
        // show the same picture twice on one page.
        self::assertCount(1, $view['source_material']);
            self::assertSame('poster.png', $view['source_material'][0]['filename']);
        }

        public function testAPromotedPosterIsAnnouncedEvenWhenItIsShownAsTheFeaturedImage(): void
        {
            // `source_material_has_poster` is what the listing card reads to decide
            // whether to embed a poster, and the card has no poster figure of its own.
            // It must be true whenever a poster role was promoted, whether or not the
            // item also appears in this page's list.
            $GLOBALS['adct_publishing_featured'][self::EVENT] = 902;

            $store = $this->storeWith([
                new SourceAttachment(32, 902, SourceAttachment::ROLE_POSTER, 'poster.png'),
                new SourceAttachment(31, 901, SourceAttachment::ROLE_BULLETIN, 'bulletin.pdf'),
            ]);

            $view = $this->page($store)->viewForPost($this->eventPost());

            self::assertTrue($view['source_material_has_poster']);
            self::assertSame(['bulletin.pdf'], array_column($view['source_material'], 'filename'));
        }

        public function testAnEventWithoutAPromotedPosterAnnouncesNone(): void
        {
            $store = $this->storeWith([
                new SourceAttachment(31, 901, SourceAttachment::ROLE_BULLETIN, 'bulletin.pdf'),
            ]);

            $view = $this->page($store)->viewForPost($this->eventPost());

            self::assertFalse($view['source_material_has_poster']);
        }

    public function testAPosterAlreadyShownAsTheFeaturedImageIsNotListedAgain(): void
    {
        $GLOBALS['adct_publishing_featured'][self::EVENT] = 902;

        $store = $this->storeWith([
            new SourceAttachment(32, 902, SourceAttachment::ROLE_POSTER, 'poster.png'),
        ]);

        $view = $this->page($store)->viewForPost($this->eventPost());

        self::assertSame([], $view['source_material']);
        self::assertTrue($view['source_material_has_poster']);
    }

    public function testAnUnpromotedFileParentedToTheEventIsNeverOffered(): void
    {
        // WordPress records the parent on the copy, so a promoted file is a
        // child of the event too. This test poses the trap directly: a child
        // exists, nothing is promoted, and the page must still offer nothing.
        $GLOBALS['adct_test_media_posts'][903] = [
            'post_parent' => self::EVENT,
            'file' => '2026/10/never-promoted.jpg',
        ];
        $GLOBALS['adct_test_children_queries'] = [];

        $view = $this->page($this->storeWith([]))->viewForPost($this->eventPost());

        self::assertSame([], $view['source_material']);
    }

    public function testThePageNeverQueriesTheEventsAttachmentsDirectly(): void
    {
        $GLOBALS['adct_test_children_queries'] = [];

        $store = $this->storeWith([
            new SourceAttachment(31, 901, SourceAttachment::ROLE_BULLETIN, 'bulletin.pdf'),
        ]);
        $this->page($store)->viewForPost($this->eventPost());

        self::assertSame(
            [],
            $GLOBALS['adct_test_children_queries'],
            'The front end read the event\'s attachments by broad query instead of the ordered post meta.'
        );
    }

    public function testTheParishFilenameIsCarriedAsTextAndNeverBecomesPartOfTheUrl(): void
    {
        $store = $this->storeWith([
            new SourceAttachment(31, 901, SourceAttachment::ROLE_BULLETIN, '../../wp-config.php'),
        ]);

        $view = $this->page($store)->viewForPost($this->eventPost());
        $entry = $view['source_material'][0];

        // The name is the parish's own text, escaped on render. The URL is the
        // stored name WordPress generated, and cannot contain a path traversal
        // or a second filename.
        self::assertSame('../../wp-config.php', $entry['filename']);
        self::assertStringNotContainsString('..', $entry['url']);
        self::assertStringNotContainsString('wp-config', $entry['url']);
    }

    public function testTheTemplateRendersNothingAtAllWhenNothingIsPromoted(): void
    {
        $rendered = $this->renderTemplate($this->page($this->storeWith([]))->viewForPost($this->eventPost()));

        self::assertStringNotContainsString('adct-event__source', $rendered);
        self::assertStringNotContainsString('Source material', $rendered);
    }

    public function testTheTemplateRendersTheOfferedFilesWithEveryFieldEscaped(): void
    {
        $store = $this->storeWith([
            new SourceAttachment(31, 901, SourceAttachment::ROLE_BULLETIN, '<script>alert(1)</script>.pdf'),
        ]);

        $rendered = $this->renderTemplate(
            $this->page($store)->viewForPost($this->eventPost())
        );

        self::assertStringContainsString('adct-event__source', $rendered);
        self::assertStringContainsString('href="', $rendered);
        self::assertStringNotContainsString('<script>', $rendered);
        self::assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function testAPageWiredWithoutAStoreStillRendersRatherThanFailing(): void
    {
        // The plugin always passes one, but a theme that instantiates the page
        // itself must get an event page, not a fatal. An absent store means no
        // promoted material is *shown*; it never means "show everything".
        $view = $this->page()->viewForPost($this->eventPost());

        self::assertSame([], $view['source_material']);
    }

    private function page(?SourceMaterialStoreInterface $store = null): PublicEventPage
    {
            // The real repositories over a database that answers nothing. This event
            // has no parish and no rule, so every query returns null and the page is
            // exercised without a fixture row that might make it pass for the wrong
            // reason.
            $database = new SourceMaterialNullDatabase();

            return new PublicEventPage(
                new SourceMaterialFixedClock(),
                new DateTimeZone('Africa/Johannesburg'),
                new ParishRepository($database),
                new VenueRepository($database),
                new OccurrenceRepository($database),
                __FILE__,
                $store
            );
        }

    /**
     * @param list<SourceAttachment> $sources
     */
    private function storeWith(array $sources): SourceMaterialStoreInterface
    {
        return new class ($sources) implements SourceMaterialStoreInterface {
            /**
             * @param list<SourceAttachment> $sources
             */
            public function __construct(private readonly array $sources)
            {
            }

            public function promote(int $eventId, int $attachmentId, string $role): SourceAttachment
            {
                throw new \LogicException('The public page must never promote.');
            }

            public function remove(int $eventId, int $mediaId): void
            {
                throw new \LogicException('The public page must never remove.');
            }

            public function forEvent(int $eventId): array
            {
                return $this->sources;
            }
        };
    }

    private function eventPost(): WP_Post
    {
        $post = new WP_Post();
        $post->ID = self::EVENT;
        $post->post_type = 'adct_event';
        $post->post_status = 'publish';
        $post->post_title = 'Parish retreat';
        $post->post_content = 'A quiet day away.';
        $post->post_excerpt = '';

        return $post;
    }

    /**
     * Render just the source-material section of the real template, with the
     * globals it needs already in place, so the assertions are about the file
     * the theme will use rather than about a copy of it.
     */
    private function renderTemplate(array $view): string
    {
        $template = dirname(__DIR__, 4) . '/templates/single-adct_event.php';
        self::assertFileExists($template);

        $source = (string) file_get_contents($template);
        $start = strpos($source, "<?php if (\$event['source_material'] !== []) : ?>");
        $end = strpos($source, '<section class="adct-event__actions"');
        self::assertIsInt($start);
        self::assertIsInt($end);

        $fragment = substr($source, (int) $start, (int) $end - (int) $start);

        $render = static function () use ($fragment, $view): string {
                    // The template reads `$event`, `$parish`, `$venue` and so on, which is
                    // what the shipped `single-adct_event.php` does once the plugin has
                    // extracted them from the view. Binding only `$event` is enough: the
                    // section under test touches no other variable.
                    $event = $view;
                    ob_start();
                    eval('?>' . $fragment);

                    return (string) ob_get_clean();
                };

        return $render();
    }
}

    /**
     * A clock that never moves, so a rendered date cannot change under a test.
     */
    final class SourceMaterialFixedClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-01T08:00:00+02:00');
        }
    }

    /**
     * A database that answers nothing, so an event with no parish, venue or rule
     * renders without a fixture.
     */
    final class SourceMaterialNullDatabase implements \ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface
    {
        public function prefix(): string
        {
            return 'wp_adct_pi_';
        }

        public function prepare(string $query, mixed ...$arguments): string
        {
            return $query;
        }

        public function query(string $query): int|false
        {
            return 0;
        }

        public function getRow(string $query): ?array
        {
            return null;
        }

        public function getResults(string $query): array
        {
            return [];
        }

        public function getVar(string $query): mixed
        {
            return null;
        }

        public function escapeLike(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function insertId(): int
        {
            return 0;
        }

        public function charsetCollate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function clearLastError(): void
        {
        }

        public function lastError(): string
        {
            return '';
        }
    }
