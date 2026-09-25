import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { Script, createContext } from 'node:vm';

const initialUrl = 'https://example.test/events/?page_id=123&adct_period=range' +
  '&adct_from=2026-10-01&adct_to=2026-10-31&adct_types%5B0%5D=3' +
  '&adct_types%5B1%5D=9&adct_parish=42&adct_deanery=7' +
  '&adct_collapse=1&adct_pin=1';

function createEnvironment({ geolocation, ignoreFetchAbort = false } = {}) {
  const listeners = {};
  const fetchCalls = [];
  const sectionHistory = [];
  let currentSection;
  let currentUrl = initialUrl;

  const entries = [
    ['page_id', '123'],
    ['adct_period', 'range'],
    ['adct_from', '2026-10-01'],
    ['adct_to', '2026-10-31'],
    ['adct_types[]', '3'],
    ['adct_types[]', '9'],
    ['adct_parish', '42'],
    ['adct_deanery', '7'],
    ['adct_collapse', '1'],
    ['adct_pin', '1'],
  ];
  const suburbField = {
    id: 'adct-near-me-suburb',
    value: '',
    focused: false,
    focus() {
      this.focused = true;
    },
    closest(selector) {
      return selector === '.adct-events__filters' ? currentSection.form : null;
    },
  };
  const radiusField = { value: '25' };
  const locationStatus = { textContent: '' };
  const listingStatus = {
    textContent: 'Showing events.',
    focused: false,
    focus() {
      this.focused = true;
    },
  };
  const nearMeButton = {};
  const suburbButton = {};

  function makeForm() {
    return {
      entries() {
        return entries.map(([key, value]) => [key, value]);
      },
      closest(selector) {
        if (selector === '.adct-events__filters') {
          return this;
        }
        if (selector === '.adct-events') {
          return currentSection;
        }
        return null;
      },
      querySelector(selector) {
        if (selector === '#adct-near-me-suburb') {
          return suburbField;
        }
        if (selector === '[data-near-radius]') {
          return radiusField;
        }
        if (selector === '.adct-events__location-status') {
          return locationStatus;
        }
        return null;
      },
    };
  }

  function makeSection(renderedHtml = '') {
    const form = makeForm();
    const section = {
      form,
      renderedHtml,
      getAttribute(name) {
        return name === 'data-endpoint'
          ? 'https://example.test/wp-json/adct-parish-intake/v1/events'
          : null;
      },
      querySelector(selector) {
        if (selector === '.adct-events__filters') {
          return form;
        }
        if (selector === '.adct-events__location-status') {
          return locationStatus;
        }
        if (selector === '.adct-events__status') {
          return listingStatus;
        }
        return null;
      },
      replaceWith(replacement) {
        if (currentSection !== section) {
          return;
        }
        currentSection = replacement;
        sectionHistory.push(replacement);
      },
    };
    return section;
  }

  currentSection = makeSection('initial');
  sectionHistory.push(currentSection);

  const historyEntries = [{ state: null, url: currentUrl }];
  const history = {
    state: null,
    index: 0,
    pushState(state, _title, url) {
      historyEntries.splice(this.index + 1);
      historyEntries.push({ state, url: String(url) });
      this.index += 1;
      this.state = state;
      currentUrl = String(url);
    },
    replaceState(state, _title, url) {
      historyEntries[this.index] = { state, url: String(url) };
      this.state = state;
      currentUrl = String(url);
    },
    back() {
      if (this.index === 0) {
        return;
      }
      this.index -= 1;
      this.state = historyEntries[this.index].state;
      currentUrl = historyEntries[this.index].url;
      listeners.popstate({ state: this.state });
    },
    forward() {
      if (this.index + 1 >= historyEntries.length) {
        return;
      }
      this.index += 1;
      this.state = historyEntries[this.index].state;
      currentUrl = historyEntries[this.index].url;
      listeners.popstate({ state: this.state });
    },
  };
  const document = {
    addEventListener(type, callback) {
      listeners[type] = callback;
    },
    createElement(tagName) {
      if (tagName !== 'div') {
        return { tagName };
      }
      let replacement;
      return {
        set innerHTML(value) {
          replacement = makeSection(value);
        },
        querySelector(selector) {
          return selector === '.adct-events' ? replacement : null;
        },
      };
    },
    querySelector(selector) {
      return selector === '.adct-events' ? currentSection : null;
    },
  };
  const sandbox = {
    AbortController,
    URL,
    FormData: class {
      constructor(form) {
        this.entriesList = form && typeof form.entries === 'function' ? form.entries() : [];
      }
      forEach(callback) {
        this.entriesList.forEach(([key, value]) => callback(value, key, this));
      }
    },
    document,
    navigator: geolocation === undefined ? {} : { geolocation },
    fetch(url, options) {
      return new Promise((resolve, reject) => {
        fetchCalls.push({ url, options, resolve, reject });
        if (!ignoreFetchAbort) {
          options.signal.addEventListener('abort', () => {
            const error = new Error('Aborted');
            error.name = 'AbortError';
            reject(error);
          }, { once: true });
        }
      });
    },
    window: {
      history,
      location: {
        get href() {
          return currentUrl;
        },
        assigned: [],
        assign(url) {
          this.assigned.push(url);
        },
      },
      addEventListener(type, callback) {
        listeners[type] = callback;
      },
    },
  };
  sandbox.window.document = document;
  sandbox.window.fetch = sandbox.fetch;
  sandbox.window.navigator = sandbox.navigator;
  sandbox.window.URL = URL;
  sandbox.window.FormData = sandbox.FormData;
  sandbox.window.AbortController = AbortController;
  sandbox.window.window = sandbox.window;
  sandbox.globalThis = sandbox;

  return {
    currentSection: () => currentSection,
    fetchCalls,
    historyEntries,
    history,
    locationStatus,
    listingStatus,
    nearMeButton,
    radiusField,
    sectionHistory,
    suburbButton,
    suburbField,
    sandbox,
    dispatch(type, event) {
      listeners[type](event);
    },
    dispatchSubmit() {
      let prevented = false;
      this.dispatch('submit', {
        target: { closest: (selector) => selector === '.adct-events__filters' ? currentSection.form : null },
        preventDefault() {
          prevented = true;
        },
      });
      return prevented;
    },
    dispatchInput() {
      this.dispatch('input', { target: suburbField });
    },
    dispatchClick(action, href) {
      const button = action === 'near' ? nearMeButton : suburbButton;
      const link = { href };
      const target = {
        closest(selector) {
          if (selector === '.adct-events__near-me-button' && action === 'near') {
            return button;
          }
          if (selector === '.adct-events__suburb-button' && action === 'suburb') {
            return button;
          }
          if (selector === '.adct-events nav a' && action === 'next') {
            return link;
          }
          return null;
        },
      };
      if (action === 'next') {
        link.closest = (selector) => selector === '.adct-events' ? currentSection : null;
      } else {
        button.closest = (selector) => {
          if (selector === '.adct-events__filters') {
            return currentSection.form;
          }
          if (selector === '.adct-events') {
            return currentSection;
          }
          return null;
        };
      }
      this.dispatch('click', {
        target,
        button: 0,
        defaultPrevented: false,
        preventDefault() {
          this.defaultPrevented = true;
        },
      });
    },
    async resolveFetch(index, { status = 200, html = 'listing', message = '' } = {}) {
      const call = fetchCalls[index];
      call.resolve({
        ok: status >= 200 && status < 300,
        status,
        json() {
          return Promise.resolve(status >= 200 && status < 300
            ? { html }
            : { code: 'adct_invalid_filter', message });
        },
      });
      await new Promise((resolve) => setImmediate(resolve));
    },
  };
}

