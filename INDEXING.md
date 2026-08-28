# Full-text and geospatial indexing for `bhl.db`

Search infrastructure over a SQLite build of the BHL data dumps, intended as the
back end for an MCP server with an emphasis on taxonomic names.

Nothing here modifies `bhl.db`. The indexes live in two separate database files
that are `ATTACH`ed at query time.

## Why separate files

| file | contents | size | rebuild |
|---|---|---|---|
| `bhl.db` | BHL dump tables, unmodified | 19 GB | from `bhldata/*.txt` |
| `bhl-fts.db` | FTS5 + trigram indexes, parsed creators | 541 MB | 23 s |
| `bhl-geo.db` | point localities + R\*Tree | grows with data | on demand |

Three reasons:

1. Re-importing a BHL dump must not force an index rebuild, and must not destroy
   data that didn't come from BHL (the localities).
2. `bhl.db` is never rewritten, so a 19 GB file is never at risk from an index build.
3. The three have completely different update cadences and provenance.

They are attached as `fts` and `geo`, so tables are addressed as `fts.title_fts`,
`geo.pagegeo`, and plain `page`, `title`, `pagename` for BHL's own.

## Requirements

- **SQLite 3.34+** for the `trigram` tokenizer (3.45+ for its `remove_diacritics`).
  Verified on 3.51 (CLI) and 3.53 (PHP).
- **Math functions** (`radians`, `sin`, `acos`, `log`) — standard in builds since
  3.35. Used for great-circle distance and creator ranking.
- **R\*Tree module** — compiled in by default; present in both the CLI and PHP PDO.
- **Python 3** for the creator parser and the locality loader.

SpatiaLite is *not* required, and is not usable here anyway: PDO has no
`loadExtension`.

## Building

```sh
./build-fts.sh                          # bhl.db -> bhl-fts.db, ~23 s
./build-fts.sh /path/bhl.db /path/out.db

./load-geo.py points.csv                # -> bhl-geo.db
```

`build-fts.sh` is idempotent — it drops and recreates every index, so just re-run
it after a BHL re-import. It honours `SQLITE_TMPDIR`, which matters if the volume
holding the output is short on space.

---

# Full-text search (`bhl-fts.db`)

## What is indexed

| table | tokenizer | columns | size |
|---|---|---|---|
| `title_fts` | `unicode61` | TitleID, **FullTitle**, ShortTitle, Authors, Subjects | 173 MB |
| `part_fts` | `unicode61` | PartID, **Title**, ContainerTitle, Authors | 240 MB |
| `creator_fts` | `unicode61` | CreatorID, NameOnly, **Surname**, Forename, ForenameFull | 13 MB |
| `title_trgm` | `trigram` | TitleID, FullTitle | 83 MB |
| `creator_trgm` | `trigram` | CreatorID, NameOnly | 13 MB |
| `creator_parsed` | — | ordinary table, see below | 10 MB |

Token indexes use `tokenize = "unicode61 remove_diacritics 2"` with
`prefix = '2 3 4'`. Diacritic folding means `soderberg` finds `Söderberg`; the
prefix index makes autocomplete (`Lesq*`) an index lookup.

Authors and subjects are folded into `title_fts` so a single `MATCH` covers
"what is this book, who wrote it, and what is it about".

## Taxonomic names are deliberately NOT in FTS

`pagename` (216,874,371 rows) already carries B-tree indexes:

```sql
CREATE INDEX pagename_idx1 ON pagename(NameConfirmed);
CREATE INDEX pagename_idx2 ON pagename(PageID);
```

Exact name lookup returns in **~7 ms**, which is what name resolution actually
needs. An FTS index over 217M short strings would be large and would buy little:
names are matched exactly or by prefix, both of which the B-tree already serves.

The one thing the B-tree cannot do is case-insensitive or mid-string matching —
`NameConfirmed` uses BINARY collation, so `poecilia reticulata` matches nothing.
Fold case in the application layer, or add a trigram index over the ~4.1M
*distinct* names if substring search over names is ever needed. Indexing the
distinct names rather than all 217M rows is what makes that affordable.

## Parsed creators

BHL stores life dates inside the creator string
(`Thunberg, Carl Peter, 1743-1828`), which makes name matching and any kind of
reconciliation awkward. `parse_creators.py` splits 79,892 distinct creators into:

```
CreatorID, CreatorName, Kind, NameOnly, Surname, Forename, ForenameFull,
Initials, BirthYear, DeathYear, FloruitStart, FloruitEnd, DateText,
Uncertain, TitleCount, PartCount
```

`Kind` comes from BHL's own `CreatorType`, not from guesswork:

| kind | n |
|---|---|
| personal | 55,393 |
| corporate | 23,743 |
| meeting | 755 |

