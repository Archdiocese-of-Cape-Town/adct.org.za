# ADR 0001: WordPress plugin on the existing shared host

- Status: Accepted
- Date: 2026-09-24

## Context
adct.org.za is a WordPress site on xneelo shared hosting. The people who run it are mostly non-technical. The system has to ingest parish communications, keep a parish directory, and publish events. Other options were a separate app (Node/Python on a VPS or serverless), SaaS automation (Zapier/Make) or a Google Sheets workflow.

## Decision
Build a single WordPress plugin (`adct-parish-intake`) that runs on the existing host. The domain logic is plain PHP 8.2 with no WordPress dependency, so it can be unit-tested and, if ever needed, moved elsewhere.

## Consequences
- Nothing new to host, pay for or monitor. Admins use the WordPress admin they already know. Events live alongside the public site.
- Shared-hosting limits apply: 90 s requests, no daemons, no Tesseract, no `ext-imap`. The design has to work around them with batching, cron, pure-PHP libraries and optional external services.
- Channels that need an always-on process (e.g. unofficial WhatsApp bots) are not possible on this host.
- Composer dependencies must be bundled and namespace-prefixed.
