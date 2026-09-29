# ADR 0016: Pre-release schema changes on disposable installations

- Status: Accepted
- Date: 2026-09-29
- Refines: the activation/upgrade expectation in [ADR 0010](0010-scheduled-jobs-with-2-hour-cron-limit.md) for new pre-release schema changes

## Context

The plugin has no non-prerelease GitHub Release yet. It already has versioned schema steps and tests for existing upgrade paths, but requiring another upgrade step for every unfinished feature slows down work before there is a supported release baseline. CI previews and integration environments use disposable databases. An installation with data to keep, however, cannot safely be treated as disposable merely because the plugin has not been released.

## Decision

1. Until the **first non-prerelease GitHub Release**, new schema changes may update the canonical fresh-install definitions without adding a historical upgrade step solely for disposable test installations. `ci-artifacts` and other prereleases do not end this exception. Each schema change still needs fresh-install tests against the built plugin, including the expected tables, columns and indexes, and portable SQL for MySQL 8 and MariaDB 10.11.
2. Keep the existing migration runner, steps, and upgrade tests. Fresh installation continues to run those steps automatically and records `adct_pi_db_version`; changing the canonical definitions before release does **not** imply that an existing installation at the same recorded version has received the new schema. Do not silently alter the recorded version to suggest otherwise.
3. Rebuild only **isolated, disposable** test installations with a fresh database when a pre-release schema change cannot upgrade them. Never automatically drop tables, reset a shared or live database, or discard the prototype `adct_parish_intake_items` table. If any installation has data that must survive (including a staging copy intended to retain data or an early production/pilot installation), provide a tested, data-preserving migration before applying the change, even before the first release.
4. The first non-prerelease GitHub Release freezes its installed schema as the supported baseline. From then on, all schema changes require ordered, versioned, tested data-preserving migrations on activation/upgrade, alongside fresh-install tests. The release checklist must verify a fresh install and record the released schema version; it must not assume that a previously used disposable test site represents a supported upgrade path.

## Consequences

- Feature PRs before the first release need not implement upgrade paths for data that will be thrown away, while tests for already implemented upgrade paths remain in place.
- Version numbers alone cannot prove that two different pre-release checkouts have identical schemas. A test environment that is not freshly provisioned needs an explicit compatibility check or a safe migration.
- Operators must never use this exception as permission to reset a live or persistent installation. Existing automatic upgrade behavior remains in place for supported migrations.