26,515 have life dates and 7,523 have a parenthetical forename expansion
(`Kindberg, N. C. (Nils Conrad)`). Handled forms include `1768?-1837` (sets
`Uncertain`), `1832-`, `-1910`, `fl. 1759-1768`, `b. 1854`, `1663 or 1664-1718`,
`1749-approximately 1809`, and `18th cent.`

Residual unparsed dates: 88 of 55,393 personal names (0.16%). Most remaining
cases are corporate bodies where the digits are legitimately part of the name
(`Expédition antarctique française (1903-1905)`).

`TitleCount` and `PartCount` are prominence counts — see ranking below.

## Query recipes

Unaliased is simplest:

```sql
SELECT TitleID, FullTitle FROM fts.title_fts
WHERE title_fts MATCH '"orchid"* AND "madagascar"*'
ORDER BY bm25(title_fts, 0.0, 10.0, 5.0, 2.0, 1.0) LIMIT 10;
```

The `bm25` weights map positionally to the FTS columns, so this says a hit in
`FullTitle` counts ten times a hit in `Subjects`.

Snippets, for returning matched context to an MCP client rather than whole records:

```sql
SELECT snippet(title_fts, 1, '<b>', '</b>', '…', 15) FROM fts.title_fts
WHERE title_fts MATCH '"natural history"';
```

Substring / fuzzy, index-backed by the trigram table:

```sql
SELECT FullTitle FROM fts.title_trgm WHERE FullTitle LIKE '%orchide%';
```

Creator search with prominence ranking:

```sql
SELECT c.CreatorName, c.BirthYear, c.DeathYear
FROM fts.creator_fts f
JOIN fts.creator_parsed c ON c.CreatorID = f.CreatorID
WHERE f.creator_fts MATCH '"hooker"*'
ORDER BY bm25(f.creator_fts, 0.0, 5.0, 10.0, 2.0, 2.0)
       * (1.0 + log(1 + c.TitleCount + c.PartCount));
```

## Design notes

These are the decisions that are non-obvious, with the evidence for them.

**Do not add porter stemming.** Tested directly: `'orchid AND madagascar'`
returned 1 hit with a porter-stemmed index versus 3 for `'orchid* AND madagascar'`
on the plain `unicode61` index. Porter is English-only and mangles the French and
Latin that BHL is full of (`Orchidées`). Expand query terms with `*` instead.

**Prefix matching has a hard limit.** `orchids*` will never reach `Orchidées`,
because "orchids" is not a prefix of "orchidees". Only the trigram index can
cross that gap. For recall-sensitive tools, query the trigram index alongside the
token index rather than only as a fallback.

**Strip stopwords, or lose every non-English title.** `"of"*` ANDed into a query
eliminates French and Latin titles that contain no "of" — which in BHL is a great
many. The stoplist in `go.php` covers English, French, German, Spanish, Italian
and Dutch function words. It is deliberately **not** applied to creator searches,
where `von`, `de`, `van` are name particles rather than noise.

**Never put raw user text in `MATCH`.** Punctuation and bare operator words are
FTS5 syntax: `Smith's` raises an error, and `NOT` silently changes the query.
Keep letters and digits only, and wrap each term in double quotes so it is a
literal.

**Aliased FTS tables need the alias-qualified hidden column.** This is easy to
get wrong and the error message is unhelpful:

```sql
-- wrong: bm25(f) / fts.title_fts MATCH ...        "no such column: f"
-- right:
SELECT ... FROM fts.title_fts f
WHERE f.title_fts MATCH '...' ORDER BY bm25(f.title_fts);
```

**bm25 cannot rank people.** Every candidate for "Hooker" matches the surname
token equally well, so only field length separates them — which put Worthington
Hooker (5 titles) first and W. J. Hooker (58 titles) eleventh. Multiplying the
score by `1 + log(1 + TitleCount + PartCount)` fixes it. bm25 is negative and
more-negative is better, so multiplying by a prominence factor promotes; `log`
stops one prolific author swamping the results.

**Trigram is substring matching, not edit distance.** It will not rescue a typo
in the middle of a word. Retrying on progressively shorter leading fragments does
rescue typos near the end, which is the common case: `lesquerux` → `lesquer` →
Lesquereux. Five characters is a sensible floor. True edit-distance matching
would need `spellfix1`, which is not in PHP's SQLite build.

**BHL text is mixed Unicode normalisation.** Some strings are decomposed (NFD) —
`É` stored as `E` + combining acute:

| field | non-ASCII | of which NFD |
|---|---|---|
| creator | 4,690 | 1,552 |
| title | 12,237 | 2,769 |
| subject | 1,694 | 590 |
| part | 60,529 | 110 |

