<?php

require_once(dirname(__FILE__) . '/sqlite.php');
require_once(dirname(__FILE__) . '/ratelimit.php');

//----------------------------------------------------------------------------------------
// Returns the response body, or false if the fetch failed. Callers MUST check:
// a failure used to die(), which under a web server or the MCP stdio transport meant
// a curl error string in place of the response and a truncated reply. Nothing is
// echoed here for the same reason - see mcp_run_tool().
function get($url, $format = '')
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	
	// Without these a slow or hanging BHL S3 blocks the request until PHP's own
	// max_execution_time kills it, which on stdio means the client just waits.
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	
	if ($format != '')
	{
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: " . $format));	
	}
	
	$response = curl_exec($ch);
	
	if ($response === false)
	{
		error_log('get(): ' . curl_error($ch) . ' for ' . $url);
		curl_close($ch);
		return false;
	}
	
	$info = curl_getinfo($ch);
	$http_code = $info['http_code'];
	
	curl_close($ch);
	
	// A 404 body is not the page - caching it would be caching the error.
	if ($http_code != 200)
	{
		error_log('get(): HTTP ' . $http_code . ' for ' . $url);
		return false;
	}
	
	return $response;
}



// example functions that we want to support


//----------------------------------------------------------------------------------------
// Get text for page (may be cached)
function get_page_text($id)
{
	global $config;
	
	$text = '';

	// Cast here, not just in the caller - safety must not depend on who calls this.
	$sql = 'SELECT * FROM page WHERE PageID=' . (int)$id;
	
	$data = db_get($config['bhlpdo'], $sql);
	

	if (isset($data[0]))
	{
		// cached?
		
		$filename = $config['cache'] . '/ocr/' . $data[0]->ItemID . '/' .  $data[0]->PageID . '.txt';
		
		if (!file_exists($filename))
		{
			$dir = $config['cache'] . '/ocr';
			if (!file_exists($dir))
			{
				$oldumask = umask(0); 
				mkdir($dir, 0777);
				umask($oldumask);
			}
			$dir .= '/'	. $data[0]->ItemID;
			if (!file_exists($dir))
			{
				$oldumask = umask(0); 
				mkdir($dir, 0777);
				umask($oldumask);
			}
				
			$url = 'https://bhl-open-data.s3.amazonaws.com/'
			. 'ocr/item-' . str_pad($data[0]->ItemID, 6, '0', STR_PAD_LEFT) 
			. '/item-' . str_pad($data[0]->ItemID, 6, '0', STR_PAD_LEFT) 
			. '-' . str_pad($data[0]->PageID, 8, '0', STR_PAD_LEFT)
			. '-' . str_pad($data[0]->SequenceOrder, 4, '0', STR_PAD_LEFT)
			. '.txt';
			
			// Cache miss, so this crosses the network. Returning false (not '')
			// keeps "we declined to fetch" distinct from "this page has no text".
			if (!bhl_fetch_allowed())
			{
				return false;
			}
			
			$text = get($url);
			
			// Only cache a real fetch. get() now returns false on failure instead of
			// dying, so writing unconditionally would leave an empty file that every
			// later call reads back as "this page has no text".
			if ($text === false)
			{
				return '';
			}
			
			file_put_contents($filename, $text);
		}
		
		$text = file_get_contents($filename);
	}
	
	return $text;
}

//----------------------------------------------------------------------------------------
// Get image for page (may be cached)
// $size is one of small, medium, large, full - BHL's own suffixes, roughly
// 235, 465, 930 and 1713 px wide. medium is the default because full is 300 KB,
// which becomes ~400 KB of base64 in an MCP response; ask for full only when the
// detail matters, such as reading a plate or cropping a figure out of one.
function get_page_image($id, $size = 'medium')
{
	global $config;
	
	$image = null;

	// Whitelist, not sanitise: this goes straight into a URL, and an unknown
	// suffix would just 404 after a wasted fetch.
	if (!in_array($size, array('small', 'medium', 'large', 'full')))
	{
		$size = 'medium';
	}

	$sql = 'SELECT * FROM page 
	INNER JOIN item USING(ItemID)
	WHERE PageID=' . (int)$id;
	
	$data = db_get($config['bhlpdo'], $sql);
	

	if (isset($data[0]))
	{
		// cached?
		
		// The size has to be in the filename. Without it the first size fetched wins
		// and every later request for a different one is served the wrong image.
		$filename = $config['cache'] . '/images/' . $data[0]->ItemID . '/' .  $data[0]->PageID . '_' . $size . '.webp';
		
		if (!file_exists($filename))
		{
			$dir = $config['cache'] . '/images';
			if (!file_exists($dir))
			{
				$oldumask = umask(0); 
				mkdir($dir, 0777);
				umask($oldumask);
			}
			$dir .= '/'	. $data[0]->ItemID;
			if (!file_exists($dir))
			{
				$oldumask = umask(0); 
				mkdir($dir, 0777);
				umask($oldumask);
			}
				
			$url = 'https://bhl-open-data.s3.amazonaws.com/'
			. 'web/' . $data[0]->BarCode 
			. '/' . $data[0]->BarCode . '_' . str_pad($data[0]->SequenceOrder, 4, '0', STR_PAD_LEFT) 
			. '_' . $size . '.webp';
						
			// As in get_page_text - false means throttled, null means unavailable.
			if (!bhl_fetch_allowed())
			{
				return false;
			}
			
			$image = get($url);
			
			// As above - a failed fetch must not become a zero-byte cached image.
			if ($image === false)
			{
				return null;
			}
			
			file_put_contents($filename, $image);
		}
		
		$image = file_get_contents($filename);
	}
	
	return $image;
}


