# Seed data

Starting data for the parish directory. The CSV import ([#30](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/30) E1.1, [#68](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/68) E1.6) and the Playground preview blueprint ([#27](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/27)) use these files.

| File | Rows | Content |
|---|---|---|
| `deaneries.csv` | 8 | The official 2026 deaneries with dean, vice-dean and secretary names, from the archdiocese directory. |
| `parishes.csv` | 124 | Parishes, outstations and mass centres with area, church, kind, mother parish (`parent_slug`), deanery, address, official parish office email (`@adct.org.za` only) and coordinates. |

## Notes

- **Source:** exported from the archdiocese "Deanery structure" working data (generated 2026-09-05). Check it against the published adct.org.za directory before the first import. Only the **current (official) deaneries** are included, not the proposed realignments.
- **Kinds:**
  - `parish`: 108 rows, of which some are administered from another parish (`is_mother_parish = no`).
  - `outstation`: 8 rows.
  - `mass_centre`: 8 rows.
  - `parent_slug` is set where the administering parish is known.
- **No personal contact details.** Personal email addresses and cellphone numbers are left out on purpose, because this repository is public (POPIA). Only official `…@adct.org.za` parish office addresses are included, and 41 rows have none. Add other contact addresses privately in the admin screen or by importing a private CSV. Never commit them.
- **Deans are names only.** A deanery **works without any approver set up**: its events go to the archdiocese reviewers until a dean (or another approver) is linked to a WordPress user with an email address ([ADR 0008](../../docs/decisions/0008-approval-by-dean-or-archdiocese-reviewer.md)).
- Coordinates feed "near me". Correct them in the admin screen if a church pin is off.
- To refresh: run `python data/seed/export_from_deanery_data.py <path-to-adct_deaneries_data.js>` and review the diff. Deanery changes (e.g. if a realignment is adopted) are imported as updates, not by deleting rows.
