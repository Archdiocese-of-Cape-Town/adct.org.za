# Anonymising parser fixtures

This repository is public and parish emails can contain personal and special personal information. The real source messages stay in their approved private storage. Do not copy a raw message or attachment into this repository, a chat, or an automated tool. **A fixture derived from a real message is fine and is the intended route** — see "Committing a derived fixture" below for what must be reduced first.

## Safe workflow

1. Work only in the approved private environment. Do not upload raw email, bulletin, poster, or attachment data to a public service or agent.
2. Re-create the message by hand using invented text and identifiers. Preserve only the layout or wording pattern needed to test parsing; do not keep original names, exact contact details, unique phrases, or irrelevant text.
3. Replace parish/church names with invented names such as `Example Parish` or `St Fictional`. Use `example.test` or `example.org` for email addresses. Use only clearly fictional phone numbers in the `021 555 01xx` range.
4. Remove personal names, private addresses, medical details, Mass-intention details, bank/account/card details, and any other personal or financial information. For skip-section tests, keep headings such as `Sick List` and `Mass Intentions`, but use obviously invented placeholder names and no real details. Never preserve a bank number, even partially.
5. Rebuild `.eml` headers with invented sender data, a synthetic subject, a fixed date with an explicit timezone, and a non-identifying message ID if one is needed. Do not retain original `Received`, authentication, routing, or provider headers.
6. Re-create attachments from the synthetic content rather than redacting and reusing originals. Remove document metadata, comments, revision history, hidden layers, image EXIF/GPS data, and embedded thumbnails. Use a placeholder attachment if the test only needs to model an image-only poster.
7. Review the final `.eml`, expected JSON, filenames, and any attachments manually. Search for original names, addresses, phone numbers, email domains, account data, and identifying phrases before adding the fixture pair.
8. Commit only the reduced fixture pair — the `.eml` and its `.expected.json`, plus any regenerated attachment. Never commit the source message, redaction notes, screenshots or working copies.

## Committing a derived fixture

A fixture derived from a real parish sample is the normal, expected outcome of this process and may be
committed. What makes a fixture unsafe is residual personal data or real infrastructure metadata, not the
fact that a real bulletin was the starting point. Reducing a sample to a safe fixture is mechanical and
reviewable, and a re-created fixture is usually more valuable than an invented one because it keeps the
oddity the test exists to catch.

Checklist before committing a derived fixture:

- the body retains only what the test needs — layout, wording pattern, column or header structure;
- every personal identifier, bank number, Mass intention and sick-list name is replaced, not masked;
- **headers are rebuilt, not scrubbed.** `Received` chains leak sending IPs and mail infrastructure, and
  a `Message-ID` leaks the sending domain. Use an `example.test` Message-ID and drop `Received` entirely;
- where a test needs a real header *shape* (`X-Mailer`, `Authentication-Results`, `Return-Path`), keep the
  header with synthetic values — see `tests/fixtures/inbound-mail/` for the pattern already in use;
- PDF and image attachments are **re-created** from the reduced content, never the original file, with
  document properties, revision history, hidden layers, EXIF/GPS and embedded thumbnails removed;
- a person has read the committed files, not just the working copy.

The committed result is a synthetic fixture, regardless of how faithfully it preserves layout. Record its
generic sample type in the fixture name and the provenance table in
[`tests/fixtures/README.md`](../tests/fixtures/README.md); record nothing that identifies a parish, a
person or a household. A human must verify the anonymisation before it is published.
