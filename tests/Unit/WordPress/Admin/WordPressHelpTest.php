<?php

declare(strict_types=1);

namespace {
    // WP_Screen, get_current_screen, current_user_can, add_action and the
    // escaping helpers WordPressHelp calls are declared once in the shared
    // stubs, for the same reason ApprovalReminderJobTest does not keep its own
    // copies: a second declaration in one namespace is a fatal, and which file
    // PHPUnit includes first decides which stub wins.
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
require_once __DIR__ . '/../../../Support/WordPressCapabilityStubs.php';
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {

    use ADCT\ParishIntake\Core\Admin\AdminGuideLinks;
    use ADCT\ParishIntake\Core\Admin\AdminHelpRegistry;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\WordPress\Admin\WordPressHelp;
    use PHPUnit\Framework\TestCase;

        // esc_url is global, exactly as it is in WordPress. The expected href is
        // built through the same function the adapter uses, so a change to how the
        // plugin escapes URLs cannot make this test pass or fail on its own.
        use function esc_url;


    /**
     * The Help tab adapter: the gate, the escaping, and the absence of any
     * form.
     *
     * There is no nonce to test here, on purpose. Every tab is read-only prose
     * and an outbound link, so there is no action to take and nothing a
     * cross-site request could do. The two things that can actually go wrong
     * are that the help appears for a user who could not reach the screen, and
     * that unescaped text from the registry reaches the page. Both are tested
     * below, and both were mutated to confirm they fail without their guard.
     */
    final class WordPressHelpTest extends TestCase
    {
        private const HEALTH_SCREEN = 'adct-parish-intake_page_adct-parish-intake-health';
        private const REVIEW_SCREEN = 'adct-parish-intake_page_adct-parish-intake-review';

        protected function setUp(): void
        {
            $GLOBALS['adct_test_wp_screen'] = null;
            $GLOBALS['adct_test_wp_caps'] = [];
            $GLOBALS['adct_test_wp_actions'] = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['adct_test_wp_screen'], $GLOBALS['adct_test_wp_caps'], $GLOBALS['adct_test_wp_actions']);
        }

        /**
         * Registration is the whole of the wiring: one hook, on admin_head.
         */
        public function testItRegistersOneHookOnAdminHead(): void
        {
            $help = new WordPressHelp();
            $help->register();

            self::assertSame(['admin_head'], array_keys($GLOBALS['adct_test_wp_actions']));
            self::assertSame(
                [[$help, 'renderHelpTabs']],
                array_map('array_values', $GLOBALS['adct_test_wp_actions']['admin_head'])
            );
        }

        /**
         * The main thing this class is for: a user who can open a screen sees
         * the Help tab on it.
         */
        public function testAUserWithTheScreensCapabilityGetsHelpTabs(): void
        {
            $tabs = $this->tabsFor(self::HEALTH_SCREEN, [Capabilities::VIEW_REPORTS]);

            self::assertCount(2, $tabs, 'Expected an overview tab and a guides tab.');
            self::assertSame('adct-parish-intake-overview', $tabs[0]['id']);
            self::assertSame('adct-parish-intake-guides', $tabs[1]['id']);
        }

        /**
         * And the other thing it is for: a user who cannot open the screen
         * gets nothing. The help names the buttons on the screen and where its
         * settings live, so it must not describe a screen the user would be
         * refused by.
         *
         * This fails if the capability check is removed, or if the gate drifts
         * to a different capability than the one the screen's own menu uses.
         */
        public function testAUserWithoutTheScreensCapabilityGetsNoHelp(): void
        {
            self::assertSame(
                [],
                $this->tabsFor(self::HEALTH_SCREEN, [Capabilities::REVIEW]),
                'A reviewer can read Health but must not be told how its screen works.'
            );
            self::assertSame(
                [],
                $this->tabsFor(self::HEALTH_SCREEN, []),
                'A user with no Parish Intake capability must get nothing.'
            );
            self::assertSame(
                [],
                $this->tabsFor('adct-parish-intake_page_adct-parish-intake-settings', [Capabilities::VIEW_REPORTS]),
                'Report access does not reach the Settings screen, so it must not get its help.'
            );
        }

        /**
         * The review queue is the screen an approver lands on first, and it is
         * registered twice. Both registrations have to get the help.
         */
        public function testBothRegistrationsOfTheReviewQueueGetHelp(): void
        {
            self::assertNotSame([], $this->tabsFor(self::REVIEW_SCREEN, [Capabilities::REVIEW]));
            self::assertNotSame(
                [],
                $this->tabsFor('toplevel_page_adct-parish-intake-review', [Capabilities::APPROVE_DEANERY]),
                'A deanery-only approver reaches the queue as a top-level page, which has its own screen id.'
            );
        }

        /**
         * A screen the plugin does not own is left alone. WordPressHelp runs on
         * admin_head across the whole admin, so every other plugin's screen
         * passes through here.
         */
        public function testAScreenThePluginDoesNotOwnGetsNoHelp(): void
        {
            self::assertSame([], $this->tabsFor('edit-page', [Capabilities::MANAGE_SETTINGS]));
            self::assertSame([], $this->tabsFor('toplevel_page_adct-parish-intake-gone', [Capabilities::MANAGE_SETTINGS]));
        }

        /**
         * With no current screen at all — admin_head outside an admin page, or
         * an unusual load order — this must do nothing rather than fail.
         */
        public function testNoScreenMeansNoHelpAndNoError(): void
        {
            $GLOBALS['adct_test_wp_screen'] = null;

            $help = new WordPressHelp();
            $help->renderHelpTabs();

            self::assertTrue(true, 'renderHelpTabs() returned without touching an absent screen.');
        }

        /**
         * The guides tab names each document rather than printing a bare URL,
         * and the review queue offers the two short guides.
         */
        public function testTheGuidesTabNamesEachDocument(): void
        {
            $content = $this->tabsFor(self::REVIEW_SCREEN, [Capabilities::REVIEW])[1]['content'];

            self::assertStringContainsString(
                'href="' . esc_url(AdminGuideLinks::APPROVER_GUIDE) . '"',
                $content
            );
            self::assertStringContainsString(
                'href="' . esc_url(AdminGuideLinks::PARISH_GUIDE) . '"',
                $content
            );
            self::assertStringContainsString('Approver guide', $content);
            self::assertStringContainsString('Parish guide', $content);
        }

        /**
         * The overview links to this screen's own section of the guide, with
         * the anchor applied.
         */
        public function testTheOverviewTabLinksToThisScreensOwnSection(): void
        {
            $content = $this->tabsFor(self::REVIEW_SCREEN, [Capabilities::REVIEW])[0]['content'];

            self::assertStringContainsString(
                'href="' . esc_url(AdminGuideLinks::operator('review-approve-and-correct')) . '"',
                $content
            );
        }

        /**
         * Escaping is not optional here. The bodies are plugin-authored today,
         * but this adapter is the boundary: a later edit that interpolates a
         * parish name, a source identifier or an error message must not be able
         * to inject markup into somebody's admin page.
         *
         * overviewContent() is public and takes the body as an argument, so
                  * the fixture is passed in directly. The registry cannot hold one: it
                  * is a const, and adding markup to a real screen's help to test this
                  * would be a false alarm in production.
         */
        public function testPluginTextInTheHelpIsEscaped(): void
        {
            $help = new WordPressHelp();
            $content = $help->overviewContent(
                'Watch out for <script>alert("x")</script> & co',
                self::REVIEW_SCREEN
            );

            self::assertStringNotContainsString('<script>', $content);
            self::assertStringContainsString('&lt;script&gt;', $content);
            self::assertStringContainsString('&amp;', $content);
        }

        /**
         * URLs go through esc_url, not esc_html, so a URL cannot break out of
         * the href attribute it is quoted in.
         */
        public function testGuideUrlsAreEscapedAsUrls(): void
        {
            $help = new WordPressHelp();
            $content = $help->guidesContent(self::REVIEW_SCREEN);

            self::assertSame(
                            3,
                            preg_match_all('/href="[^"]*"/', $content),
                            'The three guides the review queue offers must each be a quoted, closed href.'
                        );
                        self::assertSame(
                            3,
                            substr_count($content, '<li>'),
                            'No guide beyond the three the screen declares may be rendered.'
                        );
                        self::assertStringContainsString(AdminGuideLinks::APPROVER_GUIDE, $content);
                    }

        /**
         * The tab bodies are prose and links. No form, no input, no nonce.
         *
         * A Help pane that posted something would need a nonce like any other
         * admin action; there is nothing here to post, which is why there is no
         * nonce to forge and nothing for an attacker to trigger by luring a
         * logged-in administrator to a URL. If a future change adds a form
         * here, this test is the reminder that it now needs a nonce.
         */
        public function testTheHelpPaneContainsNoFormAndNothingIsPosted(): void
        {
            $_POST = ['adct_parish_intake_should_not_appear' => '1'];

            foreach (AdminHelpRegistry::screenIds() as $screenId) {
                $GLOBALS['adct_test_wp_caps'] = [Capabilities::MANAGE_SETTINGS, Capabilities::VIEW_REPORTS];

                foreach ($this->tabsFor($screenId, $GLOBALS['adct_test_wp_caps']) as $tab) {
                    self::assertStringNotContainsString('<form', strtolower($tab['content']));
                    self::assertStringNotContainsString('<input', strtolower($tab['content']));
                    self::assertStringNotContainsString('<button', strtolower($tab['content']));
                    self::assertStringNotContainsString('nonce', strtolower($tab['content']));
                }
            }

            self::assertSame(
                ['adct_parish_intake_should_not_appear' => '1'],
                $_POST,
                'Rendering help must not read or change $_POST.'
            );

            $_POST = [];
        }

        /**
         * @param list<string> $capabilities
         * @return list<array{id: string, title: string, content: string}>
         */
        private function tabsFor(string $screenId, array $capabilities): array
        {
            $screen = new \WP_Screen();
            $screen->id = $screenId;

            $GLOBALS['adct_test_wp_screen'] = $screen;
            $GLOBALS['adct_test_wp_caps'] = $capabilities;

            $help = new WordPressHelp();
            $help->renderHelpTabs();

            return $screen->help_tabs;
        }
    }
}