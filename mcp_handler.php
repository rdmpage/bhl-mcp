<?php
// mcp_handler.php
// Shared MCP request handler for both stdio and HTTP transports.
//
// All the actual work happens in queries.php. This file does three things:
// declares the tools, dispatches to them, and turns rows into text an LLM can read.

require_once(dirname(__FILE__) . '/queries.php');

//----------------------------------------------------------------------------------------
// Request dispatcher

function handleMcpRequest($request)
{
	$id     = isset($request['id']) ? $request['id'] : null;
	$method = isset($request['method']) ? $request['method'] : null;
	$params = isset($request['params']) ? $request['params'] : array();

	$response = array('jsonrpc' => '2.0', 'id' => $id);

	switch ($method)
	{
		case 'initialize':
			$protocol = isset($params['protocolVersion']) ? $params['protocolVersion'] : '2025-06-18';

			$response['result'] = array(
				'protocolVersion' => $protocol,
				'serverInfo' => array(
					'name'    => 'bhl-mcp',
					'version' => '0.1.0',
				),
				'capabilities' => array(
					'tools' => array('list' => true, 'call' => true),
				),
			);
			break;

		case 'notifications/initialized':
			return null;

		case 'tools/list':
			$response['result'] = array('tools' => getToolDefinitions());
			break;

		case 'tools/call':
			$name = isset($params['name']) ? $params['name'] : null;
			$args = isset($params['arguments']) ? $params['arguments'] : array();

			$response['result'] = mcp_run_tool($name, $args);
			break;

		case 'ping':
			$response['result'] = array('ok' => true);
			break;

		default:
			if ($id === null)
			{
				return null;
			}

			$response['error'] = array(
				'code'    => -32601,
				'message' => 'Method not found: ' . $method,
			);
			break;
	}

	return $response;
}

//----------------------------------------------------------------------------------------
// Run a tool and wrap the outcome in MCP content blocks.
//
// The output buffer is not decoration. On stdio the protocol IS stdout, so a stray
// print_r or a PHP notice inside a query function would corrupt the JSON stream and
// the client would drop the connection with no useful error. Anything a tool prints
// is captured here and pushed to stderr instead.
function mcp_run_tool($name, $args)
{
	ob_start();

	try
	{
		$result = callTool($name, $args);
	}
	catch (Exception $e)
	{
		$result = null;
		$error  = $e->getMessage();
	}

	$stray = ob_get_clean();

	if ($stray !== '')
	{
		fwrite(STDERR, "[bhl-mcp] discarded tool output: " . $stray . "\n");
	}

	if (isset($error))
	{
		return array(
			'content' => array(array('type' => 'text', 'text' => 'Error: ' . $error)),
			'isError' => true,
		);
	}

	if ($result === null)
	{
		return array(
			'content' => array(array('type' => 'text', 'text' => 'Unknown tool: ' . $name)),
			'isError' => true,
		);
	}

	// A tool may hand back ready-made content blocks (page_image does), otherwise text.
	if (is_array($result))
	{
		return array('content' => $result);
	}

	return array('content' => array(array('type' => 'text', 'text' => $result)));
}

//----------------------------------------------------------------------------------------
// Tool definitions

