# adct.org.za

## ADCT Parish Intake plugin

This repository now contains a lightweight WordPress plugin prototype for parish intake parsing on shared hosting.

### What it does

- Runs an offline-first parsing pipeline for parish communications.
- Normalizes source text, applies deterministic extraction, detects recurring events, scores confidence, and only then optionally calls an AI provider.
- Stores raw and extracted data separately so parses can be reviewed and reprocessed later.
- Adds a WordPress admin page under **Tools → Parish Intake Parser** for testing parses and viewing recent stored results.
- Can generate a static HTML snapshot report from stored parse results.

### AI support

AI is disabled by default. If enabled in the admin screen, OpenRouter can be used as a low-confidence fallback only.

### Validation

A simple smoke test is available:

```bash
php /home/runner/work/adct.org.za/adct.org.za/tests/parser_smoke_test.php
```
