# Anonymising parser fixtures

This repository is public and parish emails can contain personal and special personal information. The real source messages stay in their approved private storage. A person must create a new synthetic fixture from the needed structure; do not copy a raw message or attachment into this repository, a chat, or an automated tool.

## Safe workflow

1. Work only in the approved private environment. Do not upload raw email, bulletin, poster, or attachment data to a public service or agent.
2. Re-create the message by hand using invented text and identifiers. Preserve only the layout or wording pattern needed to test parsing; do not keep original names, exact contact details, unique phrases, or irrelevant text.
3. Replace parish/church names with invented names such as `Example Parish` or `St Fictional`. Use `example.test` or `example.org` for email addresses. Use only clearly fictional phone numbers in the `021 555 01xx` range.
4. Remove personal names, private addresses, medical details, Mass-intention details, bank/account/card details, and any other personal or financial information. For skip-section tests, keep headings such as `Sick List` and `Mass Intentions`, but use obviously invented placeholder names and no real details. Never preserve a bank number, even partially.
5. Rebuild `.eml` headers with invented sender data, a synthetic subject, a fixed date with an explicit timezone, and a non-identifying message ID if one is needed. Do not retain original `Received`, authentication, routing, or provider headers.
6. Re-create attachments from the synthetic content rather than redacting and reusing originals. Remove document metadata, comments, revision history, hidden layers, image EXIF/GPS data, and embedded thumbnails. Use a placeholder attachment if the test only needs to model an image-only poster.
7. Review the final `.eml`, expected JSON, filenames, and any attachments manually. Search for original names, addresses, phone numbers, email domains, account data, and identifying phrases before adding the fixture pair.
8. Commit only the synthetic `.eml` and its `.expected.json`; never commit source messages, redaction notes, screenshots, or working copies.

When adding a real-derived fixture, record only its generic sample type in the fixture name and documentation. A human must verify the anonymisation before it is published.
