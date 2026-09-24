# ADR 0010: Running scheduled jobs with a 2-hour cron limit

- Status: Accepted
- Date: 2026-09-24
- Refines: the "real cron every 5 minutes with `DISABLE_WP_CRON`" plan in [architecture](../architecture.md#scheduled-jobs) and [hosting environment](../hosting-environment.md)

## Context
xneelo shared hosting allows cron jobs **at most once every 2 hours**, with up to 10 cron jobs per account. There is **no WP-CLI**. Outbound HTTP(S) calls are allowed. The original design assumed a cron job every 5 minutes. Polling mail only every 2 hours would make the confirmation loop feel broken: a secretary sends a notice and hears nothing for up to 2 hours.

## Decision
Jobs are triggered in several ways. All of them run the same batched jobs (lock, ~60 s budget, checkpoint), so a trigger that arrives while a job is running does nothing harmful.

1. **WP-Cron stays on** (`DISABLE_WP_CRON` is **not** set). Visits to adct.org.za trigger due jobs through WordPress's normal loopback request, so a busy site polls often.
2. **xneelo cron job every 2 hours** as a guaranteed backstop. It calls `https://adct.org.za/wp-cron.php` over HTTP (e.g. `curl -s` or `wget -q -O -`), because there is no WP-CLI. This uses 1 of the 10 allowed cron jobs.
3. **Optional free external pinger** for timely intake: a free service such as **cron-job.org** calls `wp-cron.php` every 5–10 minutes. A GitHub Actions scheduled workflow is a second option (the repository is public, so it is free). GitHub may delay scheduled runs and disables them after 60 days without repository activity, so cron-job.org is the recommended option. The operator guide shows how to set it up in a few clicks.
4. **"Check now" button** in the admin area (and on the health dashboard). It runs the mailbox poll and queue processing straight away, within the time budget, for when someone is waiting.
5. The **health dashboard** shows the last run time per job and which trigger started it. It warns if no run has happened for more than 2 h 15 min, which means even the backstop isn't working.
6. Job intervals are chosen for these triggers. Mail polling is "due" every 10 minutes and simply runs on the next trigger after that. Daily jobs (monitoring, retention, digests) run on the first trigger after their due time.
7. There is no WP-CLI, so nothing needs a command line: migrations run automatically on plugin activation/upgrade, and every maintenance action (reprocess, rebuild occurrences, run a job) has an admin button.

## Consequences
- With the external pinger, a submitter gets their confirmation email within about 10–15 minutes. Without it, it depends on site traffic, and in the worst case it takes up to 2 hours. The MVP exit criteria assume the pinger is set up.
- Visitor-triggered WP-Cron adds a loopback request on some page views. Jobs are time-boxed and run in that separate request, so visitors don't wait for them.
- The external pinger only calls the public `wp-cron.php` URL, so no secret is needed. If abuse becomes a concern, a token-protected endpoint can be added later.
- Setting up cron-job.org is one more (free) thing to manage. If nobody sets it up, the system still works, just more slowly.
