import assert from 'node:assert/strict';
import { tmpdir } from 'node:os';
import { isAbsolute, join } from 'node:path';
import test from 'node:test';
import { resolveWpEnvHome } from '../../scripts/wp-env-home.mjs';

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
