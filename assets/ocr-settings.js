/**
 * The tunable settings of the on-demand OCR control (ADR 0018, extended).
 *
 * Split out of ocr.js and written as plain ES5 with no DOM access so the
 * decisions that matter — which page-segmentation mode a poster is read with,
 * and which lines survive the confidence cut — can be unit tested in Node
 * without a browser, a worker or a 12 MB language download.
 *
 * The file also renders the settings panel itself, because the two surfaces
 * (Manual parser and the emailed token pages) share this module and therefore
 * cannot drift apart.
 *
 * Nothing here is translated server side. English is what the plugin's own
 * strings are in, and a reviewer who cannot read the panel can leave every
 * control alone: the defaults reproduce the original behaviour exactly.
 */
(function (root, factory) {
    'use strict';

    var api = factory();

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }

    if (root) {
        root.AdctOcrSettings = api;
    }

    if (root && root.document) {
        api.init(root.document);
    }
}(typeof self !== 'undefined' ? self : null, function () {
    'use strict';

    /**
     * The page-segmentation modes a parish poster can plausibly need, in the
     * plain words a reviewer would use. The first entry must stay 'auto': it is
     * the default and it is what the control did before these settings existed,
     * so an untouched panel must behave exactly as it always has.
     *
     * The numeric values are the Tesseract PSM enum, taken from
     * tesseract.js 5.1.1 `src/constants/PSM.js` and mirrored as option values
     * in OcrControl::render(). Keeping them in this order means the panel
     * always offers the whole set.
     *
     * The labels are the ones the markup actually shows, so the readout and
     * the disclosure's tooltip name what the reviewer can see in the select
     * rather than a paraphrase of it. OcrControlTest::testTheMarkupAndThe
     * ScriptAgreeOnEveryLayout() checks the two lists against each other.
     */
    var LAYOUTS = [
        { value: 'auto', psm: '3', label: 'Automatic — works for most posters' },
        { value: 'sparse', psm: '11', label: 'Words spread across the page' },
        { value: 'column', psm: '4', label: 'One tall column' },
        { value: 'block', psm: '6', label: 'One block of text' },
        { value: 'line', psm: '7', label: 'A single line' },
        { value: 'word', psm: '8', label: 'A single word' },
        { value: 'char', psm: '10', label: 'A single character' }
    ];

    var DEFAULT_LAYOUT = 'auto';

    /**
     * Confidence is a percentage. Zero keeps everything, which makes the
     * default a no-op; anything higher drops the lines Tesseract was least sure
     * of, which is where misread names and dates hide.
     */
    var MIN_CONFIDENCE = 0;
    var MAX_CONFIDENCE = 100;
    var CONFIDENCE_STEP = 5;
    var DEFAULT_CONFIDENCE = 0;

    /**
     * The Tesseract parameter a layout maps to.
     *
     * `tessedit_pageseg_mode` is not one of the parameters tesseract.js refuses
     * to change after initialisation (worker-script/index.js keeps a short
     * blocklist of init-only names, and this is not on it), so it can be pushed
     * into a live worker between recognitions. That is what makes the panel a
     * control rather than decoration.
     */
    function parametersFor(layout) {
        return { tessedit_pageseg_mode: psmFor(layout) };
    }

    function psmFor(layout) {
        for (var index = 0; index < LAYOUTS.length; index++) {
            if (LAYOUTS[index].value === layout) {
                return LAYOUTS[index].psm;
            }
        }

        return psmFor(DEFAULT_LAYOUT);
    }

    /**
     * The plain-language name of a layout, or an empty string when the value is
     * not one of ours — so a doctored control cannot put arbitrary text into the
     * page.
     */
    function toLayout(value) {
        for (var index = 0; index < LAYOUTS.length; index++) {
            if (LAYOUTS[index].value === value) {
                return LAYOUTS[index].label;
            }
        }

        return '';
    }

    /**
     * True only for a value the panel actually offers, so a tampered select
     * falls back to the default rather than to a stray Tesseract mode.
     */
    function isKnownLayout(value) {
        return toLayout(value) !== '';
    }

    function isKnownConfidence(value) {
        var number = Number(value);

        return isFinite(number) && number >= MIN_CONFIDENCE && number <= MAX_CONFIDENCE;
    }

    /**
     * The text a confidence threshold leaves standing.
     *
     * Takes the recognition rather than its text, because the confidences live
     * alongside the lines in the result's block data and nowhere else.
     *
     * At the default threshold of zero this is the recognised text, trimmed at
     * the end only, so a reader sees exactly what they saw before this control
     * existed. Raising the threshold keeps only the lines Tesseract was at
     * least that sure about, in reading order, with the rest dropped rather
     * than shown as gaps: a reviewer wants the lines to trust, not a skeleton
     * with holes in it.
     *
     * A result with no line data at all is passed through untouched. Tesseract
     * can return text with `blocks` missing, and silently blanking a real
     * reading would be worse than not filtering it.
     */
    function filterText(result, threshold) {
        var body = textOf(result);
        var cut = isKnownConfidence(threshold) ? Number(threshold) : DEFAULT_CONFIDENCE;

        if (cut <= MIN_CONFIDENCE) {
            return trimTrailing(body);
        }

        var lines = linesFrom(result);

        if (lines === null) {
            return trimTrailing(body);
        }

        var kept = [];

        for (var index = 0; index < lines.length; index++) {
            if (lines[index].confidence >= cut) {
                kept.push(lines[index].text);
            }
        }

        return kept.join('\n');
    }

    function textOf(result) {
        if (typeof result === 'string') {
            return result;
        }

        if (result && result.data && typeof result.data.text === 'string') {
            return result.data.text;
        }

        return '';
    }

    /**
     * Flatten a recognition result into the lines a threshold applies to, or
     * null when the result carries no line data.
     */
    function linesFrom(result) {
        if (!result || !result.data || !result.data.blocks) {
            return null;
        }

        var lines = [];
        var blocks = result.data.blocks;
        var blockIndex;

        for (blockIndex = 0; blockIndex < blocks.length; blockIndex++) {
            var paragraphs = blocks[blockIndex].paragraphs || [];

            for (var paragraphIndex = 0; paragraphIndex < paragraphs.length; paragraphIndex++) {
                var blockLines = paragraphs[paragraphIndex].lines || [];

                for (var lineIndex = 0; lineIndex < blockLines.length; lineIndex++) {
                    lines.push({
                        text: blockLines[lineIndex].text || '',
                        confidence: numberOf(blockLines[lineIndex].confidence)
                    });
                }
            }
        }

        return lines.length > 0 ? lines : null;
    }

    function numberOf(value) {
        var number = Number(value);

        return isFinite(number) ? number : 0;
    }

    function trimTrailing(text) {
        return text.replace(/\s+$/, '');
    }

    /**
     * The lines of a result above a threshold, so the readout can say what was
     * dropped without the panel re-deriving it from raw text.
     */
    function countBelow(result, threshold) {
        var lines = linesFrom(result);
        var cut = isKnownConfidence(threshold) ? Number(threshold) : DEFAULT_CONFIDENCE;

        if (lines === null) {
            return 0;
        }

        var dropped = 0;

        for (var index = 0; index < lines.length; index++) {
            if (lines[index].confidence < cut) {
                dropped = dropped + 1;
            }
        }

        return dropped;
    }

    function meanConfidence(result) {
        if (!result || !result.data || result.data.confidence === null || result.data.confidence === undefined) {
            return null;
        }

        return numberOf(result.data.confidence);
    }

    /**
     * The tooltip on the folded-away disclosure.
     *
     * The panel is closed by default, so the number is only reachable by
     * hovering the summary. That keeps it out of a reviewer's way without
     * hiding it, which matters because the settings are otherwise
     * indistinguishable from guesswork: PSM 11 means something specific and
     * somebody will want to know it.
     */
    function tooltipFor(layout) {
        var name = toLayout(layout);

        return name + ' — Tesseract PSM ' + psmFor(layout);
    }

    /**
     * Wire one settings panel to a reader callback.
     *
     * The panel is inert markup; this is the only thing that makes it live, and
     * it registers no listener that touches the network. `onChange` is told
     * whether the layout moved, which is what decides between a free re-filter
     * of text already in hand and a fresh recognition.
     *
     * The disclosure itself is a native <details>, so the plugin adds no
     * open/close behaviour of its own: it only keeps the summary's tooltip
     * honest as the layout changes.
     */
    function attach(panel, onChange) {
        if (!panel || panel.getAttribute('data-adct-ocr-ready') === '1') {
            return;
        }

        panel.setAttribute('data-adct-ocr-ready', '1');

        var select = panel.querySelector('[data-adct-ocr-layout]');
        var slider = panel.querySelector('[data-adct-ocr-confidence]');
        var readout = panel.querySelector('[data-adct-ocr-readout]');
        var output = panel.querySelector('[data-adct-ocr-confidence-readout]');
        var summary = panel.querySelector('[data-adct-ocr-summary]');

        function report() {
            var chosen = current(panel);

            if (output) {
                output.textContent = chosen.confidence <= MIN_CONFIDENCE
                    ? 'Keep every line'
                    : 'Hide lines below ' + chosen.confidence + '% confidence';
            }

            if (readout) {
                readout.textContent = describe(chosen.layout, chosen.confidence);
            }

            if (summary) {
                summary.setAttribute('title', tooltipFor(chosen.layout));
            }
        }

        function changed(layoutMoved) {
            report();

            if (typeof onChange === 'function') {
                onChange(current(panel), layoutMoved === true);
            }
        }

        if (select) {
            select.addEventListener('change', function () {
                changed(true);
            });
        }

        if (slider) {
            // `input` rather than `change` so the text redraws while the slider
            // moves. Re-filtering costs no network and no recognition.
            slider.addEventListener('input', function () {
                changed(false);
            });
        }

        report();
    }

    function init(document_, onChange) {
        if (!document_ || typeof document_.querySelectorAll !== 'function') {
            return [];
        }

        var panels = document_.querySelectorAll('[data-adct-ocr-settings]');
        var attached = [];

        for (var index = 0; index < panels.length; index++) {
            attach(panels[index], onChange);
            attached.push(panels[index]);
        }

        return attached;
    }

    /**
     * What a panel is currently set to. Read straight from the controls, so
     * there is no second copy of the state to fall out of step.
     */
    function current(panel) {
        return {
            layout: defaultLayout(panel.querySelector('[data-adct-ocr-layout]')),
            confidence: defaultConfidence(panel.querySelector('[data-adct-ocr-confidence]'))
        };
    }

    function describe(layout, cut) {
        var name = toLayout(layout);

        if (cut <= MIN_CONFIDENCE) {
            return 'Reading as ' + name + '. Showing every line, however unsure.';
        }

        return 'Reading as ' + name + '. Hiding lines the reader is less than ' + cut
            + '% sure about.';
    }

    function defaultLayout(select) {
        if (select && isKnownLayout(select.value)) {
            return select.value;
        }

        return DEFAULT_LAYOUT;
    }

    function defaultConfidence(slider) {
        if (slider && isKnownConfidence(slider.value)) {
            return Number(slider.value);
        }

        return DEFAULT_CONFIDENCE;
    }

    return {
        LAYOUTS: LAYOUTS,
        DEFAULT_LAYOUT: DEFAULT_LAYOUT,
        DEFAULT_CONFIDENCE: DEFAULT_CONFIDENCE,
        MIN_CONFIDENCE: MIN_CONFIDENCE,
        MAX_CONFIDENCE: MAX_CONFIDENCE,
        CONFIDENCE_STEP: CONFIDENCE_STEP,
        parametersFor: parametersFor,
        psmFor: psmFor,
        toLayout: toLayout,
        tooltipFor: tooltipFor,
        isKnownLayout: isKnownLayout,
        isKnownConfidence: isKnownConfidence,
        filterText: filterText,
        countBelow: countBelow,
        meanConfidence: meanConfidence,
        attach: attach,
        current: current,
        init: init
    };
}));