`remove_diacritics 2` folds both forms, so token search is unaffected. Exact `=`
comparison, `LIKE`, and any reconciliation against an external authority are
**not** — normalise to NFC first. `parse_creators.py` does this on ingest.

---

# Geospatial search (`bhl-geo.db`)

Point localities for BHL pages (from BioStor or any other source), supporting
"which works mention localities in this region" and "draw a map of this work".

## Schema

```sql
CREATE TABLE pagegeo (
  GeoID       INTEGER PRIMARY KEY,
  PageID      INTEGER NOT NULL,
  Latitude    REAL NOT NULL,
  Longitude   REAL NOT NULL,
  Locality    TEXT,
  Source      TEXT,
  Uncertainty REAL);

CREATE INDEX pagegeo_page ON pagegeo(PageID);

CREATE VIRTUAL TABLE pagegeo_rtree USING rtree(
  GeoID, minLon, maxLon, minLat, maxLat);
```

One point per row, so a page may carry several localities. Points are page-level;
articles reach them through `partpage`. For points min == max on both axes.

## Loading

```sh
./load-geo.py points.csv [bhl-geo.db]
```

CSV or TSV with a header row; column names are matched loosely
(`PageID`/`page_id`, `decimalLatitude`/`lat`, `decimalLongitude`/`lon`, and
optional `locality`, `source`, `coordinateUncertaintyInMeters`). Rows that are
unparseable or out of range are skipped and counted, as are points at exactly
0,0 — almost always a failed geocode rather than the Gulf of Guinea.

The R\*Tree is rebuilt from `pagegeo` at the end of every load.

## The one correctness trap

**R\*Tree coordinates are 32-bit floats, rounded outward.** A box query therefore
never misses a point inside the box, but can return points just outside it. The
R\*Tree is a prefilter, not an answer — always re-check against the true `REAL`
values:

```sql
WHERE r.maxLon >= :minLon AND r.minLon <= :maxLon      -- index prefilter
  AND r.maxLat >= :minLat AND r.minLat <= :maxLat
  AND g.Longitude BETWEEN :minLon AND :maxLon          -- exact re-check
  AND g.Latitude  BETWEEN :minLat AND :maxLat
```

Omit the re-check and boxes leak at the edges.

For radius queries the box is only a bound; great-circle distance decides. A box
is not a circle, and a degree of longitude shrinks badly at high latitude:

```sql
6371 * acos(min(1.0,
    sin(radians(:lat)) * sin(radians(g.Latitude))
  + cos(radians(:lat)) * cos(radians(g.Latitude))
  * cos(radians(g.Longitude) - radians(:lon)))) <= :km
```

The `min(1.0, ...)` guard matters: floating-point error can push the argument
just above 1 and make `acos` return NULL for a point at zero distance.

## The join that makes this worth having

`pagename` and `pagegeo` are both keyed on `PageID`, so linking taxa to places
needs no extra machinery:

```sql
-- what has been recorded from this region?
SELECT pn.NameConfirmed, COUNT(DISTINCT g.PageID) AS Pages
FROM geo.pagegeo_rtree r
JOIN geo.pagegeo g ON g.GeoID = r.GeoID
JOIN pagename pn   ON pn.PageID = g.PageID
WHERE <bbox clause>
GROUP BY pn.NameConfirmed ORDER BY Pages DESC;

-- and the inverse: where has this name been reported from?
SELECT g.Latitude, g.Longitude, g.PageID
FROM pagename pn JOIN geo.pagegeo g ON g.PageID = pn.PageID
WHERE pn.NameConfirmed = :name;
```

---

# Files

| file | purpose |
|---|---|
| `build-fts.sh` | builds `bhl-fts.db` end to end |
| `parse_creators.py` | parses creator strings into `creator_parsed` |
| `load-geo.py` | loads point localities into `bhl-geo.db` |
| `config.inc.php` | opens `bhl.db`, attaches `fts` and `geo` |
| `go.php` | worked query functions for all of the above |

`config.inc.php` sets `$config['has_fts']` and `$config['has_geo']` so code can
degrade gracefully when an index file has not been built.

# Repository notes

**The database files cannot go into git.** GitHub rejects any file over 100 MB:

- `bhl.db` — 19 GB
- `bhl-fts.db` — 541 MB

Both are derived artifacts and should be regenerated rather than versioned:
`bhl.db` from the BHL dumps in `bhldata/`, and `bhl-fts.db` from `bhl.db` in 23
seconds. `bhl-geo.db` is the only one holding data that cannot be rebuilt from a
public source, so back it up independently — Git LFS or a release asset if it
stays small, object storage if it does not.

Suggested `.gitignore`:

```gitignore
*.db
*.db-wal
*.db-shm
bhldata/
cache/
.DS_Store
env.php
```