function getToolDefinitions()
{
	$limit = array(
		'type'        => 'integer',
		'description' => 'Maximum number of results (default 10, max 100).',
	);

	return array(
		array(
			'name'        => 'search',
			'description' => 'Search the Biodiversity Heritage Library by words in a title, author or subject. Covers both books and journals (titles) and the articles and chapters within them (parts), because a reference may be either. Returns the two kinds in separate sections; restrict with the type argument when only one is wanted. Search widens automatically - an exact all-words match first, then any-word, then substring matching for approximate wording.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'text'  => array('type' => 'string', 'description' => 'Words to search for, e.g. "orchids of madagascar".'),
					'type'  => array(
						'type'        => 'string',
						'enum'        => array('all', 'titles', 'articles'),
						'description' => 'What to search: "all" (default) returns both, "titles" only books and journals, "articles" only parts.',
					),
					'limit' => array('type' => 'integer', 'description' => 'Maximum results per section (default 10, max 100).'),
				),
				'required' => array('text'),
			),
		),
		array(
			'name'        => 'search_creators',
			'description' => 'Search BHL creators: authors, corporate bodies and meetings. Results are ranked by how much the person published, so the prolific Hooker comes before his namesakes. A year in the query is used as a life-date filter, not as a search term: "Hooker 1817" finds people alive or active in 1817.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'text'  => array('type' => 'string', 'description' => 'Name to search for, optionally with a year, e.g. "Thunberg 1743".'),
					'limit' => $limit,
				),
				'required' => array('text'),
			),
		),
		array(
			'name'        => 'creator_titles',
			'description' => 'List the titles a creator is credited on. Takes the CreatorID returned by search_creators.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'creator_id' => array('type' => 'integer', 'description' => 'BHL CreatorID.'),
					'limit'      => $limit,
				),
				'required' => array('creator_id'),
			),
		),
		array(
			'name'        => 'title_info',
			'description' => 'Get details of one BHL title (a book or journal) from its TitleID, including the external identifiers BHL holds for it — ISSN, OCLC, Wikidata and others. Use this to follow up a search hit, or to reconcile a title against an external authority.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'title_id' => array('type' => 'integer', 'description' => 'BHL TitleID.'),
				),
				'required' => array('title_id'),
			),
		),
		array(
			'name'        => 'title_items',
			'description' => 'List the items BHL holds for a title — the physical volumes that were scanned, with year, volume designation and holding institution. Use this to answer "which volumes or years are available?" for a journal or multi-volume work.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'title_id' => array('type' => 'integer', 'description' => 'BHL TitleID.'),
					'limit'    => array('type' => 'integer', 'description' => 'Maximum items to list (default 100, max 500). Some serials run to over a thousand volumes.'),
				),
				'required' => array('title_id'),
			),
		),
		array(
			'name'        => 'title_parts',
			'description' => 'List the articles and chapters BHL has indexed within a title, oldest first, optionally restricted to one year. Each carries a StartPageID that can be passed to page_text or page_image to read it. Note that many titles have no parts indexed at all — an empty result means nothing has been segmented into articles, not that the title is empty.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'title_id' => array('type' => 'integer', 'description' => 'BHL TitleID.'),
					'year'     => array('type' => 'integer', 'description' => 'Optional four-digit year. Matches the year anywhere in the article date, so an article spanning 1890-1891 is returned for either year.'),
					'limit'    => array('type' => 'integer', 'description' => 'Maximum articles to list (default 50, max 500). Long-running journals have thousands.'),
				),
				'required' => array('title_id'),
			),
		),
		array(
			'name'        => 'item_parts',
			'description' => 'List the articles and chapters within one scanned item (a single volume), in the order they appear — effectively its table of contents. ItemIDs come from title_items. Each part carries a StartPageID for page_text or page_image.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'item_id' => array('type' => 'integer', 'description' => 'BHL ItemID, as returned by title_items.'),
					'limit'   => array('type' => 'integer', 'description' => 'Maximum articles to list (default 100, max 500).'),
				),
				'required' => array('item_id'),
			),
		),
		array(
			'name'        => 'part_info',
			'description' => 'Full details of one article or chapter: its citation, any DOI and external identifiers, and the list of PageIDs it occupies. Use the page list to read the whole article with page_text, rather than only its first page.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'part_id' => array('type' => 'integer', 'description' => 'BHL PartID.'),
				),
				'required' => array('part_id'),
			),
		),
		array(
			'name'        => 'item_pages',
			'description' => 'List the PageIDs of a scanned item, in reading order. Use this to walk a volume page by page with page_text or page_image when it has no article-level parts, or to reach pages that no part covers. ItemIDs come from title_items.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'item_id' => array('type' => 'integer', 'description' => 'BHL ItemID, as returned by title_items.'),
					'limit'   => array('type' => 'integer', 'description' => 'Maximum PageIDs to list (default 200, max 1000).'),
				),
				'required' => array('item_id'),
			),
		),
		array(
			'name'        => 'name_pages',
			'description' => 'Find the BHL pages on which a taxonomic name appears, with the work each page belongs to. Set illustrated to true for pages carrying a plate or figure, which with page_image is how to actually show someone a picture of a taxon. The name must be matched exactly as BHL records it, and matching is case-sensitive on the genus.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'name'        => array('type' => 'string', 'description' => 'Taxonomic name, e.g. "Rosa canina". Case-sensitive, exact match.'),
					'illustrated' => array('type' => 'boolean', 'description' => 'When true, return only pages typed Illustration or Foldout. Default false.'),
					'limit'       => array('type' => 'integer', 'description' => 'Maximum pages (default 50, max 200). Common names run to thousands.'),
				),
				'required' => array('name'),
			),
		),
		array(
			'name'        => 'page_text',
			'description' => 'Get the OCR text of a single BHL page. Fetched from BHL and cached locally on first use, so the first call for a page is slower.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'page_id' => array('type' => 'integer', 'description' => 'BHL PageID.'),
				),
				'required' => array('page_id'),
			),
		),
		array(
			'name'        => 'page_image',
			'description' => 'Get the scanned image of a single BHL page, so it can be read directly rather than through OCR. Useful when the OCR is garbled or when the page carries a plate or figure. Ask for a larger size when the detail matters — a figure occupying a third of a medium page is only about 150 px across, which is too coarse to identify a specimen from. To return just one figure, give a crop box as fractions of the page (left/top/right/bottom, 0-1); fractions are resolution-independent, so the same box works at any size. Crop generously — a box read off a page image is an estimate, and a tight one clips the subject.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'page_id' => array('type' => 'integer', 'description' => 'BHL PageID.'),
					'size'    => array(
						'type'        => 'string',
						'enum'        => array('small', 'medium', 'large', 'full'),
						'description' => 'Image width: small ~235px, medium ~465px (default), large ~930px, full ~1713px. Sizes are whole pages, so a plate needs large or full to be legible. Defaults to full when a crop is given.',
					),
					'left'   => array('type' => 'number', 'description' => 'Crop box, left edge as a fraction of page width (0-1).'),
					'top'    => array('type' => 'number', 'description' => 'Crop box, top edge as a fraction of page height (0-1).'),
					'right'  => array('type' => 'number', 'description' => 'Crop box, right edge as a fraction of page width (0-1).'),
					'bottom' => array('type' => 'number', 'description' => 'Crop box, bottom edge as a fraction of page height (0-1).'),
				),
				'required' => array('page_id'),
			),
		),
	);
}

//----------------------------------------------------------------------------------------
// Tool dispatch

function callTool($name, $args)
{
	switch ($name)
	{
		case 'search':          return tool_search($args);
		case 'search_creators': return tool_search_creators($args);
		case 'creator_titles':  return tool_creator_titles($args);
		case 'title_info':      return tool_title_info($args);
		case 'title_items':     return tool_title_items($args);
		case 'title_parts':     return tool_title_parts($args);
		case 'item_parts':      return tool_item_parts($args);
		case 'part_info':       return tool_part_info($args);
		case 'item_pages':      return tool_item_pages($args);
		case 'name_pages':      return tool_name_pages($args);
		case 'page_text':       return tool_page_text($args);
		case 'page_image':      return tool_page_image($args);
		default:                return null;
	}
}

