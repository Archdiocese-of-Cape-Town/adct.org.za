<?php

declare(strict_types=1);

namespace {
    /**
     * In the global namespace because that is where `PublicEventListing`'s
     * `$post instanceof \WP_Post` and `array<string, \WP_Term>` resolve them.
     * Guarded, for the reason given in PublicEventPageSourceMaterialTest: the
     * two files load in no guaranteed order.
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
    require_once dirname(__DIR__, 2) . '/Core/Attachments/RecordingSourceMaterialPorts.php';

    use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
    use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
    use ADCT\ParishIntake\Core\Events\ListingSelection;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;
    use ADCT\ParishIntake\Tests\Unit\Core\Attachments\InMemorySourceMaterialStore;
    use ADCT\ParishIntake\WordPress\Events\PublicEventListing;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /**
     * Issue #172, AC6, the listing-card half: "the listing card embeds the
     * poster or links the source".
     *
     * The whole point of that clause is that a visitor is not asked to take the
     * event's word for its own details, so the card's half of the guarantee is
     * that it shows material the reviewer *promoted* and nothing else. The
     * dangerous version of this feature is a `get_children()` over the event's
     * media, which would make every attachment that happens to be parented to
     * the event world-readable the moment the media library was browsed. So
     * these are mostly refusals again:
     *
     * - The card asks the promotion store. An attachment the store does not
     *   name produces no link, even though it is one `wp_get_attachment_url()`
     *   call away and its URL resolves perfectly.
     * - A missing store renders nothing rather than falling back to a broad
     *   query. "Not wired" must not mean "show everything".
     * - The poster comes from `has_post_thumbnail()`, not from a `poster` role
     *   lookup, so releasing the featured-image role empties the card. A
     *   promoted poster that was never the featured image must not reappear
     *   through the link fallback.
     * - A reference whose URL will not resolve is skipped rather than rendered
     *   with an empty href, which would read as "the bulletin is here".
     *
     * `cardSourceMaterial()` is private and reached by reflection, which the
     * repo already does in several unit tests. Going through
     * `renderResults()` instead would mean standing up a `wpdb` double, terms,
     * options and transients to reach a method that takes its rows as a
     * parameter: the card loop is a pure function of the row and the post, and
     * asserting it directly is the smaller honest test. What that does *not*
     * cover is the call site — that `renderResults()` actually calls this
     * method per card — which is why the last test in this file checks the
     * wiring by reading the source rather than pretending a render proved it.
     */
    final class PublicEventListingSourceMaterialTest extends TestCase
    {
        private const EVENT_ID = 4312;

        private InMemorySourceMaterialStore $store;

        private array $saved;

        protected function setUp(): void
        {
            parent::setUp();

            $this->saved = [
                $GLOBALS['adct_test_attachment_urls'] ?? null,
                $GLOBALS['adct_test_media_thumbnails'] ?? null,
                $GLOBALS['adct_test_media_meta'] ?? null,
                $GLOBALS['adct_test_has_thumbnail'] ?? null,
            ];

            $GLOBALS['adct_test_attachment_urls'] = [];
            $GLOBALS['adct_test_media_thumbnails'] = [];
            $GLOBALS['adct_test_media_meta'] = [];
            $GLOBALS['adct_test_has_thumbnail'] = [];

            $this->store = new InMemorySourceMaterialStore();
        }

        protected function tearDown(): void
        {
            [$urls, $thumbnails, $meta, $hasThumbnail] = $this->saved;

            $GLOBALS['adct_test_attachment_urls'] = $urls;
            $GLOBALS['adct_test_media_thumbnails'] = $thumbnails;
            $GLOBALS['adct_test_media_meta'] = $meta;
            $GLOBALS['adct_test_has_thumbnail'] = $hasThumbnail;

            parent::tearDown();
        }

        public function testACardForAnEventWithNothingPromotedShowsNoSourceMaterial(): void
        {
            $GLOBALS['adct_test_attachment_urls'][9001] = 'https://adct.example.test/uploads/2026/10/bulletin.pdf';

            self::assertSame('', $this->card());
        }

