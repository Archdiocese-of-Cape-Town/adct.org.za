# adct.org.za

## ADCT Parish Intake plugin

This repository now contains a lightweight WordPress plugin prototype for parish intake parsing on shared hosting.

### What it does

- Runs an offline-first parsing pipeline for parish communications.
- Normalizes source text, applies deterministic extraction, detects recurring events, scores confidence, and only then optionally calls an AI provider.
- Stores raw and extracted data separately so parses can be reviewed and reprocessed later.
- Adds a dedicated **Parish Intake** admin menu with separate **Settings** and **Manual parser** screens.
- Can generate a static HTML snapshot report from stored parse results.

### AI support

AI is disabled by default. If enabled in the admin screen, OpenRouter can be used as a low-confidence fallback only.

### Inbox parsing status

Automated inbox polling and mailbox connection settings are not part of this prototype yet. The current workflow is to configure AI fallback in **Parish Intake → Settings** and test messages manually in **Parish Intake → Manual parser**.

### Project coordination

Use GitHub Issues plus a GitHub Project to coordinate work across sessions. Repository-side scaffolding for this lives in:

- `/home/runner/work/adct.org.za/adct.org.za/.github/ISSUE_TEMPLATE/epic.yml`
- `/home/runner/work/adct.org.za/adct.org.za/.github/ISSUE_TEMPLATE/work-item.yml`
- `/home/runner/work/adct.org.za/adct.org.za/docs/parish-intake-project-backlog.md`

### Validation

A simple smoke test is available:

```bash
php /home/runner/work/adct.org.za/adct.org.za/tests/parser_smoke_test.php
```
