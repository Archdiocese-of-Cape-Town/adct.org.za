# ADR 0014: MIME parser 4.x to keep wide transitive constraints

- Status: Accepted
- Date: 2026-09-28
- Supersedes: 0012 (the version line only; the rest of 0012 still stands)

## Context
`zbateson/mail-mime-parser` 3.0.7, the version pinned in `composer.lock`, was delisted from Packagist. `composer validate` does not report this, but any contributor running `composer update` hits a hard failure naming an exact-version match that no longer resolves.

The available replacements in the 3.x line narrow the package's own constraints. 3.0.8 and 3.0.9 declare `guzzlehttp/psr7: ^2.5` only, where 3.0.7 declared `^2.5 || ^3.0`. Staying on 3.0.8 therefore forces `guzzlehttp/psr7`, `zbateson/mb-wrapper` and `zbateson/stream-decorators` down a major version each. 4.0.6 restores the wide `^2.5 || ^3.0` constraints, so the current psr7 3.1.0 and mb-wrapper 3.0.1 are retained.

4.0.6 also carries the upstream security fixes for GHSA-gmgm-r6fh-fq6g (header injection through the message-building API) and GHSA-fcgh-j754-jh42 (uncontrolled resource consumption when parsing untrusted messages), plus the parse limits and constant-time boundary matching added across 4.0.2 to 4.0.6. The plugin parses mail from untrusted senders, so those limits are directly relevant.

Upstream's 4.0.0 release notes list no API breaking changes.

## Decision
- Move to `zbateson/mail-mime-parser` 4.0.6 and constrain it as `^4.0` in `composer.json`.
- Keep every other aspect of ADR 0012: the same Core `Ingestion\MimeMessageParser` service boundary, the same pure-PHP-only requirement, the same Strauss prefixing into `vendor-prefixed/`, and the same ban on `ext-imap` and `ext-dom`.
- Leave `MimeMessageParser` and `RawMessageInspector` source unchanged. Both resolve the parser class by name and use only methods that are present in 4.x.

## Consequences
- `composer update` resolves again, and the lock is pinned to a listed version.
- `guzzlehttp/psr7` stays on 3.x, so no major-version downgrade is forced on the transitive set.
- The API surface the project depends on is unchanged and verified: the 660-test suite and the parser smoke test pass on PHP 8.2, 8.3 and 8.4, and reflection confirms every called method exists in 4.0.6.
- 4.0.6's default parse limits (part count, nesting depth, header count, header size) are new caps on hostile input. Real parish mail sits far below them, and exceeding one now records a parse error instead of consuming unbounded resources.
- ADR 0012 stays on record and is not rewritten; its "3.x" wording is superseded by this record.
