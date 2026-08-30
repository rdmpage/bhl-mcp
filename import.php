<?php

// import.php
//
// Rebuild tables in bhl.db from the BHL dump files in bhldata/, taking every column
// the dump offers rather than a hand-picked subset. Replaces import-bhl.php, which
// whitelisted columns per table and so dropped CreationDate from seven of them.
//
//   php import.php            # every dump present in bhldata/
//   php import.php doi part   # just those tables
//
// Each table is DROPped and rebuilt from its own header row, so this is destructive
// for the tables it touches. Tables with no dump file are left alone - which is how
// pagename survives, since BHL's dump of it is not kept here.
//
// Indexes are NOT recreated. Export them first (indexes-bhl.sql) and apply them
// afterwards; building them before the data is loaded would slow the import down for
// no reason.

require_once(dirname(__FILE__) . '/sqlite.php');

$basedir = dirname(__FILE__) . '/bhldata';

$tables = array(
	'creator',
	'creatoridentifier',
	'doi',
	'item',
	'page',
	'pagename',
	'part',
	'partcreator',
	'partidentifier',
	'partpage',
	'subject',
	'title',
	'titleidentifier',
);

// Restrict to the tables named on the command line, if any.
if (isset($argv) && count($argv) > 1)
{
	$wanted = array_slice($argv, 1);
	$tables = array_values(array_intersect($tables, $wanted));

	$unknown = array_diff($wanted, $tables);

	if (count($unknown) > 0)
	{
		fwrite(STDERR, "Unknown table(s): " . join(', ', $unknown) . "\n");
		exit(1);
	}
}

// A write connection of our own. config.inc.php opens bhl.db read-only, which is
// right for everything that serves queries and wrong for this.
$dbfile = dirname(__FILE__) . '/bhl.db';

$pdo = new PDO('sqlite:' . $dbfile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Import speed, not durability: this is a rebuild from files that still exist, so
// there is nothing to lose to a crash that a re-run would not fix.
$pdo->exec('PRAGMA journal_mode = OFF');
$pdo->exec('PRAGMA synchronous = OFF');
$pdo->exec('PRAGMA temp_store = MEMORY');

$total   = 0;
$skipped = 0;

foreach ($tables as $table)
{
	// .gz is read through the compression; a plain .txt is used if that is what is there.
	$path = $basedir . '/' . $table . '.txt.gz';

	if (!file_exists($path))
	{
		$path = $basedir . '/' . $table . '.txt';
	}

	if (!file_exists($path))
	{
		$existing = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='" . $table . "'")->fetchColumn();

		fwrite(STDERR, '  ' . $table . ': no dump file, left as it is'
			. ($existing ? ' (existing table kept)' : ' (no such table)') . "\n");

		continue;
	}

	$started = microtime(true);

	$result = import_csv_to_sqlite($pdo, $path, array('table' => $table, 'delimiter' => "\t"));

	$total   += $result['inserted_rows'];
	$skipped += $result['skipped_rows'];

	fwrite(STDERR, '    ' . number_format($result['inserted_rows']) . ' rows in '
		. round(microtime(true) - $started) . "s, " . count($result['fields']) . " columns\n");
}

fwrite(STDERR, "\n" . number_format($total) . " rows imported"
	. ($skipped > 0 ? ', ' . number_format($skipped) . " SKIPPED" : '') . "\n");
fwrite(STDERR, "Now apply the indexes:  sqlite3 bhl.db < indexes-bhl.sql\n");

?>