        public function testTheCardLinksThePromotedBulletinByItsRole(): void
        {
            $this->promote(9001, SourceMaterialRole::BULLETIN, 'st-marys-bulletin.pdf');
            $GLOBALS['adct_test_attachment_urls'][9001] = 'https://adct.example.test/uploads/2026/10/bulletin.pdf';

            $html = $this->card();

            self::assertStringContainsString(
                '<a href="https://adct.example.test/uploads/2026/10/bulletin.pdf"',
                $html
            );
            self::assertStringContainsString('Parish bulletin', $html);
        }

        public function testAnAttachmentTheStoreDoesNotNameIsNotLinkedEvenThoughItResolves(): void
        {
            // The AC4 refusal, on the card: the file exists, the URL resolves,
            // and nothing promotes it. Only the store's answer may widen this.
            $GLOBALS['adct_test_attachment_urls'][9002] = 'https://adct.example.test/uploads/2026/10/never.pdf';
            $GLOBALS['adct_test_media_meta'][self::EVENT_ID]['source_attachment_ids'] = [];

            self::assertSame('', $this->card());
        }

        public function testTheCardShowsTheFeaturedPosterRatherThanALink(): void
        {
            $this->promote(9003, SourceMaterialRole::POSTER, 'poster.jpg');
            $GLOBALS['adct_test_attachment_urls'][9003] = 'https://adct.example.test/uploads/2026/10/poster.jpg';
            $GLOBALS['adct_test_media_thumbnails'][self::EVENT_ID] = 9003;

            $html = $this->card();

            self::assertStringContainsString('<img src="https://adct.example.test/uploads/2026/10/poster.jpg"', $html);
            self::assertStringNotContainsString('adct-events__source', $html);
        }

        public function testAPromotedPosterThatIsNotTheFeaturedImageIsLinkedNotEmbedded(): void
        {
            // Promotion sets the featured image, so this is the state after a
            // removal that released the featured-image role while the promotion
            // meta still names the poster. The card must not re-embed it: the
            // role in the store is not a second, independent source of truth.
            $this->promote(9004, SourceMaterialRole::POSTER, 'poster.jpg');
            $GLOBALS['adct_test_attachment_urls'][9004] = 'https://adct.example.test/uploads/2026/10/poster.jpg';

            $html = $this->card();

            self::assertStringNotContainsString('<img', $html);
            self::assertStringContainsString('adct-events__source', $html);
        }

        public function testAListingBuiltWithoutAStoreRendersNothingRatherThanEverything(): void
        {
            // The "port not wired" case. An earlier version of this test passed
            // `null` to a helper that then substituted the real store, so it was
            // asserting the same thing as the first test in this file and passed
            // against a broad-lookup fallback. It is now built from an explicit
            // `false`, and the probe "missing store falls back to a broad
            // attachment lookup" is killed by it.
            $GLOBALS['adct_test_attachment_urls'][9005] = 'https://adct.example.test/uploads/2026/10/anything.pdf';

            self::assertSame('', $this->cardWithoutStore());
        }

        public function testAPromotedReferenceWhoseUrlCannotBeResolvedIsSkippedNotEmptied(): void
        {
            // No URL registered: `wp_get_attachment_url()` answers false, the
            // way it does when the file is gone. An empty href would tell a
            // visitor the bulletin is here when it is not.
            $this->promote(9006, SourceMaterialRole::BULLETIN, 'gone.pdf');

            self::assertSame('', $this->card());
        }

        public function testASkippedReferenceDoesNotHideTheNextOne(): void
        {
            // The pair with the test above, because "return the first" and
            // "skip to the first that resolves" are different designs and only
            // the second one shows the visitor everything a reviewer promoted.
            $this->store->replaceForEvent(self::EVENT_ID, [
                new SourceMaterialReference(9007, SourceMaterialRole::BULLETIN, 'gone.pdf'),
                new SourceMaterialReference(9008, SourceMaterialRole::DOCUMENT, 'programme.pdf'),
            ]);
            $GLOBALS['adct_test_attachment_urls'][9008] = 'https://adct.example.test/uploads/2026/10/programme.pdf';

            $html = $this->card();

            self::assertStringContainsString(
                '<a href="https://adct.example.test/uploads/2026/10/programme.pdf"',
                $html
            );
            self::assertStringNotContainsString('gone.pdf', $html);
        }

