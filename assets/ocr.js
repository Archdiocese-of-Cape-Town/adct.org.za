/**
 * On-demand, client-side OCR for parish poster images (ADR 0017).
 *
 * Shared by the Manual parser screen and by the emailed action-token pages, so
 * it must not depend on any `wp.*` global: the token pages are rendered without
 * `wp_head()` and have no enqueued scripts.
 *
 * Nothing here runs until a person clicks. tesseract.js is roughly 12 MB with
 * its language data, so there is deliberately no preload, no worker start and no
 * CDN request on page load. The recognised text is shown in the page and then
 * discarded: it is never posted back to the server (ADR 0017).
 */
(function () {
    'use strict';

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

    function progressLabel(message) {
        return message.charAt(0).toUpperCase() + message.slice(1) + '…';
    }

    function findTarget(button) {
        var selector = button.getAttribute('data-target');

        if (!selector) {
            return null;
        }

        return document.querySelector(selector);
    }

    /**
     * Appends under a heading instead of overwriting, so anything the person has
     * already typed by hand survives.
     */
    function appendText(target, text) {
        if (!target) {
            return false;
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

    function run(button) {
        var imageUrl = button.getAttribute('data-image-url');
        var status = button.parentNode.querySelector('.adct-ocr__status');

        if (!imageUrl || !status) {
            return;
        }

        button.disabled = true;
        status.className = 'adct-ocr__status';
        status.textContent = progressLabel('Loading the text reader');

        injectScript()
            .then(function (Tesseract) {
                return Tesseract.createWorker('eng', 1, {
                    workerPath: CDN + 'worker.min.js',
                    corePath: CDN,
                    langPath: LANG_PATH,
                    logger: function (event) {
                        if (event && event.status) {
                            status.textContent = progressLabel(event.status);
                        }
                    }
                });
            })
            .then(function (worker) {
                return worker.recognize(imageUrl).then(function (result) {
                    return worker.terminate().then(function () {
                        return result.data ? result.data.text : '';
                    });
                });
            })
            .then(function (text) {
                var value = (text || '').replace(/\s+$/, '');

                if (value === '') {
                    showFallback(button, status);
                    return;
                }

                appendText(findTarget(button), value);
                status.textContent = 'Done. Check the text against the poster.';
                status.className = 'adct-ocr__status adct-ocr__status--done';
                button.disabled = false;
            })
            .catch(function () {
                showFallback(button, status);
            });
    }

    function start(button) {
        if (button.getAttribute('data-adct-ocr-started') === '1') {
            return;
        }

        button.setAttribute('data-adct-ocr-started', '1');
        button.addEventListener('click', function () {
            run(button);
        });
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
}());
