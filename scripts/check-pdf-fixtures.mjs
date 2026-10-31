import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { lstatSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';

const fixtureDirectory = 'tests/fixtures/pdfs';
const sha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');

function git(...args) {
    const result = spawnSync('git', args, { maxBuffer: 16 * 1024 * 1024 });
    if (result.error || result.status !== 0) {
        throw new Error(`git ${args.join(' ')} failed: ${result.error?.message ?? result.stderr.toString('utf8')}`);
    }
    return result.stdout;
}

try {
    const args = process.argv.slice(2);
    if (args.length !== 0 && (args.length !== 2 || args[0] !== '--export' || args[1] === '')) {
        throw new Error('Usage: node scripts/check-pdf-fixtures.mjs [--export NEW_DIRECTORY]');
    }
    const root = git('rev-parse', '--show-toplevel').toString('utf8').trim();
    process.chdir(root);
    const manifest = JSON.parse(git('show', `:${fixtureDirectory}/sha256.json`).toString('utf8'));
    const paths = git('ls-files', '-z', '--', `${fixtureDirectory}/*.pdf`)
        .toString('utf8').split('\0').filter(Boolean).sort();
    if (manifest === null || typeof manifest !== 'object' || Array.isArray(manifest)
        || paths.length === 0 || JSON.stringify(paths.map((path) => basename(path)).sort()) !== JSON.stringify(Object.keys(manifest).sort())) {
        throw new Error('Indexed PDF fixture list differs from the checksum manifest.');
    }

    // Buffers bypass shell redirection, text decoding and Git's worktree stat cache.
    const fixtures = paths.map((path) => {
        if (path !== `${fixtureDirectory}/${basename(path)}`) {
            throw new Error(`Unexpected nested PDF fixture: ${path}`);
        }
        const indexed = git('show', `:${path}`);
        if (sha256(indexed) !== manifest[basename(path)]) {
            throw new Error(`Indexed PDF fixture ${path} differs from its checksum manifest; refusing export.`);
        }
        let actual = null;
        try {
            const local = join(root, path);
            if (!lstatSync(local).isFile()) {
                throw new Error(`PDF fixture is not a regular file: ${path}`);
            }
            actual = readFileSync(local);
        } catch (error) {
            if (error.code !== 'ENOENT') throw error;
        }
        if (actual === null || !actual.equals(indexed)) {
            const expanded = Buffer.from(indexed.toString('latin1').replaceAll('\n', '\r\n'), 'latin1');
            const reason = actual?.equals(expanded) ? 'exact LF-to-CRLF expansion' : 'different bytes (possibly intentional edits)';
            console.error(`${path}: worktree ${actual === null ? 'missing' : `${actual.length} bytes SHA256=${sha256(actual)}`}; index ${indexed.length} bytes SHA256=${sha256(indexed)}; ${reason}`);
        }
        return { path, indexed, actual };
    });

    if (args.length !== 0) {
        const destination = resolve(args[1]);
        // Non-recursive mkdir refuses existing directories, files and symlinks.
        mkdirSync(destination);
        for (const { path, indexed } of fixtures) {
            const output = join(destination, basename(path));
            writeFileSync(output, indexed, { flag: 'wx' });
            if (!readFileSync(output).equals(indexed)) {
                throw new Error(`Export verification failed: ${output}`);
            }
        }
        writeFileSync(join(destination, 'sha256.json'), JSON.stringify(manifest, null, 4) + '\n', { flag: 'wx' });
        console.log(`Exported ${fixtures.length} PDF fixtures to ${destination}. Originals were not modified. Preserve and review local files before any manual replacement.`);
    } else if (fixtures.some(({ indexed, actual }) => actual === null || !actual.equals(indexed))) {
        throw new Error('PDF fixture integrity failed. No files changed. Use --export NEW_DIRECTORY for verified copies; never reset or regenerate away local edits.');
    } else {
        console.log(`${fixtures.length} PDF fixtures match the indexed bytes and checksum manifest.`);
    }
} catch (error) {
    console.error(error.message);
    process.exitCode = 1;
}
