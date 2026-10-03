(function () {
    var activeRequest;

    function load(section, target, updateHistory) {
        var endpoint = section.getAttribute('data-endpoint');
        var url = new URL(target, window.location.href);
        var requestUrl = new URL(endpoint);
        url.searchParams.forEach(function (value, key) {
            if (key.indexOf('adct_') === 0) {
                requestUrl.searchParams.append(key, value);
            }
        });
        var pageUrl = new URL(url.origin + url.pathname);
        ['page_id', 'p'].forEach(function (key) {
            if (url.searchParams.has(key)) {
                pageUrl.searchParams.set(key, url.searchParams.get(key));
            }
        });
        requestUrl.searchParams.set('page_url', pageUrl.toString());
        if (activeRequest) {
            activeRequest.abort();
        }
        activeRequest = new AbortController();
        fetch(requestUrl.toString(), { signal: activeRequest.signal, credentials: 'omit' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('The event filters could not be loaded.');
                }
                return response.json();
            })
            .then(function (result) {
                var fragment = document.createElement('div');
                fragment.innerHTML = result.html;
                var replacement = fragment.querySelector('.adct-events');
                if (!replacement) {
                    throw new Error('The event filters returned an invalid result.');
                }
                section.replaceWith(replacement);
                if (updateHistory) {
                    window.history.pushState({}, '', url.toString());
                }
                var status = replacement.querySelector('.adct-events__status');
                if (status) {
                    status.focus();
                }
            })
            .catch(function (error) {
                if (error.name !== 'AbortError') {
                    window.location.assign(url.toString());
                }
            });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.adct-events__filters');
        if (!form) {
            return;
        }
        event.preventDefault();
        var url = new URL(window.location.href);
        Array.from(url.searchParams.keys()).forEach(function (key) {
            if (key.indexOf('adct_') === 0) {
                url.searchParams.delete(key);
            }
        });
        new FormData(form).forEach(function (value, key) {
            if (key === 'page_id' || key === 'p') {
                url.searchParams.set(key, value);
            } else {
                url.searchParams.append(key, value);
            }
        });
        load(form.closest('.adct-events'), url.toString(), true);
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('.adct-events nav a');
        if (!link || event.defaultPrevented || event.button !== 0 ||
            event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        load(link.closest('.adct-events'), link.href, true);
    });

    window.addEventListener('popstate', function () {
        var section = document.querySelector('.adct-events');
        if (section) {
            load(section, window.location.href, false);
        }
    });

    /**
     * The "Near me" button.
     *
     * The browser prompt appears only because a visitor pressed this button, never on page load.
     * The position is put in the page URL and sent to our own server in the same request as the
     * rest of the filters, and nowhere else: it is not stored in a cookie, in local storage, or in
     * a third-party service, and it is not logged (ADR 0020). If permission is refused, or the
     * browser will not ask at all, the suburb box is shown instead and the feature still works.
     */
    function useMyLocation(section, button) {
        var fallback = section.querySelector('[data-adct-nearme-fallback]');
        var status = section.querySelector('.adct-events__status');
        button.disabled = true;

        function showSuburbBox(message) {
            button.disabled = false;
            if (fallback) {
                fallback.hidden = false;
            }
            if (message !== '' && status) {
                status.textContent = message;
            }
            if (fallback) {
                var field = fallback.querySelector('input[name="adct_suburb"]');
                if (field) {
                    field.focus();
                }
            }
        }

        if (!navigator.geolocation) {
            showSuburbBox('This browser will not share your location, so please type your suburb instead.');
            return;
        }

        navigator.geolocation.getCurrentPosition(function (position) {
            var url = new URL(window.location.href);
            url.searchParams.delete('adct_suburb');
            url.searchParams.delete('adct_page');
            url.searchParams.set('adct_lat', position.coords.latitude.toFixed(6));
            url.searchParams.set('adct_lng', position.coords.longitude.toFixed(6));
            if (!url.searchParams.has('adct_radius_km')) {
                url.searchParams.set('adct_radius_km', '25');
            }
            load(section, url.toString(), true);
        }, function (error) {
            if (error && error.code === 1) {
                showSuburbBox('No problem. Type your suburb instead and we will sort the list from there.');
                return;
            }
            showSuburbBox('We could not work out where you are. Please type your suburb instead.');
        }, {
            enableHighAccuracy: false,
            timeout: 10000,
            maximumAge: 300000
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-adct-nearme-button]');
        if (!button) {
            return;
        }
        var section = button.closest('.adct-events');
        if (section) {
            useMyLocation(section, button);
        }
    });
}());
