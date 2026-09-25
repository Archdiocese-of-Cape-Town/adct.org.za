import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

export function prepareIsolatedWpEnvConfig(repositoryRoot, wpEnvHome) {
  const root = resolve(repositoryRoot);
  const home = resolve(wpEnvHome);
  const sourceConfigPath = join(root, '.wp-env.json');
  const config = JSON.parse(readFileSync(sourceConfigPath, 'utf8'));

  config.mappings = Object.fromEntries(
    Object.entries(config.mappings ?? {}).map(([destination, source]) => [
      destination,
      resolve(root, source),
    ])
  );

  const configContent = `${JSON.stringify(config, null, 2)}\n`;
  const identity = [
    normalizePathForIdentity(root),
    normalizePathForIdentity(home),
    configContent,
  ].join('\0');
  const configKey = createHash('sha256').update(identity).digest('hex').slice(0, 32);
  const configDirectory = join(
    root,
    'node_modules',
    '.cache',
    `adct-parish-intake-wp-env-${configKey}`
  );
  const configPath = join(configDirectory, '.wp-env.json');

  mkdirSync(configDirectory, { recursive: true });
  writeOrReuseConfig(configPath, configContent);

  const defaultProjectHash = hashConfigPath(sourceConfigPath);
  const projectHash = hashConfigPath(configPath);

  if (projectHash === defaultProjectHash) {
    throw new Error('The isolated wp-env configuration must use a distinct project path.');
  }

  return { configDirectory, projectHash };
}

function writeOrReuseConfig(configPath, configContent) {
  try {
    writeFileSync(configPath, configContent, { encoding: 'utf8', flag: 'wx' });
  } catch (error) {
    if (error?.code !== 'EEXIST') {
      throw error;
    }

    if (readFileSync(configPath, 'utf8') !== configContent) {
      throw new Error(
        'A conflicting isolated wp-env config already exists; refusing to overwrite it.'
      );
    }
  }
}

function normalizePathForIdentity(path) {
  return process.platform === 'win32' ? path.toLowerCase() : path;
}

function hashConfigPath(configPath) {
  return createHash('md5').update(configPath).digest('hex');
}