//----------------------------------------------------------------------------------------
// Helpers

// db_get() only sets a property when the column was non-empty, so every field read
// has to be guarded - there is no null to test against, the key simply is not there.
function mcp_val($row, $key, $default = '')
{
	return isset($row->{$key}) ? $row->{$key} : $default;
}

function mcp_arg($args, $key, $default = null)
{
	return isset($args[$key]) ? $args[$key] : $default;
}

function mcp_limit($args, $default = 10)
{
	$n = (int)mcp_arg($args, 'limit', $default);

	if ($n < 1)   { $n = $default; }
	if ($n > 100) { $n = 100; }

	return $n;
}

function mcp_url($kind, $id)
{
	return 'https://www.biodiversitylibrary.org/' . $kind . '/' . $id;
}

// Guard the search tools: without bhl-fts.db every one of them is a syntax error
// about a missing table, which tells the caller nothing useful.
function mcp_need_fts()
{
	global $config;

	if (!$config['has_fts'])
	{
		return 'The full-text index (bhl-fts.db) has not been built, so searching is unavailable. Run ./build-fts.sh to build it.';
	}

	return '';
}

function mcp_none($what)
{
	return 'No ' . $what . ' found.';
}

//----------------------------------------------------------------------------------------
// Tool implementations

function tool_search($args)
{
	$err = mcp_need_fts();
	if ($err !== '') { return $err; }

	$text = trim(mcp_arg($args, 'text', ''));
	if ($text === '') { return 'Provide some text to search for.'; }

	$type = strtolower(trim(mcp_arg($args, 'type', 'all')));

	if (!in_array($type, array('all', 'titles', 'articles')))
	{
		$type = 'all';
	}

	$limit = mcp_limit($args);

	$titles   = ($type === 'articles') ? array() : search_titles($text, $limit);
	$articles = ($type === 'titles')   ? array() : search_parts($text, $limit);

	if (count($titles) === 0 && count($articles) === 0)
	{
		return mcp_none('results matching "' . $text . '"');
	}

	$lines = array();

	// Two sections rather than one ranked list. bm25 scores are not comparable
	// across title_fts and part_fts - different corpus sizes (192k vs 405k) and
	// different column weights - and search_titles widens through AND, OR and
	// substring passes whose ranks are not comparable even with each other. Sorting
	// them together would invent an ordering the data does not support.
	if ($type !== 'articles')
	{
		$lines[] = 'Books and journals (' . count($titles) . '):';

		if (count($titles) === 0)
		{
			$lines[] = '  none';
		}

		foreach ($titles as $hit)
		{
			$lines[] = '  TitleID ' . $hit->TitleID . ' - ' . mcp_val($hit, 'FullTitle', '(untitled)');

			$snippet = mcp_val($hit, 'Snippet');
			if ($snippet !== '') { $lines[] = '    ' . $snippet; }

			$lines[] = '    ' . mcp_url('bibliography', $hit->TitleID);
		}

		$lines[] = '';
	}

	if ($type !== 'titles')
	{
		$lines[] = 'Articles and chapters (' . count($articles) . '):';

		if (count($articles) === 0)
		{
			$lines[] = '  none';
		}

		foreach ($articles as $hit)
		{
			$lines[] = '  PartID ' . $hit->PartID . ' - ' . mcp_val($hit, 'Title', '(untitled)');

			$cite = array();
			if (mcp_val($hit, 'ContainerTitle') !== '') { $cite[] = mcp_val($hit, 'ContainerTitle'); }
			if (mcp_val($hit, 'Volume') !== '')         { $cite[] = 'vol. ' . mcp_val($hit, 'Volume'); }
			if (mcp_val($hit, 'Date') !== '')           { $cite[] = mcp_val($hit, 'Date'); }
			if (mcp_val($hit, 'PageRange') !== '')      { $cite[] = 'pp. ' . mcp_val($hit, 'PageRange'); }

			if (count($cite) > 0) { $lines[] = '    ' . join(', ', $cite); }

			$lines[] = '    ' . mcp_url('part', $hit->PartID);
		}
	}

	return implode("\n", $lines);
}

function tool_search_creators($args)
{
	$err = mcp_need_fts();
	if ($err !== '') { return $err; }

	$text = trim(mcp_arg($args, 'text', ''));
	if ($text === '') { return 'Provide a name to search for.'; }

	$hits = search_creators($text, mcp_limit($args));
	if (count($hits) === 0) { return mcp_none('creators matching "' . $text . '"'); }

	$lines = array(count($hits) . ' creator(s) matching "' . $text . '":', '');

	foreach ($hits as $hit)
	{
		$lines[] = 'CreatorID ' . $hit->CreatorID . ' - ' . mcp_val($hit, 'CreatorName');

		$about = array();

		$dates = mcp_dates($hit);
		if ($dates !== '') { $about[] = $dates; }

		if (mcp_val($hit, 'Kind') !== '') { $about[] = mcp_val($hit, 'Kind'); }

		$works = (int)mcp_val($hit, 'TitleCount', 0) + (int)mcp_val($hit, 'PartCount', 0);
		if ($works > 0) { $about[] = $works . ' work(s)'; }

		if (count($about) > 0) { $lines[] = '  ' . join('; ', $about); }

		$lines[] = '  ' . mcp_url('creator', $hit->CreatorID);
		$lines[] = '';
	}

	return join("\n", $lines);
}

