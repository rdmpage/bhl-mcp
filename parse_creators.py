#!/usr/bin/env python3
"""Parse BHL creator strings into structured fields for search + reconciliation."""
import re, sqlite3, sys, collections, unicodedata

BHL = sys.argv[1] if len(sys.argv) > 1 else 'bhl.db'
OUT = sys.argv[2] if len(sys.argv) > 2 else 'bhl-fts.db'

# trailing life-date expression, e.g. ", 1743-1828" / ", 1768?-1837" / ", 1832-"
# / ", -1910" / " fl. 1759-1768" / ", b. 1854" / ", 18th cent."
QUAL = r"(?:fl\.|b\.|d\.|ca\.|approximately|active)"
DATE_TAIL = re.compile(r"""
    (?:,\s*|\s+)
    (?P<qual>""" + QUAL + r"""(?:\s*""" + QUAL + r""")*)?\s*
    (?P<dates>
        \d{1,4}\?{0,2}(?:\s+or\s+\d{1,4})?\s*[-–]\s*(?:""" + QUAL + r"""\s*)?\d{0,4}\?{0,2}
      | [-–]\s*\d{1,4}\?{0,2}
      | \d{3,4}\?{0,2}
      | \d{1,2}(?:th|st|nd|rd)\s*cent\.?
    )
    \.?\s*$""", re.X | re.I)

# parenthetical forename expansion at end, e.g. "Kindberg, N. C. (Nils Conrad)"
PAREN_TAIL = re.compile(r"\s*\(([^()]*)\)\s*\.?\s*$")
INITIAL = re.compile(r"^[^\W\d_]\.$", re.UNICODE)
YEAR = re.compile(r"\d{1,4}")


def years(rec):
    txt, qual = rec["DateText"], rec["DateQual"]
    if not txt:
        return
    if "?" in txt:
        rec["Uncertain"] = 1
    if "cent" in txt.lower():
        return
    if re.search(r"[-–]", txt):
        left, _, right = re.split(r"([-–])", txt, maxsplit=1)[0], None, \
            re.split(r"[-–]", txt, maxsplit=1)[1]
        lm, rm = YEAR.search(left or ""), YEAR.search(right or "")
        if qual == "fl":
            rec["FloruitStart"] = int(lm.group()) if lm else None
            rec["FloruitEnd"] = int(rm.group()) if rm else None
        else:
            rec["BirthYear"] = int(lm.group()) if lm else None
            rec["DeathYear"] = int(rm.group()) if rm else None
    else:
        m = YEAR.search(txt)
        if not m:
            return
        v = int(m.group())
        if qual == "b":
            rec["BirthYear"] = v
        elif qual == "d":
            rec["DeathYear"] = v
        elif qual == "fl":
            rec["FloruitStart"] = v


def parse(name, kind):
    rec = dict(Surname=None, Forename=None, ForenameFull=None, Initials=None,
               BirthYear=None, DeathYear=None, FloruitStart=None, FloruitEnd=None,
               DateQual=None, DateText=None, Uncertain=0)
    s = unicodedata.normalize("NFC", (name or "")).strip()

    m = DATE_TAIL.search(s)
    if m:
        rec["DateText"] = re.sub(r"\s+", " ", m.group("dates")).strip()
        q = (m.group("qual") or "").lower()
        rec["DateQual"] = ("fl" if "fl" in q else "b" if re.search(r"\bb\.", q)
                           else "d" if re.search(r"\bd\.", q)
                           else "ca" if "ca" in q or "approximately" in q else None)
        s = s[:m.start()].strip()
        years(rec)

    if kind != "personal":
        rec["NameOnly"] = s.rstrip(" ,")
        return rec

    m = PAREN_TAIL.search(s)
    if m:
        rec["ForenameFull"] = m.group(1).strip()
        s = s[:m.start()].strip()

    s = s.rstrip(" ,")
    if s.endswith(".") and not re.search(r"\b[A-Z]\.$", s):
        s = s[:-1].rstrip(" ,")
    rec["NameOnly"] = s

    if "," in s:
        sur, fore = s.split(",", 1)
        rec["Surname"] = sur.strip()
        rec["Forename"] = fore.strip(" ,") or None
    else:
        rec["Surname"] = s or None

    if rec["Forename"]:
        toks = rec["Forename"].split()
        ini = [t for t in toks if INITIAL.match(t) and t[0].isupper()]
        if ini:
            rec["Initials"] = " ".join(ini)
    return rec


