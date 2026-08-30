# BHL item coverage — background notes

Context for working with `item_coverage`, `item_parts` and `item_pages` on the BHL MCP
server. These notes record things that are easy to get wrong; each one caused a real
mistake in an earlier session.

## What coverage measures

An *item* is one scanned physical volume or issue. A *part* is an article or chapter
segmented out of it. Coverage is the fraction of the item's scanned pages that fall
inside at least one part.

BHL segments only some items, and only some articles within those items. Low coverage
means nothing has been indexed, not that the volume is empty or unavailable — the pages
are still readable with `page_text` and `page_image` via `item_pages`.

## Ordering: SequenceOrder is the only key

Every page in an item has a **SequenceOrder**: a 1-based index into reading order. This
is what `item_coverage` calls a *scan position*, and what `item_pages` returns pages in.

**PageIDs are not in reading order.** Do not sort by PageID, do not do arithmetic on
them, do not assume a part's pages are contiguous in ID space. A single item may
interleave several ID blocks, some ascending and some descending.

Two real examples:

- ItemID 175550 — positions 1–53 descend (46343842 → 46343790), positions 54–86 jump
  back to a lower block and ascend (46343757 → 46343789), then position 87 onward
  continues from 46343843 upward.
- ItemID 110097 — positions 1–38 descend, 39–71 jump back and ascend, 72–120 continue
  from a higher block.

In both, printed pagination runs cleanly straight across the ID discontinuities. The IDs
are shuffled; the reading order is not.

To locate a part within an item, read the **Articles, by scan position** section of
`item_coverage`, which gives each part's start position and page count directly.
`item_parts` also reports the scan position alongside each `StartPageID`. Neither
requires walking `item_pages` and searching for the id, and neither is limited by that
tool's 1000-page cap.

## Parts are not always contiguous

A part's pages can be separated by hundreds of positions. In ItemID 175550, six of the
twelve parts are non-contiguous — PartID 367968 starts at position 11, has 4 pages, and
one of them is at position 487. These are plates bound at the end of the volume but
belonging to an article printed earlier.