//----------------------------------------------------------------------------------------
// Get information on name and identifiers for a title
function get_title_info($TitleID)
{
	global $config;
	
	$result = null;
	
	$TitleID = (int)$TitleID;

	$sql = "SELECT * FROM title WHERE TitleID = $TitleID";

	$data = db_get($config['bhlpdo'], $sql);
	
	if (isset($data[0]))
	{
		$result = $data[0];
	}
	
	// Creators
	if ($result)
	{
		$sql = "SELECT * FROM creator WHERE TitleID = $TitleID";

		$data = db_get($config['bhlpdo'], $sql);
		
		foreach ($data as $row)
		{
			if (!isset($result->creator))
			{
				$result->creator = [];
			}
			
			$creator = new stdclass;
			$creator->id = $row->CreatorID;
			$creator->name = $row->CreatorName;
			
			$result->creator[] = $creator;
		}
	}
	
	// Identifiers
	if ($result)
	{
		$sql = "SELECT * FROM titleidentifier WHERE TitleID = $TitleID";

		$data = db_get($config['bhlpdo'], $sql);
		
		foreach ($data as $row)
		{
			if (!isset($result->{$row->IdentifierName}))
			{
				$result->{$row->IdentifierName} = [];
			}
			$result->{$row->IdentifierName}[] = $row->IdentifierValue;
		}
	}
	
	// DOI
	if ($result)
	{
		$sql = "SELECT * FROM doi WHERE EntityType='Title' AND EntityID = $TitleID";

		$data = db_get($config['bhlpdo'], $sql);
		
		foreach ($data as $row)
		{
			if (!isset($result->doi))
			{
				$result->doi = [];
			}
		
			$result->doi[] = $row->DOI;
		}
	}	
	
	// What years do we cover in BHL?
	if ($result)
	{
		$result->coverage = get_title_year_range($TitleID);
	}	
	
	return $result;
}

//----------------------------------------------------------------------------------------
// Get list of items in a title
function get_title_items($TitleID, $limit = 500)
{
	global $config;
	
	$result = [];
	
	$TitleID = (int)$TitleID;

	// Year first: these are volumes of a serial and they arrive in insertion order,
	// which for a run of a journal is just noise. Year is TEXT, but they are all
	// four digits so a lexical sort is chronological. The cap matters because one
	// title in BHL has 1321 items.
	$sql = "SELECT * FROM item WHERE TitleID = $TitleID ORDER BY Year LIMIT " . (int)$limit;

	$data = db_get($config['bhlpdo'], $sql);
	
	foreach ($data as $row)
	{
		$item = new stdclass;
		
		foreach ($row as $k => $v)
		{
			$item->{$k} = $v;
		}
		$result[] = $item;
	}
	
	return $result;
}

//----------------------------------------------------------------------------------------
// Get list of parts in a title
function get_title_parts($TitleID, $limit = 500, $year = null)
{
	global $config;
	
	$result = [];
	
	$TitleID = (int)$TitleID;

	// Named columns rather than part.*: the rights fields, ContributorName and
	// SequenceOrder are all noise to a caller, and ContainerTitle just repeats the
	// title being asked about. StartPageID has to stay - it is the only route from
	// an article to its text. Halves the response.
	//
	// Date orders the run chronologically. The cap matters: the largest title in
	// BHL has 11,811 parts.
	$where = "item.TitleID = $TitleID";

	// Optional year filter. part.Date is mostly YYYY, but also YYYY-MM, YYYY-MM-DD,
	// ranges like 1890-1891, and a few parenthesised forms such as (1894) - so match
	// the year anywhere in the string rather than as a prefix. A part spanning
	// 1890-1891 is therefore returned for either year, which is what you want when
	// browsing a serial.
	if ($year !== null && (int)$year > 0)
	{
		$where .= " AND instr(part.Date, '" . (int)$year . "') > 0";
	}

	$sql = "SELECT part.PartID, part.Title, part.Volume, part.Date, part.PageRange, part.StartPageID
		FROM part INNER JOIN item USING(ItemID)
		WHERE $where
		ORDER BY part.Date
		LIMIT " . (int)$limit;

	$data = db_get($config['bhlpdo'], $sql);
	
	foreach ($data as $row)
	{
		$part = new stdclass;
		
		foreach ($row as $k => $v)
		{
			$part->{$k} = $v;
		}
		$result[] = $part;
	}
	
	return $result;
}

//----------------------------------------------------------------------------------------
// Get list of items in a title
function get_title_year_range($TitleID)
{
	global $config;
	
	$result = null;
	
	$TitleID = (int)$TitleID;

	$sql = "SELECT
  COUNT(*)                        AS Items,
  MIN(CAST(Year AS INTEGER))      AS FromYear,
  MAX(CAST(Year AS INTEGER))      AS ToYear,
  SUM(Year IS NULL)               AS Undated
FROM item
WHERE TitleID = $TitleID
  AND (Year IS NULL OR (Year GLOB '[0-9][0-9][0-9][0-9]' AND CAST(Year AS INTEGER) BETWEEN 1450 AND 2026));";

	$data = db_get($config['bhlpdo'], $sql);
	
	foreach ($data as $row)
	{
		if (!$result)
		{
			$result = new stdclass;
		}
		
		foreach ($row as $k => $v)
		{
			$result->{$k} = $v;
		}
	}
	
	return $result;
}


//----------------------------------------------------------------------------------------
// Get list of parts in an item
function get_item_parts($ItemID, $limit = 500)
{
	global $config;
	
	$result = [];
	
	$ItemID = (int)$ItemID;

	// Order by where the part's first page actually sits in the scan, not by
	// part.SequenceOrder. That column is an edit or ingest order, not a position:
	// for 4,093 of the 19,443 items with more than one part the two disagree, and
	// item 175550 comes back as XVI, XVII, V, XXII, XXIII, XVIII, XIV, II, IV, III,
	// XV, I when sorted by it. Date is no use either - within one volume every part
	// shares it.
	//
	// LEFT JOIN so a part whose StartPageID is missing, or points outside this item,
	// still appears; those sort to the end. One item has 580 parts, hence the cap.
	$sql = "SELECT part.PartID, part.Title, part.Volume, part.Date, part.PageRange, part.StartPageID,
		page.SequenceOrder AS StartPosition
		FROM part
		LEFT JOIN page ON page.PageID = part.StartPageID AND page.ItemID = part.ItemID
		WHERE part.ItemID = $ItemID
		ORDER BY page.SequenceOrder IS NULL, page.SequenceOrder, part.SequenceOrder
		LIMIT " . (int)$limit;

	$data = db_get($config['bhlpdo'], $sql);
	
	foreach ($data as $row)
	{
		$part = new stdclass;
		
		foreach ($row as $k => $v)
		{
			$part->{$k} = $v;
		}
		$result[] = $part;
	}
	
	return $result;
}

//----------------------------------------------------------------------------------------
// Get list of pages in an item
function get_item_pages($ItemID, $limit = 1000)
{
	global $config;
	
	$result = [];
	
	$ItemID = (int)$ItemID;

	$sql = "SELECT DISTINCT PageID FROM page WHERE ItemID = $ItemID
		ORDER BY SequenceOrder
		LIMIT " . (int)$limit;

	$data = db_get($config['bhlpdo'], $sql);
	
	foreach ($data as $row)
	{
		$result[] = $row->PageID;
	}
	
	return $result;
}

