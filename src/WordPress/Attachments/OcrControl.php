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
    public function __construct(
        private readonly string $scriptUrl,
        private readonly string $styleUrl
    ) {
    }

    /**
     * The script and stylesheet tags a page needs when it renders any control.
     *
     * The emailed token pages render standalone HTML with no wp_head(), so
     * these are plain tags and everything is versioned on the plugin's own
     * asset version rather than by WordPress.
     */
    public function headTags(): string
    {
        return sprintf(
            '<link rel="stylesheet" href="%s">' . "\n"
            . '<script src="%s" defer></script>',
            esc_url($this->styleUrl),
            esc_url($this->scriptUrl)
        );
    }

    /**
     * The poster preview plus its "Read text from this poster" button.
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
            . '</div>',
            esc_url($imageUrl),
            esc_attr($targetId),
            esc_attr($label),
            esc_html__('Read text from this poster', 'adct-parish-intake')
        );
    }
}
