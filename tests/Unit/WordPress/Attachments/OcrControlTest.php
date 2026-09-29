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

    function esc_html(mixed $value): string
    {
        return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES, 'UTF-8');
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
        private const SETTINGS_SCRIPT_URL = 'https://adct.org.za/wp-content/plugins/adct-parish-intake/assets/ocr-settings.js';
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
            $tags = (new OcrControl(
                'https://adct.org.za/"><b>x</b>.js',
                self::STYLE_URL,
                self::SETTINGS_SCRIPT_URL
            ))->headTags();

            self::assertStringNotContainsString('<b>', $tags);
            self::assertStringContainsString('&lt;b&gt;', $tags);
        }

        public function testTheSettingsScriptUrlIsEscapedToo(): void
        {
            $tags = (new OcrControl(
                self::SCRIPT_URL,
                self::STYLE_URL,
                'https://adct.org.za/"><b>x</b>.js'
            ))->headTags();

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

        public function testTheHeadTagsLoadTheSettingsModuleBeforeTheModuleThatNeedsIt(): void
        {
            $tags = $this->control()->headTags();

            // Both are deferred, which preserves the order they are written in.
            // If the OCR module ever ran first it would find no layout list and
            // silently do nothing on every poster.
            $settings = strpos($tags, self::SETTINGS_SCRIPT_URL);
            $module = strpos($tags, self::SCRIPT_URL);

            self::assertIsInt($settings);
            self::assertIsInt($module);
            self::assertLessThan($module, $settings);
        }

        public function testTheSettingsPanelIsOfferedOnEveryControl(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            self::assertStringContainsString('data-adct-ocr-settings', $html);
            self::assertStringContainsString('data-adct-ocr-layout', $html);
            self::assertStringContainsString('data-adct-ocr-confidence', $html);
        }

        public function testTheSettingsPanelIsGroupedAndLabelledForAssistiveTechnology(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            self::assertStringContainsString('<fieldset', $html);
            self::assertStringContainsString('<legend', $html);
            self::assertStringContainsString('Reading settings', $html);
        }

        public function testEverySettingHasItsOwnVisibleLabel(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            // A label pointing at the control, and an id for the label to point
            // at. The placeholder text alone is not a label.
            self::assertStringContainsString('for="adct-ocr-settings-target-layout"', $html);
            self::assertStringContainsString('id="adct-ocr-settings-target-layout"', $html);
            self::assertStringContainsString('for="adct-ocr-settings-target-confidence"', $html);
            self::assertStringContainsString('id="adct-ocr-settings-target-confidence"', $html);
        }

        public function testTheLayoutChoicesAreOfferedInPlainWordsRatherThanTesseractNumbers(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            self::assertStringContainsString('Automatic', $html);
            self::assertStringContainsString('One tall column', $html);
            self::assertStringContainsString('A single line', $html);

            // The PSM numbers are machine values and belong in the markup for
            // the module, not in front of a parish secretary.
            foreach (['3', '11', '4', '6', '7', '8', '10'] as $psm) {
                self::assertStringNotContainsString('>' . $psm . '<', $html);
            }
        }

        public function testAutomaticIsTheFirstLayoutSoAnUntouchedControlBehavesAsItAlwaysHas(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            $selected = strpos($html, '<option value="auto"');
            $other = strpos($html, '<option value="sparse"');

            self::assertIsInt($selected);
            self::assertIsInt($other);
            self::assertLessThan($other, $selected);
        }

        public function testTheConfidenceSliderStartsAtZeroSoNothingIsHiddenByDefault(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            self::assertStringContainsString('type="range"', $html);
            self::assertStringContainsString('min="0"', $html);
            self::assertStringContainsString('max="100"', $html);
            self::assertStringContainsString('value="0"', $html);
        }

        public function testTheCurrentChoiceIsSpelledOutInWordsBeneathTheControls(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            // A reviewer who does not recognise "sparse text" needs a sentence
            // saying what the page is now being read as.
            self::assertStringContainsString('data-adct-ocr-readout', $html);
        }

        public function testThePanelSaysItIsOptionalSoNobodyFeelsTheyMustUseIt(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            self::assertStringContainsString('Optional', $html);
            self::assertStringContainsString('Leave these alone', $html);
        }

        public function testTwoPostersOnOnePageGetIndependentSettings(): void
        {
            $html = $this->control()->render('https://adct.org.za/one', 'adct-ocr-result-11')
                . $this->control()->render('https://adct.org.za/two', 'adct-ocr-result-12');

            // The Manual parser lists every unread image at once, so duplicate
            // ids would cross-wire one poster's slider to another's target.
            self::assertStringContainsString('id="adct-ocr-settings-adct-ocr-result-11-layout"', $html);
            self::assertStringContainsString('id="adct-ocr-settings-adct-ocr-result-12-layout"', $html);
        }

        public function testATargetNameCannotBreakOutOfTheGeneratedIds(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'a" onmouseover="alert(1)');

            // The target is escaped for its own attribute, and the ids derived
            // from it are reduced to safe characters as well.
            self::assertStringNotContainsString('onmouseover="alert(1)"', $html);
            self::assertStringContainsString('id="adct-ocr-settings-a-onmouseover-alert-1-layout"', $html);
        }

        public function testTheSettingsPanelRendersNoInlineHandlerOrScript(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            self::assertStringNotContainsString('onchange', $html);
            self::assertStringNotContainsString('oninput', $html);
            self::assertStringNotContainsString('<script', $html);
        }

        public function testTheSettingsPanelStoresNothingSoItCannotLeakAPreference(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            // No hidden input: nothing the reviewer picks is submitted with the
            // decision, and nothing is written anywhere (ADR 0017).
            self::assertStringNotContainsString('type="hidden"', $html);
        }

        public function testTheMarkupAndTheScriptAgreeOnEveryLayout(): void
        {
            $html = $this->control()->render('https://adct.org.za/image', 'target');

            preg_match_all('/<option value="([a-z]+)" data-psm="(\d+)">/', $html, $found, PREG_SET_ORDER);

            self::assertNotEmpty($found, 'The layout select rendered no options.');

            $fromMarkup = [];

            foreach ($found as $option) {
                $fromMarkup[$option[1]] = $option[2];
            }

            // The renderer writes the PSM values, and ocr-settings.js maps the
            // chosen name back to one. If the two lists drift, a reviewer picks
            // a layout and the poster is read with somebody else's setting.
            $module = file_get_contents(__DIR__ . '/../../../../assets/ocr-settings.js');

            self::assertIsString($module, 'assets/ocr-settings.js could not be read.');

            foreach ($fromMarkup as $value => $psm) {
                $pattern = "/value: '" . preg_quote($value, '/') . "', psm: '" . preg_quote($psm, '/') . "'/";

                self::assertMatchesRegularExpression(
                    $pattern,
                    $module,
                    sprintf('ocr-settings.js has no PSM %s for the "%s" layout.', $psm, $value)
                );
            }
        }

        private function control(): OcrControl
        {
            return new OcrControl(self::SCRIPT_URL, self::STYLE_URL, self::SETTINGS_SCRIPT_URL);
        }
    }
}