//----------------------------------------------------------------------------------------
// Get one part
function get_part($PartID)
{
	global $config;
	
	$result = null;
	
	$PartID = (int)$PartID;

	$sql = "SELECT * FROM part WHERE PartID = $PartID";

	$data = db_get($config['bhlpdo'], $sql);
	
	foreach ($data as $row)
	{
		if (!$result)
		{
			$result = new stdclass;
		}
	
		foreach ($row as $k => $v)
		{
			$result->{$k} = $v;
		}
	}
	
	// Creators
	if ($result)
	{
		$sql = "SELECT * FROM partcreator WHERE PartID = $PartID";

		$data = db_get($config['bhlpdo'], $sql);
		
		foreach ($data as $row)
		{
			if (!isset($result->creator))
			{
				$result->creator = [];
			}
			
			$creator = new stdclass;
			$creator->id = $row->CreatorID;
			$creator->name = $row->CreatorName;
			
			$result->creator[] = $creator;
		}
	}
	
	
	// identifiers
	if ($result)
	{
		$sql = "SELECT * FROM partidentifier WHERE PartID = $PartID";

		$data = db_get($config['bhlpdo'], $sql);
		
		foreach ($data as $row)
		{
			if (!isset($result->{$row->IdentifierName}))
			{
				$result->{$row->IdentifierName} = [];
			}
			$result->{$row->IdentifierName}[] = $row->IdentifierValue;
		}
	}	
	
	// DOI
	if ($result)
	{
		$sql = "SELECT * FROM doi WHERE EntityType='Part' AND EntityID = $PartID";

		$data = db_get($config['bhlpdo'], $sql);
		
		foreach ($data as $row)
		{
			if (!isset($result->doi))
			{
				$result->doi = [];
			}
		
			$result->doi[] = $row->DOI;
		}
	}
	
	// pages
	if ($result)
	{
		$result->pages = [];
		
		$sql = "SELECT * FROM partpage WHERE PartID = $PartID ORDER BY SequenceOrder";

		$data = db_get($config['bhlpdo'], $sql);
		
		foreach ($data as $row)
		{
			$result->pages[] = $row->PageID;
		}
	}
		
	
	return $result;
}

//----------------------------------------------------------------------------------------
function normalise_identifier_name($namespace)
{
	$namespace = strtoupper($namespace);
	
	switch ($namespace)
	{
		// Title case
		case 'ABBREVIATION':
		case 'SOULSBY':
		case 'TROPICOS':
		case 'WIKIDATA':
			$namespace = mb_convert_case($namespace, MB_CASE_TITLE);
			break;
			
		case 'BIOSTOR':
			$namespace = 'BioStor';
			break;

		case 'ELOCATOR':
			$namespace = 'eLocator';
			break;
			
		// special
		case 'LINKING ISSN':
			$namespace = 'Linking ISSN';
			break;

		case 'RESEARCHGATE PROFILE':
			$namespace = 'ResearchGate Profile';
			break;

		case 'WONDERFETCH':
			$namespace = 'WonderFetch';
			break;

		case 'EISSN':
			$namespace = 'eISSN';
			break;
			
		// UPPER case
		case 'BPH':
		case 'CODEN':
		case 'DDC':
		case 'DLC':
		case 'GPO':
		case 'ISBN':
		case 'ISSN':
		case 'JSTOR':
		case 'MARC001':
		case 'NAL':
		case 'NLM':
		case 'OAI':
		case 'OCLC':
		case 'ORCID':
		case 'SNAC ARK':
		case 'TL2':
		case 'VIAF':
		default:
			break;
	}
			
	return $namespace;
}	

//----------------------------------------------------------------------------------------
function normalise_identifier_value($namespace, $value)
{
	$namespace = strtoupper($namespace);
	
	switch ($namespace)
	{
		case 'ORCID':
			if (preg_match('/^\d+/', $value))
			{
				$value = 'https://orcid.org/' . $value;
			}
			break;
	
		default:
			break;
	}
	
	return $value;
}

//----------------------------------------------------------------------------------------

// Quote a value for a SQL string literal. SQLite has no backslash escape, so
// doubling the apostrophe is the whole job.
function sql_string($value)
{
	return "'" . str_replace("'", "''", $value) . "'";
}

//----------------------------------------------------------------------------------------
function find_by_identifier($namespace, $identifier)
{
	global $config;

	$result = array();

	$namespace = normalise_identifier_name($namespace);

	// Every source is queried, rather than routing by namespace. Eight namespaces
	// span more than one kind of entity - Wikidata is on titles, parts AND creators;
	// DLC is on titles and creators; ISSN, ISBN, OAI, OCLC, Soulsby and TL2 are on
	// titles and parts - so any routing table gets it wrong, and stopping at the
	// first hit hides the others. Four indexed lookups cost nothing.
	$name  = sql_string($namespace);
	$value = sql_string($identifier);

	// DOIs have their own table. EntityType must be in the WHERE clause: a Part DOI
	// whose EntityID is also a valid TitleID would otherwise join to the title and
	// report that title's name (10.1002/mmnd.18580020305 is Part 210183, and Title
	// 210183 exists too).
	$queries = array(
		"SELECT EntityID AS id, 'title' AS type, FullTitle AS name
		 FROM doi INNER JOIN title ON doi.EntityID = title.TitleID
		 WHERE EntityType='Title' AND DOI=" . $value,

		"SELECT EntityID AS id, 'part' AS type, Title AS name
		 FROM doi INNER JOIN part ON doi.EntityID = part.PartID
		 WHERE EntityType='Part' AND DOI=" . $value,

		"SELECT TitleID AS id, 'title' AS type, FullTitle AS name
		 FROM titleidentifier INNER JOIN title USING(TitleID)
		 WHERE IdentifierName=" . $name . " AND IdentifierValue=" . $value . "
		 GROUP BY TitleID",

		"SELECT PartID AS id, 'part' AS type, Title AS name
		 FROM partidentifier INNER JOIN part USING(PartID)
		 WHERE IdentifierName=" . $name . " AND IdentifierValue=" . $value . "
		 GROUP BY PartID",

		// GROUP BY the creator: the creator table has one row per title they are
		// credited on, so without it a prolific author comes back hundreds of times
		// (VIAF 124245113 returned 813 identical rows).
		"SELECT creatoridentifier.CreatorID AS id, 'creator' AS type, CreatorName AS name
		 FROM creatoridentifier INNER JOIN creator ON creatoridentifier.CreatorID = creator.CreatorID
		 WHERE IdentifierName=" . $name . "
		 AND IdentifierValue=" . sql_string(normalise_identifier_value($namespace, $identifier)) . "
		 GROUP BY creatoridentifier.CreatorID",
	);

	// Only run the DOI lookups for a DOI, and skip them otherwise - the doi table has
	// no IdentifierName to filter on, so a non-DOI value would match nothing anyway.
	if ($namespace != 'DOI')
	{
		$queries = array_slice($queries, 2);
	}

	$seen = array();

	foreach ($queries as $sql)
	{
		foreach (db_get($config['bhlpdo'], $sql) as $row)
		{
			$key = $row->type . '-' . $row->id;

			if (!isset($seen[$key]))
			{
				$seen[$key] = true;
				$result[]   = $row;
			}
		}
	}

	return $result;
}