`item_coverage` flags this explicitly ("not contiguous — also has pages as late as
position 487"). Do not treat a part's first and last positions as a range: for these
parts the span is far larger than the page count, and a coverage bar drawn from
start-to-end would cover pages belonging to other articles.

Note this cuts against the assumption that end-bound plates are always orphaned. Some
are attached to their article and some are not; the tool distinguishes them.

## Reading the percentage

The denominator is **every scanned page** — covers, front matter, blanks, plates,
advertisements. It is not "percentage of articles indexed" and will usually understate
how much article content is covered.

ItemID 175550 reports 56% segmented (297 of 532 pages in 12 articles). Of the 235
uncovered pages, roughly 45 are end-bound plates and blanks and 10 are front matter.
The genuine shortfall is about 180 pages of unindexed articles, so article-level coverage
is nearer 70%.

Always read the page-type breakdown before quoting the headline number.

## Page types and what the patterns mean

Each uncovered run is reported with a page-type tally. The interpretation:

- **Text** in a long run — unindexed article content. This is the real gap.
- **Issue Start** + **Table of Contents** at the head of a run — a whole issue was
  skipped during segmentation, rather than individual articles being missed. In
  ItemID 175550 all three large gaps (123, 36 and 24 pages) begin this way.
- **Blank**, **Illustration**, **Map** clustered at the end of an item — plates bound
  after the text. Some belong to articles and are covered (see *Parts are not always
  contiguous*); the rest are genuinely unattached. Not a segmentation failure either way.
- **Chart** runs — often meteorological registers or statistical tables, especially in
  physical-science series. Arguably should not count against segmentation.
- **Cover** in the first and last runs — the item is a single issue, not a bound volume.

A page can carry more than one type: 4.77M PageIDs in BHL have two or more, and 3.7M
pages are both `Text` and `Illustration`. A tally of 124 Text + 8 Illustration over a
125-page run is not an arithmetic error.

A printed page shown as `?` (e.g. "printed ? to 253") is an unnumbered page, not missing
data.

Printed page numbers restart inside a bound volume, so a run's first and last printed
numbers are often not a range. Where that happens `item_coverage` gives scan positions
and quotes the printed numbers as endpoints ("scan positions 463–1247 (printed 462 to
335)") rather than an impossible range.

The run list is **truncated** to the twelve longest while the header totals are complete.
Summing the listed runs will not reproduce the total.

## Do not infer missing articles from article numbering

Article numbers in serials are erratic and often absent entirely. A gap in the sequence
is weak evidence at best.

In ItemID 175550 the numbering runs I–V, XIV–XVIII, XXII–XXIII, and the absences do
line up with the unsegmented runs — but the defensible evidence is the page types: 121
and 33 pages of Text with an issue boundary at the head of each. Lead with that.

## Metadata disagreements are findings, not noise

Where a part's metadata disagrees with the item's, **either source may be wrong, or
both**. Do not silently prefer one, and do not group, filter or sort on the disputed
field. Surface the disagreement — these cases are interesting in their own right and
worth collecting.

Observed examples:

- **Volume number.** In ItemID 175550, ten parts are labelled vol. 58 while PartID
  350179 (p. 254) and PartID 350246 (pp. 409–440) are labelled vol. 57, despite sitting
  in the same continuous page run as their neighbours.
- **Title vs running head.** PartID 245308 in ItemID 110097 is titled "On new and
  little-known Mantidae"; the printed running head on its pages reads "Mantodea".
- **Dates** can disagree between part and item in the same way. A volume issued in
  fascicles over several years can make both labels defensible at once — relevant if the
  date is being used for nomenclatural priority.

An item can also span several volumes: 1,671 items have parts carrying more than one
`Volume` value, and ItemID 46213 is bound as pt. 1–6. `item_parts` prints the volume per
part and flags the span, so differing volume numbers within one item are not necessarily
a disagreement.

## `item_parts` ordering

`item_parts` returns parts in the order they appear in the item, ordered by the scan
position of each part's `StartPageID`.

**Do not order by `part.SequenceOrder`.** It is an edit or ingest order, not a position
within the item, and the two disagree for 4,093 of the 19,443 items that have more than
one part. Sorted by that column ItemID 175550 comes back XVI, XVII, V, XXII, XXIII,
XVIII, XIV, II, IV, III, XV, I. This was a real bug in the tool, fixed 2026-08-30; the
column is still there and still wrong, so it is worth knowing why.

## The grid image

`image: true` returns one cell per page in reading order, 40 per row, coloured by
article, grey for uncovered. Colours distinguish adjacent articles and carry no other
meaning.

There are no page thumbnails, deliberately. One thumbnail per page would be several
hundred BHL fetches and megabytes of base64 for a single call — 346 pages costs roughly
6 minutes and 8 MB, and the largest item (4,788 pages) around 80 minutes and 113 MB. The
coloured grid costs under a kilobyte and no fetches at all.

At typical rendered sizes individual cells are hard to resolve. Treat it as indicative;
the text block is authoritative.

## Two worked profiles

**ItemID 175550** — 532 pages, 297 in 12 articles, 56%. A bound volume of the *Journal
of the Asiatic Society of Bengal*, 1889. Three unsegmented issues (123, 36 and 24 pages,
each opening with Issue Start + Table of Contents), and ~45 pages of plates and blanks at
the end. Parts start at positions 11, 14, 30, 120, 128, 268, 273, 297, 314, 324, 381,
431. Six of the twelve are non-contiguous, with plates as late as position 529.

**ItemID 110097** — 120 pages, 16 in 1 article, 13%. A single combined issue (JASB Part
II, Physical Science, Nos. II and III, 1882) in which exactly one article was segmented.
Both gaps are predominantly Text, so this is genuine unindexed content, not plates. The
run after the article opens with the issue masthead and continues into a further
taxonomic paper that has no part record.

These two are worth keeping as contrasting cases: a well-segmented volume with
structural gaps, and an issue that was barely segmented at all.
