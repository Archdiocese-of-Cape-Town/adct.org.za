import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { isAbsolute, join } from 'node:path';

export function resolveWpEnvHome(repositoryRoot, environment = process.env) {
  const checkoutId = createHash('sha256').update(repositoryRoot).digest('hex').slice(0, 10);
  const home = environment.WP_ENV_HOME || join(tmpdir(), `adct-pi-${checkoutId}`);

  if (!isAbsolute(home)) {
    throw new Error('WP_ENV_HOME must be an absolute path to an isolated test environment.');
  }

  return home;
}

export function stopWpEnvAfterTests(environment = process.env) {
  const setting = environment.ADCT_PI_KEEP_WP_ENV_RUNNING;

  if (setting === undefined) {
    return environment.CI === 'true';
  }

  if (setting === '0') {
    return true;
  }

  if (setting === '1') {
    return false;
  }

  throw new Error('ADCT_PI_KEEP_WP_ENV_RUNNING must be 0 or 1.');
}
