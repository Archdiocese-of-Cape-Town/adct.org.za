import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const projectRequire = createRequire(import.meta.url);
let runCLI;
try {
  const envRequire = createRequire(projectRequire.resolve('@wordpress/env'));
  ({ runCLI } = envRequire('@wp-playground/cli'));
} catch (error) {
  throw new Error('The Playground smoke requires the CLI supplied by @wordpress/env 12. Run npm ci with the approved dependency lockfile.', { cause: error });
}
assert.equal(typeof runCLI, 'function', 'The wp-env Playground dependency must expose the public runCLI API.');

const root = fileURLToPath(new URL('..', import.meta.url));
const blueprint = JSON.parse(readFileSync(new URL('../.github/playground/blueprint.json', import.meta.url)));
for (const step of blueprint.steps) {
  if (step.step === 'installPlugin') {
    assert.equal(step.pluginData.url, '{{ARTIFACT_URL:parish-intake}}');
    step.pluginData = {
      resource: 'literal',
      name: 'adct-parish-intake.zip',
      contents: new Uint8Array(readFileSync(new URL('../dist/adct-parish-intake.zip', import.meta.url))),
    };
  }
}

// Only test-site configuration differs from the published blueprint.
blueprint.steps.unshift({
  step: 'defineWpConfigConsts',
  consts: { DISABLE_WP_CRON: true, WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false },
});
blueprint.steps.unshift({
  step: 'writeFile',
  path: '/wordpress/wp-content/mu-plugins/adct-test-no-mail.php',
  data: "<?php add_filter('pre_wp_mail', '__return_false');",
});

const server = await runCLI({
  command: 'server',
  blueprint,
  php: blueprint.preferredVersions.php,
  wp: blueprint.preferredVersions.wp,
  port: Number(process.env.PLAYGROUND_PORT ?? 19588),
  internalCookieStore: true,
  workers: 6,
});
console.log(`Isolated Playground: ${server.serverUrl}`);
const auth = await server.playground.run({
  code: `<?php
    require '/wordpress/wp-load.php';
    echo json_encode([
      AUTH_COOKIE => wp_generate_auth_cookie(1, time() + 3600, 'auth'),
      LOGGED_IN_COOKIE => wp_generate_auth_cookie(1, time() + 3600, 'logged_in'),
    ], JSON_THROW_ON_ERROR);`,
});
assert.equal(auth.exitCode, 0, auth.errors);
const cookie = Object.entries(JSON.parse(auth.text)).map(([name, value]) => `${name}=${value}`).join('; ');

async function page(path, marker, options = {}) {
  const response = await server.playground.request({ url: path, headers: { Cookie: cookie }, ...options });
  const text = response.text;
  assert.equal(response.httpStatusCode, 200, response.errors + JSON.stringify(response.headers) + text);
  assert.doesNotMatch(response.errors, /Fatal|Uncaught|no such function|WordPress database error/i);
  assert.doesNotMatch(text, /critical error|Fatal error|Uncaught |WordPress database error/i);
  assert.ok(text.includes(marker), `Missing ${marker} on ${path}:\n${text}`);
  console.log(`Rendered ${path}`);
  return text;
}

try {
  const form = await page(blueprint.landingPage, 'Parse a message');
  await page('/wp-admin/admin.php?page=adct-parish-intake-review', 'Awaiting approval');
  const nonce = form.match(/name="adct_parish_intake_parse_nonce" value="([^"]+)"/)?.[1];
  assert.ok(nonce, 'The manual parser must provide its real WordPress nonce.');
  await page(blueprint.landingPage, 'Latest parse outcome', {
    method: 'POST',
    body: {
      adct_parish_intake_parse_nonce: nonce,
      adct_parish_intake_parse: '1',
      source_type: 'email',
      source_identifier: 'playground-regression',
      sender_email: 'fictional@example.test',
      subject: 'Fictional community gathering',
      body: 'A community gathering is on Sunday 11 October 2026 at 16:00.',
    },
  });
  await server.playground.writeFile('/wordpress/queue-json-check.php',
    readFileSync(new URL('../tests/Integration/ReviewQueueJsonCheck.php', import.meta.url), 'utf8'));
  const check = await server.playground.run({
    code: `<?php
      require '/wordpress/wp-load.php';
      global $wpdb;
      $parsed = $wpdb->get_row($wpdb->prepare(
        "SELECT title, extracted_payload FROM {$wpdb->prefix}adct_parish_intake_items WHERE source_identifier = %s",
        'playground-regression'
      ), ARRAY_A);
      if (! is_array($parsed) || trim((string) $parsed['title']) === '') {
        throw new RuntimeException('The manual parser did not persist its synthetic event.');
      }
      $fields = json_decode($parsed['extracted_payload'], true, 512, JSON_THROW_ON_ERROR);
      if (($fields['event_date'] ?? null) !== '2026-10-11') {
        throw new RuntimeException('The manual parser did not extract the synthetic event date.');
      }
      if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}adct_pi_mail_queue") !== 0) {
        throw new RuntimeException('The manual parser must not queue email.');
      }
      require '/wordpress/queue-json-check.php';
      ReviewQueueJsonCheck::run(static function ($message) { throw new RuntimeException($message); });
      echo 'queue-json-ok';`,
  });
  assert.equal(check.exitCode, 0, check.errors + check.text);
  assert.equal(check.text, 'queue-json-ok', check.errors + check.text);
  console.log('Synthetic queue JSON and sender-scope checks passed.');
  console.log(`Playground release smoke passed (${root}).`);
} catch (error) {
  if (process.env.ADCT_PI_KEEP_PLAYGROUND_RUNNING !== '1') {
    throw error;
  }
  console.error(error);
  process.exitCode = 1;
} finally {
  if (process.env.ADCT_PI_KEEP_PLAYGROUND_RUNNING === '1') {
    console.log(`Leaving isolated Playground running at ${server.serverUrl}.`);
  } else {
    await server[Symbol.asyncDispose]();
  }
}
