import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = fileURLToPath(new URL('..', import.meta.url));
const releaseZip = join(repositoryRoot, 'dist', 'adct-parish-intake.zip');
const npmCli = process.env.npm_execpath;

if (!existsSync(releaseZip)) {
  throw new Error('Build dist/adct-parish-intake.zip before running WordPress integration tests.');
}

if (!npmCli) {
  throw new Error('Run this harness with `npm run test:integration` or `composer test:integration`.');
}

const hasCustomWpEnvHome = Boolean(process.env.WP_ENV_HOME);
const wpEnvHome = hasCustomWpEnvHome
  ? resolve(process.env.WP_ENV_HOME)
  : join(tmpdir(), 'adct-parish-intake-wp-env');
const wpEnvCli = join(repositoryRoot, 'node_modules', '@wordpress', 'env', 'bin', 'wp-env');

if (!existsSync(wpEnvCli)) {
  throw new Error('Install Node dependencies before running WordPress integration tests.');
}

let temporaryConfigDirectory = null;
let wpEnvConfigDirectory = repositoryRoot;

if (hasCustomWpEnvHome) {
  const configCacheDirectory = join(repositoryRoot, 'node_modules', '.cache');
  mkdirSync(configCacheDirectory, { recursive: true });
  temporaryConfigDirectory = mkdtempSync(
    join(configCacheDirectory, 'adct-parish-intake-wp-env-')
  );
  wpEnvConfigDirectory = temporaryConfigDirectory;

  try {
    const wpEnvConfig = JSON.parse(
      readFileSync(join(repositoryRoot, '.wp-env.json'), 'utf8')
    );
    wpEnvConfig.mappings = Object.fromEntries(
      Object.entries(wpEnvConfig.mappings ?? {}).map(([destination, source]) => [
        destination,
        resolve(repositoryRoot, source),
      ])
    );
    writeFileSync(
      join(wpEnvConfigDirectory, '.wp-env.json'),
      `${JSON.stringify(wpEnvConfig, null, 2)}\n`
    );

    const defaultProjectHash = createHash('md5')
      .update(join(repositoryRoot, '.wp-env.json'))
      .digest('hex');
    const isolatedProjectHash = createHash('md5')
      .update(join(wpEnvConfigDirectory, '.wp-env.json'))
      .digest('hex');

    if (isolatedProjectHash === defaultProjectHash) {
      throw new Error('The isolated wp-env configuration must use a distinct project path.');
    }

    console.log(`Using isolated wp-env project ${isolatedProjectHash}.`);
  } catch (error) {
    rmSync(temporaryConfigDirectory, { recursive: true, force: true });
    throw error;
  }
}

const environment = {
  ...process.env,
  WP_ENV_HOME: wpEnvHome,
};

function runWpEnv(args) {
  const result = spawnSync(
    process.execPath,
    [wpEnvCli, ...args],
    {
      cwd: wpEnvConfigDirectory,
      env: environment,
      stdio: 'inherit',
    }
  );

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    throw new Error(`wp-env ${args.join(' ')} failed with exit code ${result.status ?? 'unknown'}.`);
  }
}

let startAttempted = false;
let failure = null;
let environmentStopped = false;

try {
  startAttempted = true;
  runWpEnv(['start']);
  runWpEnv([
    'run',
    'cli',
    'wp',
    'plugin',
    'install',
    '/var/www/html/wp-content/test-packages/adct-parish-intake.zip',
    '--force',
  ]);
  runWpEnv([
    'run',
    'cli',
    'wp',
    'eval',
    'if (in_array("adct-parish-intake/adct-parish-intake.php", (array) get_option("active_plugins", []), true)) { require_once ABSPATH . "wp-admin/includes/plugin.php"; deactivate_plugins("adct-parish-intake/adct-parish-intake.php", true); }',
  ]);
  runWpEnv([
    'run',
    'cli',
    'wp',
    'eval-file',
    '/var/www/html/wp-content/test-harness/verify-plugin.php',
  ]);
} catch (error) {
  failure = error;
} finally {
  try {
    if (startAttempted) {
      runWpEnv(['stop']);
      environmentStopped = true;
    }
  } catch (cleanupError) {
    if (failure === null) {
      failure = cleanupError;
    } else {
      console.error('Could not stop the isolated wp-env environment:', cleanupError);
    }
  }

  if (temporaryConfigDirectory && environmentStopped) {
    try {
      rmSync(temporaryConfigDirectory, { recursive: true, force: true });
    } catch (cleanupError) {
      if (failure === null) {
        failure = cleanupError;
      } else {
        console.error('Could not remove the temporary wp-env config:', cleanupError);
      }
    }
  } else if (temporaryConfigDirectory) {
    console.error(`Temporary wp-env config retained at ${temporaryConfigDirectory}.`);
  }
}

if (failure) {
  throw failure;
}