        public function testThePosterUrlAndAltTextAreEscaped(): void
        {
            // Two different `esc_url` doubles exist in this namespace — the
            // percent-encoding one in `WordPressStubs.php` and the
            // `htmlspecialchars` one in `EventSourceMaterialEditorTest.php` —
            // and which one wins depends on PHPUnit's file order. So this
            // asserts the property both share rather than one file's encoding:
            // the raw `"` that would close the attribute is gone. That is also
            // the only property that is actually the security requirement.
            $this->promote(9009, SourceMaterialRole::POSTER, 'poster.jpg');
            $GLOBALS['adct_test_attachment_urls'][9009] = 'https://adct.example.test/a.png"><script>alert(1)</script>';
            $GLOBALS['adct_test_media_meta'][9009] = [
                '_wp_attachment_image_alt' => 'Poster "><script>alert(1)</script>',
            ];
            $GLOBALS['adct_test_media_thumbnails'][self::EVENT_ID] = 9009;

            $html = $this->card();

            // The exact requirement: the payload's quote is not raw. Every
            // quote in the output belongs to a real attribute, so the count is
            // the size of the markup this method writes and nothing more. Drop
            // the escapers and the count rises by one, because the payload's
            // own `"` would survive into the attribute value.
            self::assertSame(12, substr_count($html, '"'), 'quotes: figure class + img x5');
            self::assertStringNotContainsString('"><script', $html);
            self::assertStringContainsString('alt="Poster', $html);
            self::assertStringContainsString('a.png', $html, 'the URL survives as a URL');
        }

        public function testTheSourceLinkUrlIsEscaped(): void
        {
            $this->promote(9010, SourceMaterialRole::BULLETIN, 'bulletin.pdf');
            $GLOBALS['adct_test_attachment_urls'][9010] = 'https://adct.example.test/b.pdf"><script>alert(1)</script>';

            $html = $this->card();

            // 6: the `class` on the <p>, and `href` + `rel` on the <a>.
            self::assertSame(6, substr_count($html, '"'), 'quotes: p class + a href/rel');
            self::assertStringNotContainsString('"><script', $html);
            self::assertStringContainsString('<a href="https://adct.example.test/b.pdf', $html);
            self::assertStringContainsString('Parish bulletin', $html, 'the label is untouched');
        }

        /**
         * Why the role label needs no escaping assertion of its own: it is a
         * closed set, so no attacker-supplied text can reach the `esc_html()`
         * there. Proved rather than assumed — `label()` throws on anything that
         * is not one of the three roles, and a role that is not one of the three
         * cannot be in the store, because `fromInput()` normalises to null.
         */
        public function testTheRoleLabelIsAClosedSetNoSubmittedTextCanReach(): void
        {
            self::assertSame(
                ['poster', 'bulletin', 'document'],
                SourceMaterialRole::values()
            );
            self::assertNull(SourceMaterialRole::fromInput('<script>alert(1)</script>'));

            $this->expectException(\InvalidArgumentException::class);
            SourceMaterialRole::label('<script>alert(1)</script>');
        }

        public function testAThumbnailIdWithoutAFeaturedImageDoesNotBecomeAPoster(): void
        {
            // The state the `has_post_thumbnail()` probe survived on. In
            // WordPress the two can disagree — a filter can veto the first, or
            // an id can outlive the attachment it names — and when they do, the
            // card must obey the *featured image* question, not the id. Asking
            // only `get_post_thumbnail_id()` would embed a poster after the
            // featured-image role was released, which is exactly the state
            // AC5's removal leaves behind.
            $this->promote(9011, SourceMaterialRole::POSTER, 'poster.jpg');
            $GLOBALS['adct_test_attachment_urls'][9011] = 'https://adct.example.test/uploads/2026/10/poster.jpg';
            $GLOBALS['adct_test_media_thumbnails'][self::EVENT_ID] = 9011;
            $GLOBALS['adct_test_has_thumbnail'][self::EVENT_ID] = false;

            $html = $this->card();

            self::assertStringNotContainsString('<img', $html);
        }

