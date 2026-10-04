<?php

declare(strict_types=1);

namespace {
    /**
     * Declared here, in the global namespace, because that is where
     * `PublicEventPage`'s `use WP_Post;` resolves it — a namespaced copy would
     * be a different class and the type hint would reject it. RevertChangeHandlerTest
     * declares one too, hence the guard: load order decides which wins, so
     * both must be shape-compatible.
     *
     * Only the properties the renderer reads are declared. Unknown ones stay
     * undefined instead of answering null, so a new read has to be noticed here
     * rather than quietly returning null in every test.
     */
    if (! class_exists('WP_Post', false)) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_title = '';
            public string $post_content = '';
            public string $post_excerpt = '';
            public string $post_status = 'publish';
            public string $post_type = 'adct_event';
        }
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Events {
    require_once dirname(__DIR__, 4) . '/tests/Support/WordPressStubs.php';
    // Not autoloaded: composer.json maps only ADCT\ParishIntake\Core and
    // ADCT\ParishIntake\WordPress, so a second test directory's doubles have to
    // be required by hand the way EventSourceMaterialEditorTest does.
    require_once dirname(__DIR__, 2) . '/Core/Attachments/RecordingSourceMaterialPorts.php';

    use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
    use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Tests\Unit\Core\Attachments\InMemorySourceMaterialStore;
    use ADCT\ParishIntake\WordPress\Attachments\WordPressSourceMaterialStore;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\OccurrenceRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
    use ADCT\ParishIntake\WordPress\Events\EventPostType;
    use ADCT\ParishIntake\WordPress\Events\PublicEventPage;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;

    /**
     * Issue #172, AC4 and the single-event half of AC6: the event page shows
     * the event's promoted source material, and shows nothing else.
     *
     * AC4 is the privacy guarantee, and its whole value is that it is a
     * *negative* — an attachment nobody promoted must be unreachable from a
     * public page. So this file spends most of its assertions on refusals:
     *
     * - The page asks the promotion store and nothing else. An attachment in the
     *   media library that the store does not name produces no markup, even
     *   though the very same file is one `wp_get_attachment_url()` call away.
     * - An attachment whose URL cannot be resolved is dropped rather than
     *   rendered as an empty or `#` link.
     * - The parish's original filename is metadata: it is shown to a visitor
     *   (so the download is recognisable) but only after escaping, and it never
     *   reaches a URL.
     * - The stored order is the rendered order, because the reviewer chose that
     *   order when they promoted.
     * - The featured image is untouched by this path. Promoting a poster calls
     *   `set_post_thumbnail()` elsewhere; the page merely reads the result. So
     *   the poster figure stays driven by `has_post_thumbnail()`, and a
     *   reference with role `poster` that was *not* set as the featured image
     *   must not be silently promoted into the figure here.
     *
     * Every stub is declared in the namespace `PublicEventPage` calls from,
     * which is where PHP looks first, so no shared stub file has to grow.
     */
    final class PublicEventPageSourceMaterialTest extends TestCase
    {
        private const EVENT_ID = 4312;

        private InMemorySourceMaterialStore $store;

        private array $savedMediaMeta;

        protected function setUp(): void
        {
            parent::setUp();

            $this->savedMediaMeta = $GLOBALS['adct_test_media_meta'] ?? [];

            $GLOBALS['adct_test_media_meta'] = [];
            $GLOBALS['adct_test_media_thumbnails'] = [];
            $GLOBALS['adct_test_attachment_urls'] = [];

            // The renderer refuses an unparseable start/end rather than
            // guessing, so the fixture needs a real one. These tests are about
            // the source-material key, so nothing else in the view is asserted.
            $GLOBALS['adct_test_media_meta'][self::EVENT_ID]['start_local'] = '2026-10-12T09:00';

            $this->store = new InMemorySourceMaterialStore();
        }

        protected function tearDown(): void
        {
            $GLOBALS['adct_test_media_meta'] = $this->savedMediaMeta;
            unset($GLOBALS['adct_test_media_thumbnails'], $GLOBALS['adct_test_attachment_urls']);

            parent::tearDown();
        }

        public function testAnEventWithNothingPromotedExposesNoSourceMaterial(): void
        {
            self::assertSame([], $this->page()->viewForPost($this->event())['source_material']);
        }

        /**
         * AC4. The attachment exists, in the same media library, and would be
         * fetchable if anything linked it. Nothing does, because the page reads
         * the promotion meta and the promotion meta does not name it.
         */
        public function testAnAttachmentThatWasNeverPromotedIsUnreachable(): void
        {
            $GLOBALS['adct_test_attachment_urls'][7788] = 'https://adct.example.test/wp-content/uploads/sick-list.pdf';

            $view = $this->page()->viewForPost($this->event());

            self::assertSame([], $view['source_material']);
            self::assertStringNotContainsString(
                'sick-list.pdf',
                $this->rendered($view),
                'A file that was never promoted must not be named anywhere on the page.'
            );
        }

        public function testThePageReadsTheStoreRatherThanTheMetaDirectly(): void
        {
                    // The store is the port, and this is the assertion that proves the
                    // page consults it rather than the post meta directly. Emptied
                    // first, so nothing can pass on the fixture's say-so.
                    $reference = new SourceMaterialReference(7788, SourceMaterialRole::BULLETIN, 'Parish bulletin.pdf');
                    $GLOBALS['adct_test_attachment_urls'][7788] = 'https://adct.example.test/wp-content/uploads/ab12.pdf';

                    $page = $this->page();

                    // Meta alone, store empty: nothing. This is the same shape as AC4,
                    // one layer in, and it is the direction that matters — a hand-edited
                    // or stale meta cannot add a file to a public page.
                    $GLOBALS['adct_test_media_meta'][self::EVENT_ID]['source_attachment_ids'] = [$reference->toArray()];

                    self::assertSame([], $page->viewForPost($this->event())['source_material']);

                    // Store alone, meta empty: the file appears. So the store is what is
                    // being read, and the meta above was not simply being ignored.
                    $this->store->stored[self::EVENT_ID] = [$reference];

                    self::assertCount(1, $page->viewForPost($this->event())['source_material']);
                }

        public function testAPromotedBulletinIsListedWithItsRoleLabel(): void
        {
            $this->store->stored[self::EVENT_ID] = [
                new SourceMaterialReference(7788, SourceMaterialRole::BULLETIN, 'Parish bulletin.pdf'),
            ];
            $GLOBALS['adct_test_attachment_urls'][7788] = 'https://adct.example.test/wp-content/uploads/ab12.pdf';

            $items = $this->page()->viewForPost($this->event())['source_material'];

            self::assertCount(1, $items);
            self::assertSame('bulletin', $items[0]['role']);
            self::assertSame('Parish bulletin', $items[0]['label']);
            self::assertSame('Parish bulletin.pdf', $items[0]['name']);
            self::assertSame('https://adct.example.test/wp-content/uploads/ab12.pdf', $items[0]['url']);
        }

        public function testTheStoredOrderIsTheRenderedOrder(): void
        {
            $this->store->stored[self::EVENT_ID] = [
                new SourceMaterialReference(7002, SourceMaterialRole::DOCUMENT, 'Timetable.pdf'),
                new SourceMaterialReference(7001, SourceMaterialRole::BULLETIN, 'Bulletin.pdf'),
                new SourceMaterialReference(7003, SourceMaterialRole::DOCUMENT, 'Permissions.pdf'),
            ];
            foreach ([7001, 7002, 7003] as $id) {
                $GLOBALS['adct_test_attachment_urls'][$id] = 'https://adct.example.test/wp-content/uploads/' . $id . '.pdf';
            }

            $items = $this->page()->viewForPost($this->event())['source_material'];

            self::assertSame(
                [7002, 7001, 7003],
                array_column($items, 'attachment_id'),
                'The reviewer chose this order when they promoted; the page must not re-sort it.'
            );
        }

        /**
         * An id whose URL will not resolve is dropped, not rendered.
         *
         * An empty href would produce a link back to the page itself, which
         * reads as "the bulletin is here" and is a worse outcome than silence.
         */
        public function testAnItemWithNoResolvableUrlIsDropped(): void
        {
            $this->store->stored[self::EVENT_ID] = [
                new SourceMaterialReference(7788, SourceMaterialRole::BULLETIN, 'Gone.pdf'),
            ];

            self::assertSame([], $this->page()->viewForPost($this->event())['source_material']);
        }

        public function testAPlainFalseUrlIsAlsoRefused(): void
        {
            $this->store->stored[self::EVENT_ID] = [
                new SourceMaterialReference(7788, SourceMaterialRole::BULLETIN, 'Gone.pdf'),
            ];
            $GLOBALS['adct_test_attachment_urls'][7788] = false;

            self::assertSame([], $this->page()->viewForPost($this->event())['source_material']);
        }

        /**
         * The parish's own filename is attacker-controlled text that a visitor
         * will see, so it is escaped. It is also metadata only: it must never be
         * pasted into the URL, or a filename of `a.pdf" onload="x` becomes part
         * of the address.
         */
        public function testTheOriginalFilenameIsEscapedAndNeverReachesTheUrl(): void
        {
            $hostile = 'Bulletin "><script>alert(1)</script>.pdf';
            $this->store->stored[self::EVENT_ID] = [
                new SourceMaterialReference(7788, SourceMaterialRole::BULLETIN, $hostile),
            ];
            $GLOBALS['adct_test_attachment_urls'][7788] = 'https://adct.example.test/wp-content/uploads/ab12.pdf';

            $item = $this->page()->viewForPost($this->event())['source_material'][0];

            self::assertSame($hostile, $item['name'], 'The page escapes on render, so the view keeps the raw name.');
            self::assertSame('https://adct.example.test/wp-content/uploads/ab12.pdf', $item['url']);
            self::assertStringNotContainsString($hostile, $item['url']);
        }

        /**
         * A reference with role `poster` that is not the featured image must not
         * be promoted into the poster figure by this code path.
         *
         * AC5 makes `set_post_thumbnail()` the thing that fills the figure. If
         * the page also rendered every `poster`-role reference as a figure, the
         * two would disagree the moment somebody removed the featured image
         * without removing the reference, and the figure would come back.
         */
        public function testAPosterRoleReferenceDoesNotBecomeThePosterFigure(): void
        {
            $this->store->stored[self::EVENT_ID] = [
                new SourceMaterialReference(7001, SourceMaterialRole::POSTER, 'Poster.jpg'),
            ];
            $GLOBALS['adct_test_attachment_urls'][7001] = 'https://adct.example.test/wp-content/uploads/poster.jpg';

            $view = $this->page()->viewForPost($this->event());

            self::assertNull($view['poster'], 'No featured image was set, so there is no poster figure.');
            self::assertCount(1, $view['source_material'], 'It is still listed as source material.');
        }

        public function testEveryPromotedRoleCarriesItsLabel(): void
        {
            $this->store->stored[self::EVENT_ID] = [
                new SourceMaterialReference(7001, SourceMaterialRole::POSTER, 'Poster.jpg'),
                new SourceMaterialReference(7002, SourceMaterialRole::BULLETIN, 'Bulletin.pdf'),
                new SourceMaterialReference(7003, SourceMaterialRole::DOCUMENT, 'Timetable.pdf'),
            ];
            foreach ([7001, 7002, 7003] as $id) {
                $GLOBALS['adct_test_attachment_urls'][$id] = 'https://adct.example.test/wp-content/uploads/' . $id;
            }

            self::assertSame(
                ['Poster', 'Parish bulletin', 'Document'],
                array_column($this->page()->viewForPost($this->event())['source_material'], 'label')
            );
        }

        /**
         * The real store is what production wires in, and it drops corrupt
         * entries. Proving the page survives one is the difference between
         * "the page handles what the store gives it" and "the page handles what
         * the tests give it".
         */
        public function testTheRealStoreShieldsThePageFromCorruptMeta(): void
        {
            $GLOBALS['adct_test_media_meta'][self::EVENT_ID]['source_attachment_ids'] = [
                ['attachment_id' => 7001, 'role' => 'poster', 'name' => 'Poster.jpg'],
                ['attachment_id' => 0, 'role' => 'bulletin', 'name' => 'Never.pdf'],
                ['attachment_id' => 7002, 'role' => 'nonsense', 'name' => 'Bad role.pdf'],
                'not an entry',
                ['attachment_id' => 7002, 'role' => 'bulletin', 'name' => 'Bulletin.pdf'],
            ];
            $GLOBALS['adct_test_attachment_urls'][7001] = 'https://adct.example.test/wp-content/uploads/a.jpg';
            $GLOBALS['adct_test_attachment_urls'][7002] = 'https://adct.example.test/wp-content/uploads/b.pdf';

            $page = $this->page(new WordPressSourceMaterialStore());
            $items = $page->viewForPost($this->event())['source_material'];

            self::assertSame([7001, 7002], array_column($items, 'attachment_id'));
        }

        private function event(): \WP_Post
        {
            $post = new \WP_Post();
            $post->ID = self::EVENT_ID;
            $post->post_type = EventPostType::POST_TYPE;
            $post->post_status = 'publish';
            $post->post_title = 'Parish retreat';
            $post->post_content = 'A day of reflection.';
            $post->post_excerpt = '';

            return $post;
        }

        /**
         * @return array<string, mixed>
         */
        private function view(): array
        {
            return $this->page()->viewForPost($this->event());
        }

        private function rendered(array $view): string
        {
            // The template is a separate file; this is the same shape it reads,
            // so the assertion is about the view the template is handed rather
            // than about a re-implementation of the template.
            $html = '';

            foreach ($view['source_material'] as $item) {
                $html .= '<li><a href="' . esc_url($item['url']) . '">' . esc_html($item['name']) . '</a></li>';
            }

            return $html;
        }

        private function page(?object $store = null): PublicEventPage
        {
            $clock = new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-10-01 09:00:00', new DateTimeZone('Africa/Johannesburg'));
                }
            };

            $database = new class implements DatabaseConnectionInterface {
                public function prefix(): string
                {
                    return 'wp_';
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

                public function escapeLike(string $text): string
                {
                    return $text;
                }

                public function insertId(): int
                {
                    return 0;
                }

                public function charsetCollate(): string
                {
                    return '';
                }

                public function clearLastError(): void
                {
                }

                public function lastError(): string
                {
                    return '';
                }
            };

            return new PublicEventPage(
                $clock,
                new DateTimeZone('Africa/Johannesburg'),
                new ParishRepository($database),
                new VenueRepository($database),
                new OccurrenceRepository($database),
                __DIR__ . '/../../../../adct-parish-intake.php',
                $store ?? $this->store
            );
        }
    }
}

namespace ADCT\ParishIntake\WordPress\Events {
    /*
     * Shared rather than declared here. These ten are the WordPress functions
     * the renderer calls that `tests/Support/WordPressStubs.php` does not
     * answer; SingleEventTemplateSourceMaterialTest needs the same ten, and the
     * two files load in no guaranteed order, so one file declares them and both
     * require it. Each is guarded with function_exists(), so whichever is
     * loaded first wins.
     */
    require_once __DIR__ . '/EventRenderWordPressDoubles.php';
}