function title_part_coverage() {}

//----------------------------------------------------------------------------------------
function item_part_coverage($ItemID)
{
	global $config;
	
	$ItemID = (int)$ItemID;
	
	$page_map = [];

	// One row per page, in reading order. Grouped because page holds a row per
	// PageTypeName and 4.77M PageIDs carry more than one - a page can be both Text
	// and Illustration. Ungrouped, every multi-typed page would appear twice.
	//
	// No LIMIT: 3,933 items run past 1,000 pages and the largest is 4,788, and a
	// truncated map gives a coverage figure against the wrong denominator.
	$sql = "SELECT PageID,
		MIN(PageNumber)                     AS PageNumber,
		PagePrefix                          AS PagePrefix,
		group_concat(DISTINCT PageTypeName) AS PageTypes,
		MIN(SequenceOrder)                  AS SequenceOrder
		FROM page
		WHERE ItemID = $ItemID
		GROUP BY PageID
		ORDER BY MIN(SequenceOrder)";

	$data = db_get($config['bhlpdo'], $sql);
	
	foreach ($data as $row)
	{
		$page = new stdclass;
		
		$page->PageID        = $row->PageID;
		$page->PagePrefix    = isset($row->PagePrefix) ? $row->PagePrefix : '';
		$page->PageNumber    = isset($row->PageNumber) ? $row->PageNumber : '';
		$page->PageTypes     = isset($row->PageTypes) ? explode(',', $row->PageTypes) : [];
		$page->SequenceOrder = isset($row->SequenceOrder) ? $row->SequenceOrder : null;
		$page->parts         = [];
		
		$page_map[$row->PageID] = $page;
	}
	
	// parts in item
	$sql = "SELECT PageID, part.PartID
FROM part
INNER JOIN partpage USING(PartID)
WHERE part.ItemID = $ItemID";	

	$data = db_get($config['bhlpdo'], $sql);
	
	foreach ($data as $row)
	{
		// Only annotate pages already in the map. Assigning to a missing key would
		// append it at the end, out of reading order, and the order is the whole
		// point of this array.
		if (isset($page_map[$row->PageID]))
		{
			$page_map[$row->PageID]->parts[] = $row->PartID;
		}
	}	

	return $page_map;
}

//----------------------------------------------------------------------------------------
// Return list of pages that are not in any parts, can filter by type
// The page types actually present in an item, with counts. Lets a caller check a
// type name before filtering on it: the names are exact strings and "Illustrations"
// or "Plate" match nothing at all, silently.
function item_page_types($ItemID)
{
	$types = [];

	foreach (item_part_coverage($ItemID) as $page)
	{
		foreach ($page->PageTypes as $type)
		{
			if ($type !== '')
			{
				$types[$type] = isset($types[$type]) ? $types[$type] + 1 : 1;
			}
		}
	}

	arsort($types);

	return $types;
}

//----------------------------------------------------------------------------------------
function item_orphan_pages($ItemID, $types = [])
{
	$pages = [];
	
	$page_map = item_part_coverage($ItemID);
	
	// Case-insensitive, so a caller does not have to reproduce BHL's exact casing.
	// The names themselves still have to be right - see item_page_types().
	$wanted = array_map('strtolower', $types);

	foreach ($page_map as $page)
	{
		// An orphan is a page in NO part. The test is on an empty parts array.
		if (count($page->parts) == 0)
		{
			if (count($wanted) == 0)
			{
				$pages[] = $page;
			}
			else
			{
				// only accept certain page types 
				if (isset($page->PageTypes) && is_array($page->PageTypes))
				{
					if (count(array_intersect(array_map('strtolower', $page->PageTypes), $wanted)) > 0)
					{
						$pages[] = $page;
					}
				}
			}
		}
	}	
	return $pages;
}

//----------------------------------------------------------------------------------------





//----------------------------------------------------------------------------------------