// Life dates, rendered the way a bibliography would render them. BirthYear and
// DeathYear may each be missing on their own, and a floruit stands in for both.
function mcp_dates($hit)
{
	$birth = mcp_val($hit, 'BirthYear');
	$death = mcp_val($hit, 'DeathYear');

	if ($birth !== '' || $death !== '')
	{
		$dates = $birth . '-' . $death;

		// db_get() keeps a literal "0" (it only drops empty strings), so this has to be
		// compared as a number - testing it for emptiness marks every creator uncertain.
		if ((int)mcp_val($hit, 'Uncertain', 0) === 1) { $dates .= '?'; }

		return $dates;
	}

	$from = mcp_val($hit, 'FloruitStart');
	$to   = mcp_val($hit, 'FloruitEnd');

	if ($from !== '' || $to !== '')
	{
		return 'fl. ' . $from . '-' . $to;
	}

	return '';
}

function tool_creator_titles($args)
{
	$id = (int)mcp_arg($args, 'creator_id', 0);
	if ($id === 0) { return 'Provide a creator_id.'; }

	$hits = creator_titles($id, mcp_limit($args, 20));
	if (count($hits) === 0) { return mcp_none('titles for CreatorID ' . $id); }

	$lines = array(count($hits) . ' title(s) for CreatorID ' . $id . ':', '');

	foreach ($hits as $hit)
	{
		$role = mcp_val($hit, 'CreatorType');

		$lines[] = 'TitleID ' . $hit->TitleID . ' - ' . mcp_val($hit, 'FullTitle', '(untitled)')
			. ($role !== '' ? ' [' . $role . ']' : '');
		$lines[] = '  ' . mcp_url('bibliography', $hit->TitleID);
	}

	return join("\n", $lines);
}

// get_title_info() and get_part() both attach a 'creator' array of {id, name}
// objects. It has to be rendered explicitly: the identifier sweeps below skip
// non-scalars, so without this the authors are silently dropped.
function mcp_creator_lines($obj)
{
	if (!isset($obj->creator) || !is_array($obj->creator))
	{
		return array();
	}

	$lines = array('', 'Creators:');

	foreach ($obj->creator as $creator)
	{
		if (!is_object($creator))
		{
			continue;
		}

		$name = isset($creator->name) ? $creator->name : '(unnamed)';

		$lines[] = '  ' . $name
			. (isset($creator->id) ? ' [CreatorID ' . $creator->id . ', use creator_titles]' : '');
	}

	return (count($lines) > 2) ? $lines : array();
}

// A resolvable link for the identifiers that have one. BHL stores the bare value,
// which is not much use to a reader or to a model trying to follow it up.
function mcp_identifier_url($name, $value)
{
	switch ($name)
	{
		case 'Wikidata':
			return 'https://www.wikidata.org/wiki/' . $value;

		case 'ISSN':
		case 'Linking ISSN':
			return 'https://portal.issn.org/resource/ISSN/' . $value;

		case 'OCLC':
			// BHL holds these both zero-padded and not; WorldCat wants them bare.
			return 'https://worldcat.org/oclc/' . ltrim($value, '0');

		case 'DOI':
			return 'https://doi.org/' . $value;

		case 'BioStor':
			return 'https://biostor.org/reference/' . $value;

		case 'JSTOR':
			return 'https://www.jstor.org/stable/' . $value;

		default:
			return '';
	}
}

function tool_title_info($args)
{
	$id = (int)mcp_arg($args, 'title_id', 0);
	if ($id === 0) { return 'Provide a title_id.'; }

	$info = get_title_info($id);
	if (!$info) { return 'No title found with TitleID ' . $id . '.'; }

	$lines = array();
	$lines[] = 'TitleID ' . $info->TitleID . ' - ' . mcp_val($info, 'FullTitle', '(untitled)');

	$short = mcp_val($info, 'ShortTitle');

	if ($short !== '' && $short !== mcp_val($info, 'FullTitle'))
	{
		$lines[] = 'Short title: ' . $short;
	}

	$lines[] = mcp_url('bibliography', $info->TitleID);

	$lines = array_merge($lines, mcp_creator_lines($info));

	// What BHL actually holds, which is a different question from what the title is.
	// A range alone can badly overstate coverage, so the item count and the number
	// of undated items go with it.
	if (isset($info->coverage) && is_object($info->coverage))
	{
		$c     = $info->coverage;
		$items = (int)mcp_val($c, 'Items', 0);
		$from  = mcp_val($c, 'FromYear');
		$to    = mcp_val($c, 'ToYear');

		if ($items > 0)
		{
			$held = $items . ' item(s) held';

			if ($from !== '' && $to !== '')
			{
				$held .= ', ' . ($from === $to ? $from : $from . '-' . $to);
			}

			$undated = (int)mcp_val($c, 'Undated', 0);

			if ($undated > 0)
			{
				$held .= ' (' . $undated . ' undated, so the range does not cover everything)';
			}

			$lines[] = $held;
		}
		else
		{
			$lines[] = 'No items held - nothing has been scanned for this title.';
		}
	}

	// get_title_info() attaches each identifier as a property named after BHL's own
	// IdentifierName, so anything that is not one of the title columns is one.
	// Non-scalars are skipped: 'coverage' is an object, and anything added later
	// would otherwise be concatenated into a string and fatal.
	$skip = array(
		'TitleID' => true, 'FullTitle' => true, 'ShortTitle' => true,
		'coverage' => true, 'creator' => true,
	);

	$identifiers = array();

	foreach ($info as $key => $value)
	{
		if (isset($skip[$key]))
		{
			continue;
		}

		if (is_object($value))
		{
			continue;
		}

		$values = is_array($value) ? $value : array($value);

		$scalars = array();

		foreach ($values as $v)
		{
			if (!is_array($v) && !is_object($v))
			{
				$scalars[] = $v;
			}
		}

		if (count($scalars) > 0)
		{
			$identifiers[$key] = $scalars;
		}
	}

	if (count($identifiers) === 0)
	{
		$lines[] = '';
		$lines[] = 'No external identifiers recorded.';

		return implode("\n", $lines);
	}

	$lines[] = '';
	$lines[] = 'Identifiers:';

	foreach ($identifiers as $name => $values)
	{
		foreach (array_unique($values) as $value)
		{
			$url  = mcp_identifier_url($name, $value);
			$line = '  ' . $name . ': ' . $value;

			if ($url !== '')
			{
				$line .= ' - ' . $url;
			}

			$lines[] = $line;
		}
	}

	return implode("\n", $lines);
}