src = sqlite3.connect(f"file:{BHL}?mode=ro", uri=True)
# authoritative kind from CreatorType; take the most common type per CreatorID
kinds = {}
for cid, ctype, n in src.execute(
        "SELECT CreatorID, CreatorType, COUNT(*) FROM creator "
        "GROUP BY CreatorID, CreatorType ORDER BY COUNT(*) DESC"):
    if cid in kinds:
        continue
    t = (ctype or "").lower()
    kinds[cid] = ("personal" if "personal" in t else
                  "corporate" if "corporate" in t else
                  "meeting" if "meeting" in t else "unknown")

# Prominence: bm25 can't rank people, because every candidate for "Hooker" matches
# the same surname token equally. Number of works credited is what separates
# W. J. Hooker (58 titles) from a namesake with one.
title_counts = dict(src.execute(
    "SELECT CreatorID, COUNT(DISTINCT TitleID) FROM creator GROUP BY CreatorID"))
part_counts = dict(src.execute(
    "SELECT CreatorID, COUNT(DISTINCT PartID) FROM partcreator GROUP BY CreatorID"))

# Creators come from BOTH tables. There is no master creator table: `creator` holds
# title-level credits (79,892) and `partcreator` holds article-level ones (171,732),
# overlapping by only 10,149. Indexing `creator` alone left 161,583 people - two
# thirds of BHL's creators, and most of the individual authors - unfindable by
# search_creators. Title-level spelling wins where a creator appears in both.
names = {}
for cid, cname in src.execute("SELECT DISTINCT CreatorID, CreatorName FROM partcreator"):
    names.setdefault(cid, cname)
for cid, cname in src.execute("SELECT DISTINCT CreatorID, CreatorName FROM creator"):
    names[cid] = cname

rows, stats = [], collections.Counter()
for cid, cname in sorted(names.items()):
    kind = kinds.get(cid)
    if kind is None:
        # partcreator carries no CreatorType, so infer it. parse() returns early for
        # anything not "personal" and never fills Surname, which creator_fts weights
        # at 10 against NameOnly's 5 - so guessing "unknown" would index these people
        # on their full name only and rank them below everyone else.
        #
        # BHL writes personal names as "Surname, Forename", so a comma is the signal.
        # Checked against the 79,892 creators whose type IS known: 92% correct overall,
        # 98.9% of personal names caught. The 24% of corporate names it over-calls
        # cost only an odd Surname split; the name is still indexed and still found.
        kind = "personal" if "," in (cname or "") else "corporate"
        stats["kind_inferred"] += 1
    r = parse(cname, kind)
    stats[kind] += 1
    if r["BirthYear"] or r["DeathYear"]:
        stats["with_life_dates"] += 1
    if r["FloruitStart"]:
        stats["with_floruit"] += 1
    if kind == "personal" and not r["Surname"]:
        stats["personal_no_surname"] += 1
    if r["ForenameFull"]:
        stats["with_expansion"] += 1
    cname = unicodedata.normalize("NFC", cname or "")
    rows.append((cid, cname, kind, r["NameOnly"], r["Surname"], r["Forename"],
                 r["ForenameFull"], r["Initials"], r["BirthYear"], r["DeathYear"],
                 r["FloruitStart"], r["FloruitEnd"], r["DateText"], r["Uncertain"],
                 title_counts.get(cid, 0), part_counts.get(cid, 0)))
src.close()

out = sqlite3.connect(OUT)
out.execute("DROP TABLE IF EXISTS creator_parsed")
out.execute("""CREATE TABLE creator_parsed (
  CreatorID INTEGER PRIMARY KEY, CreatorName TEXT, Kind TEXT, NameOnly TEXT,
  Surname TEXT, Forename TEXT, ForenameFull TEXT, Initials TEXT,
  BirthYear INTEGER, DeathYear INTEGER, FloruitStart INTEGER, FloruitEnd INTEGER,
  DateText TEXT, Uncertain INTEGER, TitleCount INTEGER, PartCount INTEGER)""")
out.executemany("INSERT INTO creator_parsed VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", rows)
out.execute("CREATE INDEX cp_surname ON creator_parsed(Surname)")
out.execute("CREATE INDEX cp_birth ON creator_parsed(BirthYear)")
out.execute("CREATE INDEX cp_titles ON creator_parsed(TitleCount)")
out.commit(); out.close()

print(f"parsed {len(rows)} creators")
for k, v in stats.most_common():
    print(f"  {k}: {v}")
