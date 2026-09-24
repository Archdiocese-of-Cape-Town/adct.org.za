# ADR 0005: Offline-first parsing with pluggable AI and OCR

- Status: Accepted
- Date: 2026-09-24

## Context
Parishes send free-form English text, bulletins and posters (PDF/image). AI models cost money, and free endpoints have rate limits and may change. Shared hosting can't run local ML models or Tesseract. PHP has no mature NLP library for event extraction.

## Decision
1. **Deterministic pipeline first**: normalise → strip quoted replies/signatures → split into event blocks → rule-based extraction (dates, times, venues, contacts) → **lookup lists** (parish names, venues, event-type keywords from the directory and taxonomy, admin-editable) → recurrence phrases → classification → confidence score.
2. **Context beats NLP**: a verified sender gives us the parish, its default venue and its location. Dates are resolved relative to the email's received date (for dates without a year and "this Sunday"). Numbers are day-first (DD/MM); the timezone is Africa/Johannesburg.
3. **Good enough is enough**: the confirmation loop ([ADR 0004](0004-trust-and-confirmation-model.md)) catches errors. Parser quality is measured with a fixture corpus in CI.
4. **AI is optional** and off by default. It goes through `AiProviderInterface`, and the first adapter is any **OpenAI-compatible** endpoint (base URL, model, key), which covers OpenRouter `:free` models, Groq, and Ollama locally. AI is only called for low-confidence candidates. There is a daily call cap, a timeout, and strict JSON schema validation. Email text is sent as quoted untrusted data. AI never decides publishing, and results are recorded with provider/model provenance.
5. **Text from documents**:
   - PDFs with a text layer: pure-PHP extraction (e.g. `smalot/pdfparser`).
   - Images or scanned PDFs: optional `OcrProviderInterface` (OCR.space free tier, or an AI vision model). Off by default.
   - **Always fall back** to showing the attachment to the submitter/admin for manual entry.
6. Candidates store `parser_version`, so they can be re-parsed when rules improve.

## Consequences
- Runs free and offline by default; AI and OCR can be switched on without code changes.
- Rule maintenance is ongoing, but it is test-driven and visible.
- Posters without a text layer need either an external OCR service or manual entry.