        public function testAFeaturedImageWhoseFileIsGoneRendersNoImgAtAll(): void
        {
            // `wp_get_attachment_image_src()` answers false when the attachment
            // row survives but its file does not — a deleted file on the host, or
            // a restored backup that never carried the uploads directory. The
            // featured image still answers true, so this state is reachable and
            // must not produce `<img src="">`: an empty src makes the browser
            // re-request the page itself as an image.
            $GLOBALS['adct_test_media_thumbnails'][self::EVENT_ID] = 9012;
            $GLOBALS['adct_test_has_thumbnail'][self::EVENT_ID] = true;

            $html = $this->card();

            self::assertFalse(
                            \ADCT\ParishIntake\WordPress\Events\wp_get_attachment_image_src(9012, 'medium'),
                'the double really is answering false for this case'
            );
            self::assertStringNotContainsString('<img', $html);
            self::assertStringNotContainsString('src=', $html);
        }

        public function testTheCardIsRenderedForEachEventInTheListingResults(): void
        {
            // The one thing reflection cannot reach: that `renderResults()`
            // calls the card method at all. Reads the source instead of
            // standing up a wpdb double, and says which lines it is checking.
            $source = (string) file_get_contents(
                            dirname(__DIR__, 4) . '/src/WordPress/Events/PublicEventListing.php'
            );

            self::assertStringContainsString("\$html .= \$this->cardSourceMaterial(\$post);", $source);
            self::assertStringContainsString("\$html .= '</li>';", $source);
        }

        /**
         * The card for the fixture event, rendered the way `renderResults()`
         * renders it.
         */
        private function card(): string
        {
            // Reflection rather than setAccessible(), which is a no-op since
            // PHP 8.1 and deprecated in 8.5; the repo already documents this.
            $method = new ReflectionMethod(PublicEventListing::class, 'cardSourceMaterial');

            return (string) $method->invoke($this->listing($this->store), $this->event());
        }

        /**
         * The same card, on a listing whose promotion port was never wired.
         */
        private function cardWithoutStore(): string
        {
            $method = new ReflectionMethod(PublicEventListing::class, 'cardSourceMaterial');

            return (string) $method->invoke($this->listing(null), $this->event());
        }

        private function promote(int $attachmentId, string $role, string $name): void
        {
            self::assertContains($role, SourceMaterialRole::values(), 'the fixture uses a real role');

            $this->store->replaceForEvent(self::EVENT_ID, [
                new SourceMaterialReference($attachmentId, $role, $name),
            ]);
        }

        private function event(): \WP_Post
        {
            $post = new \WP_Post();
            $post->ID = self::EVENT_ID;
            $post->post_title = 'Parish retreat';
            $post->post_type = 'adct_event';
            $post->post_status = 'publish';

            return $post;
        }

        private function listing(?SourceMaterialStoreInterface $store): PublicEventListing
        {
            $clock = new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-10-01 09:00:00', new DateTimeZone('Africa/Johannesburg'));
                }
            };

            return new PublicEventListing(
                $clock,
                new DateTimeZone('Africa/Johannesburg'),
                dirname(__DIR__, 4) . '/adct-parish-intake.php',
                null,
                null,
                $store
            );
        }
    }
}

namespace ADCT\ParishIntake\WordPress\Events {
    /*
     * The same shared doubles the two other event-renderer tests use: the
     * listing calls `has_post_thumbnail`, `get_post_thumbnail_id` (globally)
     * and `wp_get_attachment_image_src` and `wp_get_attachment_url`
     * unqualified, and `tests/Support/WordPressStubs.php` answers only the
     * second of those.
     */
    require_once __DIR__ . '/EventRenderWordPressDoubles.php';
}