//----------------------------------------------------------------------------------------
// Dashboard statistics.
//
// Every count is COUNT(DISTINCT id): the tables carry duplicate rows for real
// reasons - 9,050 items are bound with more than one title, a page has a row per
// PageTypeName, 14 parts have a stray null-ItemID row - so COUNT(*) overstates all
// of them.
//
// Every metric here runs in well under a second against the indexes, so nothing is
// precomputed. If a metric is ever added that does not, it belongs in a separate
// stats database rather than here.
function bhl_statistics($metric, $options = array())
{
	global $config;

	$contributor = isset($options['contributor']) ? trim($options['contributor']) : '';
	$limit       = isset($options['limit']) ? (int)$options['limit'] : 0;

	switch ($metric)
	{
		case 'counts':
			// Creators are the union of title-level and article-level credits. There
			// is no master creator table: creator holds 79,892 and partcreator holds
			// 171,732, overlapping by only 10,149, so either alone badly undercounts.
			$sql = "SELECT
				(SELECT COUNT(DISTINCT TitleID) FROM title) AS Titles,
				(SELECT COUNT(DISTINCT ItemID)  FROM item)  AS Items,
				(SELECT COUNT(DISTINCT PartID)  FROM part)  AS Parts,
				(SELECT COUNT(DISTINCT PageID)  FROM page)  AS Pages,
				(SELECT COUNT(*) FROM (SELECT CreatorID FROM creator
				                       UNION
				                       SELECT CreatorID FROM partcreator)) AS Creators";
			break;

		case 'items_by_year':
			// GROUP BY the expression, not the alias. item has its own Year column
				// (the publication year) and SQLite binds the column in preference to
				// the alias, so "GROUP BY Year" silently groups by publication year -
				// 525 groups instead of 20.
			$sql = "SELECT substr(CreationDate,1,4) AS AddedYear, COUNT(DISTINCT ItemID) AS Items
				FROM item"
				. ($contributor !== '' ? " WHERE InstitutionName = " . sql_string($contributor) : '')
				. " GROUP BY substr(CreationDate,1,4) ORDER BY substr(CreationDate,1,4)";
			break;

		case 'items_by_publication_year':
			// item.Year is BHL's simplified year of publication - a single year even
			// where the volume spans several. It is either NULL or exactly four
			// digits; nothing else occurs.
			//
			// 9999 is excluded as a sentinel (3 items). Nothing else is: the pre-1500
			// values are mostly genuine incunabula, several items per year through the
			// 1470s-1490s, and quietly dropping them would misrepresent the holdings.
			// 14,537 items carry no year at all and cannot appear here.
			$from = isset($options['from']) ? (int)$options['from'] : 0;
			$to   = isset($options['to'])   ? (int)$options['to']   : 0;

			$where = "Year GLOB '[0-9][0-9][0-9][0-9]' AND CAST(Year AS INTEGER) != 9999";

			if ($from > 0) { $where .= ' AND CAST(Year AS INTEGER) >= ' . $from; }
			if ($to   > 0) { $where .= ' AND CAST(Year AS INTEGER) <= ' . $to; }

			if ($contributor !== '')
			{
				$where .= ' AND InstitutionName = ' . sql_string($contributor);
			}

			$sql = "SELECT CAST(Year AS INTEGER) AS PublicationYear, COUNT(DISTINCT ItemID) AS Items
				FROM item
				WHERE $where
				GROUP BY CAST(Year AS INTEGER)
				ORDER BY CAST(Year AS INTEGER)";
			break;

		case 'items_by_contributor':
			$sql = "SELECT InstitutionName AS Contributor, COUNT(DISTINCT ItemID) AS Items
				FROM item GROUP BY InstitutionName ORDER BY Items DESC"
				. ($limit > 0 ? " LIMIT " . $limit : '');
			break;

		case 'items_by_year_contributor':
			// The cap is on contributors, not rows: cutting rows would lop the tail off
			// whichever years happened to sort last and silently understate them. This
			// keeps every year for the largest N institutions. Ungrouped there are 465
			// of them, which is 1,905 rows and an unreadable chart.
			$where = ($contributor !== '') ? " WHERE InstitutionName = " . sql_string($contributor) : '';

			if ($contributor === '' && $limit > 0)
			{
				$where = " WHERE InstitutionName IN (
					SELECT InstitutionName FROM item
					GROUP BY InstitutionName
					ORDER BY COUNT(DISTINCT ItemID) DESC
					LIMIT " . $limit . ")";
			}

			$sql = "SELECT substr(CreationDate,1,4) AS AddedYear, InstitutionName AS Contributor,
				COUNT(DISTINCT ItemID) AS Items
				FROM item" . $where
				. " GROUP BY substr(CreationDate,1,4), InstitutionName
				   ORDER BY substr(CreationDate,1,4), Items DESC";
			break;

		case 'parts_by_contributor':
			$sql = "SELECT ContributorName AS Contributor, COUNT(DISTINCT PartID) AS Parts
				FROM part GROUP BY ContributorName ORDER BY Parts DESC"
				. ($limit > 0 ? " LIMIT " . $limit : '');
			break;

		case 'part_dois_by_year':
			$sql = "SELECT substr(CreationDate,1,4) AS AddedYear, COUNT(DISTINCT EntityID) AS DOIs
				FROM doi WHERE EntityType='Part'
				GROUP BY substr(CreationDate,1,4) ORDER BY substr(CreationDate,1,4)";
			break;

		case 'dois_by_year_type':
			$sql = "SELECT substr(CreationDate,1,4) AS AddedYear, EntityType, COUNT(DISTINCT EntityID) AS DOIs
				FROM doi GROUP BY substr(CreationDate,1,4), EntityType
				ORDER BY substr(CreationDate,1,4)";
			break;

		case 'creator_identifiers':
			$sql = "SELECT IdentifierName AS Identifier, COUNT(DISTINCT CreatorID) AS Creators
				FROM creatoridentifier GROUP BY IdentifierName ORDER BY Creators DESC";
			break;

		case 'creator_identifiers_by_year':
			$sql = "SELECT substr(CreationDate,1,4) AS AddedYear, IdentifierName AS Identifier,
				COUNT(DISTINCT CreatorID) AS Creators
				FROM creatoridentifier GROUP BY substr(CreationDate,1,4), IdentifierName
				ORDER BY substr(CreationDate,1,4)";
			break;

		case 'item_licences':
			// Deliberately NOT normalised. BHL stores the same Creative Commons
			// licence under both http and https, with and without a trailing slash;
			// showing that as-is is the point, because it is a finding about the data.
			$sql = "SELECT COALESCE(LicenseType,'(none recorded)') AS Licence,
				COUNT(DISTINCT ItemID) AS Items
				FROM item GROUP BY LicenseType ORDER BY Items DESC";
			break;

		case 'item_copyright_status':
			// 297 distinct strings, equally unnormalised and equally deliberate.
			$sql = "SELECT COALESCE(CopyrightStatus,'(none recorded)') AS Status,
				COUNT(DISTINCT ItemID) AS Items
				FROM item GROUP BY CopyrightStatus ORDER BY Items DESC"
				. ($limit > 0 ? " LIMIT " . $limit : '');
			break;

		default:
			return null;
	}

	return db_get($config['bhlpdo'], $sql);
}

