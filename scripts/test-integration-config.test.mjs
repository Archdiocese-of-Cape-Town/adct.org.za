import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { prepareIsolatedWpEnvConfig } from './wp-env-config.mjs';

const testRoot = mkdtempSync(join(tmpdir(), 'adct-pi-wp-env-config-'));
const repositoryRoot = join(testRoot, 'repository');
const wpEnvHome = join(testRoot, 'wp-env-home');

mkdirSync(repositoryRoot, { recursive: true });
writeFileSync(
  join(repositoryRoot, '.wp-env.json'),
  `${JSON.stringify({
    port: 18088,
    mappings: {
      'wp-content/test-packages': './dist',
      'wp-content/test-harness-fixtures': './tests/fixtures',
    },
  }, null, 2)}\n`
);

after(() => {
  rmSync(testRoot, { recursive: true, force: true });
});

test('reuses a stable config path and project hash for the same home', () => {
  const first = prepareIsolatedWpEnvConfig(repositoryRoot, wpEnvHome);
  const second = prepareIsolatedWpEnvConfig(repositoryRoot, wpEnvHome);
  const generatedConfig = JSON.parse(
    readFileSync(join(first.configDirectory, '.wp-env.json'), 'utf8')
  );

  assert.equal(first.configDirectory, second.configDirectory);
  assert.equal(first.projectHash, second.projectHash);
  assert.equal(
    first.projectHash,
    createHash('md5').update(join(first.configDirectory, '.wp-env.json')).digest('hex')
  );
  assert.notEqual(
    first.projectHash,
    createHash('md5').update(join(repositoryRoot, '.wp-env.json')).digest('hex')
  );
  assert.equal(
    generatedConfig.mappings['wp-content/test-packages'],
    join(repositoryRoot, 'dist')
  );
  assert.equal(
    generatedConfig.mappings['wp-content/test-harness-fixtures'],
    join(repositoryRoot, 'tests', 'fixtures')
  );
  assert.equal(existsSync(wpEnvHome), false);
});

test('uses a distinct project for a different wp-env home', () => {
  const first = prepareIsolatedWpEnvConfig(repositoryRoot, wpEnvHome);
  const second = prepareIsolatedWpEnvConfig(
    repositoryRoot,
    join(testRoot, 'another-wp-env-home')
  );

  assert.notEqual(first.configDirectory, second.configDirectory);
  assert.notEqual(first.projectHash, second.projectHash);
});

test('does not overwrite an existing config that it cannot verify', () => {
  const generatedConfig = prepareIsolatedWpEnvConfig(
    repositoryRoot,
    join(testRoot, 'user-config-home')
  );
  const configPath = join(generatedConfig.configDirectory, '.wp-env.json');
  const userConfig = '{"userOwned":true}\n';
  writeFileSync(configPath, userConfig);

  assert.throws(
    () => prepareIsolatedWpEnvConfig(repositoryRoot, join(testRoot, 'user-config-home')),
    /refusing to overwrite/i
  );
  assert.equal(readFileSync(configPath, 'utf8'), userConfig);
});

test('preserves a caller-provided wp-env home', () => {
  const callerHome = join(testRoot, 'caller-home');
  const markerPath = join(callerHome, 'keep.txt');
  mkdirSync(callerHome);
  writeFileSync(markerPath, 'caller data');

  prepareIsolatedWpEnvConfig(repositoryRoot, callerHome);

  assert.equal(readFileSync(markerPath, 'utf8'), 'caller data');
});
