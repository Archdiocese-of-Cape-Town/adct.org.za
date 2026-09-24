# Hosting environment

Production runs on **xneelo shared hosting**, alongside the main adct.org.za WordPress site.

## Known values (reported September 2026)

| Setting | Value |
|---|---|
| PHP | 8.2.33, 64-bit, `fpm-fcgi` |
| curl | 8.14.1, OpenSSL 3.5.7 |
| Database | MariaDB 10.11.19 (mysqli / mysqlnd) |
| `max_execution_time` | 90 s |
| `memory_limit` | 256M |
| `upload_max_filesize` / `post_max_size` | 64M / 64M |
| `max_input_vars` | 3500 |
| `max_input_time` | -1 |
| Mail | xneelo mailboxes, IMAP over TLS |

Update this table whenever the host is upgraded.

## Design consequences

- **PHP 8.2 is the minimum.** CI also tests 8.3 and 8.4 so a host upgrade won't break the plugin.
- **No `ext-imap`.** It isn't installed and was removed from PHP core in 8.4. Mail is read with a pure-PHP IMAP client over TLS sockets (library chosen in a spike; see backlog).
- **90 s time limit.** Every job has a ~60 s time budget, a lock and a checkpoint (see [architecture](architecture.md#scheduled-jobs)). Web requests never do polling or AI calls synchronously.
- **256M memory.** Large attachments are streamed to disk. Per-attachment size cap (default 15 MB). PDFs over a page limit are skipped for text extraction and flagged for manual entry.
- **WP-Cron needs a real trigger.** Set up a konsoleH cron job every 5 minutes calling `wp-cron.php` (or WP-CLI if available) and set `define('DISABLE_WP_CRON', true);` in `wp-config.php`.
- **Composer dependencies** are bundled into the release zip and namespace-prefixed (Strauss or PHP-Scoper) so they can't clash with other plugins.
- **MariaDB 10.11** supports JSON columns/functions; JSON is still stored in `longtext` with `JSON_VALID` checks for `dbDelta` compatibility.
- **Mailbox space.** Processed mail is moved to a `Processed` folder and deleted after the retention period. Raw copies needed for re-parsing are kept on disk under `wp-content/uploads/adct-parish-intake/` (protected by deny rules).
- **Outbound mail.** `wp_mail` through xneelo SMTP (an SMTP plugin, or the plugin's own SMTP settings). Confirmation emails are low-volume (~tens per day), so they stay well within shared-host sending limits. SPF/DKIM for adct.org.za must include the sending server.

## Still to confirm (Phase 0 hosting spike)

- Is a konsoleH cron job available for this site, and at what minimum interval?
- Is WP-CLI available over SSH?
- Outbound SMTP rate limits per hour/day.
- Can a staging subdomain (`staging.adct.org.za`) with its own database and a test mailbox be created?
- Are outbound HTTPS calls to external APIs (OpenRouter, OCR.space, Google ICS) allowed? (curl is present, so this is expected to work.)

## Other mailbox providers

If a Hotmail/Outlook.com or Microsoft 365 mailbox is used later (e.g. for space), note that Microsoft has disabled basic (password) authentication for IMAP. It needs OAuth2 (XOAUTH2), which means an Azure app registration and token refresh. This is supported behind the `MailboxInterface` adapter but is **not** part of Phase 1.
