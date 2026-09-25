# ADR 0012: Pure-PHP MIME parsing with a prefixed Composer dependency

- Status: Accepted
- Date: 2026-09-25

## Context
Inbound parish mail arrives as raw RFC 822 messages. The host has no `ext-imap`, and a hand-written MIME parser would need to correctly handle nested multipart bodies, transfer encodings, charsets, headers and attachment metadata. HTML mail also needs useful plain text on hosting where `ext-dom` is not assumed.

## Decision
- Use the pre-approved `zbateson/mail-mime-parser` 3.x package behind the Core `Ingestion\MimeMessageParser` service. The package is pure PHP, supports PHP 8.1 and later (including 8.2–8.4), and uses the BSD-2-Clause license; its resolved runtime dependencies are permissively licensed.
- Convert HTML with the Core's pure-PHP tag tokenizer, not `DOMDocument`. Keep newsletter cleanup, quoted text, signatures and forwarded-message metadata in reusable Core support/value objects.
- Keep the dependency in Composer and Strauss-prefix it into `vendor-prefixed/` for release. Do not call PHP's IMAP functions or depend on `ext-imap` or `ext-dom`.
- Retain attachment metadata and a MIME-part reference only. Attachment content remains available through the stored raw message and is out of scope for E2.4.

## Consequences
- Common nested MIME, transfer encoding, header and charset edge cases are handled by a maintained parser without a native mail extension.
- The release zip includes the MIME parser and its transitive runtime packages; the build and bootstrap checks must continue to verify Strauss prefixing.
- HTML conversion and text cleanup remain application code and are covered by unit and golden-fixture tests.
