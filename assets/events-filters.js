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
}());
