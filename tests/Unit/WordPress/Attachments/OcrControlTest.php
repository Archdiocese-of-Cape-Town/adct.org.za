<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Attachments {
    function esc_url(mixed $value): string
    {
        // The shape of WordPress's own filter is close enough to catch a quote
        // or a tag breaking out of an attribute. It encodes `&` once, so the
        // expectations below must not pre-escape the input.
        return htmlspecialchars(
            is_string($value) ? $value : '',
            ENT_QUOTES,
            'UTF-8'
        );
    }

    function esc_attr(mixed $value): string
    {
        return htmlspecialchars(
            is_string($value) ? $value : '',
            ENT_QUOTES,
            'UTF-8'
        );
    }

    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Attachments {

    use ADCT\ParishIntake\WordPress\Attachments\OcrControl;
    use PHPUnit\Framework\TestCase;

    /**
     * Both surfaces render their control through this one renderer, so these
     * tests are as much about the data attributes the module binds to as about
     * escaping.
     */
    final class OcrControlTest extends TestCase
    {
        private const SCRIPT_URL = 'https://adct.org.za/wp-content/plugins/adct-parish-intake/assets/ocr.js';
        private const STYLE_URL = 'https://adct.org.za/wp-content/plugins/adct-parish-intake/assets/ocr.css';

        public function testTheControlCarriesTheImageUrlAndTargetTheModuleBindsTo(): void
        {
            $html = $this->control()->render('https://adct.org.za/?adct_token_image=11', 'adct_edit_description');

            self::assertStringContainsString('data-adct-ocr', $html);
            self::assertStringContainsString('data-image-url="https://adct.org.za/?adct_token_image=11"', $html);
            self::assertStringContainsString('data-target="adct_edit_description"', $html);
        }

        public function testTheControlShowsThePosterSoTheReviewerCanReadItThemselves(): void
        {
            $html = $this->control()->render('https://adct.org.za/?adct_token_image=11', 'adct_edit_description');

            self::assertStringContainsString('<img class="adct-ocr__preview"', $html);
            self::assertStringContainsString('src="https://adct.org.za/?adct_token_image=11"', $html);
            self::assertStringContainsString('alt="Event poster"', $html);
        }

        public function testTheTriggerIsAButtonThatDoesNotSubmitAnyForm(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            // The control lives inside a form that posts the decision, so the
            // trigger must never submit it.
            self::assertStringContainsString('type="button"', $html);
        }

        public function testTheStatusRegionIsAnnouncedToAssistiveTechnology(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            self::assertStringContainsString('role="status"', $html);
            self::assertStringContainsString('aria-live="polite"', $html);
        }

        public function testProgressIsAnnouncedRatherThanOnlyColoured(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            self::assertStringContainsString('aria-label="', $html);
        }

        public function testAnImageUrlCannotBreakOutOfItsAttribute(): void
        {
            $html = $this->control()->render('https://adct.org.za/"><script>alert(1)</script>', 'target');

            self::assertStringNotContainsString('<script>', $html);
            self::assertStringContainsString('&lt;script&gt;', $html);
        }

        public function testATargetIdCannotBreakOutOfItsAttribute(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'a" onmouseover="alert(1)');

            self::assertStringNotContainsString('onmouseover="alert(1)"', $html);
            self::assertStringContainsString('data-target="a&quot;', $html);
        }

        public function testALabelCannotBreakOutOfItsAttribute(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target', 'Poster"><b>x</b>');

            self::assertStringNotContainsString('<b>', $html);
            self::assertStringContainsString('&lt;b&gt;', $html);
        }

        public function testTheDefaultLabelIsUsedWhenNoneIsGiven(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            self::assertStringContainsString('aria-label="Read the text on this poster"', $html);
        }

        public function testASuppliedLabelReplacesTheDefault(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target', 'Poster for the family Mass');

            self::assertStringContainsString('aria-label="Poster for the family Mass"', $html);
            self::assertStringNotContainsString('aria-label="Read the text on this poster"', $html);
        }

        public function testTheHeadTagsLoadTheModuleAndItsStyles(): void
        {
            $tags = $this->control()->headTags();

            self::assertStringContainsString('<script src="' . self::SCRIPT_URL . '" defer></script>', $tags);
            self::assertStringContainsString('<link rel="stylesheet" href="' . self::STYLE_URL . '">', $tags);
        }

        public function testTheModuleIsDeferredSoItNeverBlocksThePage(): void
        {
            self::assertStringContainsString('defer', $this->control()->headTags());
        }

        public function testTheHeadTagsCarryNoVersionSoTheyCannotBeStale(): void
        {
            // WordPress does not version these tags on the standalone token
            // pages, so a version query would be the only cache-buster available.
            self::assertStringNotContainsString('ver=', $this->control()->headTags());
        }

        public function testTheHeadTagsEscapeTheAssetUrls(): void
        {
            $tags = (new OcrControl('https://adct.org.za/"><b>x</b>.js', self::STYLE_URL))->headTags();

            self::assertStringNotContainsString('<b>', $tags);
            self::assertStringContainsString('&lt;b&gt;', $tags);
        }

        public function testTheControlRendersNoInlineScriptOrHandler(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            // The token pages emit no wp_head(), so an inline handler would be
            // the only thing keeping the control alive there. Keeping the
            // markup inert means the module is the single thing to get right.
            self::assertStringNotContainsString('onclick', $html);
            self::assertStringNotContainsString('javascript:', $html);
        }

        public function testTheControlDoesNotEmbedTheImageItself(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            // The bytes are served by the endpoint, never inlined as a data URI,
            // so the reviewer's browser fetches them only for this session.
            self::assertStringNotContainsString('data:image', $html);
        }

        private function control(): OcrControl
        {
            return new OcrControl(self::SCRIPT_URL, self::STYLE_URL);
        }
    }
}
