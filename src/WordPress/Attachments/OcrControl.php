<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Attachments;

/**
 * Renders the shared on-demand OCR control (ADR 0017).
 *
 * Both surfaces that show a poster to a human — the admin parser page and the
 * emailed action-token page — render their control here, so the markup, the
 * data attributes the module binds to, and the accessibility wiring cannot
 * drift apart.
 *
 * The control is inert markup: no inline handler, no WordPress globals. The
 * behaviour comes from assets/ocr.js, which is only added to a page that has at
 * least one control on it.
 */
final class OcrControl
{
    /**
     * The page-segmentation modes offered, in the plain words a reviewer would
     * use, each paired with the Tesseract PSM value it stands for.
     *
     * The first entry is the default and must stay so: with every control left
     * alone the control behaves exactly as it did before these settings
     * existed.
     *
     * assets/ocr-settings.js holds the same list so it can resolve a value back
     * to a PSM number. The two are checked against each other by
     * OcrControlTest::testTheMarkupAndTheScriptAgreeOnEveryLayout(), so they
     * cannot drift apart unnoticed.
     */
    private const LAYOUTS = [
        ['value' => 'auto', 'psm' => '3'],
        ['value' => 'sparse', 'psm' => '11'],
        ['value' => 'column', 'psm' => '4'],
        ['value' => 'block', 'psm' => '6'],
        ['value' => 'line', 'psm' => '7'],
        ['value' => 'word', 'psm' => '8'],
        ['value' => 'char', 'psm' => '10'],
    ];

    private const CONFIDENCE_MIN = 0;
    private const CONFIDENCE_MAX = 100;
    private const CONFIDENCE_STEP = 5;

    public function __construct(
        private readonly string $scriptUrl,
        private readonly string $styleUrl,
        private readonly string $settingsScriptUrl
    ) {
    }

    /**
     * The script and stylesheet tags a page needs when it renders any control.
     *
     * The emailed token pages render standalone HTML with no wp_head(), so
     * these are plain tags and everything is versioned on the plugin's own
     * asset version rather than by WordPress.
     *
     * Both scripts are deferred, which preserves the order they are written in,
     * and the settings module has to come first: the OCR module reads its
     * layout list at start-up and does nothing at all without it.
     *
     * Neither tag is a reason to reach the network on page load. Both point at
     * the plugin's own files, and tesseract.js itself is still only fetched
     * when someone presses the button (ADR 0017).
     */
    public function headTags(): string
    {
        return sprintf(
            '<link rel="stylesheet" href="%s">' . "\n"
            . '<script src="%s" defer></script>' . "\n"
            . '<script src="%s" defer></script>',
            esc_url($this->styleUrl),
            esc_url($this->settingsScriptUrl),
            esc_url($this->scriptUrl)
        );
    }

    /**
     * The poster preview, its "Read text from this poster" button, and the
     * settings a reviewer can adjust before or after reading.
     *
     * The settings are deliberately forgiving. Every one of them is optional,
     * every one is reset to its default when the page loads, and the default
     * reads the poster exactly as the button did before they existed. A
     * reviewer who never touches them sees no difference; a reviewer fighting a
     * difficult poster has somewhere to go.
     *
     * Every id is derived from the target field, so a page showing several
     * posters gets several independent panels that cannot be wired to each
     * other's controls.
     *
     * @param string $imageUrl  A URL that serves the bytes to this browser only.
     * @param string $targetId  The field the extracted text is appended to.
     * @param string $label     Accessible name of the control.
     */
    public function render(string $imageUrl, string $targetId, string $label = ''): string
    {
        $label = $label === '' ? 'Read the text on this poster' : $label;

        return sprintf(
            '<div class="adct-ocr">'
            . '<img class="adct-ocr__preview" src="%1$s" alt="Event poster" loading="lazy">'
            . '<button type="button" class="button adct-ocr__trigger" data-adct-ocr data-image-url="%1$s" data-target="%2$s" aria-label="%3$s">%4$s</button>'
            . '<span class="adct-ocr__status" role="status" aria-live="polite"></span>'
            . '%5$s'
            . '</div>',
            esc_url($imageUrl),
            esc_attr($targetId),
            esc_attr($label),
            esc_html__('Read text from this poster', 'adct-parish-intake'),
            $this->settingsPanel($targetId)
        );
    }