function loadScript(environment) {
  const source = readFileSync(new URL('../assets/events-filters.js', import.meta.url), 'utf8');
  new Script(source).runInContext(createContext(environment.sandbox));
}

function bodyOf(call) {
  return JSON.parse(call.options.body);
}

function assertNoLocationInUrl(url) {
  const parsed = new URL(url);
  for (const key of parsed.searchParams.keys()) {
    assert.doesNotMatch(key, /near|latitude|longitude|suburb|radius/i);
  }
}

test('geolocation is requested only on activation and sent only in a no-store POST body', async () => {
  let geolocationCalls = 0;
  let success;
  const environment = createEnvironment({
    geolocation: {
      getCurrentPosition(onSuccess) {
        geolocationCalls += 1;
        success = onSuccess;
      },
    },
  });
  loadScript(environment);

  assert.equal(geolocationCalls, 0);
  assert.equal(environment.dispatchSubmit(), true);
  assert.equal(geolocationCalls, 0);
  assert.equal(environment.fetchCalls.length, 1);
  assert.equal(environment.fetchCalls[0].options.method, 'POST');
  await environment.resolveFetch(0);

  environment.dispatchClick('near');
  assert.equal(geolocationCalls, 1);
  assert.equal(environment.fetchCalls.length, 1);
  success({ coords: { latitude: -33.9258, longitude: 18.4232 } });

  const call = environment.fetchCalls[1];
  const body = bodyOf(call);
  assert.equal(call.options.cache, 'no-store');
  assert.match(call.options.headers['Content-Type'], /application\/json/);
  assert.equal(body.near_mode, 'browser');
  assert.equal(body.near_latitude, -33.9258);
  assert.equal(body.near_longitude, 18.4232);
  assert.equal(body.adct_period, 'range');
  assert.deepEqual(body.adct_types, ['3', '9']);
  assert.equal(body.adct_parish, '42');
  assert.equal(body.adct_deanery, '7');
  assert.equal(body.adct_collapse, '1');
  assert.equal(body.adct_pin, '1');
  assert.equal(body.page_url, 'https://example.test/events/?page_id=123');
  assertNoLocationInUrl(call.url);
  assertNoLocationInUrl(environment.historyEntries.at(-1).url);
  assert.equal(JSON.stringify(environment.historyEntries.at(-1).state).includes('-33.9258'), false);
  assert.equal(environment.suburbField.name, undefined);
  assert.equal(environment.radiusField.name, undefined);
  assert.equal(environment.currentSection().form.entries().some(([key]) => /near|latitude|longitude|suburb|radius/i.test(key)), false);

  await environment.resolveFetch(1, { html: 'nearby-results' });
  assert.equal(environment.currentSection().renderedHtml, 'nearby-results');
});

