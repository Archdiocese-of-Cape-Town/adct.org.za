<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin {
    // The detail screen reaches for these in this namespace; the shared stub
    // file does not declare them here.
    if (! function_exists(__NAMESPACE__ . '\\add_query_arg')) {
        function add_query_arg(mixed $key, mixed $value = null, string $url = ''): string
        {
            $arguments = is_array($key) ? $key : [$key => $value];
            $query = $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($arguments);

            return $arguments === [] ? $url : $query;
        }
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';

    use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
    use ADCT\ParishIntake\WordPress\Admin\CandidateDetailView;
    use ADCT\ParishIntake\WordPress\Admin\CandidateEditForm;
    use ADCT\ParishIntake\WordPress\Admin\ReviewQueuePage;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * Issue #172, the reviewer-facing half: the panel that publishes a notice's own
     * poster or bulletin beside its event.
     *
     * The handler side is pinned in ReviewQueuePromoteSourceMaterialTest. This
     * file pins what a reviewer is actually shown, because a handler that works
     * and a panel that lies to the reviewer is still a broken feature: the two
     * ways it can lie are (a) offering a button the handler will refuse, and (b)
     * offering nothing while a file could have been published.
     *
     * Two things are asserted over and over because they are the acceptance
     * criteria themselves: the checkbox defaults to nothing, and a file cannot be
     * offered a role its own type cannot fill.
     */
    final class SourceMaterialPanelTest extends TestCase
    {
        /** @var array<string, mixed> */
        private array $savedPost;

        /** @var array<string, mixed> */
        private array $savedGet;

        protected function setUp(): void
        {
            parent::setUp();

            $this->savedPost = $_POST;
            $this->savedGet = $_GET;

            $GLOBALS['adct_test_nonce_fields'] = [];
            $GLOBALS['adct_test_nonce_checks'] = [];
            $GLOBALS['adct_test_styles'] = [];
            $GLOBALS['adct_test_scripts'] = [];
            $GLOBALS['adct_test_current_user'] = new \WP_User(9, 'dean@example.test');
            $GLOBALS['adct_test_wp_caps'] = ['adct_pi_review'];
            $GLOBALS['adct_test_is_admin'] = true;
            $GLOBALS['adct_test_wp_screen'] = null;
            $GLOBALS['adct_test_redirect'] = null;
        }

        protected function tearDown(): void
        {
            $_POST = $this->savedPost;
            $_GET = $this->savedGet;

            unset(
                $GLOBALS['adct_test_nonce_fields'],
                $GLOBALS['adct_test_nonce_checks'],
                $GLOBALS['adct_test_styles'],
                $GLOBALS['adct_test_scripts'],
                $GLOBALS['adct_test_current_user'],
                $GLOBALS['adct_test_wp_caps'],
                $GLOBALS['adct_test_is_admin'],
                $GLOBALS['adct_test_wp_screen'],
                $GLOBALS['adct_test_redirect']
            );

            parent::tearDown();
        }

        public function testThePanelIsOfferedOnceTheCandidateHasAPublishedEvent(): void
        {
            $html = $this->panelHtml($this->posterRow(), 412);

            self::assertStringContainsString(
                'Publish the poster or bulletin',
                $html,
                'A published event with a publishable file must offer the panel.'
            );
            self::assertStringContainsString(
                'Publish the ticked files',
                $html,
                'The panel needs a submit button, or the reviewer has nothing to press.'
            );
            self::assertStringNotContainsString(
                'has not been published as an event yet',
                $html,
                'Saying "not published yet" on a published event would be a plain falsehood.'
            );
        }

        public function testNoCheckboxIsPreTicked(): void
        {
                    $panel = $this->panelHtml([
                $this->posterRow(11),
                $this->bulletinRow(12),
            ], 412);

                    // Scoped to the panel: the edit form above it legitimately carries a
                    // `checked` attribute of its own, so the assertion has to be about
                    // the publish form, not about the whole screen.
                    $panel = $this->panelOnly($panel);

                    self::assertStringNotContainsString(
                        'disabled',
                        $panel,
                        'Nothing may arrive pre-decided: criterion 2 says a person says out loud '
                        . 'which file is published, and a disabled control cannot be chosen at all.'
                    );

                    self::assertStringNotContainsString(
                        'checked',
                        $panel,
                        'A ticked box on arrival is a decision the reviewer did not make.'
                    );

                    // `selected` is the word the role select would use if a role were
                    // preselected; the empty prompt has to be the first option.
                    self::assertDoesNotMatchRegularExpression(
                        '/<option[^>]*\bselected\b/',
                        $panel,
                        'The role select must open on the empty prompt, not on a role.'
                    );
                }

        public function testTheFormCarriesTheCandidateItWasOpenedFrom(): void
        {
            $html = $this->panelHtml($this->posterRow(), 412, 77);

            self::assertStringContainsString(
                'name="candidate_id" value="77"',
                $html,
                'The handler resolves the candidate from the form; without the id it cannot act.'
            );
        }

        public function testTheFormCarriesNoEventId(): void
        {
            $html = $this->panelHtml($this->posterRow(), 412);

            self::assertStringNotContainsString(
                'name="event_id"',
                $html,
                'The event is derived from the candidate on the server. A posted event id '
                . 'would be an unchecked second route to the same decision.'
            );
        }

        public function testTheFormUsesThePromoteActionAndItsOwnNonce(): void
                {
                    $panel = $this->panelOnly($this->panelHtml($this->posterRow(), 412));

                    self::assertStringContainsString(
                        'name="' . ReviewQueuePage::PROMOTE_SOURCE_NONCE . '"',
                        $panel,
                        'The form must carry the promote route\'s own nonce field. A shared nonce '
                        . 'would let a download button replay itself into a publication.'
                    );
                    self::assertStringContainsString(
                        ReviewQueuePage::PROMOTE_SOURCE_ACTION,
                        $panel,
                        'And must post to the promote action, or the handler never runs.'
                    );
                }

        public function testAPosterIsOfferedThePosterRole(): void
        {
            $html = $this->panelHtml($this->posterRow(11), 412);

            self::assertStringContainsString(
                'name="roles[11]"',
                $html,
                'The role select must be keyed by attachment id, or the handler reads the wrong role.'
            );
            self::assertMatchesRegularExpression(
                '/<select name="roles\[11\]".*?value="poster"/s',
                $html,
                'A JPEG must be offerable as the poster, or the feature cannot do its main job.'
            );
        }

        public function testAPdfIsOfferedTheBulletinRoleButNotThePosterRole(): void
        {
            $html = $this->panelHtml($this->bulletinRow(12), 412);

            self::assertMatchesRegularExpression(
                '/<select name="roles\[12\]".*?value="bulletin"/s',
                $html,
                'A parish bulletin is a PDF, and this is the role it is published under.'
            );
            self::assertStringNotContainsString(
                'value="poster"',
                $html,
                'A PDF offered as a poster would be embedded as an image and render as a broken page.'
            );
        }

        #[DataProvider('mimeTypeRoles')]
        public function testNoFileIsOfferedARoleItsOwnTypeCannotFill(
            string $mimeType,
            string $forbidden
        ): void {
            $html = $this->panelHtml([[
                'id' => 5,
                'filename' => 'notice',
                'mime_type' => $mimeType,
                'storage_path' => str_repeat('a', 64) . '.pdf',
            ]], 412);

            self::assertStringNotContainsString(
                'value="' . $forbidden . '"',
                $html,
                sprintf(
                    'A %s must never be offered the %s role: the handler refuses that combination, '
                    . 'so offering it teaches the reviewer that the panel does not work.',
                    $mimeType,
                    $forbidden
                )
            );
        }

        /**
         * @return iterable<string, array{string, string}>
         */
        public static function mimeTypeRoles(): iterable
        {
            yield 'a jpeg may not be a bulletin' => ['image/jpeg', 'bulletin'];
            yield 'a pdf may not be a poster' => ['application/pdf', 'poster'];
            yield 'a png may not be a bulletin' => ['image/png', 'bulletin'];
            yield 'a webp may not be a bulletin' => ['image/webp', 'bulletin'];
        }

        public function testTheRoleSelectStartsOnAnEmptyPrompt(): void
        {
            $html = $this->panelHtml($this->posterRow(11), 412);

            self::assertStringContainsString(
                'Choose',
                $html,
                'A select that opens on a role has already made the reviewer\'s decision for them.'
            );
            self::assertMatchesRegularExpression(
                '/<option value="">/',
                $html,
                'The first option must carry no role, so a ticked file without a deliberate '
                . 'role choice is refused rather than silently guessed.'
            );
        }

        public function testAPublishedCandidateWithNoPublishableFileSaysSo(): void
        {
            $html = $this->panelHtml([], 412);

            self::assertStringContainsString(
                'no file that can be published',
                $html,
                'An email with no JPEG, PNG, WebP or PDF has nothing to publish. Silence '
                . 'would read as a broken screen rather than an accurate one.'
            );
            self::assertStringNotContainsString(
                'name="selected[]"',
                $html,
                'With nothing to publish there must be no form to submit.'
            );
        }

        public function testAnUnpublishedCandidateIsToldToApproveFirst(): void
        {
            $html = $this->panelHtml($this->posterRow(), null);

            self::assertStringContainsString(
                'has not been published as an event yet',
                $html,
                'Publishing a file needs a published event; the screen must say which action is missing.'
            );
            self::assertStringContainsString(
                'Approve it first',
                $html,
                'The reviewer has to be told what to do, not just what is missing.'
            );
            self::assertStringNotContainsString(
                'name="selected[]"',
                $html,
                'No form until there is an event for the file to belong to.'
            );
        }

        public function testTheWordingStaysPublishRatherThanPromote(): void
        {
                    $panel = $this->panelOnly($this->panelHtml($this->posterRow(), 412));

                    // The form's `action` value legitimately contains "promote" -- it is the
                    // route's name. What must not appear is the word in anything a person
                    // reads.
                    $readable = $this->visibleText($panel);

                    self::assertStringNotContainsStringIgnoringCase(
                        'promote',
                        $readable,
                        '"Promote" reads as an internal state change. A reviewer on this screen is '
                        . 'deciding what goes on the website, and the words have to say that.'
                    );
                }

                /**
                 * Just the words a person on the page can read: no tags, so no attribute
                 * value such as the route's own `promote_source_nonce` field name.
                 */
                private function visibleText(string $html): string
                {
                    return trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? $html);
                }

                public function testThePanelWarnsThatPublishingIsImmediateAndNotReversibleByRemoval(): void
                {
                    $panel = $this->panelOnly($this->panelHtml($this->posterRow(), 412));

                    self::assertStringContainsString(
                        'public the moment you submit',
                        $panel,
                        'A reviewer must know this is not a draft. The copy is fetchable by anyone '
                        . 'as soon as it lands in the media library.'
            );
                    self::assertStringContainsString(
                        'Nothing is ticked for you',
                        $this->visibleText($panel),
                        'Saying the empty case is a no-op tells the reviewer the screen is safe to open.'
                    );
                }

                /**
                 * The publish panel on its own, so an assertion about it is not silently
                 * satisfied (or spoiled) by the rest of the screen.
                 */
                private function panelOnly(string $html): string
                {
                    $start = strpos($html, 'adct-pi-source-material');
                    self::assertNotFalse($start, 'The publish panel has to be present to be asserted on.');

                    return substr($html, $start);
                }

        public function testAParishSuppliedFilenameIsEscaped(): void
        {
            $html = $this->panelHtml([[
                'id' => 5,
                'filename' => '"><script>alert(1)</script>.png',
                'mime_type' => 'image/png',
                'storage_path' => str_repeat('a', 64) . '.png',
            ]], 412);

            self::assertStringNotContainsString('<script>', $html, 'A parish filename is untrusted input.');
            self::assertStringContainsString(
                '&lt;script&gt;',
                $html,
                'It has to be escaped into visible text, not dropped: the reviewer needs '
                . 'to see the real filename.'
            );
        }

        public function testAnUnnamedFileStillRendersARow(): void
        {
            $html = $this->panelHtml([[
                'id' => 5,
                'filename' => '',
                'mime_type' => 'image/png',
                'storage_path' => str_repeat('a', 64) . '.png',
            ]], 412);

            self::assertStringContainsString('(unnamed)', $html, 'An empty name still has to render a row.');
            self::assertStringContainsString('name="selected[]" value="5"', $html, 'And still be publishable.');
        }

        public function testARowWithNoIdIsSkippedRatherThanRenderedUnpublishable(): void
        {
            $html = $this->panelHtml([[
                'id' => 0,
                'filename' => 'orphan.png',
                'mime_type' => 'image/png',
                'storage_path' => str_repeat('a', 64) . '.png',
            ]], 412);

            self::assertStringNotContainsString(
                'orphan.png',
                $html,
                'An attachment with no id cannot be published, and a checkbox for it would '
                . 'post an id of zero and be refused.'
            );
        }

        public function testThePanelIsAbsentWhenTheScreenRendersWithoutTheNewArguments(): void
        {
            $html = $this->detailHtml();

            self::assertStringContainsString(
                'adct-pi-source-material',
                $html,
                'The panel is always present; it explains its own absence rather than vanishing.'
            );
            self::assertStringContainsString(
                'has not been published as an event yet',
                $html,
                'No arguments means no published event, which is the absent case.'
            );
        }

        /**
         * @return array<string, mixed>
         */
        private function posterRow(int $id = 11): array
        {
            return [
                'id' => $id,
                'message_id' => 3,
                'filename' => 'notice-poster.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 2048,
                'storage_path' => str_repeat('a', 64) . '.jpg',
            ];
        }

        /**
         * @return array<string, mixed>
         */
        private function bulletinRow(int $id = 12): array
        {
            return [
                'id' => $id,
                'message_id' => 3,
                'filename' => 'bulletin.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 8192,
                'storage_path' => str_repeat('b', 64) . '.pdf',
            ];
        }

        /**
                 * @param list<array<string, mixed>>|array<string, mixed> $promotable
         */
        private function panelHtml(array $promotable, ?int $eventId, int $candidateId = 77): string
        {
                    return $this->detailHtml(
                        array_is_list($promotable) ? $promotable : [$promotable],
                        $eventId,
                        $candidateId
                    );
                }

        /**
         * @param list<array<string, mixed>> $promotable
         */
        private function detailHtml(array $promotable = [], ?int $eventId = null, int $candidateId = 77): string
        {
            $row = [
                'id' => $candidateId,
                'message_id' => 3,
                'fields' => '{}',
                'recurrence' => '{}',
                'status' => 'awaiting_approval',
                'match_kind' => 'new',
                'match_event_id' => null,
                'sender_email' => 'parish@example.test',
                'parish_name' => 'Test Parish',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
                'decided_by' => '',
                'decided_at' => '',
                'decision_note' => '',
            ];

            ob_start();
            try {
                (new CandidateDetailView(new CandidateEditForm()))->render(
                    $row,
                    null,
                    [],
                    [],
                    'awaiting_approval',
                    '',
                    false,
                    false,
                    null,
                    null,
                    null,
                    '',
                    false,
                    false,
                    false,
                    $promotable,
                    $eventId
                );
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        }
    }
}