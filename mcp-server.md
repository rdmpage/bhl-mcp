# MCP server

An [MCP](https://modelcontextprotocol.io/) server over a local SQLite build of the
BHL data dumps, so an LLM can search the Biodiversity Heritage Library, read pages,
and (once localities are loaded) ask geographic questions.

## Architecture

Three files, two transports, one shared handler — plus `queries.php`, which holds
all the SQL and is the only file that needs to change to add a tool:

```
queries.php           SQL and query functions (see INDEXING.md)
mcp_handler.php       Tool definitions, dispatch, row-to-text formatting
mcp_server.php        Stdio transport (Claude Code / Claude Desktop)
mcp_http_server.php   HTTP transport (POST JSON-RPC)
```

Both transports call `handleMcpRequest()`. Database handles come from
`config.inc.php`, which opens `bhl.db` and attaches `fts` and `geo`.

## Setup

### Claude Code

Add to `.mcp.json` in the project root, or to `~/.claude.json`:

```json
{
  "mcpServers": {
    "bhl": {
      "command": "php",
      "args": ["/Users/rpage/Development/bhl-mcp/mcp_server.php"]
    }
  }
}
```

### Claude Desktop

Same block, in
`~/Library/Application Support/Claude/claude_desktop_config.json`. Restart the app;
the tools appear under the hammer icon.

### HTTP

```sh
php -S localhost:3000 mcp_http_server.php
```

POST JSON-RPC to `/`. A GET returns a plain-text listing of the tools.

## Tools

### Bibliographic

| tool | takes | does |
|---|---|---|
| `search_titles` | `text`, `limit` | books and journals, by title, author or subject |
| `search_parts` | `text`, `limit` | articles and chapters |
| `search_creators` | `text`, `limit` | authors, corporate bodies, meetings |
| `creator_titles` | `creator_id`, `limit` | what a creator is credited on |
| `name_pages` | `name`, `limit` | pages a taxonomic name appears on |

`search_titles` and `search_creators` widen automatically — strict all-words match
first, then any-word, then substring — so a half-remembered title or a typo near the
end of a name still lands. A four-digit year inside a creator query is lifted out and
used as a life-date filter rather than a search term: `Thunberg 1743` finds the man,
not the year.

### Pages

| tool | takes | does |
|---|---|---|
| `page_text` | `page_id` | OCR text, fetched from BHL and cached on first use |
| `page_image` | `page_id` | the scan itself, returned as an image content block |

`page_image` is the fallback when OCR is garbled, and the only way to see a plate.

### Geographic

| tool | takes |
|---|---|
| `works_in_bbox` | `min_lat`, `max_lat`, `min_lon`, `max_lon`, `limit` |
| `parts_in_bbox` | same |
| `names_in_bbox` | same |
| `points_near` | `lat`, `lon`, `km`, `limit` |
| `title_points` | `title_id`, `limit` |
| `name_points` | `name`, `limit` |

**`bhl-geo.db` currently holds the schema and no data.** Every geographic tool
therefore returns a message saying so, rather than an empty result — an empty result
would read as "nothing was recorded from this region", which is a finding, and this
is not one. Load real points with `./load-geo.py` and the tools start answering.

## Notes for anyone adding a tool

**Never let a query function write to stdout.** On stdio the protocol *is* stdout, so
one stray `print_r` corrupts the JSON stream and the client disconnects with no useful
error. `mcp_run_tool()` wraps every call in an output buffer and redirects anything it
catches to stderr, so a slip is survivable — but it is a net, not a licence.

**Guard every field read.** `db_get()` sets a property only when the column was
non-empty, so a missing value is an absent key, not a null. Use `mcp_val()`. Note that
it keeps a literal `"0"`, so flags like `Uncertain` must be compared as numbers — an
emptiness test marks every creator's dates uncertain.

**Name matching is exact and case-sensitive.** `pagename.NameConfirmed` uses BINARY
collation, so `poecilia reticulata` matches nothing. `name_pages` says as much in its
no-results message instead of implying the name is absent from BHL.

**Escape before interpolating.** `queries.php` builds SQL by string concatenation, so
anything reaching a `LIKE` or an `=` needs `str_replace("'", "''", ...)`, and anything
reaching a `MATCH` needs `fts_query()` first — raw text there is FTS5 *syntax*.

See `INDEXING.md` for the index design and the reasoning behind the ranking weights.
