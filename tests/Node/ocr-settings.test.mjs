import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

// The module is written as a UMD file so a plain <script defer> tag can load it
// on the token pages, which have no bundler. Requiring it here exercises the
// same file the browser gets, not a copy of it.
const require = createRequire(import.meta.url);
const settings = require('../../assets/ocr-settings.js');

/**
 * A recognition shaped like the one tesseract.js 5.1.1 returns, with line
 * confidences a poster actually produces: the heading is unambiguous, the date
 * is not.
 */
function result(lines, confidence = 72) {
  return {
    data: {
      text: lines.map((line) => line.text).join('\n'),
      confidence,
      blocks: [
        {
          paragraphs: [
            {
              lines: lines.map((line) => ({
                text: line.text,
                confidence: line.confidence
              }))
            }
          ]
        }
      ]
    }
  };
}

test('the default layout is the one the control already used', () => {
  // PSM 3 is tesseract.js PSM.AUTO. If this ever moves, every poster starts
  // being read differently from the day the button was introduced.
  assert.equal(settings.DEFAULT_LAYOUT, 'auto');
  assert.deepEqual(settings.parametersFor('auto'), { tessedit_pageseg_mode: '3' });
});

test('the page segmentation mode is sent as the string tesseract expects', () => {
  // The PSM enum in tesseract.js 5.1.1 is made of strings, not numbers. A
  // numeric value is silently ignored and every layout would read as auto.
  for (const layout of settings.LAYOUTS) {
    assert.equal(typeof layout.psm, 'string');
    assert.match(layout.psm, /^\d+$/);
    assert.equal(settings.psmFor(layout.value), layout.psm);
  }
});

test('every offered layout is a different segmentation mode', () => {
  const modes = settings.LAYOUTS.map((layout) => layout.psm);

  assert.equal(new Set(modes).size, modes.length);
});

test('an unknown layout falls back to the default rather than a stray mode', () => {
  // A tampered or hand-edited select must not be able to put an arbitrary
  // Tesseract parameter into the worker.
  assert.equal(settings.psmFor('nonsense'), '3');
  assert.equal(settings.psmFor(''), '3');
  assert.equal(settings.isKnownLayout('nonsense'), false);
  assert.equal(settings.isKnownLayout('auto'), true);
});

test('layout names come back in the plain words the reviewer chose', () => {
  assert.match(settings.toLayout('column'), /column/i);
  assert.equal(settings.toLayout('nonsense'), '');
});

test('the folded-away tooltip names both the layout and its Tesseract number', () => {
  // The settings are hidden behind a disclosure, so the number has to be
  // reachable without opening anything. A tooltip that said only "Advanced"
  // would leave the panel unexplained and the choice unlookable.
  assert.match(settings.tooltipFor('auto'), /PSM 3/);
  assert.match(settings.tooltipFor('sparse'), /PSM 11/);
  assert.match(settings.tooltipFor('char'), /PSM 10/);

  // Both halves: the plain words for a reviewer, the number for anyone who
  // needs to look the setting up.
  assert.match(settings.tooltipFor('sparse'), /spread across the page/i);
});

test('the tooltip follows the default for an unknown layout rather than going blank', () => {
  assert.match(settings.tooltipFor('nonsense'), /PSM 3/);
  assert.match(settings.tooltipFor(''), /PSM 3/);
});



test('a threshold of zero keeps the text exactly as it was read', () => {
  const text = 'Parish Mass\nSaturday 12 October 2026\nSt Mary';

  assert.equal(settings.filterText({ data: { text } }, 0), text);
});

test('the default threshold hides nothing, so the control starts unchanged', () => {
  const text = 'Parish Mass\nSaturday 12 October 2026';

  assert.equal(settings.filterText({ data: { text } }, settings.DEFAULT_CONFIDENCE), text);
});

test('a raised threshold drops the lines the reader was least sure of', () => {
  const read = result([
    { text: 'Parish Mass', confidence: 96 },
    { text: 'Saturdav 12 Octoober 2O26', confidence: 41 },
    { text: 'St Mary Church', confidence: 88 }
  ]);

  // The date is the line most likely to be misread on a poster, and the whole
  // point of the control is being able to leave it out and check it by eye.
  assert.equal(settings.filterText(read, 0), read.data.text);
  assert.equal(settings.countBelow(read, 0), 0);

  const confident = settings.filterText(read, 70);

  assert.equal(confident, 'Parish Mass\nSt Mary Church');
  assert.equal(settings.countBelow(read, 70), 1);
});

