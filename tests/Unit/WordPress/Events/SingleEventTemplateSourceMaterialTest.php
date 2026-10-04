<?php

declare(strict_types=1);

namespace {
    // See PublicEventPageSourceMaterialTest for why this is global and guarded:
    // `PublicEventPage` has `use WP_Post;`, and that test declares one too, so
    // load order decides which declaration wins. The two must stay
    // shape-compatible.
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
namespace {
    /*
     * Probed, not assumed: `tests/Support/WordPressStubs.php` declares none of
     * these four - grep for `function get_header`, `get_footer`, `wp_json_encode`
     * and `get_queried_object` across tests/ returns nothing - so without them
     * the include below dies on its seventh line.
     *
     * Global rather than namespaced, and that is forced rather than chosen:
     * `templates/single-adct_event.php` carries a `use` statement but no
     * `namespace` declaration, so it is a global-namespace file and its
     * unqualified `get_header()` call can only resolve to a global function.
     * Declaring them in this test's namespace was tried first and did not work:
     * a probe confirmed all four existed there and were still not found.
     */
    if (! function_exists('get_header')) {
        function get_header(): void
        {
            echo '<!-- header -->';
        }
    }

    if (! function_exists('get_footer')) {
        function get_footer(): void
        {
            echo '<!-- footer -->';
        }
    }

    if (! function_exists('wp_json_encode')) {
        function wp_json_encode(mixed $value, int $options = 0, int $depth = 512): string|false
        {
            return json_encode($value, $options, $depth);
        }
    }

