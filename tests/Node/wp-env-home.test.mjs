import assert from 'node:assert/strict';
import { tmpdir } from 'node:os';
import { isAbsolute, join } from 'node:path';
import test from 'node:test';
import { resolveWpEnvHome, stopWpEnvAfterTests } from '../../scripts/wp-env-home.mjs';

test('retains an explicit isolated home', () => {
  const home = join(tmpdir(), 'parish-intake-explicit-test');

  assert.equal(resolveWpEnvHome('/checkout/one', { WP_ENV_HOME: home }), home);
});

test('defaults to a stable distinct home per checkout', () => {
  const first = resolveWpEnvHome('/checkout/one', {});
  const second = resolveWpEnvHome('/checkout/two', {});

  assert.ok(isAbsolute(first));
  assert.equal(resolveWpEnvHome('/checkout/one', {}), first);
  assert.notEqual(first, second);
});

test('rejects relative homes', () => {
  assert.throws(
    () => resolveWpEnvHome('/checkout/one', { WP_ENV_HOME: 'shared-test-env' }),
    /WP_ENV_HOME must be an absolute path/
  );
});

test('keeps local environments running by default and stops in CI', () => {
  assert.equal(stopWpEnvAfterTests({}), false);
  assert.equal(stopWpEnvAfterTests({ CI: 'true' }), true);
  assert.equal(stopWpEnvAfterTests({ ADCT_PI_KEEP_WP_ENV_RUNNING: '0' }), true);
});

test('keeps its environment running when requested', () => {
  assert.equal(stopWpEnvAfterTests({ ADCT_PI_KEEP_WP_ENV_RUNNING: '1' }), false);
  assert.equal(stopWpEnvAfterTests({ CI: 'true', ADCT_PI_KEEP_WP_ENV_RUNNING: '1' }), false);
});

test('rejects an invalid keep-running setting', () => {
  assert.throws(
    () => stopWpEnvAfterTests({ ADCT_PI_KEEP_WP_ENV_RUNNING: 'yes' }),
    /ADCT_PI_KEEP_WP_ENV_RUNNING must be 0 or 1/
  );
});
