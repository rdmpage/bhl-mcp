#!/bin/sh
# Build the BHL bibliographic search layer into a SEPARATE database file.
#
#   ./build-fts.sh [path/to/bhl.db] [path/to/bhl-fts.db]
#
# Kept out of bhl.db so re-importing a BHL dump never forces an index rebuild,
# and so the 19 GB base file is never rewritten. Attach it at query time:
#   ATTACH 'bhl-fts.db' AS fts;
set -e

BHL="${1:-bhl.db}"
FTS="${2:-bhl-fts.db}"
BHL_ABS=$(cd "$(dirname "$BHL")" && pwd)/$(basename "$BHL")

# sorting 400k rows needs scratch space; point it somewhere with room
: "${SQLITE_TMPDIR:=$(dirname "$FTS")}"
export SQLITE_TMPDIR

echo "==> titles, parts, subjects, authors"
sqlite3 "$FTS" <<SQL
ATTACH '$BHL_ABS' AS bhl;
PRAGMA journal_mode=OFF;
PRAGMA synchronous=OFF;
PRAGMA cache_size=-2000000;

DROP TABLE IF EXISTS title_fts;
DROP TABLE IF EXISTS part_fts;
DROP TABLE IF EXISTS title_trgm;

-- Token search over titles. Subjects and authors are folded in so one MATCH
-- covers "what is this book, who wrote it, what is it about".
CREATE VIRTUAL TABLE title_fts USING fts5(
  TitleID UNINDEXED, FullTitle, ShortTitle, Authors, Subjects,
  tokenize = "unicode61 remove_diacritics 2",
  prefix   = '2 3 4'
);
INSERT INTO title_fts(TitleID, FullTitle, ShortTitle, Authors, Subjects)
SELECT t.TitleID, t.FullTitle, t.ShortTitle,
       (SELECT group_concat(c.CreatorName, ' ; ') FROM bhl.creator c WHERE c.TitleID = t.TitleID),
       (SELECT group_concat(s.Subject,      ' ; ') FROM bhl.subject s WHERE s.TitleID = t.TitleID)
FROM bhl.title t;

-- Token search over articles/chapters.
CREATE VIRTUAL TABLE part_fts USING fts5(
  PartID UNINDEXED, Title, ContainerTitle, Authors,
  tokenize = "unicode61 remove_diacritics 2",
  prefix   = '2 3 4'
);
INSERT INTO part_fts(PartID, Title, ContainerTitle, Authors)
SELECT p.PartID, p.Title, p.ContainerTitle,
       (SELECT group_concat(pc.CreatorName, ' ; ') FROM bhl.partcreator pc WHERE pc.PartID = p.PartID)
FROM bhl.part p;

-- Substring/fuzzy search over titles (LIKE '%...%' becomes index-backed).
CREATE VIRTUAL TABLE title_trgm USING fts5(
  TitleID UNINDEXED, FullTitle,
  tokenize = "trigram remove_diacritics 1"
);
INSERT INTO title_trgm(TitleID, FullTitle) SELECT TitleID, FullTitle FROM bhl.title;

INSERT INTO title_fts(title_fts)  VALUES('optimize');
INSERT INTO part_fts(part_fts)    VALUES('optimize');
INSERT INTO title_trgm(title_trgm) VALUES('optimize');
SQL

echo "==> parsing creator strings"
python3 "$(dirname "$0")/parse_creators.py" "$BHL_ABS" "$FTS"

echo "==> creator indexes"
sqlite3 "$FTS" <<SQL
PRAGMA journal_mode=OFF;
PRAGMA synchronous=OFF;

DROP TABLE IF EXISTS creator_fts;
DROP TABLE IF EXISTS creator_trgm;

CREATE VIRTUAL TABLE creator_fts USING fts5(
  CreatorID UNINDEXED, NameOnly, Surname, Forename, ForenameFull,
  tokenize = "unicode61 remove_diacritics 2",
  prefix   = '2 3 4'
);
INSERT INTO creator_fts(CreatorID, NameOnly, Surname, Forename, ForenameFull)
SELECT CreatorID, NameOnly, Surname, Forename, ForenameFull FROM creator_parsed;

CREATE VIRTUAL TABLE creator_trgm USING fts5(
  CreatorID UNINDEXED, NameOnly,
  tokenize = "trigram remove_diacritics 1"
);
INSERT INTO creator_trgm(CreatorID, NameOnly) SELECT CreatorID, NameOnly FROM creator_parsed;

INSERT INTO creator_fts(creator_fts)   VALUES('optimize');
INSERT INTO creator_trgm(creator_trgm) VALUES('optimize');
SQL

sqlite3 "$FTS" "PRAGMA journal_mode=WAL;" >/dev/null
echo "==> done: $FTS"
ls -lh "$FTS"
