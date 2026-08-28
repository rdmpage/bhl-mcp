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
			'name'        => 'search_titles',
			'description' => 'Search BHL titles (books and journals) by words in the title, author or subject headings. Widens automatically: an exact all-words match first, then any-word, then substring matching for approximate wording.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'text'  => array('type' => 'string', 'description' => 'Words to search for, e.g. "orchids of madagascar".'),
					'limit' => $limit,
				),
				'required' => array('text'),
			),
		),
		array(
			'name'        => 'search_parts',
			'description' => 'Search BHL parts (articles and book chapters) by words in the article title, container title or author.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'text'  => array('type' => 'string', 'description' => 'Words to search for, e.g. "new species of Sphaerodactylus".'),
					'limit' => $limit,
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
			'description' => 'Get the scanned image of a single BHL page, so it can be read directly rather than through OCR. Useful when the OCR is garbled or when the page carries a plate or figure.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'page_id' => array('type' => 'integer', 'description' => 'BHL PageID.'),
				),
				'required' => array('page_id'),
			),
		),
		array(
			'name'        => 'name_pages',
			'description' => 'Find the BHL pages on which a taxonomic name appears, with the work each page belongs to. The name must be matched exactly as BHL records it, and matching is case-sensitive on the genus.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'name'  => array('type' => 'string', 'description' => 'Taxonomic name, e.g. "Poecilia reticulata".'),
					'limit' => $limit,
				),
				'required' => array('name'),
			),
		),
		array(
			'name'        => 'works_in_bbox',
			'description' => 'Which works mention localities inside a geographic bounding box.',
			'inputSchema' => mcp_bbox_schema($limit),
		),
		array(
			'name'        => 'parts_in_bbox',
			'description' => 'Which articles mention localities inside a geographic bounding box.',
			'inputSchema' => mcp_bbox_schema($limit),
		),
		array(
			'name'        => 'names_in_bbox',
			'description' => 'Which taxonomic names have been recorded from inside a geographic bounding box, with how many pages mention each.',
			'inputSchema' => mcp_bbox_schema($limit),
		),
		array(
			'name'        => 'points_near',
			'description' => 'Point localities within a radius of a coordinate, nearest first. Distance is great-circle, not a box.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'lat'   => array('type' => 'number', 'description' => 'Latitude in decimal degrees.'),
					'lon'   => array('type' => 'number', 'description' => 'Longitude in decimal degrees.'),
					'km'    => array('type' => 'number', 'description' => 'Radius in kilometres (default 100).'),
					'limit' => $limit,
				),
				'required' => array('lat', 'lon'),
			),
		),
		array(
			'name'        => 'title_points',
			'description' => 'Every point locality mentioned in one work, in page order. This is what you draw a map of a work from.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'title_id' => array('type' => 'integer', 'description' => 'BHL TitleID.'),
					'limit'    => $limit,
				),
				'required' => array('title_id'),
			),
		),
		array(
			'name'        => 'name_points',
			'description' => 'Where has a taxonomic name been reported from? The inverse of names_in_bbox.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'name'  => array('type' => 'string', 'description' => 'Taxonomic name, e.g. "Poecilia reticulata".'),
					'limit' => $limit,
				),
				'required' => array('name'),
			),
		),
	);
}

//----------------------------------------------------------------------------------------
// The four bbox tools share one input schema.
function mcp_bbox_schema($limit)
{
	return array(
		'type'       => 'object',
		'properties' => array(
			'min_lat' => array('type' => 'number', 'description' => 'Southern edge, decimal degrees.'),
			'max_lat' => array('type' => 'number', 'description' => 'Northern edge, decimal degrees.'),
			'min_lon' => array('type' => 'number', 'description' => 'Western edge, decimal degrees.'),
			'max_lon' => array('type' => 'number', 'description' => 'Eastern edge, decimal degrees.'),
			'limit'   => $limit,
		),
		'required' => array('min_lat', 'max_lat', 'min_lon', 'max_lon'),
	);
}

//----------------------------------------------------------------------------------------
// Tool dispatch