test('a pending filter response cannot detach the section while geolocation is pending', async () => {
  let browserSuccess;
  const environment = createEnvironment({
    geolocation: {
      getCurrentPosition(success) {
        browserSuccess = success;
      },
    },
    ignoreFetchAbort: true,
  });
  loadScript(environment);

  environment.dispatchSubmit();
  const filterRequest = environment.fetchCalls[0];
  environment.dispatchClick('near');

  await environment.resolveFetch(0, { html: 'stale-filter-results' });
  assert.equal(environment.currentSection().renderedHtml, 'initial');
  assert.equal(filterRequest.options.signal.aborted, true);

  browserSuccess({ coords: { latitude: -33.9258, longitude: 18.4232 } });
  assert.equal(environment.fetchCalls.length, 2);
  assert.equal(bodyOf(environment.fetchCalls[1]).near_mode, 'browser');
  await environment.resolveFetch(1, { html: 'nearby-results' });
  assert.equal(environment.currentSection().renderedHtml, 'nearby-results');
});

test('denied or unavailable geolocation exposes and focuses the local lookup without a location request', () => {
  const denied = createEnvironment({
    geolocation: {
      getCurrentPosition(_success, failure) {
        failure({ code: 1 });
      },
    },
  });
  loadScript(denied);
  denied.dispatchClick('near');
  assert.match(denied.locationStatus.textContent, /suburb or parish/i);
  assert.equal(denied.suburbField.focused, true);
  assert.equal(denied.fetchCalls.length, 0);

  const unavailable = createEnvironment();
  loadScript(unavailable);
  unavailable.dispatchClick('near');
  assert.match(unavailable.locationStatus.textContent, /unavailable/i);
  assert.equal(unavailable.suburbField.focused, true);
  assert.equal(unavailable.fetchCalls.length, 0);
});

test('suburb search is exclusive with browser coordinates and never enters a native GET form', async () => {
  let browserSuccess;
  const environment = createEnvironment({
    geolocation: {
      getCurrentPosition(success) {
        browserSuccess = success;
      },
    },
  });
  loadScript(environment);
  environment.dispatchClick('near');
  browserSuccess({ coords: { latitude: -33.9, longitude: 18.4 } });
  await environment.resolveFetch(0, { html: 'browser-results' });

  environment.suburbField.value = 'Exampleville';
  environment.dispatchInput();
  environment.radiusField.value = '50';
  environment.dispatchClick('suburb');
  const call = environment.fetchCalls[1];
  const body = bodyOf(call);
  assert.equal(body.near_mode, 'suburb');
  assert.equal(body.near_suburb, 'Exampleville');
  assert.equal(body.near_radius_km, '50');
  assert.equal(Object.hasOwn(body, 'near_latitude'), false);
  assert.equal(Object.hasOwn(body, 'near_longitude'), false);
  assertNoLocationInUrl(call.url);
  assertNoLocationInUrl(environment.historyEntries.at(-1).url);
  assert.equal(environment.currentSection().form.entries().some(([key]) => /near|latitude|longitude|suburb|radius/i.test(key)), false);
  await environment.resolveFetch(1, { html: 'suburb-results' });
  assert.equal(environment.currentSection().renderedHtml, 'suburb-results');
});