function tool_title_items($args)
{
	$id = (int)mcp_arg($args, 'title_id', 0);
	if ($id === 0) { return 'Provide a title_id.'; }

	$limit = (int)mcp_arg($args, 'limit', 100);
	if ($limit < 1)   { $limit = 100; }
	if ($limit > 500) { $limit = 500; }

	// Ask for one more than we will show, so we can tell the difference between
	// "that is all of them" and "there are more" - without that, a truncated list
	// reads as a complete holdings statement.
	$items = get_title_items($id, $limit + 1);

	if (count($items) === 0)
	{
		return 'No items found for TitleID ' . $id . '. The title may not exist, or nothing has been scanned for it.';
	}

	$more  = (count($items) > $limit);
	$items = array_slice($items, 0, $limit);

	// Year is the sort key, so the first and last rows bound the run.
	$first = mcp_val($items[0], 'Year');
	$last  = mcp_val($items[count($items) - 1], 'Year');

	$span = ($first !== '' && $last !== '' && $first !== $last) ? ' (' . $first . '-' . $last . ')' : '';

	$lines = array();
	$lines[] = count($items) . ' item(s) for TitleID ' . $id . $span . ':';

	if ($more)
	{
		$lines[] = 'There are more than this - the list is truncated at ' . $limit . '. Raise limit to see further.';
	}

	$lines[] = '';

	foreach ($items as $item)
	{
		$parts = array();

		if (mcp_val($item, 'Year') !== '')        { $parts[] = mcp_val($item, 'Year'); }
		if (mcp_val($item, 'VolumeInfo') !== '')  { $parts[] = mcp_val($item, 'VolumeInfo'); }

		$lines[] = 'ItemID ' . $item->ItemID . ' - ' . (count($parts) > 0 ? join('  ', $parts) : '(no volume information)');

		if (mcp_val($item, 'InstitutionName') !== '')
		{
			$lines[] = '  held by ' . mcp_val($item, 'InstitutionName');
		}

		$lines[] = '  ' . mcp_url('item', $item->ItemID);
	}

	return implode("\n", $lines);
}

function tool_title_parts($args)
{
	$id = (int)mcp_arg($args, 'title_id', 0);
	if ($id === 0) { return 'Provide a title_id.'; }

	$limit = (int)mcp_arg($args, 'limit', 50);
	if ($limit < 1)   { $limit = 50; }
	if ($limit > 500) { $limit = 500; }

	$year = (int)mcp_arg($args, 'year', 0);
	if ($year <= 0) { $year = null; }

	// One more than we show, so a truncated list can say so - see tool_title_items.
	$parts = get_title_parts($id, $limit + 1, $year);

	if (count($parts) === 0)
	{
		// Two quite different findings, and conflating them would be misleading:
		// nothing indexed at all, versus nothing in the year asked for.
		if ($year !== null)
		{
			return 'No articles dated ' . $year . ' are indexed for TitleID ' . $id . '. Other years may still have articles - call again without a year to see the range covered.';
		}

		return 'No articles are indexed for TitleID ' . $id . '. BHL segments only some titles into parts, so this does not mean the title has no content - try title_items for the volumes that were scanned.';
	}

	$more  = (count($parts) > $limit);
	$parts = array_slice($parts, 0, $limit);

	$first = mcp_val($parts[0], 'Date');
	$last  = mcp_val($parts[count($parts) - 1], 'Date');

	$span = ($first !== '' && $last !== '' && $first !== $last) ? ' (' . $first . '-' . $last . ')' : '';

	$lines = array();
	$lines[] = count($parts) . ' article(s) for TitleID ' . $id
		. ($year !== null ? ' dated ' . $year : $span) . ':';

	if ($more)
	{
		$lines[] = 'There are more than this - the list is truncated at ' . $limit . '. Raise limit, or use search with type "articles" to search within them.';
	}

	$lines[] = '';

	foreach ($parts as $part)
	{
		$lines[] = 'PartID ' . $part->PartID . ' - ' . mcp_val($part, 'Title', '(untitled)');

		$cite = array();
		if (mcp_val($part, 'Date') !== '')      { $cite[] = mcp_val($part, 'Date'); }
		if (mcp_val($part, 'Volume') !== '')    { $cite[] = 'vol. ' . mcp_val($part, 'Volume'); }
		if (mcp_val($part, 'PageRange') !== '') { $cite[] = 'pp. ' . mcp_val($part, 'PageRange'); }

		if (count($cite) > 0)
		{
			$lines[] = '  ' . join(', ', $cite);
		}

		// The route into the text - name it so the model knows what to do next.
		if (mcp_val($part, 'StartPageID') !== '')
		{
			$lines[] = '  starts at PageID ' . $part->StartPageID . ' (use page_text to read it)';
		}

		$lines[] = '  ' . mcp_url('part', $part->PartID);
	}

	return implode("\n", $lines);
}

