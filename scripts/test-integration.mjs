import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { prepareIsolatedWpEnvConfig } from './wp-env-config.mjs';

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

let wpEnvConfigDirectory = repositoryRoot;

if (hasCustomWpEnvHome) {
  const isolatedConfig = prepareIsolatedWpEnvConfig(repositoryRoot, wpEnvHome);
  wpEnvConfigDirectory = isolatedConfig.configDirectory;
  console.log(`Using isolated wp-env project ${isolatedConfig.projectHash}.`);
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
    }
  } catch (cleanupError) {
    if (failure === null) {
      failure = cleanupError;
    } else {
      console.error('Could not stop the isolated wp-env environment:', cleanupError);
    }
  }
}

if (failure) {
  throw failure;
}
