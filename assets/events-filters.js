(function () {
    var activeRequest = null;
    var locationAttempt = 0;
    var nextViewId = 0;
    var savedViews = Object.create(null);
    var locationState = null;

    function isLocationQueryKey(key) {
        return key.indexOf('near_') === 0 || key.indexOf('adct_near_me') === 0 ||
            key === 'latitude' || key === 'longitude' || key === 'suburb' || key === 'radius_km';
    }

    function copyLocation(state) {
        if (!state) {
            return null;
        }
        if (state.mode === 'browser') {
            return {
                mode: 'browser',
                latitude: Number(state.latitude),
                longitude: Number(state.longitude),
                radiusKm: String(state.radiusKm)
            };
        }
        if (state.mode === 'suburb') {
            return {
                mode: 'suburb',
                suburb: String(state.suburb),
                radiusKm: String(state.radiusKm)
            };
        }
        return null;
    }

    function paramsFromForm(form) {
        var params = {};
        new FormData(form).forEach(function (value, key) {
            if (key === 'adct_types[]' || key === 'adct_types') {
                if (!Array.isArray(params.adct_types)) {
                    params.adct_types = [];
                }
                params.adct_types.push(String(value));
            } else if (key.indexOf('adct_') === 0 && !isLocationQueryKey(key)) {
                params[key] = String(value);
            }
        });
        return params;
    }

    function paramsFromUrl(url) {
        var params = {};
        var types = [];
        url.searchParams.forEach(function (value, key) {
            if (/^adct_types(?:\[\d*\])?$/.test(key)) {
                types.push(value);
            } else if (key.indexOf('adct_') === 0 && !isLocationQueryKey(key)) {
                params[key] = value;
            }
        });
        if (types.length) {
            params.adct_types = types;
        }
        return params;
    }

    function buildTargetUrl(form, baseHref) {
        var url = new URL(baseHref, window.location.href);
        Array.from(url.searchParams.keys()).forEach(function (key) {
            if (key.indexOf('adct_') === 0 || isLocationQueryKey(key)) {
                url.searchParams.delete(key);
            }
        });
        new FormData(form).forEach(function (value, key) {
            if (key === 'page_id' || key === 'p') {
                url.searchParams.set(key, String(value));
            } else if (key.indexOf('adct_') === 0 && !isLocationQueryKey(key)) {
                url.searchParams.append(key, String(value));
            }
        });
        return url;
    }

    function pageUrlFor(url) {
        var pageUrl = new URL(url.origin + url.pathname);
        ['page_id', 'p'].forEach(function (key) {
            if (url.searchParams.has(key)) {
                pageUrl.searchParams.set(key, url.searchParams.get(key));
            }
        });
        return pageUrl.toString();
    }

    function writeHistory(url, state, action) {
        if (!action || !window.history || typeof window.history[action + 'State'] !== 'function') {
            return;
        }
        var id = String(++nextViewId);
        savedViews[id] = copyLocation(state);
        var historyState = {};
        if (window.history.state && typeof window.history.state === 'object') {
            Object.assign(historyState, window.history.state);
        }
        historyState.adctEventsView = id;
        window.history[action + 'State'](historyState, '', url.toString());
    }

    function applyLocationState(form, state, message) {
        if (!form) {
            return;
        }
        var suburb = form.querySelector('#adct-near-me-suburb');
        var radius = form.querySelector('[data-near-radius]');
        var status = form.querySelector('.adct-events__location-status');
        if (suburb) {
            suburb.value = state && state.mode === 'suburb' ? state.suburb : '';
        }
        if (radius) {
            radius.value = state ? String(state.radiusKm) : '25';
        }
        if (status) {
            if (message) {
                status.textContent = message;
            } else if (state && state.mode === 'browser') {
                status.textContent = 'Showing events near your location.';
            } else if (state && state.mode === 'suburb') {
                status.textContent = 'Showing events near ' + state.suburb + '.';
            } else {
                status.textContent = '';
            }
        }
    }

    function setLocationStatus(section, message) {
        var status = section.querySelector('.adct-events__location-status');
        if (status) {
            status.textContent = message;
        }
    }

    function locationForForm(form) {
        var state = copyLocation(locationState);
        var radius = form.querySelector('[data-near-radius]');
        if (state && radius) {
            state.radiusKm = String(radius.value);
        }
        return state;
    }

    function currentSelection(form, url) {
        return form ? paramsFromForm(form) : paramsFromUrl(url);
    }

    function load(section, target, options) {
        options = options || {};
        var form = section.querySelector('.adct-events__filters');
        var endpoint = section.getAttribute('data-endpoint');
        var url = new URL(target, window.location.href);
        var params = options.params || currentSelection(form, url);
        var location = copyLocation(options.location);
        var requestUrl = new URL(endpoint, window.location.href);
        var body = Object.assign({}, params, { page_url: pageUrlFor(url) });

        if (location) {
            body.near_mode = location.mode;
            body.near_radius_km = location.radiusKm;
            if (location.mode === 'browser') {
                body.near_latitude = location.latitude;
                body.near_longitude = location.longitude;
            } else {
                body.near_suburb = location.suburb;
            }
            setLocationStatus(section, 'Finding nearby events.');
        }

        if (activeRequest) {
            activeRequest.abort();
        }
        var controller = new AbortController();
        activeRequest = controller;
        fetch(requestUrl.toString(), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            signal: controller.signal,
            credentials: 'omit',
            cache: 'no-store',
            body: JSON.stringify(body)
        })
            .then(function (response) {
                return response.json().then(function (result) {
                    if (!response.ok) {
                        var error = new Error(result && result.message
                            ? result.message
                            : 'The event filters could not be loaded.');
                        error.status = response.status;
                        throw error;
                    }
                    return result;
                });
            })
            .then(function (result) {
                if (activeRequest !== controller) {
                    return;
                }
                var fragment = document.createElement('div');
                fragment.innerHTML = result.html;
                var replacement = fragment.querySelector('.adct-events');
                if (!replacement) {
                    throw new Error('The event filters returned an invalid result.');
                }
                section.replaceWith(replacement);
                locationState = copyLocation(location);
                applyLocationState(
                    replacement.querySelector('.adct-events__filters'),
                    location,
                    ''
                );
                writeHistory(url, location, options.historyAction);
                var status = replacement.querySelector('.adct-events__status');
                if (status) {
                    status.focus();
                }
            })
            .catch(function (error) {
                if (error.name === 'AbortError' || activeRequest !== controller) {
                    return;
                }
                activeRequest = null;
                if (location) {
                    setLocationStatus(section, error.message || 'Nearby events could not be loaded.');
                    return;
                }
                window.location.assign(url.toString());
            });
    }

    function requestLocation(form) {
        var section = form.closest('.adct-events');
        var suburb = form.querySelector('#adct-near-me-suburb');
        var radius = form.querySelector('[data-near-radius]');
        var attempt = ++locationAttempt;
        if (activeRequest) {
            activeRequest.abort();
            activeRequest = null;
        }
        setLocationStatus(section, 'Requesting your location.');

        function unavailable() {
            if (attempt !== locationAttempt) {
                return;
            }
            setLocationStatus(
                section,
                'Location access was denied or unavailable. Enter a suburb or parish instead.'
            );
            if (suburb) {
                suburb.focus();
            }
        }

        if (!navigator.geolocation || !navigator.geolocation.getCurrentPosition) {
            unavailable();
            return;
        }

        navigator.geolocation.getCurrentPosition(function (position) {
            if (attempt !== locationAttempt || !position || !position.coords) {
                return;
            }
            var latitude = Number(position.coords.latitude);
            var longitude = Number(position.coords.longitude);
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                unavailable();
                return;
            }
            var state = {
                mode: 'browser',
                latitude: latitude,
                longitude: longitude,
                radiusKm: radius ? String(radius.value) : '25'
            };
            if (suburb) {
                suburb.value = '';
            }
            locationState = copyLocation(state);
            var url = buildTargetUrl(form, window.location.href);
            load(section, url, {
                params: paramsFromForm(form),
                location: state,
                historyAction: 'push'
            });
        }, unavailable, {
            enableHighAccuracy: false,
            timeout: 10000,
            maximumAge: 0
        });
    }

    function requestSuburb(form) {
        var section = form.closest('.adct-events');
        var suburb = form.querySelector('#adct-near-me-suburb');
        var radius = form.querySelector('[data-near-radius]');
        var value = suburb ? suburb.value.trim() : '';
        locationAttempt += 1;
        if (!value) {
            setLocationStatus(section, 'Enter a suburb or parish from the local list.');
            if (suburb) {
                suburb.focus();
            }
            return;
        }
        var state = {
            mode: 'suburb',
            suburb: value,
            radiusKm: radius ? String(radius.value) : '25'
        };
        locationState = copyLocation(state);
        var url = buildTargetUrl(form, window.location.href);
        load(section, url, {
            params: paramsFromForm(form),
            location: state,
            historyAction: 'push'
        });
    }

    function initialiseHistory() {
        if (!window.history || typeof window.history.replaceState !== 'function') {
            return;
        }
        writeHistory(new URL(window.location.href), null, 'replace');
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.adct-events__filters');
        if (!form) {
            return;
        }
        event.preventDefault();
        locationAttempt += 1;
        var url = buildTargetUrl(form, window.location.href);
        var state = locationForForm(form);
        locationState = copyLocation(state);
        load(form.closest('.adct-events'), url, {
            params: paramsFromForm(form),
            location: state,
            historyAction: 'push'
        });
    });

    document.addEventListener('input', function (event) {
        if (!event.target || event.target.id !== 'adct-near-me-suburb') {
            return;
        }
        if (
            locationState
            && (locationState.mode !== 'suburb' || event.target.value.trim() !== locationState.suburb)
        ) {
            locationState = null;
        }
    });

    document.addEventListener('keydown', function (event) {
        if (
            event.key !== 'Enter'
            || !event.target
            || event.target.id !== 'adct-near-me-suburb'
        ) {
            return;
        }
        event.preventDefault();
        requestSuburb(event.target.closest('.adct-events__filters'));
    });

    document.addEventListener('click', function (event) {
        var nearMeButton = event.target.closest('.adct-events__near-me-button');
        if (nearMeButton) {
            event.preventDefault();
            requestLocation(nearMeButton.closest('.adct-events__filters'));
            return;
        }
        var suburbButton = event.target.closest('.adct-events__suburb-button');
        if (suburbButton) {
            event.preventDefault();
            requestSuburb(suburbButton.closest('.adct-events__filters'));
            return;
        }

        var link = event.target.closest('.adct-events nav a');
        if (!link || event.defaultPrevented || event.button !== 0 ||
            event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        locationAttempt += 1;
        var target = new URL(link.href, window.location.href);
        var state = copyLocation(locationState);
        load(link.closest('.adct-events'), target, {
            params: paramsFromUrl(target),
            location: state,
            historyAction: 'push'
        });
    });

    window.addEventListener('popstate', function (event) {
        var section = document.querySelector('.adct-events');
        if (!section) {
            return;
        }
        locationAttempt += 1;
        var url = new URL(window.location.href);
        var stateId = event.state && event.state.adctEventsView;
        var state = Object.prototype.hasOwnProperty.call(savedViews, stateId)
            ? copyLocation(savedViews[stateId])
            : null;
        locationState = copyLocation(state);
        load(section, url, {
            params: paramsFromUrl(url),
            location: state,
            historyAction: ''
        });
    });

    initialiseHistory();
}());