function tool_item_parts($args)
{
	$id = (int)mcp_arg($args, 'item_id', 0);
	if ($id === 0) { return 'Provide an item_id.'; }

	$limit = (int)mcp_arg($args, 'limit', 100);
	if ($limit < 1)   { $limit = 100; }
	if ($limit > 500) { $limit = 500; }

	$parts = get_item_parts($id, $limit + 1);

	if (count($parts) === 0)
	{
		return 'No articles are indexed within ItemID ' . $id . '. BHL segments only some items into parts, so the volume may well have been scanned in full without its contents being listed - the pages are still readable with page_text.';
	}

	$more  = (count($parts) > $limit);
	$parts = array_slice($parts, 0, $limit);

	// Flag a bound run up front, so the differing volume numbers below read as the
	// item's structure rather than as inconsistent data.
	$volumes = array();

	foreach ($parts as $part)
	{
		$v = mcp_val($part, 'Volume');

		if ($v !== '') { $volumes[$v] = true; }
	}

	$volumes = array_keys($volumes);

	// Natural sort: these are volume labels, so "2" must come before "10", and some
	// carry a suffix. They arrive in SequenceOrder, which does not reliably track
	// volume - in ItemID 46213 a single part of vol. 6 sits at sequence 1, ahead of
	// the whole of vol. 1.
	sort($volumes, SORT_NATURAL);

	$spans = (count($volumes) > 1) ? ' spanning vol. ' . join(', ', $volumes) : '';

	$lines = array();

	// "as they appear in the item" rather than "in volume order": the sort key is
	// SequenceOrder, the physical order of the scan, which is not the same thing.
	$lines[] = count($parts) . ' article(s) in ItemID ' . $id . $spans . ', in the order they appear in the item:';

	if ($more)
	{
		$lines[] = 'There are more than this - the list is truncated at ' . $limit . '. Raise limit to see the rest.';
	}

	$lines[] = '  ' . mcp_url('item', $id);
	$lines[] = '';

	foreach ($parts as $part)
	{
		$lines[] = 'PartID ' . $part->PartID . ' - ' . mcp_val($part, 'Title', '(untitled)');

		// Volume is printed per part, not just in the header: an item can be a bound
		// run of several volumes (ItemID 46213 is pt.1-6), and 1671 items in BHL have
		// parts spanning more than one, so it is not constant within an item.
		$cite = array();
		if (mcp_val($part, 'Date') !== '')      { $cite[] = mcp_val($part, 'Date'); }
		if (mcp_val($part, 'Volume') !== '')    { $cite[] = 'vol. ' . mcp_val($part, 'Volume'); }
		if (mcp_val($part, 'PageRange') !== '') { $cite[] = 'pp. ' . mcp_val($part, 'PageRange'); }

		if (count($cite) > 0)
		{
			$lines[] = '  ' . join(', ', $cite);
		}

		if (mcp_val($part, 'StartPageID') !== '')
		{
			$lines[] = '  starts at PageID ' . $part->StartPageID . ' (use page_text to read it)';
		}

		$lines[] = '  ' . mcp_url('part', $part->PartID);
	}

	return implode("\n", $lines);
}

function tool_part_info($args)
{
	$id = (int)mcp_arg($args, 'part_id', 0);
	if ($id === 0) { return 'Provide a part_id.'; }

	$part = get_part($id);
	if (!$part) { return 'No part found with PartID ' . $id . '.'; }

	$lines = array();
	$lines[] = 'PartID ' . $part->PartID . ' - ' . mcp_val($part, 'Title', '(untitled)');

	$cite = array();
	if (mcp_val($part, 'ContainerTitle') !== '') { $cite[] = mcp_val($part, 'ContainerTitle'); }
	if (mcp_val($part, 'Volume') !== '')         { $cite[] = 'vol. ' . mcp_val($part, 'Volume'); }
	if (mcp_val($part, 'Date') !== '')           { $cite[] = mcp_val($part, 'Date'); }
	if (mcp_val($part, 'PageRange') !== '')      { $cite[] = 'pp. ' . mcp_val($part, 'PageRange'); }

	if (count($cite) > 0)
	{
		$lines[] = implode(', ', $cite);
	}

	$lines[] = mcp_url('part', $part->PartID);

	$lines = array_merge($lines, mcp_creator_lines($part));

	// Identifiers are attached by get_part() as properties named after BHL's own
	// IdentifierName, alongside a lower-case 'doi' and 'pages'. Anything that is not
	// a part column or one of those two is an identifier.
	$skip = array(
		'PartID' => true, 'ItemID' => true, 'ContributorName' => true,
		'SequenceOrder' => true, 'Title' => true, 'ContainerTitle' => true,
		'Volume' => true, 'Date' => true, 'PageRange' => true, 'StartPageID' => true,
		'RightsStatus' => true, 'RightsStatement' => true, 'LicenseUrl' => true,
		'RightsHolder' => true, 'pages' => true, 'creator' => true,
	);

	$identifiers = array();

	foreach ($part as $key => $value)
	{
		if (isset($skip[$key]) || is_object($value))
		{
			continue;
		}

		// Same guard as tool_title_info: anything attached later that is not a flat
		// list of strings must be skipped rather than concatenated, which is fatal.
		$values  = is_array($value) ? $value : array($value);
		$scalars = array();

		foreach ($values as $v)
		{
			if (!is_array($v) && !is_object($v))
			{
				$scalars[] = $v;
			}
		}

		if (count($scalars) > 0)
		{
			$identifiers[($key === 'doi') ? 'DOI' : $key] = $scalars;
		}
	}

	if (count($identifiers) > 0)
	{
		$lines[] = '';
		$lines[] = 'Identifiers:';

		foreach ($identifiers as $name => $values)
		{
			foreach (array_unique($values) as $value)
			{
				$url  = mcp_identifier_url($name, $value);
				$line = '  ' . $name . ': ' . $value;

				if ($url !== '')
				{
					$line .= ' - ' . $url;
				}

				$lines[] = $line;
			}
		}
	}

	$pages = isset($part->pages) && is_array($part->pages) ? $part->pages : array();

	$lines[] = '';

	if (count($pages) === 0)
	{
		$lines[] = 'No pages are listed for this part.';

		return implode("\n", $lines);
	}

	// One part in BHL runs to 1722 pages, so this cannot be printed unconditionally.
	$show = array_slice($pages, 0, 40);

	$lines[] = count($pages) . ' page(s), in order - pass any of these to page_text or page_image:';
	$lines[] = '  ' . implode(' ', $show);

	if (count($pages) > count($show))
	{
		// Not "running to": the pages are not a contiguous ascending range. PageIDs
		// are not linear, and a part can pull in plates from elsewhere in the item,
		// so the last page in reading order is often not the highest PageID.
		$lines[] = '  ... and ' . (count($pages) - count($show)) . ' more not shown.';
		$lines[] = '  The last in reading order is PageID ' . $pages[count($pages) - 1] . '.';
	}

	return implode("\n", $lines);
}

