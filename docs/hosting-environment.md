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

## Behaviour documented by xneelo (verified against public sources)

These are the provider's own published rules, not measurements of the archdiocese account. They were read from xneelo's Help Centre and Hetzner's konsoleH documentation in September 2026. They explain *how* the confirmed values above arise and *what the limits are*; they do not tell us how many of them the account has left.

| Fact | Source |
|---|---|
| Cron jobs may run **no more than once every 2 hours**, and an account may have at most **10 cron jobs**. Jobs that exceed the resource limits in the Acceptable Use Policy are terminated. | [Cronjob Manager (konsoleH)](https://xneelo.co.za/help-centre/website/cronjob-manager/), [Manage your cronjobs via the xneelo Control Panel](https://xneelo.co.za/help-centre/control-panel/manage-your-cronjobs-via-the-xneelo-control-panel/) |
| Cron jobs can be added, edited, deleted, and **run manually** ("Run now"), and can e-mail a notification address with the result. An Advanced view shows the raw crontab line and any log file. | [Cronjob Manager (konsoleH)](https://xneelo.co.za/help-centre/website/cronjob-manager/) |
| A cron command is an ordinary shell line, e.g. `lynx -dump http://<domain>/path/script.php`, or a PHP script via `/usr/bin/php-wrapper /usr/www/users/<FTP-user>/path/script.php`. | [Cronjob Manager (konsoleH)](https://xneelo.co.za/help-centre/website/cronjob-manager/) |
| A subdomain is added in konsoleH under **Services → Subdomains**, and its target **must be a directory**, not a file or external URL. If the directory does not exist it is created under `/public_html/`. A new subdomain can take **up to 24 hours** to become reachable, and can be edited or deleted in the same list. | [Subdomain administration](https://docs.hetzner.com/managed/domain-and-dns/subdomain/) |
| **To create a mailbox for a subdomain, the subdomain must first be an addon domain** ("Multiple domain"). Hetzner notes you may need to delete the subdomain before recreating it as an addon domain. | [Subdomain administration](https://docs.hetzner.com/managed/domain-and-dns/subdomain/) |
| A Multiple (addon) domain has **no setup fee** and **includes one mailbox**, with its own web files but sharing the parent account's disk, database and traffic quotas. FTP is through the parent account. Quotas: Basic 1, Standard 5, Advanced 10, Master 20. | [How to order a Multiple domain](https://xneelo.co.za/help-centre/products-and-services/multiple-domain/) |
| Mailboxes are managed under **Email → Mailboxes** for the selected **Domain** (not the hosting name), via **New Mailbox**, entering only the part before the `@`. The full address is the username for webmail and IMAP. **Deleting a mailbox cannot be undone and loses all mail in it.** | [Mailbox management](https://docs.hetzner.com/managed/email/mailbox-features/mailbox-management/) |
| Databases are created in konsoleH under **Databases → Manage MySQL → Add**. The generated password is displayed **once** and is not stored by xneelo, so it must be recorded at creation. Deleting a database **cannot be undone**. phpMyAdmin is provided. | [Create and manage a MySQL database](https://xneelo.co.za/help-centre/website/managing-website/mysql/how-do-i-create-and-manage-a-mysql-database-2/) |
| SSD-backed MySQL/MariaDB databases per web-hosting package: **Basic 3, Standard 5, Advanced 10, Master 40**. Mailboxes: 100 / 250 / 500 / 1000. Subdomains are listed separately from, and do not consume, the Multiple-domain allowance. | [Web hosting packages](https://xneelo.co.za/web-hosting/) |
| Outgoing mail server is `smtp.<your-domain>` on **port 465 with SSL** (port 587 without SSL on the Option 3 SMTP service); IMAP is port 993 with SSL. The exact hostname is shown in konsoleH under the domain's **Hosting Server**. | [Email settings](https://xneelo.co.za/help-centre/email/email-settings/) |
| **SSH is disabled by default** and must be activated on request; it is free on all web-hosting packages. Only the **main FTP user** may use it, on port **2222**. This does not change the "no WP-CLI" finding — see the caveat below. | [How to SSH to your hosting server](https://xneelo.co.za/help-centre/website/ssh-to-your-hosting-server/) |
| xneelo's documented SPF value is `v=spf1 mx a include:spf.host-h.net ~all`, and a domain must have **exactly one** SPF record (merge providers into it). DKIM records are TXT records added via **Domain Tools → Manage DNS**; "all new hosting packages will include DKIM authentication by default". | [How to add an SPF record](https://xneelo.co.za/help-centre/control-panel/spf/), [How to add DKIM records](https://xneelo.co.za/help-centre/control-panel/add-dkim-records-mail/) |
| The Acceptable Use Policy prohibits spam and unsolicited bulk email and cites POPIA and ECTA, with suspension and blacklisting as consequences. This is the policy behind the hourly recipient cap in [ADR 0011](decisions/0011-outbound-email-queue-with-hourly-cap.md). | [Acceptable Use Policy](https://xneelo.co.za/legal/acceptable-use-policy/) |

**Caveat on SSH.** SSH being *available on request* does not contradict the confirmed "no WP-CLI" answer: SSH gives a restricted shell on the shared host, not WP-CLI, and every operation still needs a screen or button because there is no WP-CLI to run them with. It is recorded here only so the "no shell access" assumption is not built into any design.

## Outbound third-party hosts

Only one of these is actually contacted by the server today, and the plugin's own code is the reason:

| Host | Is the server calling it? | Evidence |
|---|---|---|
| `calendar.google.com` | **No.** Only the browser is. The plugin builds an "Add to Google Calendar" URL as a string and returns it to the page; there is no server-side request. | `src/Core/Events/EventPresentation.php` (base constant `GOOGLE_CALENDAR_BASE`); the URL is composed for the visitor |
| `api.ocr.space` | **No, and not planned.** OCR is client-side only per [ADR 0018](decisions/0018-client-side-ocr-for-image-posters.md): tesseract.js runs in the browser and the extracted text is never sent back or stored. Server-side/queue OCR is E8.3 ([#75](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/75)) and is not approved. | No occurrence of `ocr.space` anywhere under `src/` |
| `openrouter.ai` | **Yes — the only real server-side egress.** It is the AI provider endpoint, used only when an AI provider is configured. | `src/WordPress/Ai/OpenAiCompatibleProvider.php` (`DEFAULT_URL = 'https://openrouter.ai/api/v1'`); `src/WordPress/Http/WordPressHttpClient.php` is the single `wp_remote_post()` wrapper in the codebase |

**What public evidence does and does not show.** In September 2026 all three hostsnames resolved in DNS and answered HTTPS with a valid certificate and an HTTP 200 when contacted from an ordinary client on an arbitrary network. That establishes the services exist and are not blocked at the public-internet level. It does **not** establish that the xneelo web server can reach them: shared hosts apply per-account egress rules and network ACLs that no public page documents, and the archdiocese's own firewall or mail-provider restrictions would not show up in a test from outside. Reachability **from the host itself** is therefore still open — see [Still to confirm](#still-to-confirm-phase-0-hosting-spike) below. Note also that if the AI feature stays off (AI is optional; offline parsing is the default), the plugin makes no outbound request at all and this question becomes moot for launch.

## Design consequences

- **PHP 8.2 is the minimum, and nothing deprecated in PHP 8.3 or later may be used.** CI enforces 8.3 and 8.4 so a host upgrade won't break the plugin, and runs 8.5 as an advisory `continue-on-error` job. The version is a floor, never a ceiling: don't cap `composer.json` with an upper bound, or the plugin would refuse to install after a host upgrade.
- **No `ext-imap`.** It isn't installed and was removed from PHP core in 8.4. `MailboxInterface` is currently implemented by the built-in pure-PHP `ImapMailbox` over PHP stream sockets; it adds no Composer dependency and verifies TLS peers by default. [ADR 0017](decisions/0017-library-first-protocol-and-format-handling.md) supersedes the built-in-client choice in [ADR 0013](decisions/0013-built-in-pure-php-imap-client.md) and calls for a library-backed replacement.
- **Mailbox settings and connection checks.** The Parish Intake → Mailboxes screen uses port 993 with SSL/TLS by default and always verifies the server certificate in production. Use the exact hostname shown on the certificate, not a custom alias; the screen's Test connection action checks access and folder configuration but does not poll or process mail.
- **90 s time limit.** Every job has a ~60 s time budget, a lock and a checkpoint (see [architecture](architecture.md#scheduled-jobs)). Web requests never do polling or AI calls synchronously.
- **256M memory.** Large attachments are streamed to disk. Per-attachment size cap (default 15 MB), which is also the PDF text-extraction size limit; PDFs over the 10 page limit or the 10 second per-file budget are skipped and flagged for manual entry. One message's PDFs also share a 30 second budget, so the 5 PDFs it may carry can never spend more than half of the job's ~60 s allowance on extraction. No native PDF tool (`pdftotext`, Ghostscript) or OCR binary is available, so extraction is pure PHP (ADR 0015).
- **Cron only every 2 hours, and no WP-CLI** ([ADR 0010](decisions/0010-scheduled-jobs-with-2-hour-cron-limit.md)). Keep WP-Cron **on** so site visits trigger jobs; do **not** set `DISABLE_WP_CRON`. Add one xneelo cron job every 2 hours as a backstop, calling `https://<site>/wp-cron.php?doing_wp_cron`. For timely intake, optionally set up a free external pinger (cron-job.org) that calls the same URL every 5–10 minutes. The Parish Intake → Scheduled jobs screen provides a nonce-checked "Run now" action for each registered job. Until mailbox and queue jobs are registered, its framework heartbeat does no intake work. See the [operator guide](operator-guide.md#keep-scheduled-jobs-running).
- **Health monitoring.** Parish Intake → Health reports the last job trigger, source and mailbox failures, queue backlog and a warning after 2 h 15 min without a run. Its Check now action spends at most 25 s on each of mailbox polling, saved-message parsing and outbound mail, staying below the 90 s PHP limit. Alerts are queued after three consecutive failures or a stalled heartbeat; they cannot deliver when both cron and the mail sender are stopped. See [Check intake health](operator-guide.md#check-intake-health).
- **Secrets without WP-CLI.** xneelo does not provide WP-CLI, so add or change `wp-config.php` constants through konsoleH File Manager or SFTP. Put them above `That's all, stop editing!`; see [Configure API keys and mailbox passwords](operator-guide.md#configure-api-keys-and-mailbox-passwords) for the names and placeholder-only example.
- **Email size.** Inbound messages can be up to 30 MB. Attachments over the per-attachment cap (15 MB) are skipped and flagged.
- **Composer production dependencies** are bundled into the release zip and namespace-prefixed with Strauss so they can't clash with other plugins. Development dependencies are not shipped.
- **Database: the site's existing MySQL database.** The plugin adds its own `wp_adct_pi_*` tables to the WordPress database through `$wpdb`, and needs no separate database. xneelo runs MariaDB 10.11, which is MySQL-compatible. Only SQL that works on both MySQL 8 and MariaDB 10.11 is used. JSON is stored in `longtext` with `JSON_VALID` checks (for `dbDelta` compatibility).
- **Mailbox space.** Processed mail is moved to a `Processed` folder. Opt-in retention deletes only exact plugin-move UIDs recorded from a valid `COPYUID`, scoped to the source, mailbox identity, folder and current UIDVALIDITY. Unrelated or untracked messages are never selected, and cleanup fails closed when UIDPLUS is unavailable. The IMAP client checks message size before fetching its body and skips downloads over the configurable 30 MiB default. Raw copies needed for re-parsing are kept on disk under `wp-content/uploads/adct-parish-intake/` (protected by deny rules) and can be removed after the configured day limit and the per-message `retention_until` floor.
- **Outbound mail.** `wp_mail` is routed through xneelo's **authenticated SMTP**, set up once for the whole site with an SMTP plugin (**FluentSMTP** recommended; free). This gives SPF and DKIM for every email the site sends, not only ours, so the parish intake plugin has **no SMTP settings of its own**. Check that the adct.org.za SPF record includes `include:spf.host-h.net` and that DKIM is switched on in konsoleH. The 500-recipient/hour limit is shared by the whole account or domain. All plugin mail goes through a queue with a configurable rolling cap (default 100 successfully sent recipients per 60 minutes). A technical operator may set `ADCT_PI_MAIL_HOURLY_CAP` in `wp-config.php` from 1–500; 100 is recommended, while 500 can use the entire shared allowance. Login links and confirmations have first priority, reminders and digests last ([ADR 0011](decisions/0011-outbound-email-queue-with-hourly-cap.md)). Outbound emails contain no attachments.

**Test mode on non-production sites.** Use Parish Intake → Outbound email to enable the off-by-default, queue-only allow-list before sending test mail. The current settings apply again when queued rows are dispatched; an empty or invalid enabled allow-list suppresses all Parish Intake queue mail. Other plugins' `wp_mail()` calls are unaffected.

## Temporary staging instance: create, then remove

No permanent staging site is planned ([ADR 0009](decisions/0009-preview-and-test-environments.md)), so this is a **create-then-remove** procedure for the one-off pre-launch check described in [testing.md](testing.md#pre-launch-check-on-a-temporary-staging-instance). It is written to be executed once, before launch, and then torn down completely.

**Read this first.** It has not been executed. Nothing in it has been tried against the real account, because the investigation for this document deliberately did not touch the live account. Every step that depends on an unanswered question is marked **[blocked]** with the question it waits on; the rest are steps whose correctness is established by the sources in the table above. Placeholders are used for every name and password — nothing account-specific belongs in this repository.

### Before you start: three choices that need the owner

These are decisions, not unknowns, and only the owner can make them:

1. **The staging subdomain name.** Suggested: `staging` (so `<staging>.adct.org.za`). Decide it before step 1.
2. **Which staging window** the pre-launch check runs in. The subdomain can take up to 24 hours to become reachable, and the addon-domain conversion in step 2 may require deleting and recreating it, so allow a day or two of slack rather than an afternoon.
3. **Who is allowed to receive test mail.** This must be one real, person-owned mailbox that the owner has agreed to read, because [ADR 0011](decisions/0011-outbound-email-queue-with-hourly-cap.md) counts real recipients against the 500/hour account allowance. Nothing in this procedure sends real mail unless you turn it on deliberately in step 8.

### Create

1. **Order the staging name as a Multiple (addon) domain.** konsoleH → Admin login → select or search `adct.org.za` → **Manage Services** → **Add Multiple Domain**. No setup fee applies; the addon gets its own web files and **one** mailbox. Source: [How to order a Multiple domain](https://xneelo.co.za/help-centre/products-and-services/multiple-domain/).
   - **[blocked — Q1]** Does the account have a free Multiple-domain slot? Quota is Basic 1 / Standard 5 / Advanced 10 / Master 20, and the archdiocese account's package is not recorded here because it is account-specific. *Check:* look at the domain in konsoleH and count existing Multiple domains against the package limit on the [web hosting packages](https://xneelo.co.za/web-hosting/) page.
   - If the answer is no, stop and use the alternatives under **If there is no addon-domain slot** below.
2. **Point it at its own directory.** The addon is hosted on a sub-directory of the primary package. To choose or change it, use **Services → Subdomains** on the addon and set the target directory; the target must be a **directory**, not a file or URL, and is created under `/public_html/` if it does not exist. Sources: [Subdomain administration](https://docs.hetzner.com/managed/domain-and-dns/subdomain/), [How to order a Multiple domain](https://xneelo.co.za/help-centre/products-and-services/multiple-domain/).
   - **Do not just create a bare subdomain and expect mail to work.** A subdomain must be an addon domain *before* mailboxes can be created for it, and Hetzner's own note is that you may have to delete the subdomain first and recreate it as an addon domain. That is why step 1 comes first.
3. **Wait for DNS.** A newly added subdomain or domain can take **up to 24 hours** to resolve. Source: [Subdomain administration](https://docs.hetzner.com/managed/domain-and-dns/subdomain/).
4. **Create its own database.** konsoleH → **Databases → Manage MySQL → Add** → create a database for the addon domain, and **record the generated password immediately**: xneelo shows it once and does not store it. Source: [Create and manage a MySQL database](https://xneelo.co.za/help-centre/website/managing-website/mysql/how-do-i-create-and-manage-a-mysql-database-2/).
   - **[blocked — Q2]** Is there a free database slot? Quota is Basic 3 / Standard 5 / Advanced 10 / Master 40 and the account's remaining count is unknown. *Check:* the same Manage MySQL screen lists existing databases against the package allowance.
   - Why a separate database matters here: the plugin adds `wp_adct_pi_*` tables to whatever WordPress database it is given. Staging on its own database means a staging migration or schema check can never touch the live site's tables, which is the whole point of the exercise.
5. **Create the `events-test@` mailbox.** konsoleH → select the **Domain** (not the hosting name) → **Email → Mailboxes** → **New Mailbox** → enter `events-test` as the part before the `@` → set a password → **Save**. The full address is the username for webmail and IMAP. Source: [Mailbox management](https://docs.hetzner.com/managed/email/mailbox-features/mailbox-management/).
   - Note: the addon domain already includes one mailbox, so this is that mailbox, not an extra one. Naming it `events-test@<staging>.adct.org.za` keeps every message that reaches it visibly a test, and keeps it out of the live `events@` mailbox the parishes write to.
6. **Install WordPress on the staging domain and install the release zip as a plugin**, pointing it at the database from step 4. Source: [Install WordPress on a sub- or Multiple domain](https://xneelo.co.za/help-centre/website/wordpress/install-wordpress-on-sub-or-multiple-domain/). Record the automatically installed schema version — this is the first non-prerelease release, so that version is the baseline for the later upgrade check in [testing.md](testing.md#pre-launch-check-on-a-temporary-staging-instance).
7. **Install and configure FluentSMTP on staging too.** Staging is a separate WordPress install with its own options table, so it does not inherit the live site's FluentSMTP settings and must be configured separately. Point it at xneelo's outgoing mail server `smtp.<staging-domain>` on **port 465 with SSL**; the exact hostname is shown in konsoleH under the domain's **Hosting Server**. Source: [Email settings](https://xneelo.co.za/help-centre/email/email-settings/). Add any `wp-config.php` constants through konsoleH File Manager or SFTP, since there is no WP-CLI — see [Secrets without WP-CLI](#design-consequences).
8. **Turn on Test mode before anything can send.** **Parish Intake → Outbound email → enable Test mode**, and add only the `events-test@` address (or a domain rule) to the allow-list. Confirm the conspicuous admin banner appears, and confirm a non-allow-listed fixture is shown as *suppressed* and is not delivered. This is the step that makes the rest of the procedure safe, and it is queue-only: other plugins' `wp_mail()` calls are untouched.
9. **Run the pre-launch checks** listed in [testing.md](testing.md#pre-launch-check-on-a-temporary-staging-instance) against the staging URL, using the `events-test@` mailbox for every confirmation and approval email.
10. **[blocked — Q3] Optional, and the only step that touches mail routing: outbound host reachability.** The procedure assumes the staging site can reach `openrouter.ai` over HTTPS if any AI feature is enabled. See [Outbound third-party hosts](#outbound-third-party-hosts) for why `calendar.google.com` and `api.ocr.space` are *not* server-side calls and therefore not part of this check. *Check:* with the AI provider configured, trigger one AI parse from the staging site and watch **Parish Intake → Health** for the request result, or ask the owner to run the provider's documented connectivity test from a staging cron job. No egress firewall change is needed for this and none should be requested.

### Remove

Do this as soon as the check is finished. Nothing here is reversible in the ways that matter: deleting the database or the mailbox destroys data permanently.

1. **Take a final screenshot/export of anything worth keeping** — the installed schema version, the release zip checksum, and the check results — before deleting anything.
2. **Delete the staging database.** **Databases → Manage MySQL**, select the staging database → delete. Irreversible. Source: [Create and manage a MySQL database](https://xneelo.co.za/help-centre/website/managing-website/mysql/how-do-i-create-and-manage-a-mysql-database-2/).
3. **Delete the `events-test@` mailbox.** **Email → Mailboxes** → trash icon on the mailbox. Irreversible; "you will lose all emails in this mailbox". Source: [Mailbox management](https://docs.hetzner.com/managed/email/mailbox-features/mailbox-management/).
4. **Delete the staging WordPress files**, by removing the target directory from **Services → Subdomains** or by clearing it over SFTP/FTP. The FTP login is the **parent** account's, since a Multiple domain is hosted on a sub-directory of the primary package. Source: [How to order a Multiple domain](https://xneelo.co.za/help-centre/products-and-services/multiple-domain/).
5. **Cancel the Multiple domain.** konsoleH → the domain → cancel/remove it. Source: [How to cancel a Multiple domain](https://xneelo.co.za/help-centre/control-panel/how-to-cancel-a-multiple-or-a-parked-domain/).
6. **Check for leftovers**: any cron job, `wp-config.php` constant, or DNS record pointing at the staging name. The 10-job cron allowance and the addon-domain quota are both shared with the live site, so a leftover quietly consumes something the live site needs.
7. **Confirm the live site is untouched**: live `events@` mailbox still receives mail, live cron still fires within its 2-hour window, and the live site has no new tables or plugin changes from this exercise.

### If there is no addon-domain slot

If Q1 or Q2 comes back "no slot", the mailbox requirement is the hard constraint, because mailboxes cannot exist for a bare subdomain. Options, in the order I would try them:

- **Ask the owner to add one Multiple domain and one database** as a one-off, then cancel them in step 5 above. This is the cleanest fit and matches ADR 0009 exactly.
- **Test without a staging mailbox.** The plugin's confirmation flow needs a mailbox to poll. A staging site pointed at a *locally held* copy of the mail (an exported `.eml` fixture uploaded through the Manual parser screen) exercises parsing, preview and approval without any mailbox at all. This weakens the check and should be a deliberate, recorded decision, not a silent fallback.
- **Upgrade the hosting package** for a temporary period. Only worth it if the owner wants the headroom anyway; it costs money and touches the live site's plan, so ask before doing it.

## Still to confirm (Phase 0 hosting spike)

Answered on 2026-09-24: the limits are per account/domain, and SPF/DKIM work through authenticated SMTP (see above). Also confirmed: **FluentSMTP is set up**, `wp_mail` works on the server, the Site Health loopback check passes (so WP-Cron runs), and the default cap of 100 emails an hour is right.

None of the items below block the first build. Each one names where the answer comes from and the single check that would close it.

| # | Open item | Why it cannot be answered from public sources | The check that closes it |
|---|---|---|---|
| Q1 | Free **Multiple-domain slot** for the staging subdomain | Quota is published (Basic 1 / Standard 5 / Advanced 10 / Master 20) but the account's package and current count are account-specific | konsoleH → `adct.org.za` → **Manage Services**: count existing Multiple domains against the package limit. One screen, no changes. |
| Q2 | Free **database slot** for the staging database | Quota is published (Basic 3 / Standard 5 / Advanced 10 / Master 40) but the count is account-specific | konsoleH → **Databases → Manage MySQL**: read the list of existing databases against the package allowance. Read-only screen. |
| Q3 | **Outbound HTTPS from the host** to `openrouter.ai` (the only real server-side egress) | Public testing proves the service is up, not that this shared host may reach it. Per-account egress rules and ACLs are not documented publicly | With the AI provider configured, trigger one parse from the staging site and read **Parish Intake → Health**, or run one documented provider connectivity test from a staging cron job. See [Outbound third-party hosts](#outbound-third-party-hosts). |
| Q4 | Optional: send a test email and confirm its headers show **SPF and DKIM `pass`** | The mechanism is documented; only the live site's actual headers settle it | Send one test email through the existing FluentSMTP setup, then read the `Authentication-Results` header in the received copy. |
| Q5 | Whether **SSH is enabled** on the account, and whether the owner wants it | Availability is documented (free on request, main FTP user only, port 2222) but the account's current state is not. It does not affect WP-CLI either way | Look for the SSH option in the control panel, or just try `ssh <FTP-user>@<domain> -p2222`. No change is needed to answer it. |

**Not open any more:** whether a temporary staging instance is *possible*. It is — Multiple domains have no setup fee and include a mailbox — and the full create/remove procedure is written above. What is still unknown is only whether this particular account has the quota headroom for it (Q1, Q2), which is a two-minute check rather than an investigation.

## Other mailbox providers

If a Hotmail/Outlook.com or Microsoft 365 mailbox is used later (e.g. for space), note that Microsoft has disabled basic (password) authentication for IMAP. It needs OAuth2 (XOAUTH2), which means an Azure app registration and token refresh. This is supported behind the `MailboxInterface` adapter but is **not** part of Phase 1.