//----------------------------------------------------------------------------------------
function get_pages_with_name($name, $limit = 100, $illustrated = false)
{
	global $config;
	
	$pages = [];

	//$sql = "SELECT * FROM pagename WHERE NameConfirmed = '" . str_replace("'", "''", $name) . "' LIMIT " . (int)$limit;
	
	$sql = "SELECT
  x.PageID,
  x.PageNumber,
  x.PageTypes,
  x.ItemID,
  x.TitleID,
  x.PartID,
  COALESCE(pa.Title, x.FullTitle) AS Reference
FROM (
  SELECT
    p.PageID,
    MIN(p.PageNumber)                     AS PageNumber,
    group_concat(DISTINCT p.PageTypeName) AS PageTypes,
    MIN(p.SequenceOrder)                  AS Seq,
    i.ItemID,
    t.TitleID,
    t.FullTitle,
    (SELECT MIN(pp.PartID) FROM partpage pp WHERE pp.PageID = p.PageID) AS PartID
  FROM pagename pn
  INNER JOIN page  p ON p.PageID = pn.PageID
  INNER JOIN item  i ON i.ItemID = p.ItemID
  INNER JOIN title t ON t.TitleID = i.TitleID
  WHERE pn.NameConfirmed = '" . str_replace("'", "''", $name) . "'
  GROUP BY p.PageID";
  
  	if ($illustrated)
	{
  		$sql .= " HAVING SUM(p.PageTypeName IN ('Illustration','Foldout')) > 0";
	}

$sql .= ") x
LEFT JOIN part pa ON pa.PartID = x.PartID
ORDER BY x.TitleID, x.ItemID, x.Seq
LIMIT " . (int)$limit . ";";

	$data = db_get($config['bhlpdo'], $sql);
	
	foreach ($data as $row)
	{
		$page = new stdclass;
		
		foreach ($row as $k => $v)
		{
			$page->{$k} = $v;
		}
	
		$pages[] = $page;
	}	
	
	return $pages;
}

//----------------------------------------------------------------------------------------
// Turn free text into a safe FTS5 MATCH expression.
//
// Raw user text can't go straight into MATCH: punctuation ("Smith's", "1861-1944")
// and bare operator words (AND, OR, NOT, NEAR) are FTS5 syntax and will either throw
// or silently mean something else. So we keep only letters/digits and wrap each term
// as a quoted string, which makes it a literal.
//
// $prefix appends * to each term. BHL titles aren't stemmed (deliberately - porter
// stemming mangles the French and Latin), so "orchid" alone will not match
// "Orchidees" or "orchids". Prefix matching is what makes that work.
function fts_query($text, $prefix = true, $op = ' AND ', $use_stop = true)
{
	// Function words, in the languages BHL titles are actually written in.
	// These have to go: ANDing "of" against a French or Latin title throws away
	// a perfectly good match just because the title isn't in English.
	static $stop = array(
		'a','an','and','the','of','on','in','for','to','with','from','by','or','its',
		'de','des','du','la','le','les','et','un','une','aux','sur','ou','dans','au',
		'der','die','das','den','dem','und','von','vom','zur','zum','im','bei',
		'el','los','las','y','del','il','della','di','da','e','na','van','het','og'
	);

	preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);

	$terms = array();

	foreach ($matches[0] as $term)
	{
		if ($use_stop && in_array(mb_strtolower($term), $stop))
		{
			continue;
		}

		$terms[] = '"' . $term . '"' . ($prefix ? '*' : '');
	}

	return join($op, $terms);
}

//----------------------------------------------------------------------------------------
// Run one MATCH against title_fts.
function title_match($q, $limit)
{
	global $config;

	if ($q == '')
	{
		return array();
	}

	$sql = 'SELECT f.TitleID, t.FullTitle, t.ShortTitle,
		snippet(f.title_fts, 1, "<b>", "</b>", "...", 15) AS Snippet,
		bm25(f.title_fts, 0.0, 10.0, 5.0, 2.0, 1.0) AS Rank
		FROM fts.title_fts f
		INNER JOIN title t ON t.TitleID = f.TitleID
		WHERE f.title_fts MATCH \'' . str_replace("'", "''", $q) . '\'
		ORDER BY Rank
		LIMIT ' . (int)$limit;

	return db_get($config['bhlpdo'], $sql);
}

//----------------------------------------------------------------------------------------
// Search titles (books/journals) by name.
//
// title_fts columns are: TitleID, FullTitle, ShortTitle, Authors, Subjects
// so the bm25 weights below say "a hit in FullTitle counts for far more than a hit
// in the subject headings".
function search_titles($text, $limit = 10)
{
	// Three passes, narrowest first, each topping up the last rather than
	// replacing it. A strict AND match is the best answer when it exists, but on
	// its own it silently under-returns: "orchids" is not a prefix of "Orchidees",
	// so an AND search finds the English title and misses the French one.
	$hits = array();
	$seen = array();

	$passes = array(
		fts_query($text, true, ' AND '),	// every content word present
		fts_query($text, true, ' OR ')		// any content word, bm25 ranked
	);

	foreach ($passes as $q)
	{
		if (count($hits) >= $limit)
		{
			break;
		}

		foreach (title_match($q, $limit) as $hit)
		{
			if (!isset($seen[$hit->TitleID]))
			{
				$seen[$hit->TitleID] = true;
				$hits[] = $hit;
			}
		}
	}

	// Last resort: the wording may not line up with token boundaries at all
	// ("orchide" inside "Orchideenkunde"), so try substrings.
	if (count($hits) == 0)
	{
		return search_titles_fuzzy($text, $limit);
	}

	return array_slice($hits, 0, $limit);
}

//----------------------------------------------------------------------------------------
// Search parts (articles/chapters) by name.
//
// part_fts columns are: PartID, Title, ContainerTitle, Authors
function search_parts($text, $limit = 10)
{
	global $config;

	$q = fts_query($text);

	if ($q == '')
	{
		return array();
	}

	$sql = 'SELECT f.PartID, p.Title, p.ContainerTitle, p.Volume, p.Date, p.PageRange,
		p.StartPageID,
		bm25(f.part_fts, 0.0, 10.0, 2.0, 2.0) AS Rank
		FROM fts.part_fts f
		INNER JOIN part p ON p.PartID = f.PartID
		WHERE f.part_fts MATCH \'' . str_replace("'", "''", $q) . '\'
		ORDER BY Rank
		LIMIT ' . (int)$limit;

	return db_get($config['bhlpdo'], $sql);
}

//----------------------------------------------------------------------------------------
// Fuzzy title search, for when the wording is only approximately right.
// Uses the trigram index, so LIKE '%...%' is index-backed rather than a table scan.
function search_titles_fuzzy($text, $limit = 10)
{
	global $config;

	$sql = 'SELECT g.TitleID, t.FullTitle
		FROM fts.title_trgm g
		INNER JOIN title t ON t.TitleID = g.TitleID
		WHERE g.FullTitle LIKE \'%' . str_replace("'", "''", $text) . '%\'
		LIMIT ' . (int)$limit;

	return db_get($config['bhlpdo'], $sql);
}

//----------------------------------------------------------------------------------------
// Pull four-digit years out of a creator query.
//
// BHL keeps life dates inside the creator string ("Thunberg, Carl Peter, 1743-1828"),
// so people naturally paste them in - but the FTS index holds names only, because the
// dates were split out into creator_parsed as integers. Left in the MATCH they would
// match nothing and, ANDed, would sink the whole query. So lift them out here and use
// them as a filter instead.
function split_years($text, &$years)
{
	$years = array();

	$text = preg_replace_callback(
		'/\b(1[0-9]{3}|20[0-2][0-9])\b/',
		function ($m) use (&$years)
		{
			$years[] = (int)$m[1];
			return ' ';
		},
		$text);

	return $text;
}