    if (! function_exists('get_queried_object')) {
        function get_queried_object(): mixed
        {
            return $GLOBALS['adct_test_queried_object'] ?? null;
        }
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Events {

    use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
    use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Tests\Unit\Core\Attachments\InMemorySourceMaterialStore;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\OccurrenceRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
    use ADCT\ParishIntake\WordPress\Events\EventPostType;
    use ADCT\ParishIntake\WordPress\Events\PublicEventPage;
    use ADCT\ParishIntake\WordPress\Plugin;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionProperty;

    require_once dirname(__DIR__, 4) . '/tests/Support/WordPressStubs.php';
    // Not autoloaded: composer.json maps only ADCT\ParishIntake\Core and
    // ADCT\ParishIntake\WordPress, so a second test directory's doubles have to
    // be required by hand the way EventSourceMaterialEditorTest does.
    require_once dirname(__DIR__, 2) . '/Core/Attachments/RecordingSourceMaterialPorts.php';
    // The same ten doubles the sibling test needs; see that file for the probe that
    // established which WordPress functions the shared stub file does not answer.
    require_once __DIR__ . '/EventRenderWordPressDoubles.php';

    /**
     * Issue #172, AC6: the single-event template renders the promoted list.
     *
     * PublicEventPageSourceMaterialTest proves the *view* hands over the right
     * array. This proves the *template* turns that array into markup which
     * neither executes script nor offers a dead link.
     *
     * The template file itself is included, not re-implemented, so a mutation to
     * its escaping is caught by behaviour rather than by a string match on a
     * copy. That is the whole reason this file exists: a
     * `$source['name']` echoed without `esc_html()` is the kind of mistake that
     * only a real render catches.
     *
     * `Plugin::publicEventPage()` reads a private static instance, so the real
     * `PublicEventPage` is injected there by reflection rather than booting the
     * plugin. Booting it would need a database and a mailbox.
     */
    final class SingleEventTemplateSourceMaterialTest extends TestCase
    {
        private const EVENT_ID = 4313;

        private const TEMPLATE = __DIR__ . '/../../../../templates/single-adct_event.php';

        private InMemorySourceMaterialStore $store;

        private ?Plugin $savedInstance;

        private bool $hadInstance;

        private ?\WP_Post $savedQueriedObject;

        private bool $hadQueriedObject;

        protected function setUp(): void
        {
            parent::setUp();

            $this->store = new InMemorySourceMaterialStore();

            $reflection = new ReflectionClass(Plugin::class);
            $property = new ReflectionProperty(Plugin::class, 'instance');
            $property->setAccessible(true);
            $this->savedInstance = $property->getValue();
            $this->hadInstance = $property->getValue() !== null;

            $this->hadQueriedObject = isset($GLOBALS['adct_test_queried_object']);
            $this->savedQueriedObject = $GLOBALS['adct_test_queried_object'] ?? null;

            $post = new \WP_Post();
            $post->ID = self::EVENT_ID;
            $post->post_type = EventPostType::POST_TYPE;
            $post->post_status = 'publish';
            $post->post_title = 'Parish retreat';
            $post->post_content = 'A day of reflection.';
            $GLOBALS['adct_test_queried_object'] = $post;

            // The renderer refuses an unparseable start/end rather than guessing,
            // so the fixture needs a real one.
            $GLOBALS['adct_test_media_meta'][self::EVENT_ID]['start_local'] = '2026-10-12T09:00';
            $GLOBALS['adct_test_media_meta'][self::EVENT_ID]['end_local'] = '2026-10-12T16:00';

            $GLOBALS['adct_test_media_thumbnails'] = [];
            $GLOBALS['adct_test_attachment_urls'] = [];

            $stub = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
            $reflectionPage = new ReflectionProperty(Plugin::class, 'publicEventPage');
            $reflectionPage->setAccessible(true);
            $reflectionPage->setValue($stub, $this->page());
            $property->setValue(null, $stub);
        }

        protected function tearDown(): void
        {
            $property = new ReflectionProperty(Plugin::class, 'instance');
            $property->setAccessible(true);
            $property->setValue(null, $this->hadInstance ? $this->savedInstance : null);

            if ($this->hadQueriedObject) {
                $GLOBALS['adct_test_queried_object'] = $this->savedQueriedObject;
            } else {
                unset($GLOBALS['adct_test_queried_object']);
            }

            unset($GLOBALS['adct_test_media_meta'], $GLOBALS['adct_test_media_thumbnails'], $GLOBALS['adct_test_attachment_urls']);

            parent::tearDown();
        }

        public function testAnEventWithNothingPromotedRendersNoDocumentsSection(): void
        {
            $html = $this->render();

            self::assertStringNotContainsString('adct-event__source-material', $html);
        }

        public function testAPromotedBulletinIsRenderedWithItsNameAndUrl(): void
        {
            $this->promote(7101, SourceMaterialRole::BULLETIN, 'Parish bulletin.pdf', 'bulletin.pdf');

            $html = $this->render();

            self::assertStringContainsString('adct-event__source-material', $html);
            self::assertStringContainsString('Parish bulletin.pdf', $html);
            self::assertStringContainsString('bulletin.pdf', $html);
        }

        public function testTheRoleIsLabelledSoAPosterLinkIsNotMistakenForTheFeaturedFigure(): void
        {
            $this->promote(7102, SourceMaterialRole::POSTER, 'Retreat poster.jpg', 'poster.jpg');

            $html = $this->render();

            self::assertStringContainsString('adct-event__source-material-role', $html);
            // No featured image was set, so there must be no poster figure: a
            // promoted poster is a link, not the header image.
            self::assertStringNotContainsString('adct-event__poster', $html);
        }

        public function testAHostileParishFilenameIsEscapedRatherThanRendered(): void
        {
            $this->promote(7103, SourceMaterialRole::DOCUMENT, '<script>alert(1)</script>.pdf', 'evil.pdf');

            $html = $this->render();

            self::assertStringNotContainsString('<script>alert(1)</script>', $html);
            self::assertStringContainsString('&lt;script&gt;', $html);
        }

        public function testAnEmptyFilenameFallsBackToTheRoleLabelRatherThanRenderingABlankLink(): void
        {
            $this->promote(7104, SourceMaterialRole::BULLETIN, '', 'unnamed.pdf');

            $html = $this->render();

            self::assertStringContainsString('>Parish bulletin<', $html);
        }

        public function testTheLinkOpensInANewTabWithoutHandingOverTheOpener(): void
        {
            $this->promote(7105, SourceMaterialRole::DOCUMENT, 'Timetable.pdf', 'timetable.pdf');

            $html = $this->render();

            self::assertStringContainsString('rel="noopener noreferrer"', $html);
        }

        /**
         * Mutation probe: deleting `rel="noopener noreferrer"` from the source
         * link survives the assertion in the test above, because that string
         * also appears on the Google Calendar and parish-website links. This one
         * reads the href out of the section itself, so it cannot be satisfied by
         * some other anchor on the page.
         */
        public function testTheSourceLinkItselfCarriesTheOpenerHardening(): void
        {
            $this->promote(7105, SourceMaterialRole::DOCUMENT, 'Timetable.pdf', 'timetable.pdf');

            $html = $this->render();

            self::assertStringContainsString(
                '<a href="https://adct.example.test/wp-content/uploads/timetable.pdf" target="_blank" rel="noopener noreferrer">',
                $html
            );
        }

        /**
         * The attachment URL reaches the template through `wp_get_attachment_url`,
         * which answers from the uploads directory and can carry a quote if a
         * filename was mangled upstream. The stub `esc_url()` percent-encodes
         * `"` (see tests/Support/WordPressStubs.php), so this assertion is
         * genuinely load-bearing: it was added after the mutation probe showed
         * that dropping `esc_url` entirely left every other test green.
         *
         * It does not prove scheme filtering. The stub is a `str_replace` with no
         * scheme allow-list, so no test here can assert that a `javascript:` URL
         * is refused - that would be vacuous. See the note in the PR body.
         *
         * One more mutation survives in this file and is deliberately left so:
         * removing `esc_html()` from the role label. `label` comes from
         * `SourceMaterialRole::label()`, a `match` over three string literals that
         * throws on anything else, so no parish input can reach it. The escaping is
         * defence in depth on a constant, not a security boundary, and a test for it
         * could only be made to pass by feeding the template a value the view can
         * never produce.
         */
        public function testAQuoteInAnAttachmentUrlCannotBreakOutOfTheHref(): void
        {
            $this->promote(7107, SourceMaterialRole::DOCUMENT, 'Odd name.pdf', 'odd.pdf');
            $GLOBALS['adct_test_attachment_urls'][7107] = 'https://adct.example.test/wp-content/uploads/od"d.pdf';

            $html = $this->render();

            self::assertStringContainsString('uploads/od%22d.pdf', $html);
            self::assertStringNotContainsString('uploads/od"d.pdf', $html);
        }

        public function testTheSectionSitsInsideTheArticleSoItIsPartOfTheEventNotThePageChrome(): void
        {
            $this->promote(7106, SourceMaterialRole::BULLETIN, 'Bulletin.pdf', 'bulletin.pdf');

            $html = $this->render();

            $articleAt = strpos($html, '<article');
            $sectionAt = strpos($html, 'adct-event__source-material');
            self::assertNotFalse($articleAt);
            self::assertNotFalse($sectionAt);
            self::assertGreaterThan($articleAt, $sectionAt);
        }

        private function promote(int $attachmentId, string $role, string $name, string $slug): void
        {
            $this->store->stored[self::EVENT_ID] = [
                new SourceMaterialReference($attachmentId, $role, $name),
            ];
            $GLOBALS['adct_test_attachment_urls'][$attachmentId] = 'https://adct.example.test/wp-content/uploads/' . $slug;
        }

        private function render(): string
        {
            ob_start();
            try {
                include self::TEMPLATE;
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        }

        private function page(): PublicEventPage
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
                $this->store
            );
        }
    }
}
