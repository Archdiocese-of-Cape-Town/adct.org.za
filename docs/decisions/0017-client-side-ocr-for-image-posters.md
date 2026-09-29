# ADR 0017: Opt-in client-side OCR for image posters

- Status: Accepted
- Date: 2026-09-26

## Context
Real samples showed that about **1 in 4 posters are image-only**. The rule-based parser has no text to work with, so those notices produce no candidate and become manual operator work. The existing plan for this is E8.3 (#75), a server-side OCR provider behind the provisional `OcrProviderInterface`.

That shape does not fit this request, and the reason is structural rather than a matter of preference:

- **Ingestion has no browser.** `MailboxPollingJob` and `InboundMessageProcessingJob` are cron jobs with a ~60 second budget inside a 90 second PHP limit. There is no user-present moment during ingestion at which "on demand" could apply.
- **The host cannot do OCR.** xneelo shared hosting has no OCR binary, no `pdftotext` and no Ghostscript. ADR 0015 rejected server-side OCR partly for this reason, and adding a paid OCR service is a paid dependency the project owner has not accepted.
- **No image reaches a browser today.** Attachments are written to a deny-all private directory with `random_bytes`-derived filenames, and the preview surface (E8.2, #63) is not built.

The owner asked for OCR to be available in the browser whenever images are ingested or a reviewer is approving an image-backed event. That is achievable, and it is close to opposite to ADR 0015's concern: the work happens on the visitor's own device, costs the host nothing, and fits the existing manual-entry fallback that ADR 0005 already requires.

The open design decisions were: which surfaces, how tesseract.js is delivered, and what happens to the extracted text.

## Decision
- **Scope the ADR 0015 prohibition to server-side OCR.** Client-side OCR is permitted. ADR 0015's PDF handling is unchanged: a scanned PDF is still `no_text_layer` and still operator work. This ADR clarifies 0015's scope; it does not supersede it, and 0015 is not edited.
- **Load tesseract.js from the jsDelivr CDN at runtime, with the version pinned.** Do not vendor it. The core plus the English traineddata is roughly 12 MB unpacked, and the repository is public; committing that as a binary in every release zip is the wrong trade for an assist. The zip therefore stays small. Pinning the version keeps a CDN change a one-line edit.
- **Accept the deviation from ADR 0005's offline-first posture, for this feature only.** Everything else stays offline-first. Record the dependency honestly: if the CDN is unreachable or slow, OCR does not run and manual entry stands.
- **Nothing OCR produces is ever sent to or stored by the server.** The recognised text is displayed in the browser to help a person fill the form, and is then discarded. There is no write endpoint, no new `extraction_method` value, and no migration. The schema is unchanged.
- **Only on an explicit click.** No preloading, no worker start and no CDN request on page load. This is the "on demand" the owner asked for, and it keeps the ~12 MB fetch off every page view.
- **Serve images through capability-checked, read-only endpoints.** The admin surface uses `admin_post.php` with a nonce and `Capabilities::REVIEW`. The emailed action-token pages are public, so there the live action token is the authorisation, and the candidate's `message_id` must resolve to a stored image or the request 404s so the endpoint cannot be used to enumerate the private store. Viewing an image never consumes a token, preserving ADR 0004's GET-shows / POST-acts model. The private directory's deny-all `.htaccess` is never relaxed.
- **Never overwrite what a person has typed.** Extracted text is appended under a heading, so manual corrections survive.
- **Let the reviewer tune the reading, in the browser, without remembering it.** The control offers a page-layout choice and a confidence floor, both applied in the browser. They are labelled in plain language rather than by PSM number, and their defaults reproduce the behaviour the control had before they existed, so leaving both alone is exactly the old behaviour. Nothing about a chosen layout or threshold is stored, sent or persisted: the control is a lens on this one reading, not a setting, so it introduces no write path, no new column and no request the previous version did not already make. A control that remembered its choice, or that fetched tesseract.js before the click, would contradict the decisions above and would need its own ADR.
- **Always fall back to manual entry.** An unsupported type (HEIC and HEIF, which browsers generally cannot decode) or any OCR failure shows a readable message pointing at manual entry. OCR is an assist and never a gate.

## Consequences
- Image-only posters can be turned into events by a person in seconds, on the Manual parser screen or on the approve/confirm/edit page, without the host running anything.
- The plugin gains its first runtime dependency that is fetched over the network, and its first external CDN. A request to jsDelivr discloses the visitor's IP address and request timing to a third party. No parish content is sent with it; only the fetch itself. This is a deliberate, recorded trade against a 12 MB release zip.
- Parishes on a slow or filtered connection may find OCR simply unavailable. Because the fallback is manual entry, this degrades rather than breaks.
- Extracted text is not retained, so the pipeline cannot use it later. If that becomes valuable it is a separate decision: a write endpoint, a new `extraction_method`, and a migration.
- E8.3 (#75) remains open and unchanged. It is server-side, queue-based OCR, which this ADR does not address.
- The control now sends a reviewer two settings to choose, which is one more thing to understand on a screen aimed at non-technical parish staff. It is mitigated by plain-language labels, by the defaults matching the previous behaviour, and by the panel sitting below the button rather than in front of the poster.
- Reading a poster's image requires a new endpoint. Any such endpoint serves bytes that may contain personal information, so it is capability-checked, nonced, read-only, and served with `X-Content-Type-Options: nosniff` and a restrictive content security policy.