function tool_item_pages($args)
{
	$id = (int)mcp_arg($args, 'item_id', 0);
	if ($id === 0) { return 'Provide an item_id.'; }

	$limit = (int)mcp_arg($args, 'limit', 200);
	if ($limit < 1)    { $limit = 200; }
	if ($limit > 1000) { $limit = 1000; }

	$pages = get_item_pages($id, $limit + 1);

	if (count($pages) === 0)
	{
		return 'No pages found for ItemID ' . $id . '. The item may not exist.';
	}

	$more  = (count($pages) > $limit);
	$pages = array_slice($pages, 0, $limit);

	$lines = array();
	$lines[] = count($pages) . ' page(s) in ItemID ' . $id . ', in reading order:';
	$lines[] = '  ' . mcp_url('item', $id);

	if ($more)
	{
		$lines[] = '  Truncated at ' . $limit . '; there are more. Raise limit to see the rest.';
	}

	$lines[] = '';

	// Reading order, not numeric: PageIDs within an item routinely descend as
	// SequenceOrder ascends, so this list must be used as given.
	$lines[] = implode(' ', $pages);

	return implode("\n", $lines);
}

function tool_name_pages($args)
{
	$name = trim(mcp_arg($args, 'name', ''));
	if ($name === '') { return 'Provide a name.'; }

	$illustrated = (bool)mcp_arg($args, 'illustrated', false);

	$limit = (int)mcp_arg($args, 'limit', 50);
	if ($limit < 1)   { $limit = 50; }
	if ($limit > 200) { $limit = 200; }

	$pages = get_pages_with_name($name, $limit + 1, $illustrated);

	if (count($pages) === 0)
	{
		// Three different findings here, and they must not read alike: the name is
		// absent from BHL, or it is present but nothing is illustrated, or the
		// capitalisation is wrong. pagename uses BINARY collation, so a lower-case
		// genus matches nothing at all.
		if ($illustrated)
		{
			$any = get_pages_with_name($name, 1, false);

			if (count($any) > 0)
			{
				return '"' . $name . '" appears in BHL, but none of its pages are typed as an illustration or foldout. Call again with illustrated false to see the text pages.';
			}
		}

		return 'No pages found for "' . $name . '". Matching is exact and case-sensitive, so check the spelling and the capitalisation of the genus.';
	}

	$more  = (count($pages) > $limit);
	$pages = array_slice($pages, 0, $limit);

	$lines = array();
	$lines[] = count($pages) . ($illustrated ? ' illustrated' : '') . ' page(s) for "' . $name . '":';

	if ($more)
	{
		$lines[] = 'There are more than this - the list is truncated at ' . $limit . '. Raise limit to see further.';
	}

	// Grouped by source rather than one flat list. The query orders by title, item
	// then sequence, so pages from the same work are already adjacent; printing the
	// reference once per run instead of once per page is what keeps the response
	// small when a name runs to hundreds of pages.
	$reference = null;

	foreach ($pages as $page)
	{
		$ref = mcp_val($page, 'Reference', '(untitled)');

		if ($ref !== $reference)
		{
			$reference = $ref;

			$where = array();
			if (mcp_val($page, 'PartID') !== '')  { $where[] = 'PartID ' . $page->PartID; }
			if (mcp_val($page, 'TitleID') !== '') { $where[] = 'TitleID ' . $page->TitleID; }

			$lines[] = '';
			$lines[] = $ref . (count($where) > 0 ? ' [' . join(', ', $where) . ']' : '');
		}

		$number = mcp_val($page, 'PageNumber');
		$types  = mcp_val($page, 'PageTypes');

		$lines[] = '  PageID ' . $page->PageID
			. ($number !== '' ? ' (p. ' . $number . ')' : '')
			. ($types !== '' ? ' [' . $types . ']' : '')
			. ' - ' . mcp_url('page', $page->PageID);
	}

	return implode("\n", $lines);
}

function tool_page_text($args)
{
	$id = (int)mcp_arg($args, 'page_id', 0);
	if ($id === 0) { return 'Provide a page_id.'; }

	$text = get_page_text($id);

	// false means the fetch budget declined a network call - say so plainly, or the
	// model reads a throttle as evidence that the page has no text.
	if ($text === false)
	{
		return 'PageID ' . $id . ' is not cached locally, and this server has reached its limit on how fast it will fetch new pages from BHL. Try again shortly. ' . mcp_url('page', $id);
	}

	if (trim($text) === '')
	{
		return 'No OCR text available for PageID ' . $id . '. The page may not exist, or BHL may hold no text layer for it. ' . mcp_url('page', $id);
	}

	return 'OCR text of PageID ' . $id . ' (' . mcp_url('page', $id) . "):\n\n" . $text;
}