//----------------------------------------------------------------------------------------
// Run one MATCH against creator_fts, optionally constrained by year.
//
// creator_fts columns are: CreatorID, NameOnly, Surname, Forename, ForenameFull
// Surname is weighted hardest - searching "Hooker" should put the Hookers first, not
// everyone with Hooker as a middle name.
function creator_match($q, $years, $limit)
{
	global $config;

	if ($q == '')
	{
		return array();
	}

	$where = 'f.creator_fts MATCH \'' . str_replace("'", "''", $q) . '\'';

	if (count($years) > 0)
	{
		$clauses = array();

		foreach ($years as $y)
		{
			$y = (int)$y;

			// a year the person was born, died, was alive, or was active in
			$clauses[] = '(c.BirthYear = ' . $y . '
				OR c.DeathYear = ' . $y . '
				OR ' . $y . ' BETWEEN c.BirthYear AND c.DeathYear
				OR ' . $y . ' BETWEEN c.FloruitStart AND c.FloruitEnd)';
		}

		$where .= ' AND (' . join(' OR ', $clauses) . ')';
	}

	// bm25 is negative and more-negative is better, so multiplying by a prominence
	// factor pushes well-published people up. Without this, searching "Hooker"
	// buries W. J. Hooker (58 titles) below a namesake with five, because every
	// candidate matches the surname token equally well and only field length
	// separates them. log() keeps one prolific author from swamping everything.
	$sql = 'SELECT c.CreatorID, c.CreatorName, c.Kind, c.Surname, c.Forename,
		c.ForenameFull, c.Initials, c.BirthYear, c.DeathYear, c.FloruitStart,
		c.FloruitEnd, c.Uncertain, c.TitleCount, c.PartCount,
		bm25(f.creator_fts, 0.0, 5.0, 10.0, 2.0, 2.0) AS Rank,
		bm25(f.creator_fts, 0.0, 5.0, 10.0, 2.0, 2.0)
			* (1.0 + log(1 + c.TitleCount + c.PartCount)) AS Score
		FROM fts.creator_fts f
		INNER JOIN fts.creator_parsed c ON c.CreatorID = f.CreatorID
		WHERE ' . $where . '
		ORDER BY Score
		LIMIT ' . (int)$limit;

	return db_get($config['bhlpdo'], $sql);
}

//----------------------------------------------------------------------------------------
// Search creators (authors, corporate bodies, meetings) by name.
//
// Same widening cascade as search_titles: strictest pass first, later passes topping
// up rather than replacing. Note that the bibliographic stopword list is deliberately
// NOT applied here - "von", "de", "van" are name particles, not noise.
function search_creators($text, $limit = 10)
{
	$years = array();
	$name = split_years($text, $years);

	$and = fts_query($name, true, ' AND ', false);
	$or  = fts_query($name, true, ' OR ',  false);

	$passes = array();

	if (count($years) > 0)
	{
		// name AND year is the most specific thing we can ask for
		$passes[] = array($and, $years);
		$passes[] = array($or,  $years);
	}

	// then the same thing ignoring the year, in case the person has no dates recorded
	$passes[] = array($and, array());
	$passes[] = array($or,  array());

	$hits = array();
	$seen = array();

	foreach ($passes as $pass)
	{
		if (count($hits) >= $limit)
		{
			break;
		}

		foreach (creator_match($pass[0], $pass[1], $limit) as $hit)
		{
			if (!isset($seen[$hit->CreatorID]))
			{
				$seen[$hit->CreatorID] = true;
				$hits[] = $hit;
			}
		}
	}

	if (count($hits) == 0)
	{
		return search_creators_fuzzy($text, $limit);
	}

	return array_slice($hits, 0, $limit);
}

//----------------------------------------------------------------------------------------
// Substring creator search, for partial and half-remembered names.
//
// The trigram index makes LIKE '%...%' index-backed rather than a table scan, so this
// finds a fragment anywhere inside a name. It is NOT edit-distance matching: trigram
// will not rescue a typo in the middle of a word. It does rescue a typo near the end,
// because we retry on progressively shorter leading fragments - "lesquerux" fails,
// but "lesquer" finds Lesquereux. Five characters is the floor; below that almost
// anything matches and the results stop meaning much.
function search_creators_fuzzy($text, $limit = 10)
{
	global $config;

	$years = array();
	$probe = trim(split_years($text, $years));

	while (mb_strlen($probe) >= 5)
	{
		$sql = 'SELECT c.CreatorID, c.CreatorName, c.Surname, c.BirthYear, c.DeathYear,
			c.TitleCount, c.PartCount
			FROM fts.creator_trgm g
			INNER JOIN fts.creator_parsed c ON c.CreatorID = g.CreatorID
			WHERE g.NameOnly LIKE \'%' . str_replace("'", "''", $probe) . '%\'
			ORDER BY c.TitleCount + c.PartCount DESC
			LIMIT ' . (int)$limit;

		$hits = db_get($config['bhlpdo'], $sql);

		if (count($hits) > 0)
		{
			return $hits;
		}

		$probe = mb_substr($probe, 0, mb_strlen($probe) - 1);
	}

	return array();
}

//----------------------------------------------------------------------------------------
// What did this creator write? Titles they are credited on.
// Everything a creator is credited on - titles AND articles. Both, because credits
// live in two separate tables and 161,583 creators appear only in partcreator: for
// them a title-only lookup returns nothing, which reads as "this person published
// nothing" when they may have written dozens of papers.
function creator_titles($CreatorID, $limit = 20)
{
	global $config;

	$CreatorID = (int)$CreatorID;
	$limit     = (int)$limit;

	$sql = 'SELECT t.TitleID AS ID, "title" AS Kind, t.FullTitle AS Name,
		cr.CreatorType AS Role, NULL AS Date
		FROM creator cr
		INNER JOIN title t ON t.TitleID = cr.TitleID
		WHERE cr.CreatorID = ' . $CreatorID . '
		GROUP BY t.TitleID

		UNION ALL

		SELECT p.PartID AS ID, "part" AS Kind, p.Title AS Name,
		NULL AS Role, p.Date AS Date
		FROM partcreator pc
		INNER JOIN part p ON p.PartID = pc.PartID
		WHERE pc.CreatorID = ' . $CreatorID . '
		GROUP BY p.PartID

		LIMIT ' . $limit;

	return db_get($config['bhlpdo'], $sql);
}

