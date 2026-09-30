/**
 * On-demand, client-side OCR for parish poster images (ADR 0018).
 *
 * Shared by the Manual parser screen and by the emailed action-token pages, so
 * it must not depend on any `wp.*` global: the token pages are rendered without
 * `wp_head()` and have no enqueued scripts.
 *
 * Nothing here runs until a person clicks. tesseract.js is roughly 12 MB with
 * its language data, so there is deliberately no preload, no worker start and no
 * CDN request on page load. The recognised text is shown in the page and then
 * discarded: it is never posted back to the server (ADR 0018).
 *
 * The reading settings live in ocr-settings.js, which is loaded first and holds
 * the pure decisions. This file is the plumbing: it finds the controls, keeps a
 * single tesseract worker alive between reads, and puts the text in front of
 * the reviewer.
 */
(function (root) {
    'use strict';

    var settings = root.AdctOcrSettings;

    if (!settings) {
        return;
    }

    /**
     * Pinned so a CDN change is a one-line edit and reproducible. Pass the
     * worker, core and language paths explicitly rather than relying on the
     * library's own defaults.
     */
    var TESSERACT_VERSION = '5.1.1';
    var CDN = 'https://cdn.jsdelivr.net/npm/tesseract.js@' + TESSERACT_VERSION + '/dist/';
    var LANG_PATH = 'https://cdn.jsdelivr.net/npm/@tesseract.js-data/eng@1.0.0/4.0.0_best_int';

    var HEADING = 'Text read from the poster image';
    var NOTICE = 'Read in your browser with Tesseract.js. Nothing was sent to the server. '
        + 'Check it against the poster before saving, then edit or delete it.';
    var FALLBACK = 'The text could not be read automatically. Please type the event details by hand '
        + 'from the poster below.';

    var loaded = null;

    /**
     * One worker for the whole page, shared by every poster on it. The Manual
     * parser lists all the unread images at once, and creating and terminating
     * a worker per click meant paying the download and the language load again
     * for the second poster. It is built only when the first read starts and
     * dropped only if that read fails, so an untouched page still costs
     * nothing.
     */
    var shared = null;

    /**
     * Reads are queued rather than run together. A worker carries one page of
     * engine state, so two simultaneous recognitions would race on the
     * page-segmentation mode — the very setting the panel now exposes.
     */
    var queue = Promise.resolve();

    function injectScript() {
        if (loaded) {
            return loaded;
        }

        loaded = new Promise(function (resolve, reject) {
            var element = document.createElement('script');

            element.src = CDN + 'tesseract.min.js';
            element.async = true;

            element.onload = function () {
                if (!window.Tesseract) {
                    reject(new Error('Tesseract did not load.'));
                    return;
                }

                resolve(window.Tesseract);
            };

            element.onerror = function () {
                reject(new Error('Tesseract could not be reached.'));
            };

            document.head.appendChild(element);
        });

        return loaded;
    }

    function worker() {
        if (!shared) {
            shared = injectScript()
                .then(function (Tesseract) {
                    return Tesseract.createWorker('eng', 1, {
                        workerPath: CDN + 'worker.min.js',
                        corePath: CDN,
                        langPath: LANG_PATH
                    });
                })
                .catch(function (error) {
                    // A failed download must not poison every later attempt.
                    shared = null;
                    throw error;
                });
        }

        return shared;
    }

    function progressLabel(message) {
        return message.charAt(0).toUpperCase() + message.slice(1) + '…';
    }

    /**
     * The field a control's text belongs in.
     *
     * `data-target` names a field, not a CSS selector. It is resolved as an
     * element id first and only then as a `name`, because the two surfaces
     * spell it differently: the Manual parser gives its textarea an id, while
     * the emailed token page gives it only a name. Treating the value as a
     * selector outright made `document.querySelector('adct_edit_description')`
     * parse as a tag name, match nothing, and silently discard the text read
     * from a poster on the approval page.
     *
     * Resolved through the DOM rather than a query string, so the value is
     * matched literally and can never carry a selector.
     */
    function findTarget(button) {
        var name = button.getAttribute('data-target');

        if (!name) {
            return null;
        }

        return document.getElementById(name) || document.getElementsByName(name)[0] || null;
    }

    /**
     * The settings panel belonging to a control. It sits inside the same
     * wrapper, so a page showing several posters keeps their settings apart.
     */
    function panelFor(button) {
        return button.parentNode ? button.parentNode.querySelector('[data-adct-ocr-settings]') : null;
    }

    function settingsFor(button) {
        var panel = panelFor(button);

        return panel
            ? settings.current(panel)
            : { layout: settings.DEFAULT_LAYOUT, confidence: settings.DEFAULT_CONFIDENCE };
    }

    function statusFor(button) {
        return button.parentNode ? button.parentNode.querySelector('.adct-ocr__status') : null;
    }

    /**
     * Put the text in the page, under a heading rather than over the field, so
     * anything already typed by hand survives. A second read replaces the first
     * block instead of stacking another copy underneath it, which is what makes
     * trying a different setting worth the click.
     */
    function showText(button, text) {
        var target = findTarget(button);

        if (!target) {
            return false;
        }

        var previous = target.querySelectorAll('.adct-ocr__result');

        for (var index = 0; index < previous.length; index++) {
            previous[index].parentNode.removeChild(previous[index]);
        }

        var block = document.createElement('div');
        var heading = document.createElement('p');
        var body = document.createElement('p');
        var notice = document.createElement('p');

        heading.textContent = HEADING;
        body.textContent = text;
        notice.textContent = NOTICE;

        block.className = 'adct-ocr__result';
        heading.className = 'adct-ocr__result-heading';
        notice.className = 'adct-ocr__notice';

        block.appendChild(heading);
        block.appendChild(body);
        block.appendChild(notice);
        target.appendChild(block);

        return true;
    }

    function showFallback(button, status) {
        status.textContent = FALLBACK;
        status.className = 'adct-ocr__status adct-ocr__status--error';
        button.disabled = false;
    }

    function reportBusy(button, status, message) {
        button.disabled = true;
        status.className = 'adct-ocr__status';
        status.textContent = message;
    }

    /**
     * Draw the text a recognition supports at the current settings, reusing the
     * lines already in memory — moving the confidence slider costs no download
     * and no second read.
     */
    function paint(button, status, result, chosen) {
        var text = settings.filterText(result, chosen.confidence);
        var dropped = settings.countBelow(result, chosen.confidence);

        if (text === '') {
            showFallback(button, status);
            return;
        }

        if (!showText(button, text)) {
            // Nothing to put it in. The poster was read, so say that plainly
            // rather than reporting success with no text on screen.
            status.textContent = 'The text was read, but there is nowhere on this page to show it. '
                + 'Please type the details by hand from the poster.';
            status.className = 'adct-ocr__status adct-ocr__status--error';
            button.disabled = false;
            return;
        }

        status.textContent = dropped > 0
            ? 'Done. ' + dropped + ' unsure ' + (dropped === 1 ? 'line was' : 'lines were')
                + ' hidden. Check the rest against the poster.'
            : 'Done. Check the text against the poster.';
        status.className = 'adct-ocr__status adct-ocr__status--done';
        button.disabled = false;
    }

    /**
     * The last recognition for a control, so the confidence slider can redraw
     * text that is already in hand. A WeakMap keeps this off the DOM and lets
     * a discarded poster's text be collected with the page.
     */
    var readings = typeof WeakMap === 'function' ? new WeakMap() : null;

    function remember(button, result) {
        if (readings) {
            readings.set(button, result);
        }
    }

    function recall(button) {
        return readings ? readings.get(button) || null : null;
    }

    function read(button, status) {
        var imageUrl = button.getAttribute('data-image-url');
        var chosen = settingsFor(button);
        var working = null;

        reportBusy(button, status, progressLabel('Loading the text reader'));

        return worker()
            .then(function (workerInstance) {
                working = workerInstance;

                // The page-segmentation mode is pushed into the live worker for
                // this read. Only the block and text outputs are requested: the
                // confidence control needs line data, and nothing here wants
                // hOCR or TSV, so neither is generated.
                return workerInstance.setParameters(settings.parametersFor(chosen.layout))
                    .then(function () {
                        return workerInstance.recognize(imageUrl, {}, { blocks: true, text: true });
                    });
            })
            .then(function (result) {
                remember(button, result);
                paint(button, status, result, chosen);
            })
            .catch(function () {
                if (working && typeof working.terminate === 'function') {
                    working.terminate();
                    shared = null;
                }

                showFallback(button, status);
            });
    }

    function run(button) {
        var status = statusFor(button);

        if (!button.getAttribute('data-image-url') || !status) {
            return Promise.resolve();
        }

        // Queued behind whatever is already waiting, so two posters on one page
        // cannot fight over the shared engine.
        queue = queue.then(function () {
            return read(button, status);
        });
    }

    /**
     * What to do when someone moves a control.
     *
     * A new layout needs the poster read again, which happens by itself — the
     * reviewer should not have to press the button a second time. A new
     * confidence threshold does not, because the lines are already in memory.
     */
    function onSettingsChange(control, chosen, layoutMoved) {
        var status = statusFor(control);
        var last = recall(control);

        if (!status || !last) {
            return;
        }

        if (layoutMoved) {
            run(control);
            return;
        }

        paint(control, status, last, chosen);
    }

    function start(button) {
        if (button.getAttribute('data-adct-ocr-started') === '1') {
            return;
        }

        button.setAttribute('data-adct-ocr-started', '1');

        button.addEventListener('click', function () {
            run(button);
        });

        var panel = panelFor(button);

        if (panel) {
            // The panel is told which control it belongs to, so a change can be
            // traced back to the poster it was made for.
            settings.attach(panel, function (chosen, layoutMoved) {
                onSettingsChange(button, chosen, layoutMoved);
            });
        }
    }

    function init() {
        var buttons = document.querySelectorAll('[data-adct-ocr]');

        for (var index = 0; index < buttons.length; index++) {
            start(buttons[index]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Exposed for the tests, which drive this module against a fake DOM rather
    // than a 12 MB download.
    root.AdctOcr = {
        findTarget: findTarget,
        showText: showText,
        paint: paint,
        settingsFor: settingsFor,
        TESSERACT_VERSION: TESSERACT_VERSION
    };
}(typeof self !== 'undefined' ? self : this));
