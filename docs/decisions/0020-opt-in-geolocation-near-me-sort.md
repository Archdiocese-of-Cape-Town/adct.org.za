# ADR 0020: Opt-in geolocation "Near me" sort, with a suburb fallback

- Status: Accepted
- Date: 2026-10-03

## Context
Issue #55 asks for "Near me": browser location or suburb, sorted by distance, on the public event listing.

The plugin already holds the coordinates this needs, so no new data source and no migration is required. `adct_pi_parishes` and `adct_pi_occurrences` each carry nullable `latitude`/`longitude`, and parishes also carry `area` and `suburb` (see `docs/data-model.md`). The listing runs against `adct_pi_occurrences`, so an occurrence whose venue has been given coordinates is sortable as it stands.

What is genuinely new is the privacy story. Geolocation is special personal information under POPIA section 26, and this is the first public-facing feature in the plugin that touches it. Three questions had to be answered before any code was written:

1. **When does the browser ask?** A page that asks on load is hostile and is exactly what a visitor on a shared or office connection would object to.
2. **Where does the location go?** Any answer that writes it down creates a new category of stored personal information and a new deletion story the archdiocese does not currently have.
3. **What happens when the visitor says no, or the browser cannot ask?** A feature that only works for people who grant location is not "near me", it is "near me for some of the public".

The hosting limits also constrain the shape. The listing must sort the whole archive, not a hand-picked handful, so a sorted query with a distance filter has to fit the ~60 second job budget and the existing block paging. And the listing caches rendered HTML in a transient; a cache key that grew with the visitor's coordinates would let anonymous visitors grow `wp_options` without bound, the same failure mode the ICS feed filters had to avoid.

The issue's acceptance criteria did not settle the consent and decline path, so the choices below are this ADR's to make, and are recorded here so they can be reversed deliberately rather than discovered later.

## Decision

**Explicit opt-in only.** The browser's geolocation prompt never fires on page load, on scroll, or on any interaction other than pressing the **Near me** button. There is no permission request, no IP-based or coarse-network fallback, and no attempt to infer a location from anything else on the page. A visitor who never presses the button is never asked, and nothing about their visit changes.

**The location lives in the URL and nowhere else.** Coordinates are carried in the page's own query string (`adct_lat` / `adct_lng`) or, for the suburb route, the suburb name (`adct_suburb`). That means:

- nothing is written to the database — no column, no option, no user meta, no attachment;
- nothing is logged by the plugin;
- nothing is sent to a third party. There is no geolocation API provider, no IP-geolocation service and no CDN involvement in this feature;
- the browser's history holds it, which is the visitor's own device and the same exposure as any other page they visit;
- a distance sort writes **no listing transient at all**. The listing's rendered-HTML cache is keyed by its ordinary filters, and a near-me request bypasses it entirely rather than minting a cache entry per coordinate pair.

The page says this in plain words next to the button: we use your location to sort this list, nothing is saved, and the page is not shared with anyone.

**Declining is a first-class path, not an error.** If the visitor refuses, or the browser reports the API as unavailable or insecure, the page reveals a suburb box instead. It says the location was not used and offers the suburb route. The suburb route is a complete peer of the location route, not a consolation prize: it works with no browser permission at all, on any device, and needs no JavaScript beyond the datalist.

**Suburbs come from coordinates the archdiocese already holds.** A suburb name typed by a visitor is resolved against the parishes and venues that already have coordinates, and the visitor is then shown what was found. There is no suburb table, no geocoder and no external lookup. Pins that carry an identical name are averaged into one point. Only active parishes and venues contribute, and the archdiocese's own spelling is displayed rather than the visitor's typing.

**An unrecognised suburb never silently falls back.** Typing something we do not have produces a plain-English message plus the full list to pick from, not a quiet return to date order. A visitor who asked for a distance sort and got date order would reasonably believe it was the answer.

**Distances are computed in the query, with a real radius filter.** A haversine expression over the occurrence's own coordinates is used in the SELECT, in the radius `WHERE` and in the `ORDER BY`, on MySQL 8 and MariaDB 10.11 alike. The radius is a genuine filter, not a hint. It is chosen from a fixed set (5, 10, 25, 50, 100 km) so the query cannot be pushed into an unbounded scan by a crafted parameter, and it is validated to that set server-side.

**Sorting by distance replaces date order.** The listing groups by day, so a pure distance sort interleaves the day headings. That is deliberate and is the honest reading of "sorted by distance"; a visitor who wants dates back gets one click on a visible **Show all events by date instead** link. For the same reason a distance sort replaces `o.start_utc ASC, o.id ASC` entirely, which means the existing "featured first" pin no longer wins while a distance sort is active. Both are consequences of the sort being real rather than a cosmetic re-order of the top of the page.

**Occurrences with no coordinates are excluded from a distance sort.** Showing them last would claim they are far away, which is a different and false statement. The listing is sorted by distance and contains events with a known location; the date-ordered listing remains complete.

## Consequences
- The public gets both routes named in the issue, and the decline path is as usable as the consent path. A visitor on a locked-down device, or one who simply prefers not to be located, can still use the feature.
- Nothing about a visitor's location is stored, so there is no new record to protect, no new deletion obligation and no new entry for the privacy tooling. The trade is that the sort is not remembered: the visitor presses the button again on their next visit.
- No new table, column or migration. ADR 0016's migration discipline is untouched, and `verify-plugin.php`'s schema-version literal does not change.
- Because the near-me result is never cached, a distance sort costs more database work than a date-ordered one. It is bounded by the existing block paging and the radius filter, and it is a deliberate, user-initiated request rather than a background job, so the 90 second limit is not at risk.
- A suburb sort is only as good as the coordinates the archdiocese has entered. Where no pin exists, the suburb is not offered. This is a data-quality consequence, and the fix is entering more venue pins, not a new lookup service.
- Coordinates in the URL are visible to anyone the visitor shares the link with. This is stated on the page. Because the coordinates are used only to sort a public list of public events, the disclosure is to a person the visitor chooses, and no account or personal record is involved.
- If the archdiocese later wants a remembered location, that is a new decision with a new cost: storing special personal information, a consent record, and a deletion path. It is not implied by this ADR.
