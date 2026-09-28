# ADR 0015: Pure-PHP PDF text extraction with hard limits

- Status: Accepted
- Date: 2026-09-26

## Context
Parish event notices are often a one-page PDF poster attached to a short email. Before E8.1 the plugin stored attachments but never read them, so a PDF-only notice produced a zero-candidate result and the event was silently lost. The host has no `pdftotext`, no Ghostscript and no OCR, and there is no budget to add a binary tool or a paid OCR service, so a text-layer PDF has to be read by PHP alone. A PDF is also an untrusted input from the internet: a crafted or merely enormous file must not be allowed to exhaust the 90 second PHP limit or the 256M memory limit of the shared host.

## Decision
- Extract text with the pure-PHP `prinsfrank/pdfparser` 3.x package, behind the Core `Pdf\PdfTextExtractorInterface` port. It is the maintained successor to the abandoned `smalot/pdfparser`, runs on PHP 8.1 and later, and is MIT licensed. It is Strauss-prefixed into `vendor-prefixed/` for release like every other dependency.
- Apply hard limits before and during extraction, configurable through `PdfExtractionLimits` but defaulting to values: the existing attachment size limit (15 MB), 10 pages, and a 10 second budget that leaves headroom inside the job's ~60 second allowance. The library parses lazily, so the budget is re-read after opening the file, after reading the page tree and after every page.
- Read time through a `StopwatchInterface` rather than the `ClockInterface`. The wall clock can be corrected by NTP between two reads and would let a long extraction jump backwards and defeat the timeout.
- Detect columns in application code (`ColumnAwareTextAssembler`) by banding text into columns on the x-axis and emitting each column as its own block. This is a first pass that suits the two-column poster and bulletin layouts in the fixtures; it is not full column detection.
- Persist the text and the outcome on the existing attachment row (`extracted_text`, `extraction_method`, `status`) and append the text to the message body as a clearly marked section, so the rest of the pipeline is unchanged. No migration is needed: the new status values fit the existing columns.
- Treat every PDF failure as non-fatal. A missing file, an unreadable PDF, a timeout or a database error is recorded as a status on the row and surfaced to the operator; the email around it is still parsed and still processed. A PDF never fails its message.
- At most 3 PDFs are read per message, so one mail carrying a dozen attachments cannot consume the job budget.
- Do not add OCR. A scanned PDF is recorded as `no_text_layer` and listed on the Manual parser screen as operator work, which stays consistent with ADR 0005's offline-first posture.

## Consequences
- Notices sent only as a text-layer PDF now produce a candidate and reach the submitter's preview, closing the silent-loss gap.
- The release zip grows by the PDF parser and its small runtime dependency set; the build's Strauss prefixing check must keep passing.
- Posters that are scans, oversized, page-capped or slow still need manual entry, but the Manual parser screen now says which ones and why instead of leaving it to the log.
- Column handling is heuristic. A poster with interleaved columns, rotated text or a table that reads across both columns can still mis-order lines; that is tracked separately as follow-up work.
