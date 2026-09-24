# ADR 0002: Email intake via IMAP polling behind a mailbox adapter

- Status: Accepted
- Date: 2026-09-24

## Context
Phase 1 intake is email to `events@adct.org.za`. Options: IMAP polling, email piping to a script, a forwarding service with a webhook (Mailgun/SendGrid inbound parse), or provider APIs (Microsoft Graph, Gmail API). The xneelo host runs PHP 8.2 without `ext-imap`, and `ext-imap` is removed from PHP 8.4 core. The owner prefers xneelo mail because it has fewer breaking points, but may need a larger mailbox such as Hotmail later.

## Decision
- Poll an **xneelo IMAP mailbox over TLS** with a **pure-PHP IMAP client**. The library is chosen in a Phase 0 spike: candidates are `webklex/php-imap` or a small built-in client that covers only the commands we need (LOGIN, SELECT, UID SEARCH, UID FETCH, UID MOVE/COPY+STORE, EXPUNGE).
- Hide it behind `MailboxInterface` so OAuth2 providers (Outlook.com/Microsoft 365 via XOAUTH2, Gmail) can be added later without touching the pipeline.
- Keep the checkpoint per mailbox as UIDVALIDITY + last processed UID. De-duplicate on `Message-ID` and a content hash.
- Store the raw `.eml` on disk, move processed mail to a `Processed` folder, and delete it after retention to save mailbox space.
- Multiple addresses (e.g. `events@`, `news@`) can each be a source, or can forward into one mailbox.

## Consequences
- Works with no third-party services, using credentials the archdiocese already controls.
- Polling delay: new mail is picked up within about 5–10 minutes, which is fine for events.
- IMAP is tested with a throwaway GreenMail server in CI.
- OAuth mailboxes need extra work later (an app registration and token refresh).