    /**
     * The reading settings for one poster, folded away by default.
     *
     * Most reviewers will never open this, and they should not have to think
     * about it: the settings only matter for a poster whose text comes out
     * jumbled, and that is not a decision anybody makes in advance. A native
     * <details> element does the folding, which means it works with no
     * JavaScript at all and needs no ARIA bookkeeping of ours — the browser
     * already wires the summary to the disclosure.
     *
     * Inside it, a fieldset and legend rather than a bare pair of inputs, so a
     * screen reader announces what the controls are for, and a plain-language
     * readout restates the current choice in a sentence.
     *
     * The numbers behind the plain words are all still reachable: each option
     * carries its PSM in a title, and the summary carries the current one, so
     * the settings are deferrable rather than opaque.
     *
     * Nothing here is persisted or transmitted: the values live in the form
     * controls until the page is closed, exactly as the recognised text does
     * (ADR 0017).
     */
    private function settingsPanel(string $targetId): string
    {
        $base = 'adct-ocr-settings-' . $this->safeId($targetId);

        return sprintf(
            '<details class="adct-ocr__advanced" data-adct-ocr-settings>'
            . '<summary class="adct-ocr__advanced-summary" data-adct-ocr-summary'
            . ' title="%1$s">%2$s</summary>'
            . '<fieldset class="adct-ocr__settings">'
            . '<legend class="adct-ocr__settings-legend">%3$s</legend>'
            . '<p class="adct-ocr__settings-help">%4$s</p>'
            . '<span class="adct-ocr__field">'
            . '<label class="adct-ocr__settings-label" for="%5$s">%6$s</label>'
            . '<select class="adct-ocr__settings-select" id="%5$s" data-adct-ocr-layout>'
            . '%7$s'
            . '</select>'
            . '</span>'
            . '<span class="adct-ocr__field">'
            . '<label class="adct-ocr__settings-label" for="%8$s">%9$s</label>'
            . '<input class="adct-ocr__settings-slider" type="range" id="%8$s" data-adct-ocr-confidence'
            . ' min="%10$d" max="%11$d" step="%12$d" value="%10$d">'
            . '<output class="adct-ocr__settings-value" for="%8$s" data-adct-ocr-confidence-readout>Keep every line</output>'
            . '</span>'
            . '<p class="adct-ocr__settings-readout" data-adct-ocr-readout role="status" aria-live="polite"></p>'
            . '</fieldset>'
            . '</details>',
            esc_attr($this->summaryTooltip(self::LAYOUTS[0]['value'])),
            esc_html__('Advanced', 'adct-parish-intake'),
            esc_html__('Reading settings', 'adct-parish-intake'),
            esc_html__(
                'Optional. Leave these alone unless the text comes out jumbled, then try another '
                . 'layout and read the poster again.',
                'adct-parish-intake'
            ),
            esc_attr($base . '-layout'),
            esc_html__('How is the poster laid out?', 'adct-parish-intake'),
            $this->layoutOptions(),
            esc_attr($base . '-confidence'),
            esc_html__('Hide text the reader is unsure of', 'adct-parish-intake'),
            self::CONFIDENCE_MIN,
            self::CONFIDENCE_MAX,
            self::CONFIDENCE_STEP
        );
    }

    /**
     * The native tooltip on the disclosure.
     *
     * Hovering "Advanced" should say what it would do, and the number is the
     * part an archivist or a maintainer is actually looking for. assets/
     * ocr-settings.js rewrites this as the layout changes, so it always names
     * the setting that is in force rather than the one the page loaded with.
     */
    private function summaryTooltip(string $layout): string
    {
        return sprintf(
            '%s — Tesseract PSM %s',
            $this->layoutLabel($layout),
            $this->layoutPsm($layout)
        );
    }

    /**
     * The layout choices, in plain words, with the PSM value written into the
     * markup so the browser never has to be told which number goes with which
     * name at click time.
     *
     * Each option also carries its number as a title. The label stays in plain
     * words because that is what a parish secretary reads, but the number is
     * not hidden — it is one hover away, and the plain-language label is
     * exactly the kind of description that makes a Tesseract manual entry
     * findable.
     */
    private function layoutOptions(): string
    {
        $options = '';

        foreach (self::LAYOUTS as $layout) {
            $label = $this->layoutLabel($layout['value']);

            $options .= sprintf(
                '<option value="%s" data-psm="%s" title="%s — Tesseract PSM %s">%s</option>',
                esc_attr($layout['value']),
                esc_attr($layout['psm']),
                esc_attr($label),
                esc_attr($this->layoutPsm($layout['value'])),
                esc_html($label)
            );
        }

        return $options;
    }

    private function layoutLabel(string $value): string
    {
        $labels = [
            'auto' => 'Automatic — works for most posters',
            'sparse' => 'Words spread across the page',
            'column' => 'One tall column',
            'block' => 'One block of text',
            'line' => 'A single line',
            'word' => 'A single word',
            'char' => 'A single character',
        ];

        return $labels[$value] ?? $value;
    }

    /**
     * The Tesseract page-segmentation number behind a layout name.
     *
     * Looked up rather than taken from a caller's position, so a tooltip can
     * never name one layout while quoting another's number.
     */
    private function layoutPsm(string $value): string
    {
        foreach (self::LAYOUTS as $layout) {
            if ($layout['value'] === $value) {
                return $layout['psm'];
            }
        }

        return '';
    }

    /**
     * Reduce a field name to something usable inside an element id.
     *
     * The target name is server-chosen, but an id still has to be well formed,
     * and a name carrying punctuation must not be able to break out of the
     * attribute.
     */
    private function safeId(string $targetId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $targetId) ?? '';

        return $safe === '' ? 'field' : trim($safe, '-');
    }
}
