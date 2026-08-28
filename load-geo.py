#!/usr/bin/env python3
"""Load BioStor (or any) point localities for BHL pages into bhl-geo.db.

    ./load-geo.py points.csv [bhl-geo.db]

Input is CSV or TSV with a header row. Column names are matched loosely, so
most exports work unchanged:

    PageID       page_id, pageid, BHLPageID, page
    Latitude     lat, decimalLatitude, latitude
    Longitude    lon, lng, long, decimalLongitude, longitude
    Locality     locality, verbatimLocality, place        (optional)
    Source       source, dataset                          (optional)
    Uncertainty  coordinateUncertaintyInMeters, radius     (optional)

Kept in its own database, attached as "geo", for the same reason the FTS
indexes are: re-importing a BHL dump must not destroy work that didn't come
from BHL. Points are page-level; articles roll up through partpage.
"""
import csv, os, sqlite3, sys

SRC = sys.argv[1] if len(sys.argv) > 1 else None
OUT = sys.argv[2] if len(sys.argv) > 2 else "bhl-geo.db"

ALIASES = {
    "PageID":      ("pageid", "page_id", "page", "bhlpageid", "bhl_page_id"),
    "Latitude":    ("latitude", "lat", "decimallatitude"),
    "Longitude":   ("longitude", "lon", "lng", "long", "decimallongitude"),
    "Locality":    ("locality", "verbatimlocality", "place", "placename"),
    "Source":      ("source", "dataset", "provenance"),
    "Uncertainty": ("coordinateuncertaintyinmeters", "uncertainty", "radius"),
}

SCHEMA = """
CREATE TABLE IF NOT EXISTS pagegeo (
  GeoID       INTEGER PRIMARY KEY,
  PageID      INTEGER NOT NULL,
  Latitude    REAL NOT NULL,
  Longitude   REAL NOT NULL,
  Locality    TEXT,
  Source      TEXT,
  Uncertainty REAL
);
CREATE INDEX IF NOT EXISTS pagegeo_page   ON pagegeo(PageID);
CREATE INDEX IF NOT EXISTS pagegeo_source ON pagegeo(Source);

-- Bounding-box index. R*Tree coordinates are 32-bit floats and are rounded
-- OUTWARD, so a box query can return a few extra rows but never misses one.
-- Always re-check against pagegeo.Latitude/Longitude to drop the extras.
CREATE VIRTUAL TABLE IF NOT EXISTS pagegeo_rtree USING rtree(
  GeoID, minLon, maxLon, minLat, maxLat
);
"""


def sniff(path):
    with open(path, newline="", encoding="utf-8-sig") as fh:
        head = fh.read(64 * 1024)
    try:
        return csv.Sniffer().sniff(head, delimiters=",\t;|")
    except csv.Error:
        return csv.excel_tab if "\t" in head.split("\n")[0] else csv.excel


def resolve(fieldnames):
    got, lowered = {}, {(f or "").strip().lower(): f for f in fieldnames}
    for want, names in ALIASES.items():
        for n in names:
            if n in lowered:
                got[want] = lowered[n]
                break
    return got


def main():
    if not SRC:
        print(__doc__)
        sys.exit(2)

    db = sqlite3.connect(OUT)
    db.executescript(SCHEMA)

    with open(SRC, newline="", encoding="utf-8-sig") as fh:
        reader = csv.DictReader(fh, dialect=sniff(SRC))
        cols = resolve(reader.fieldnames or [])

        missing = [k for k in ("PageID", "Latitude", "Longitude") if k not in cols]
        if missing:
            print(f"error: could not find column(s) {missing}")
            print(f"       header was: {reader.fieldnames}")
            sys.exit(1)

        print(f"reading {SRC}")
        for k, v in cols.items():
            print(f"  {k:12} <- {v}")

        rows, bad, nullisland = [], 0, 0
        for rec in reader:
            try:
                page = int(str(rec[cols["PageID"]]).strip())
                lat = float(str(rec[cols["Latitude"]]).strip())
                lon = float(str(rec[cols["Longitude"]]).strip())
            except (ValueError, TypeError, KeyError):
                bad += 1
                continue

            if not (-90 <= lat <= 90) or not (-180 <= lon <= 180):
                bad += 1
                continue

            # 0,0 is almost always a failed geocode rather than the Gulf of Guinea
            if lat == 0 and lon == 0:
                nullisland += 1
                continue

            def opt(key):
                v = rec.get(cols[key]) if key in cols else None
                v = (v or "").strip()
                return v or None

            unc = opt("Uncertainty")
            try:
                unc = float(unc) if unc else None
            except ValueError:
                unc = None

            rows.append((page, lat, lon, opt("Locality"),
                         opt("Source") or "biostor", unc))

    db.executemany(
        "INSERT INTO pagegeo (PageID,Latitude,Longitude,Locality,Source,Uncertainty)"
        " VALUES (?,?,?,?,?,?)", rows)

    # rebuild the box index from scratch; points, so min == max
    db.execute("DELETE FROM pagegeo_rtree")
    db.execute("INSERT INTO pagegeo_rtree (GeoID,minLon,maxLon,minLat,maxLat)"
               " SELECT GeoID,Longitude,Longitude,Latitude,Latitude FROM pagegeo")
    db.commit()

    n, pages = db.execute(
        "SELECT COUNT(*), COUNT(DISTINCT PageID) FROM pagegeo").fetchone()
    print(f"\nloaded {len(rows)} points ({n} total, {pages} distinct pages)")
    if bad:
        print(f"  skipped {bad} unparseable/out-of-range")
    if nullisland:
        print(f"  skipped {nullisland} at 0,0")
    db.close()


if __name__ == "__main__":
    main()
