"""Regenerate deaneries.csv and parishes.csv from the "Deanery structure" data file (adct_deaneries_data.js).

Usage: python data/seed/export_from_deanery_data.py <path-to-adct_deaneries_data.js>

Only the official (current) deaneries and public directory fields are exported. Proposed realignments,
personal email addresses and phone numbers are left out on purpose (public repository, POPIA)."""
import csv, json, pathlib, re, sys

SRC = pathlib.Path(sys.argv[1])
OUT = pathlib.Path(__file__).resolve().parent
OUT.mkdir(parents=True, exist_ok=True)

t = SRC.read_text(encoding="utf-8")
d = json.loads(t[t.index("{"):t.rstrip().rstrip(";").rindex("}") + 1])


def slug(s):
    return re.sub(r"[^a-z0-9]+", "-", s.lower().replace("'", "").replace("\u2019", "")).strip("-")


def office_email(v):
    # Only official @adct.org.za parish office addresses; personal addresses are added privately at import.
    v = clean(v).lower()
    return v if v.endswith("@adct.org.za") else ""


def clean(v):
    return "" if v in (None, "None appointed") else " ".join(str(v).split())


with open(OUT / "deaneries.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.writer(f, lineterminator="\n")
    w.writerow(["slug", "name", "dean", "vice_dean", "secretary"])
    for key, v in sorted(d["official_deaneries"].items()):
        w.writerow([slug(key), v["name"], clean(v.get("dean")), clean(v.get("vice_dean")), clean(v.get("secretary"))])

KIND = {"Parish": "parish", "Outstation": "outstation", "Mass Center": "mass_centre"}
names = {p["name"] for p in d["parishes"]}
rows = []
for p in sorted(d["parishes"], key=lambda x: x["name"].lower()):
    parent = clean(p.get("administered_by"))
    if parent and parent not in names:
        raise SystemExit(f"unknown parent {parent!r} for {p['name']!r}")
    rows.append([
        slug(p["name"]), p["name"], clean(p.get("area")), clean(p.get("church")),
        KIND[p["type"]], "yes" if p["is_mother_parish"] else "no", slug(parent) if parent else "",
        slug(p["current_deanery"]), clean(p.get("address")), office_email(p.get("email")),
        f'{p["lat"]:.6f}', f'{p["lng"]:.6f}',
    ])
with open(OUT / "parishes.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.writer(f, lineterminator="\n")
    w.writerow(["slug", "name", "area", "church", "kind", "is_mother_parish", "parent_slug", "deanery_slug",
                "address", "office_email", "latitude", "longitude"])
    w.writerows(rows)

slugs = [r[0] for r in rows]
assert len(slugs) == len(set(slugs)), "duplicate parish slugs"
print(len(d["official_deaneries"]), "deaneries;", len(rows), "parishes; generated_at", d["generated_at"])