function callTool($name, $args)
{
	switch ($name)
	{
		case 'search_titles':   return tool_search_titles($args);
		case 'search_parts':    return tool_search_parts($args);
		case 'search_creators': return tool_search_creators($args);
		case 'creator_titles':  return tool_creator_titles($args);
		case 'page_text':       return tool_page_text($args);
		case 'page_image':      return tool_page_image($args);
		case 'name_pages':      return tool_name_pages($args);
		case 'works_in_bbox':   return tool_works_in_bbox($args);
		case 'parts_in_bbox':   return tool_parts_in_bbox($args);
		case 'names_in_bbox':   return tool_names_in_bbox($args);
		case 'points_near':     return tool_points_near($args);
		case 'title_points':    return tool_title_points($args);
		case 'name_points':     return tool_name_points($args);
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

// Guard the geo tools. bhl-geo.db currently holds the schema but no localities, and
// an empty result there means "nothing has been loaded" rather than "nothing is
// recorded from this region" - a distinction worth being explicit about, because the
// two look identical from the outside and only one of them is a finding.
function mcp_need_geo()
{
	global $config;

	static $empty = null;

	if (!$config['has_geo'])
	{
		return 'No locality database (bhl-geo.db) is present, so geographic search is unavailable.';
	}

	if ($empty === null)
	{
		$row = $config['bhlpdo']->query('SELECT count(*) AS n FROM geo.pagegeo')->fetch(PDO::FETCH_ASSOC);
		$empty = ((int)$row['n'] === 0);
	}

	if ($empty)
	{
		return 'No point localities have been loaded yet - geo.pagegeo is empty. This is not evidence that nothing was recorded from this region; there is simply no locality data to search. Load points with ./load-geo.py.';
	}

	return '';
}

function mcp_none($what)
{
	return 'No ' . $what . ' found.';
}

//----------------------------------------------------------------------------------------
// Tool implementations

function tool_search_titles($args)
{
	$err = mcp_need_fts();
	if ($err !== '') { return $err; }

	$text = trim(mcp_arg($args, 'text', ''));
	if ($text === '') { return 'Provide some text to search for.'; }

	$hits = search_titles($text, mcp_limit($args));
	if (count($hits) === 0) { return mcp_none('titles matching "' . $text . '"'); }

	$lines = array(count($hits) . ' title(s) matching "' . $text . '":', '');

	foreach ($hits as $hit)
	{
		$lines[] = 'TitleID ' . $hit->TitleID . ' - ' . mcp_val($hit, 'FullTitle', '(untitled)');

		$snippet = mcp_val($hit, 'Snippet');
		if ($snippet !== '') { $lines[] = '  ' . $snippet; }

		$lines[] = '  ' . mcp_url('bibliography', $hit->TitleID);
		$lines[] = '';
	}

	return join("\n", $lines);
}

function tool_search_parts($args)
{
	$err = mcp_need_fts();
	if ($err !== '') { return $err; }

	$text = trim(mcp_arg($args, 'text', ''));
	if ($text === '') { return 'Provide some text to search for.'; }

	$hits = search_parts($text, mcp_limit($args));
	if (count($hits) === 0) { return mcp_none('articles matching "' . $text . '"'); }

	$lines = array(count($hits) . ' article(s) matching "' . $text . '":', '');

	foreach ($hits as $hit)
	{
		$lines[] = 'PartID ' . $hit->PartID . ' - ' . mcp_val($hit, 'Title', '(untitled)');

		// Container, volume, date and pages read as one citation line, so build it
		// from whichever of them survived db_get().
		$cite = array();
		if (mcp_val($hit, 'ContainerTitle') !== '') { $cite[] = mcp_val($hit, 'ContainerTitle'); }
		if (mcp_val($hit, 'Volume') !== '')         { $cite[] = 'vol. ' . mcp_val($hit, 'Volume'); }
		if (mcp_val($hit, 'Date') !== '')           { $cite[] = mcp_val($hit, 'Date'); }
		if (mcp_val($hit, 'PageRange') !== '')      { $cite[] = 'pp. ' . mcp_val($hit, 'PageRange'); }

		if (count($cite) > 0) { $lines[] = '  ' . join(', ', $cite); }

		if (mcp_val($hit, 'StartPageID') !== '')
		{
			$lines[] = '  starts at PageID ' . $hit->StartPageID;
		}

		$lines[] = '  ' . mcp_url('part', $hit->PartID);
		$lines[] = '';
	}

	return join("\n", $lines);
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

function tool_page_image($args)
{
	$id = (int)mcp_arg($args, 'page_id', 0);
	if ($id === 0) { return 'Provide a page_id.'; }

	$image = get_page_image($id);

	if ($image === false)
	{
		return 'PageID ' . $id . ' is not cached locally, and this server has reached its limit on how fast it will fetch new images from BHL. Try again shortly. ' . mcp_url('page', $id);
	}

	if ($image === null || strlen($image) === 0)
	{
		return 'No image available for PageID ' . $id . '.';
	}

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
			'text' => 'Scan of PageID ' . $id . ' - ' . mcp_url('page', $id),
		),
	);
}

// Names sit in pagename, which is indexed but BINARY-collated, so this is an exact
// match: "poecilia reticulata" finds nothing. Say so rather than reporting no hits.
function tool_name_pages($args)
{
	global $config;

	$name = trim(mcp_arg($args, 'name', ''));
	if ($name === '') { return 'Provide a name.'; }

	$sql = 'SELECT pn.PageID, pn.NameBankID, p.PageNumber, p.PageTypeName,
		i.ItemID, t.TitleID, t.FullTitle
		FROM pagename pn
		INNER JOIN page p ON p.PageID = pn.PageID
		INNER JOIN item i ON i.ItemID = p.ItemID
		INNER JOIN title t ON t.TitleID = i.TitleID
		WHERE pn.NameConfirmed = \'' . str_replace("'", "''", $name) . '\'
		LIMIT ' . mcp_limit($args, 20);

	$hits = db_get($config['bhlpdo'], $sql);

	if (count($hits) === 0)
	{
		return 'No pages found for "' . $name . '". Matching is exact and case-sensitive, so check the spelling and the capitalisation of the genus.';
	}

	$lines = array(count($hits) . ' page(s) mentioning "' . $name . '":', '');

	foreach ($hits as $hit)
	{
		$page = mcp_val($hit, 'PageNumber');

		$lines[] = 'PageID ' . $hit->PageID . ($page !== '' ? ' (p. ' . $page . ')' : '')
			. ' in ' . mcp_val($hit, 'FullTitle', '(untitled)');
		$lines[] = '  ' . mcp_url('page', $hit->PageID);
	}

	return join("\n", $lines);
}

//----------------------------------------------------------------------------------------
// Geographic tools

function mcp_bbox($args)
{
	return array(
		(float)mcp_arg($args, 'min_lat', 0),
		(float)mcp_arg($args, 'max_lat', 0),
		(float)mcp_arg($args, 'min_lon', 0),
		(float)mcp_arg($args, 'max_lon', 0),
	);
}

function tool_works_in_bbox($args)
{
	$err = mcp_need_geo();
	if ($err !== '') { return $err; }

	list($minLat, $maxLat, $minLon, $maxLon) = mcp_bbox($args);

	$hits = works_in_bbox($minLat, $maxLat, $minLon, $maxLon, mcp_limit($args, 20));
	if (count($hits) === 0) { return mcp_none('works with localities in that box'); }

	$lines = array(count($hits) . ' work(s) with localities in that box:', '');

	foreach ($hits as $hit)
	{
		$lines[] = 'TitleID ' . $hit->TitleID . ' - ' . mcp_val($hit, 'FullTitle', '(untitled)');
		$lines[] = '  ' . mcp_val($hit, 'Points', 0) . ' point(s) on ' . mcp_val($hit, 'Pages', 0) . ' page(s)';
		$lines[] = '  ' . mcp_url('bibliography', $hit->TitleID);
	}

	return join("\n", $lines);
}

function tool_parts_in_bbox($args)
{
	$err = mcp_need_geo();
	if ($err !== '') { return $err; }

	list($minLat, $maxLat, $minLon, $maxLon) = mcp_bbox($args);

	$hits = parts_in_bbox($minLat, $maxLat, $minLon, $maxLon, mcp_limit($args, 20));
	if (count($hits) === 0) { return mcp_none('articles with localities in that box'); }

	$lines = array(count($hits) . ' article(s) with localities in that box:', '');

	foreach ($hits as $hit)
	{
		$lines[] = 'PartID ' . $hit->PartID . ' - ' . mcp_val($hit, 'Title', '(untitled)');
		$lines[] = '  ' . mcp_val($hit, 'ContainerTitle') . ' ' . mcp_val($hit, 'Date')
			. ' - ' . mcp_val($hit, 'Points', 0) . ' point(s)';
		$lines[] = '  ' . mcp_url('part', $hit->PartID);
	}

	return join("\n", $lines);
}

function tool_names_in_bbox($args)
{
	$err = mcp_need_geo();
	if ($err !== '') { return $err; }

	list($minLat, $maxLat, $minLon, $maxLon) = mcp_bbox($args);

	$hits = names_in_bbox($minLat, $maxLat, $minLon, $maxLon, mcp_limit($args, 50));
	if (count($hits) === 0) { return mcp_none('names recorded from that box'); }

	$lines = array(count($hits) . ' name(s) recorded from that box:', '');

	foreach ($hits as $hit)
	{
		$lines[] = mcp_val($hit, 'NameConfirmed') . ' - ' . mcp_val($hit, 'Pages', 0) . ' page(s)';
	}

	return join("\n", $lines);
}

function tool_points_near($args)
{
	$err = mcp_need_geo();
	if ($err !== '') { return $err; }

	$lat = (float)mcp_arg($args, 'lat', 0);
	$lon = (float)mcp_arg($args, 'lon', 0);
	$km  = (float)mcp_arg($args, 'km', 100);

	if ($km <= 0) { $km = 100; }

	$hits = points_near($lat, $lon, $km, mcp_limit($args, 50));
	if (count($hits) === 0) { return mcp_none('localities within ' . $km . ' km of ' . $lat . ', ' . $lon); }

	$lines = array(count($hits) . ' localit(ies) within ' . $km . ' km of ' . $lat . ', ' . $lon . ':', '');

	foreach ($hits as $hit)
	{
		$lines[] = mcp_val($hit, 'DistanceKm', 0) . ' km - ' . mcp_val($hit, 'Locality', '(unnamed)')
			. ' (' . mcp_val($hit, 'Latitude') . ', ' . mcp_val($hit, 'Longitude') . ')';
		$lines[] = '  PageID ' . $hit->PageID . ' - ' . mcp_url('page', $hit->PageID);
	}

	return join("\n", $lines);
}

function tool_title_points($args)
{
	$err = mcp_need_geo();
	if ($err !== '') { return $err; }

	$id = (int)mcp_arg($args, 'title_id', 0);
	if ($id === 0) { return 'Provide a title_id.'; }

	$hits = points_for_title($id, mcp_limit($args, 100));
	if (count($hits) === 0) { return mcp_none('localities in TitleID ' . $id); }

	$lines = array(count($hits) . ' localit(ies) in TitleID ' . $id . ':', '');

	foreach ($hits as $hit)
	{
		$lines[] = mcp_val($hit, 'Latitude') . ', ' . mcp_val($hit, 'Longitude')
			. ' - ' . mcp_val($hit, 'Locality', '(unnamed)')
			. ' [PageID ' . $hit->PageID . ']';
	}

	return join("\n", $lines);
}

function tool_name_points($args)
{
	$err = mcp_need_geo();
	if ($err !== '') { return $err; }

	$name = trim(mcp_arg($args, 'name', ''));
	if ($name === '') { return 'Provide a name.'; }

	$hits = points_for_name($name, mcp_limit($args, 100));
	if (count($hits) === 0) { return mcp_none('localities for "' . $name . '"'); }

	$lines = array(count($hits) . ' localit(ies) reported for "' . $name . '":', '');

	foreach ($hits as $hit)
	{
		$lines[] = mcp_val($hit, 'Latitude') . ', ' . mcp_val($hit, 'Longitude')
			. ' - ' . mcp_val($hit, 'Locality', '(unnamed)')
			. ' [PageID ' . $hit->PageID . ']';
	}

	return join("\n", $lines);
}

?>
