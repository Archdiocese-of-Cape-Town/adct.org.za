# Hosting environment

Production runs on **xneelo shared hosting**, alongside the main adct.org.za WordPress site.

## Known values (reported September 2026)

| Setting | Value |
|---|---|
| PHP | 8.2.33, 64-bit, `fpm-fcgi` |
| curl | 8.14.1, OpenSSL 3.5.7 |
| Database | MySQL-compatible: MariaDB 10.11.19 (mysqli / mysqlnd). This is the database the WordPress site already uses. |
| `max_execution_time` | 90 s |
| `memory_limit` | 256M |
| `upload_max_filesize` / `post_max_size` | 64M / 64M |
| `max_input_vars` | 3500 |
| `max_input_time` | -1 |
| Mail | xneelo mailboxes, IMAP over TLS |
| Cron jobs | At most **once every 2 hours**; at most **10 active cron jobs** per account |
| WP-CLI | **Not available** |
| Outbound email | **500 recipients per hour per hosting account or domain** (shared with the rest of adct.org.za) |
| SPF / DKIM | SPF passes if the record includes `include:spf.host-h.net`. DKIM signing needs **authenticated SMTP** through xneelo; plain PHP `mail()` often isn't signed. |
| Email size | **30 MB** per message, including attachments |
| Outbound HTTP(S) | Allowed |

Update this table whenever the host is upgraded.

## Design consequences

- **PHP 8.2 is the minimum.** CI also tests 8.3 and 8.4 so a host upgrade won't break the plugin.
- **No `ext-imap`.** It isn't installed and was removed from PHP core in 8.4. `MailboxInterface` is implemented by the built-in pure-PHP `ImapMailbox` over PHP stream sockets; it adds no Composer dependency and verifies TLS peers by default ([ADR 0013](decisions/0013-built-in-pure-php-imap-client.md)).
- **90 s time limit.** Every job has a ~60 s time budget, a lock and a checkpoint (see [architecture](architecture.md#scheduled-jobs)). Web requests never do polling or AI calls synchronously.
- **256M memory.** Large attachments are streamed to disk. Per-attachment size cap (default 15 MB). PDFs over a page limit are skipped for text extraction and flagged for manual entry.
- **Cron only every 2 hours, and no WP-CLI** ([ADR 0010](decisions/0010-scheduled-jobs-with-2-hour-cron-limit.md)). Keep WP-Cron **on** so site visits trigger jobs; do **not** set `DISABLE_WP_CRON`. Add one xneelo cron job every 2 hours as a backstop, calling `https://<site>/wp-cron.php?doing_wp_cron`. For timely intake, optionally set up a free external pinger (cron-job.org) that calls the same URL every 5–10 minutes. The Parish Intake → Scheduled jobs screen provides a nonce-checked "Run now" action for each registered job. Until mailbox and queue jobs are registered, its framework heartbeat does no intake work. See the [operator guide](operator-guide.md#keep-scheduled-jobs-running).
- **Email size.** Inbound messages can be up to 30 MB. Attachments over the per-attachment cap (15 MB) are skipped and flagged.
- **Composer production dependencies** are bundled into the release zip and namespace-prefixed with Strauss so they can't clash with other plugins. Development dependencies are not shipped.
- **Database: the site's existing MySQL database.** The plugin adds its own `wp_adct_pi_*` tables to the WordPress database through `$wpdb`, and needs no separate database. xneelo runs MariaDB 10.11, which is MySQL-compatible. Only SQL that works on both MySQL 8 and MariaDB 10.11 is used. JSON is stored in `longtext` with `JSON_VALID` checks (for `dbDelta` compatibility).
- **Mailbox space.** Processed mail is moved to a `Processed` folder and deleted after the retention period. The IMAP client checks message size before fetching its body and skips downloads over the configurable 30 MiB default. Raw copies needed for re-parsing are kept on disk under `wp-content/uploads/adct-parish-intake/` (protected by deny rules).
- **Outbound mail.** `wp_mail` sent through xneelo's **authenticated SMTP**, set up once for the whole site with an SMTP plugin (**FluentSMTP** recommended; free). This gives SPF and DKIM for every email the site sends, not only ours, so the parish intake plugin has **no SMTP settings of its own**. Its health dashboard warns if no SMTP plugin is set up (mail would fall back to PHP `mail()` without DKIM). Check that the adct.org.za SPF record includes `include:spf.host-h.net` and that DKIM is switched on in konsoleH. The 500/hour limit applies to the whole account or domain. So all plugin mail goes through a queue with a configurable hourly cap (default 100) and priorities: login links and confirmations are sent first, reminders and digests last ([ADR 0011](decisions/0011-outbound-email-queue-with-hourly-cap.md)). Outbound emails contain no attachments.

## Still to confirm (Phase 0 hosting spike)

Answered on 2026-09-24: the limits are per account/domain, and SPF/DKIM work through authenticated SMTP (see above). Also confirmed: **FluentSMTP is set up**, `wp_mail` works on the server, the Site Health loopback check passes (so WP-Cron runs), and the default cap of 100 emails an hour is right. Still open (none of these block the first build):

- Can a **temporary** staging instance (e.g. a subdomain with its own database and a test mailbox) be set up for the one-off pre-launch check, and removed afterwards? No permanent staging site is planned ([ADR 0009](decisions/0009-preview-and-test-environments.md)).
- Optional: send a test email from the site and check its headers show SPF and DKIM `pass`.

## Other mailbox providers

If a Hotmail/Outlook.com or Microsoft 365 mailbox is used later (e.g. for space), note that Microsoft has disabled basic (password) authentication for IMAP. It needs OAuth2 (XOAUTH2), which means an Azure app registration and token refresh. This is supported behind the `MailboxInterface` adapter but is **not** part of Phase 1.