test('the lines are kept in the order they were read', () => {
  const read = result([
    { text: 'first', confidence: 90 },
    { text: 'second', confidence: 20 },
    { text: 'third', confidence: 90 }
  ]);

  assert.equal(settings.filterText(read, 50), 'first\nthird');
});

test('a threshold above every line leaves nothing and says so', () => {
  const read = result([{ text: 'faint', confidence: 12 }]);

  assert.equal(settings.filterText(read, 95), '');
  assert.equal(settings.countBelow(read, 95), 1);
});

test('a line exactly at the threshold is kept', () => {
  const read = result([{ text: 'borderline', confidence: 80 }]);

  assert.equal(settings.filterText(read, 80), 'borderline');
  assert.equal(settings.countBelow(read, 80), 0);
});

test('lines are gathered from every block and paragraph of a poster', () => {
  // A poster is often read as several blocks, not one. Missing any of them
  // would hide text the reviewer needs.
  const read = {
    data: {
      text: 'kept one\ndropped\nkept two',
      blocks: [
        { paragraphs: [{ lines: [{ text: 'kept one', confidence: 90 }] }] },
        { paragraphs: [{ lines: [{ text: 'dropped', confidence: 10 }] }] },
        {
          paragraphs: [
            { lines: [{ text: 'kept two', confidence: 90 }] },
            { lines: [{ text: 'also dropped', confidence: 10 }] }
          ]
        }
      ]
    }
  };

  assert.equal(settings.filterText(read, 50), 'kept one\nkept two');
  assert.equal(settings.countBelow(read, 50), 2);
});

test('text with no line data is passed through rather than blanked', () => {
  // Tesseract can return a text with `blocks` missing. Silently emptying a
  // real reading would be worse than not filtering it at all.
  const bare = { data: { text: 'Parish Mass', confidence: 60 } };

  assert.equal(settings.filterText(bare, 90), 'Parish Mass');
  assert.equal(settings.countBelow(bare, 90), 0);
});

test('an empty result is handled without throwing', () => {
  assert.equal(settings.filterText('', 50), '');
  assert.equal(settings.filterText(null, 50), '');
  assert.equal(settings.filterText({ data: {} }, 50), '');
  assert.equal(settings.countBelow(null, 50), 0);
  assert.equal(settings.meanConfidence(null), null);
});

test('a threshold outside the slider range falls back to the default', () => {
  const read = { data: { text: 'Parish Mass\nSaturday' } };

  assert.equal(settings.filterText(read, 5000), 'Parish Mass\nSaturday');
  assert.equal(settings.filterText(read, -1), 'Parish Mass\nSaturday');
  assert.equal(settings.filterText(read, 'nonsense'), 'Parish Mass\nSaturday');
  assert.equal(settings.isKnownConfidence(101), false);
  assert.equal(settings.isKnownConfidence(0), true);
});

test('the slider range is one a person can actually land on', () => {
  // A slider in single-percent steps is unusable with a keyboard or a thumb.
  assert.equal(settings.MIN_CONFIDENCE, 0);
  assert.equal(settings.MAX_CONFIDENCE, 100);
  assert.ok(settings.CONFIDENCE_STEP >= 5, 'The slider moves in steps too small to hit.');
  assert.equal(settings.MAX_CONFIDENCE % settings.CONFIDENCE_STEP, 0);
});

test('the mean confidence is reported when there is one', () => {
  assert.equal(settings.meanConfidence(result([{ text: 'x', confidence: 50 }], 84)), 84);
  assert.equal(settings.meanConfidence({ data: { confidence: null } }), null);
});

test('initialising a page with no panels is not an error', () => {
  assert.deepEqual(settings.init(null), []);
  assert.deepEqual(settings.init({}), []);
  assert.deepEqual(settings.init({ querySelectorAll: () => [] }), []);
});