//----------------------------------------------------------------------------------------
// Bounding-box clause over the R*Tree, plus the exact re-check.
//
// R*Tree coordinates are 32-bit floats rounded OUTWARD, so the index can hand back
// points just outside the box but never drops one inside it. That makes it a
// prefilter, not an answer: the second half of this clause re-checks the real
// REAL-precision values in pagegeo. Skip the re-check and boxes leak at the edges.
function bbox_where($minLat, $maxLat, $minLon, $maxLon)
{
	return 'r.maxLon >= ' . (float)$minLon . ' AND r.minLon <= ' . (float)$maxLon . '
		AND r.maxLat >= ' . (float)$minLat . ' AND r.minLat <= ' . (float)$maxLat . '
		AND g.Longitude BETWEEN ' . (float)$minLon . ' AND ' . (float)$maxLon . '
		AND g.Latitude  BETWEEN ' . (float)$minLat . ' AND ' . (float)$maxLat;
}

//----------------------------------------------------------------------------------------
// Great-circle distance in km, as a SQL expression.
function distance_km($lat, $lon)
{
	return '6371 * acos(min(1.0,
		sin(radians(' . (float)$lat . ')) * sin(radians(g.Latitude))
		+ cos(radians(' . (float)$lat . ')) * cos(radians(g.Latitude))
		* cos(radians(g.Longitude) - radians(' . (float)$lon . '))))';
}

//----------------------------------------------------------------------------------------
// Which works mention localities inside this box?
function works_in_bbox($minLat, $maxLat, $minLon, $maxLon, $limit = 20)
{
	global $config;

	$sql = 'SELECT t.TitleID, t.FullTitle,
		COUNT(DISTINCT g.GeoID) AS Points,
		COUNT(DISTINCT g.PageID) AS Pages,
		min(g.Latitude) AS MinLat, max(g.Latitude) AS MaxLat,
		min(g.Longitude) AS MinLon, max(g.Longitude) AS MaxLon
		FROM geo.pagegeo_rtree r
		INNER JOIN geo.pagegeo g ON g.GeoID = r.GeoID
		INNER JOIN page  p ON p.PageID = g.PageID
		INNER JOIN item  i ON i.ItemID = p.ItemID
		INNER JOIN title t ON t.TitleID = i.TitleID
		WHERE ' . bbox_where($minLat, $maxLat, $minLon, $maxLon) . '
		GROUP BY t.TitleID
		ORDER BY Points DESC
		LIMIT ' . (int)$limit;

	return db_get($config['bhlpdo'], $sql);
}

//----------------------------------------------------------------------------------------
// Which articles mention localities inside this box?
// Parts have no pages of their own - they reach them through partpage.
function parts_in_bbox($minLat, $maxLat, $minLon, $maxLon, $limit = 20)
{
	global $config;

	$sql = 'SELECT pt.PartID, pt.Title, pt.ContainerTitle, pt.Date,
		COUNT(DISTINCT g.GeoID) AS Points
		FROM geo.pagegeo_rtree r
		INNER JOIN geo.pagegeo g ON g.GeoID = r.GeoID
		INNER JOIN partpage pp ON pp.PageID = g.PageID
		INNER JOIN part pt ON pt.PartID = pp.PartID
		WHERE ' . bbox_where($minLat, $maxLat, $minLon, $maxLon) . '
		GROUP BY pt.PartID
		ORDER BY Points DESC
		LIMIT ' . (int)$limit;

	return db_get($config['bhlpdo'], $sql);
}

//----------------------------------------------------------------------------------------
// Every point in one work - this is what you draw a map from.
function points_for_title($TitleID, $limit = 1000)
{
	global $config;

	$sql = 'SELECT g.PageID, g.Latitude, g.Longitude, g.Locality, g.Source,
		p.SequenceOrder
		FROM item i
		INNER JOIN page p ON p.ItemID = i.ItemID
		INNER JOIN geo.pagegeo g ON g.PageID = p.PageID
		WHERE i.TitleID = ' . (int)$TitleID . '
		ORDER BY p.SequenceOrder
		LIMIT ' . (int)$limit;

	return db_get($config['bhlpdo'], $sql);
}

//----------------------------------------------------------------------------------------
// Points near a coordinate. The box is a cheap prefilter sized to the radius, then
// the great-circle distance does the real work - a box is not a circle, and at high
// latitudes a degree of longitude is much shorter than a degree of latitude.
function points_near($lat, $lon, $km = 100, $limit = 50)
{
	global $config;

	$dLat = $km / 111.0;
	$cos = cos(deg2rad($lat));
	$dLon = ($cos > 0.01) ? $km / (111.0 * $cos) : 180.0;

	$dist = distance_km($lat, $lon);

	$sql = 'SELECT g.PageID, g.Latitude, g.Longitude, g.Locality,
		round(' . $dist . ', 2) AS DistanceKm
		FROM geo.pagegeo_rtree r
		INNER JOIN geo.pagegeo g ON g.GeoID = r.GeoID
		WHERE r.maxLon >= ' . ($lon - $dLon) . ' AND r.minLon <= ' . ($lon + $dLon) . '
		AND r.maxLat >= ' . ($lat - $dLat) . ' AND r.minLat <= ' . ($lat + $dLat) . '
		AND ' . $dist . ' <= ' . (float)$km . '
		ORDER BY DistanceKm
		LIMIT ' . (int)$limit;

	return db_get($config['bhlpdo'], $sql);
}

//----------------------------------------------------------------------------------------
// Taxonomic names recorded from within a region.
//
// This is the join that makes the geo data worth having: pagename and pagegeo are
// both keyed on PageID, so "what was reported from here" needs no extra machinery.
function names_in_bbox($minLat, $maxLat, $minLon, $maxLon, $limit = 50)
{
	global $config;

	$sql = 'SELECT pn.NameConfirmed, pn.NameBankID,
		COUNT(DISTINCT g.PageID) AS Pages
		FROM geo.pagegeo_rtree r
		INNER JOIN geo.pagegeo g ON g.GeoID = r.GeoID
		INNER JOIN pagename pn ON pn.PageID = g.PageID
		WHERE ' . bbox_where($minLat, $maxLat, $minLon, $maxLon) . '
		GROUP BY pn.NameConfirmed
		ORDER BY Pages DESC
		LIMIT ' . (int)$limit;

	return db_get($config['bhlpdo'], $sql);
}

//----------------------------------------------------------------------------------------
// Where has this name been reported from? The inverse of names_in_bbox.
function points_for_name($name, $limit = 500)
{
	global $config;

	$sql = 'SELECT g.Latitude, g.Longitude, g.PageID, g.Locality
		FROM pagename pn
		INNER JOIN geo.pagegeo g ON g.PageID = pn.PageID
		WHERE pn.NameConfirmed = \'' . str_replace("'", "''", $name) . '\'
		LIMIT ' . (int)$limit;

	return db_get($config['bhlpdo'], $sql);
}

?>
