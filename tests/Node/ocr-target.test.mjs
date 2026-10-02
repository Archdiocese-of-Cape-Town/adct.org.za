import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

/**
 * The two surfaces that show a poster name their target field differently: the
 * Manual parser gives its textarea an `id`, the emailed token page gives it only
 * a `name`. Both are exercised here, because getting this wrong does not fail
 * loudly — it throws the recognised text away and leaves the reviewer none the
 * wiser.
 */

const require = createRequire(import.meta.url);

/** Just enough DOM for the module to look a field up and write into it. */
class FakeElement {
  constructor(tag = 'div', attributes = {}) {
    this.tagName = tag.toUpperCase();
    this.attributes = { ...attributes };
    this.className = '';
    this.children = [];
    this.textContent = '';
    this.parentNode = null;
  }

  getAttribute(name) {
    return Object.prototype.hasOwnProperty.call(this.attributes, name)
      ? this.attributes[name]
      : null;
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  removeChild(child) {
    this.children = this.children.filter((one) => one !== child);
    child.parentNode = null;
    return child;
  }

  querySelectorAll(selector) {
    const wanted = selector.replace(/^\./, '');
    return this.children.filter((child) =>
      wanted ? child.className.split(/\s+/).includes(wanted) : true
    );
  }
}

/**
 * Load assets/ocr.js against a page that holds the given fields, and hand back
 * the module's own exports.
 *
 * Each load gets a fresh copy of the module: both files are IIFEs that attach
 * themselves to a global, so a cached copy would still be holding the previous
 * test's page.
 */
function loadOcr(fields) {
  const scope = {};
  const byId = {};
  const byName = {};

  for (const field of fields) {
    if (field.getAttribute('id')) {
      byId[field.getAttribute('id')] = field;
    }

    if (field.getAttribute('name')) {
      byName[field.getAttribute('name')] = field;
    }
  }

  globalThis.self = scope;
  globalThis.document = {
    readyState: 'loading',
    addEventListener() {},
    querySelectorAll: () => [],
    getElementById: (id) => byId[id] || null,
    getElementsByName: (name) => (byName[name] ? [byName[name]] : []),
    createElement: (tag) => new FakeElement(tag),
    head: new FakeElement('head')
  };

  for (const file of ['../../assets/ocr-settings.js', '../../assets/ocr.js']) {
    delete require.cache[require.resolve(file)];
    require(file);
  }

  return { ocr: scope.AdctOcr, settings: scope.AdctOcrSettings };
}

test('a target named by its id is found', () => {
  const field = new FakeElement('textarea', { id: 'adct-ocr-result-11' });
  const { ocr } = loadOcr([field]);
  const button = new FakeElement('button', { 'data-target': 'adct-ocr-result-11' });

  assert.equal(ocr.findTarget(button), field);
});

test('a target named only by its name is found too', () => {
  // This is the emailed approval page. Reading the name as a CSS selector made
  // `adct_edit_description` parse as a tag name, match nothing, and silently
  // discard every poster read there.
  const field = new FakeElement('textarea', { name: 'adct_edit_description' });
  const { ocr } = loadOcr([field]);
  const button = new FakeElement('button', { 'data-target': 'adct_edit_description' });

  assert.equal(ocr.findTarget(button), field);
});

test('an id wins over a name when both could match', () => {
  const byName = new FakeElement('textarea', { name: 'shared' });
  const byId = new FakeElement('textarea', { id: 'shared' });
  const { ocr } = loadOcr([byName, byId]);
  const button = new FakeElement('button', { 'data-target': 'shared' });

  assert.equal(ocr.findTarget(button), byId);
});

test('a target that is not on the page resolves to nothing rather than throwing', () => {
  const { ocr } = loadOcr([]);
  const button = new FakeElement('button', { 'data-target': 'adct_edit_description' });

  assert.equal(ocr.findTarget(button), null);
});

test('a button with no target is not a crash', () => {
  const { ocr } = loadOcr([]);

  assert.equal(ocr.findTarget(new FakeElement('button')), null);
});

test('the target name is matched literally and never read as a selector', () => {
  // A field whose name looks like a selector must match only itself, so no
  // crafted name can reach out and fill a field it does not own.
  const decoy = new FakeElement('textarea', { id: 'pwned' });
  const { ocr } = loadOcr([decoy]);
  const button = new FakeElement('button', { 'data-target': '#pwned' });

  assert.equal(ocr.findTarget(button), null);
});

test('reading a poster twice replaces the text instead of stacking copies', () => {
  // The point of trying another setting is seeing the difference, which a second
  // block underneath the first would hide.
  const field = new FakeElement('textarea', { id: 'target' });
  const { ocr } = loadOcr([field]);
  const button = new FakeElement('button', { 'data-target': 'target' });

  assert.equal(ocr.showText(button, 'first reading'), true);
  assert.equal(ocr.showText(button, 'second reading'), true);

  const blocks = field.querySelectorAll('.adct-ocr__result');

  assert.equal(blocks.length, 1);
  assert.equal(blocks[0].children[1].textContent, 'second reading');
});

test('the text is shown under a heading so typing is never overwritten', () => {
  const field = new FakeElement('textarea', { id: 'target' });
  const { ocr } = loadOcr([field]);
  const button = new FakeElement('button', { 'data-target': 'target' });

  ocr.showText(button, 'Parish Mass');

  const block = field.querySelectorAll('.adct-ocr__result')[0];

  assert.equal(block.children[0].textContent, 'Text read from the poster image');
  assert.equal(block.children[1].textContent, 'Parish Mass');
  assert.match(block.children[2].textContent, /Nothing was sent to the server/);
});

test('a control with no page to write to reports that instead of pretending', () => {
  const { ocr } = loadOcr([]);
  const button = new FakeElement('button', { 'data-target': 'adct_edit_description' });

  assert.equal(ocr.showText(button, 'Parish Mass'), false);
});

test('a control with no settings panel still reads as it always did', () => {
  const { ocr, settings } = loadOcr([]);

  assert.deepEqual(ocr.settingsFor(new FakeElement('button')), {
    layout: settings.DEFAULT_LAYOUT,
    confidence: settings.DEFAULT_CONFIDENCE
  });
});
