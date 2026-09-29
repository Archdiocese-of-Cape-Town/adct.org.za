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
     * The reading settings for one poster.
     *
     * A fieldset and legend rather than a bare pair of inputs, so a screen
     * reader announces what the controls are for. Both inputs are labelled, and
     * the plain-language summary under them restates the current choice in a
     * sentence, because "sparse text" on its own tells a parish secretary
     * nothing about what changed.
     *
     * Nothing here is persisted or transmitted: the values live in the form
     * controls until the page is closed, exactly as the recognised text does
     * (ADR 0017).
     */
    private function settingsPanel(string $targetId): string
    {
        $base = 'adct-ocr-settings-' . $this->safeId($targetId);

        return sprintf(
            '<fieldset class="adct-ocr__settings" data-adct-ocr-settings>'
            . '<legend class="adct-ocr__settings-legend">%1$s</legend>'
            . '<p class="adct-ocr__settings-help">%2$s</p>'
            . '<span class="adct-ocr__field">'
            . '<label class="adct-ocr__settings-label" for="%3$s">%4$s</label>'
            . '<select class="adct-ocr__settings-select" id="%3$s" data-adct-ocr-layout>'
            . '%5$s'
            . '</select>'
            . '</span>'
            . '<span class="adct-ocr__field">'
            . '<label class="adct-ocr__settings-label" for="%6$s">%7$s</label>'
            . '<input class="adct-ocr__settings-slider" type="range" id="%6$s" data-adct-ocr-confidence'
            . ' min="%8$d" max="%9$d" step="%10$d" value="%8$d">'
            . '<output class="adct-ocr__settings-value" for="%6$s" data-adct-ocr-confidence-readout>Keep every line</output>'
            . '</span>'
            . '<p class="adct-ocr__settings-readout" data-adct-ocr-readout role="status" aria-live="polite"></p>'
            . '</fieldset>',
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
     * The layout choices, in plain words, with the PSM value written into the
     * markup so the browser never has to be told which number goes with which
     * name at click time.
     */
    private function layoutOptions(): string
    {
        $options = '';

        foreach (self::LAYOUTS as $layout) {
            $options .= sprintf(
                '<option value="%s" data-psm="%s">%s</option>',
                esc_attr($layout['value']),
                esc_attr($layout['psm']),
                esc_html($this->layoutLabel($layout['value']))
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
