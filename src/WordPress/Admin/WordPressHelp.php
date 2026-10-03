<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Admin\AdminHelpRegistry;
use WP_Screen;

/**
 * Puts the registry's help on the admin screens, as WordPress Help tabs.
 *
 * WordPress renders a screen's Help tabs into its own help pane, reachable from
 * the "Help" link at the top right of every admin page. That link is the one
 * piece of help chrome every WordPress user already knows to look for, so
 * attaching the guidance there means nobody has to learn a new place to look
 * and nobody has to be sent to a repository URL to find it.
 *
 * Every tab is read-only prose with an outbound link, so there is no form and
 * nothing to protect with a nonce. What it does have to be is *gated*: the help
 * tells a reviewer how to approve and an administrator where the retention
 * switches are, so it is only added for a user who could reach the screen
 * anyway. Checking that here means the help cannot leak the shape of a screen to
 * a user who would be refused by the screen's own render().
 *
 * The gate is the capability the screen's own menu was registered with, taken
 * from the registry. Inventing a narrower gate here would hide the health
 * screen's help from a legitimate VIEW_REPORTS user who can read that page, and
 * the check would disagree with the menu the user can already see.
 */
final class WordPressHelp
{
    public function register(): void
    {
        // admin_head is where the current screen is known and the help pane has
        // not rendered yet. The hook suffix is not used: a page can have several
        // hook suffixes over its lifetime, and the screen id is re-checked on
        // every call anyway.
        add_action('admin_head', [$this, 'renderHelpTabs']);
    }

    /**
     * Called by WordPress on admin_head, where the current screen is known.
     *
     * WP_Screen::add_help_tab() only stores the tab; the help pane renders it
     * later. So on a screen with no help this does nothing at all, and the
     * registry lookup keeps it that cheap.
     */
    public function renderHelpTabs(): void
    {
        $screen = $this->currentScreen();

        if ($screen === null || ! AdminHelpRegistry::has($screen->id)) {
            return;
        }

        $help = AdminHelpRegistry::forScreen($screen->id);

        if (! current_user_can($help['capability'])) {
            return;
        }

        $screen->add_help_tab([
            'id' => 'adct-parish-intake-overview',
            'title' => $help['title'],
            'content' => $this->overviewContent($help['body'], $screen->id),
        ]);

        $screen->add_help_tab([
            'id' => 'adct-parish-intake-guides',
            'title' => __('Guides', 'adct-parish-intake'),
            'content' => $this->guidesContent($screen->id),
        ]);
    }

    /**
     * The first tab: what this screen is for, and where to read more.
     *
     * The body is plugin-authored text and is escaped anyway, so there is one
     * rule for this file and a future edit that interpolates a parish name
     * cannot leak.
     */
    public function overviewContent(string $body, string $screenId): string
    {
        return '<p>' . esc_html($body) . '</p>'
            . '<p><a href="' . esc_url(AdminHelpRegistry::guideUrl($screenId)) . '">'
            . esc_html__('Read this section of the operator guide', 'adct-parish-intake')
            . '</a></p>';
    }

    /**
     * The second tab: the documents, each named so a reader can pick one.
     */
    public function guidesContent(string $screenId): string
    {
        $labels = AdminHelpRegistry::guideLabels();
        $items = [];

        foreach (AdminHelpRegistry::guideLinks($screenId) as $key => $url) {
            $items[] = '<li><a href="' . esc_url($url) . '">'
                . esc_html($labels[$key] ?? $key) . '</a></li>';
        }

        return '<p>' . esc_html__(
            'These guides are also in the plugin repository under docs/.',
            'adct-parish-intake'
        ) . '</p><ul>' . implode('', $items) . '</ul>';
    }

    private function currentScreen(): ?WP_Screen
    {
            // An unqualified function call inside a namespace resolves to this
            // namespace first and then falls back to the global one, so both names
            // are the ones that can satisfy the call below. Checking only the
            // global name would miss a namespaced implementation and silently
            // disable the help.
            if (! function_exists(__NAMESPACE__ . '\\get_current_screen')
                && ! function_exists('get_current_screen')
            ) {
                return null;
            }

            $screen = get_current_screen();

            return $screen instanceof WP_Screen ? $screen : null;
        }
}