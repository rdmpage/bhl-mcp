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
function get_page_image($id)
{
	global $config;
	
	$image = null;

	$sql = 'SELECT * FROM page 
	INNER JOIN item USING(ItemID)
	WHERE PageID=' . (int)$id;
	
	$data = db_get($config['bhlpdo'], $sql);
	

	if (isset($data[0]))
	{
		// cached?
		
		$filename = $config['cache'] . '/images/' . $data[0]->ItemID . '/' .  $data[0]->PageID . '.webp';
		
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
			. '_medium.webp';
						
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

	// SequenceOrder, not Date: within a single volume every part carries the same
	// date, so ordering by it is arbitrary. SequenceOrder is the order they appear
	// in the volume, which makes this a table of contents. One item has 580 parts,
	// hence the cap.
	$sql = "SELECT part.PartID, part.Title, part.Volume, part.Date, part.PageRange, part.StartPageID
		FROM part
		WHERE ItemID = $ItemID
		ORDER BY part.SequenceOrder
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
// Get list of parts in an item
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

function get_pages_collection($namespace, $id)
{


}


//----------------------------------------------------------------------------------------
function get_pages_with_name($name, $limit = 100)
{
	global $config;
	
	$pages = [];

	$sql = "SELECT * FROM pagename WHERE NameConfirmed = '" . str_replace("'", "''", $name) . "' LIMIT " . (int)$limit;

	$data = db_get($config['bhlpdo'], $sql);
	
	foreach ($data as $row)
	{
		$pages[] = $row->PageID;
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
function creator_titles($CreatorID, $limit = 20)
{
	global $config;

	$sql = 'SELECT t.TitleID, t.FullTitle, cr.CreatorType
		FROM creator cr
		INNER JOIN title t ON t.TitleID = cr.TitleID
		WHERE cr.CreatorID = ' . (int)$CreatorID . '
		GROUP BY t.TitleID
		LIMIT ' . (int)$limit;

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