test('pressing Enter in the local lookup sends an ephemeral suburb POST', async () => {
  const environment = createEnvironment();
  loadScript(environment);
  environment.suburbField.value = 'Exampleville';
  let prevented = false;
  environment.dispatch('keydown', {
    target: environment.suburbField,
    key: 'Enter',
    preventDefault() {
      prevented = true;
    },
  });

  assert.equal(prevented, true);
  assert.equal(bodyOf(environment.fetchCalls[0]).near_mode, 'suburb');
  assert.equal(bodyOf(environment.fetchCalls[0]).near_suburb, 'Exampleville');
  assert.equal(environment.fetchCalls[0].options.cache, 'no-store');
  assertNoLocationInUrl(environment.fetchCalls[0].url);
});

test('Back and Forward restore the matching URL filters and in-memory location request', async () => {
  let browserSuccess;
  const environment = createEnvironment({
    geolocation: {
      getCurrentPosition(success) {
        browserSuccess = success;
      },
    },
  });
  loadScript(environment);
  const initialLength = environment.historyEntries.length;

  environment.dispatchClick('near');
  browserSuccess({ coords: { latitude: -33.9258, longitude: 18.4232 } });
  await environment.resolveFetch(0, { html: 'near-page-one' });
  const nearPageOneUrl = environment.historyEntries.at(-1).url;

  environment.dispatchClick(
    'next',
    'https://example.test/events/?page_id=123&adct_period=range&adct_from=2026-10-01' +
      '&adct_to=2026-10-31&adct_types%5B0%5D=3&adct_types%5B1%5D=9' +
      '&adct_parish=42&adct_deanery=7&adct_collapse=1&adct_pin=1&adct_page=2'
  );
  assert.equal(bodyOf(environment.fetchCalls[1]).near_mode, 'browser');
  assert.equal(bodyOf(environment.fetchCalls[1]).adct_page, '2');
  await environment.resolveFetch(1, { html: 'near-page-two' });

  environment.history.back();
  const backToPageOne = environment.fetchCalls[2];
  assert.equal(bodyOf(backToPageOne).near_mode, 'browser');
  assert.equal(Object.hasOwn(bodyOf(backToPageOne), 'adct_page'), false);
  assert.equal(new URL(backToPageOne.url).search, '');
  await environment.resolveFetch(2, { html: 'restored-near-page-one' });
  assert.equal(environment.currentSection().renderedHtml, 'restored-near-page-one');
  assert.match(environment.locationStatus.textContent, /near your location/i);

  environment.history.back();
  const backToInitial = environment.fetchCalls[3];
  assert.equal(Object.hasOwn(bodyOf(backToInitial), 'near_mode'), false);
  assert.equal(bodyOf(backToInitial).adct_period, 'range');
  await environment.resolveFetch(3, { html: 'initial-results' });
  assert.equal(environment.currentSection().renderedHtml, 'initial-results');
  assert.equal(environment.locationStatus.textContent, '');

  environment.history.forward();
  const forwardToNear = environment.fetchCalls[4];
  assert.equal(bodyOf(forwardToNear).near_mode, 'browser');
  assert.equal(bodyOf(forwardToNear).near_latitude, -33.9258);
  await environment.resolveFetch(4, { html: 'forwarded-near-page-one' });
  assert.equal(environment.currentSection().renderedHtml, 'forwarded-near-page-one');
  assert.equal(environment.historyEntries.length, initialLength + 2);
  for (const entry of environment.historyEntries) {
    assert.equal(JSON.stringify(entry.state).includes('-33.9258'), false);
    assertNoLocationInUrl(entry.url);
  }
});

test('suburb lookup errors remain on-page instead of falling back to a URL navigation', async () => {
  const environment = createEnvironment();
  loadScript(environment);
  environment.suburbField.value = 'Not In Directory';
  environment.dispatchClick('suburb');
  await environment.resolveFetch(0, {
    status: 400,
    message: 'Choose a suburb or parish from the local list.',
  });
  assert.match(environment.locationStatus.textContent, /local list/i);
  assert.equal(environment.sandbox.window.location.assigned.length, 0);
});
