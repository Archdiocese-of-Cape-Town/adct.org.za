import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, rmSync, utimesSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import test from 'node:test';

const script = resolve('scripts/check-pdf-fixtures.mjs');
const bytes = readFileSync('tests/fixtures/pdfs/single-column-poster.pdf');
const hash = (data) => createHash('sha256').update(data).digest('hex');

function repository(t) {
    const root = mkdtempSync(join(tmpdir(), 'adct-pdf-'));
    t.after(() => rmSync(root, { recursive: true, force: true }));
    const git = (...args) => {
        const result = spawnSync('git', args, { cwd: root, encoding: 'utf8' });
        assert.equal(result.status, 0, result.stderr);
        return result.stdout;
    };
    git('init', '--quiet');
    git('config', 'user.email', 'fixture@example.test');
    git('config', 'user.name', 'Fixture test');
    git('config', 'core.autocrlf', 'false');
    mkdirSync(join(root, 'tests/fixtures/pdfs'), { recursive: true });
    const path = join(root, 'tests/fixtures/pdfs/poster.pdf');
    writeFileSync(path, bytes);
    writeFileSync(join(root, 'tests/fixtures/pdfs/sha256.json'), JSON.stringify({ 'poster.pdf': hash(bytes) }));
    writeFileSync(join(root, '.gitattributes'), '*.pdf binary\n');
    git('add', '.');
    git('commit', '--quiet', '-m', 'Synthetic fixture');
    const run = (...args) => spawnSync(process.execPath, [script, ...args], { cwd: root, encoding: 'utf8' });
    return { root, git, path, run };
}

test('valid bytes pass without writing anything', (t) => {
    const { git, run } = repository(t);
    const result = run();
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /1 PDF fixture/);
    assert.equal(git('status', '--porcelain'), '');
});

test('detects CRLF corruption even when status trusts cached worktree state', (t) => {
    const { path, git, run } = repository(t);
    writeFileSync(path, Buffer.from(bytes.toString('latin1').replaceAll('\n', '\r\n'), 'latin1'));
    // Model Git trusting cached state; the checker must ignore stat/skip flags.
    git('update-index', '--assume-unchanged', 'tests/fixtures/pdfs/poster.pdf');
    assert.equal(git('status', '--porcelain'), '');
    const result = run();
    assert.equal(result.status, 1);
    assert.match(result.stderr, /poster\.pdf.*942 bytes.*906 bytes/s);
    assert.match(result.stderr, /CRLF/);
    assert.equal(readFileSync(path).length, 942);
});

test('binary attributes prevent new conversions but do not repair an old clean checkout', (t) => {
    const { root, path, git, run } = repository(t);
    writeFileSync(join(root, '.gitattributes'), '*.pdf text eol=crlf\n');
    git('add', '.gitattributes');
    git('add', '--renormalize', 'tests/fixtures/pdfs/poster.pdf');
    git('commit', '--quiet', '-m', 'Model old text attributes');
    rmSync(path);
    git('checkout-index', '--', 'tests/fixtures/pdfs/poster.pdf');
    assert.equal(readFileSync(path).length, 942);
    // Staging under the old text rule stores LF in the blob but caches the CRLF
    // worktree size. Changing attributes later does not invalidate those bytes.
    // Give the file an older mtime so Git does not deliberately smudge a racy
    // index entry's cached size to zero on this fast disposable reproduction.
    utimesSync(path, new Date('2020-01-01T00:00:00Z'), new Date('2020-01-01T00:00:00Z'));
    git('add', 'tests/fixtures/pdfs/poster.pdf');
    assert.equal(git('status', '--porcelain'), '');
    writeFileSync(join(root, '.gitattributes'), '*.pdf binary\n');
    git('add', '.gitattributes');
    // A tree/ref update, unlike porcelain commit's refresh, leaves the old
    // cached PDF stat entry intact when binary attributes arrive.
    const tree = git('write-tree').trim();
    const commit = git('commit-tree', tree, '-p', 'HEAD', '-m', 'Prevent future PDF conversion').trim();
    git('update-ref', 'HEAD', commit);
    assert.equal(readFileSync(path).length, 942);
    assert.equal(git('status', '--porcelain'), '', git('ls-files', '--debug', 'tests/fixtures/pdfs/poster.pdf'));
    assert.equal(run().status, 1);
    rmSync(path);
    git('checkout-index', '--', 'tests/fixtures/pdfs/poster.pdf');
    assert.deepEqual(readFileSync(path), bytes);
    assert.equal(run().status, 0);
});

test('same-size arbitrary edits are detected, not called CRLF damage', (t) => {
    const { path, run } = repository(t);
    const edited = Buffer.from(bytes);
    edited[100] ^= 1;
    writeFileSync(path, edited);
    const result = run();
    assert.equal(result.status, 1);
    assert.match(result.stderr, /different bytes/);
});

test('explicit export preserves local edits and exports exact indexed bytes', (t) => {
    const { root, path, run } = repository(t);
    writeFileSync(path, 'local work to keep');
    const destination = join(root, 'recovery');
    const result = run('--export', destination);
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(readFileSync(join(destination, 'poster.pdf')), bytes);
    assert.equal(readFileSync(path, 'utf8'), 'local work to keep');
    assert.match(result.stdout, /Originals were not modified/);
});

test('export refuses any existing destination without overwriting', (t) => {
    const { root, run } = repository(t);
    const destination = join(root, 'recovery');
    mkdirSync(destination);
    writeFileSync(join(destination, 'poster.pdf'), 'keep me');
    const result = run('--export', destination);
    assert.equal(result.status, 1);
    assert.equal(readFileSync(join(destination, 'poster.pdf'), 'utf8'), 'keep me');
});

test('missing files fail detection but can be exported without recreating originals', (t) => {
    const { root, path, run } = repository(t);
    rmSync(path);
    assert.equal(run().status, 1);
    assert.equal(run('--export', join(root, 'recovery')).status, 0);
    assert.throws(() => readFileSync(path));
});

test('staged corrupt bytes cannot be exported as trusted fixtures', (t) => {
    const { root, path, git, run } = repository(t);
    writeFileSync(path, 'corrupt staged data');
    git('add', 'tests/fixtures/pdfs/poster.pdf');
    const result = run('--export', join(root, 'recovery'));
    assert.equal(result.status, 1);
    assert.match(result.stderr, /manifest/);
    assert.throws(() => readFileSync(join(root, 'recovery/poster.pdf')));
});

test('unknown arguments fail rather than triggering writes', (t) => {
    const { run } = repository(t);
    assert.equal(run('--repair').status, 1);
});