// Crop to a box given as fractions of the page. Fractions rather than pixels
// because a box is normally worked out by looking at one rendering and applied to
// another - at pixel scale that silently crops the wrong region, since small to
// full is a factor of 7.3.
//
// Returns the cropped bytes, or a string describing why it could not.
function mcp_crop_image($bytes, $box, &$width, &$height)
{
	$src = @imagecreatefromstring($bytes);

	if ($src === false)
	{
		return 'The image could not be decoded, so it was returned uncropped.';
	}

	$w = imagesx($src);
	$h = imagesy($src);

	// floor the near edges and ceil the far ones, so rounding can only widen the
	// box. Clipping a specimen is worse than including a millimetre of margin.
	$x1 = (int)floor($box['left']   * $w);
	$y1 = (int)floor($box['top']    * $h);
	$x2 = (int)ceil($box['right']   * $w);
	$y2 = (int)ceil($box['bottom']  * $h);

	$x1 = max(0, min($w - 1, $x1));
	$y1 = max(0, min($h - 1, $y1));
	$x2 = max($x1 + 1, min($w, $x2));
	$y2 = max($y1 + 1, min($h, $y2));

	$out = imagecrop($src, array('x' => $x1, 'y' => $y1, 'width' => $x2 - $x1, 'height' => $y2 - $y1));
	imagedestroy($src);

	if ($out === false)
	{
		return 'The crop produced an empty image, so the page was returned uncropped.';
	}

	$width  = imagesx($out);
	$height = imagesy($out);

	ob_start();
	imagewebp($out, null, 82);
	$cropped = ob_get_clean();
	imagedestroy($out);

	return ($cropped === '' || $cropped === false)
		? 'The cropped image could not be encoded, so the page was returned uncropped.'
		: $cropped;
}

// True when the caller supplied some crop edges but not a usable box - so a
// mistake can be reported rather than silently returning the whole page.
function mcp_crop_partial($args)
{
	foreach (array('left', 'top', 'right', 'bottom') as $edge)
	{
		if (isset($args[$edge]))
		{
			return true;
		}
	}

	return false;
}

// Read the four crop fractions, or null if no usable box was given.
function mcp_crop_box($args)
{
	$box = array();

	foreach (array('left', 'top', 'right', 'bottom') as $edge)
	{
		if (!isset($args[$edge]) || !is_numeric($args[$edge]))
		{
			return null;
		}

		$box[$edge] = (float)$args[$edge];
	}

	foreach ($box as $v)
	{
		if ($v < 0.0 || $v > 1.0)
		{
			return null;
		}
	}

	if ($box['right'] <= $box['left'] || $box['bottom'] <= $box['top'])
	{
		return null;
	}

	return $box;
}

function tool_page_image($args)
{
	$id = (int)mcp_arg($args, 'page_id', 0);
	if ($id === 0) { return 'Provide a page_id.'; }

	$box = mcp_crop_box($args);

	// A crop is a request for detail, so take the biggest source unless told
	// otherwise - cropping 40% out of a 465px page gives about 190px, which is the
	// problem the crop was meant to solve.
	$size = strtolower(trim(mcp_arg($args, 'size', ($box === null) ? 'medium' : 'full')));

	if (!in_array($size, array('small', 'medium', 'large', 'full')))
	{
		$size = ($box === null) ? 'medium' : 'full';
	}

	$image = get_page_image($id, $size);

	if ($image === false)
	{
		return 'PageID ' . $id . ' is not cached locally, and this server has reached its limit on how fast it will fetch new images from BHL. Try again shortly. ' . mcp_url('page', $id);
	}

	if ($image === null || strlen($image) === 0)
	{
		return 'No image available for PageID ' . $id . '.';
	}

	$note = '';

	if ($box !== null)
	{
		$w = 0;
		$h = 0;

		$cropped = mcp_crop_image($image, $box, $w, $h);

		if (is_string($cropped) && $w > 0)
		{
			$image = $cropped;
			$note  = ' cropped to ' . $box['left'] . '-' . $box['right'] . ' across and '
				. $box['top'] . '-' . $box['bottom'] . ' down';
		}
		else
		{
			// mcp_crop_image returns a message instead of bytes when it fails. Say so
			// rather than passing off a whole page as the requested detail.
			$note = ' (crop failed: ' . $cropped . ')';
		}
	}
	else if (mcp_crop_partial($args))
	{
		$note = ' (crop ignored: left, top, right and bottom must all be given, each 0-1, with right > left and bottom > top)';
	}

	// State the pixel dimensions. Anything reasoning about a region of the page - a
	// crop, a figure's position - needs to know which coordinate space it is working
	// in, and the sizes differ by a factor of 7 from small to full.
	$info       = @getimagesizefromstring($image);
	$dimensions = ($info !== false) ? ', ' . $info[0] . 'x' . $info[1] . ' px' : '';

	// Two blocks: the image itself, plus a line of text saying what it is, because an
	// image block alone arrives with no indication of which page it came from.
	return array(
		array(
			'type'     => 'image',
			'data'     => base64_encode($image),
			'mimeType' => 'image/webp',
		),
		array(
			'type' => 'text',
			'text' => 'Scan of PageID ' . $id . ' at size "' . $size . '"' . $note . $dimensions
				. ' - ' . mcp_url('page', $id),
		),
	);
}

?>
