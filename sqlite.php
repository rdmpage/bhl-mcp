<?php

require_once (dirname(__FILE__) . '/config.inc.php');

//----------------------------------------------------------------------------------------
// retrieve data from database
function db_get($pdo, $sql)
{
	$stmt = $pdo->query($sql);

	$data = array();

	while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

		$item = new stdclass;
		
		$keys = array_keys($row);
	
		foreach ($keys as $k)
		{
			if ($row[$k] != '')
			{
				$item->{$k} = $row[$k];
			}
		}
	
		$data[] = $item;
	}	
	return $data;	
}

//----------------------------------------------------------------------------------------
function db_put($pdo, $sql)
{
	$stmt = $pdo->prepare($sql);
	
	if (!$stmt)
	{
		echo "\nPDO::errorInfo():\n";
		print_r($pdo->errorInfo());
	}	
	
	$stmt->execute();
	
	if (!$stmt)
	{
		echo "\nPDO::errorInfo():\n";
		print_r($pdo->errorInfo());
	}
}

//----------------------------------------------------------------------------------------
function obj_to_sql($obj, $table_name)
{
	// to $sql
	$keys = array();
	$values = array();
	
	foreach ($obj as $k => $v)
	{
		$keys[] = '"' . $k . '"'; // must be double quotes
	
		if (is_array($v))
		{
			$values[] = "'" . str_replace("'", "''", json_encode(array_values($v))) . "'";
		}
		elseif(is_object($v))
		{
			$values[] = "'" . str_replace("'", "''", json_encode($v)) . "'";
		}
		else
		{				
			$values[] = "'" . str_replace("'", "''", $v) . "'";
		}					
	}
	
	//$sql = 'INSERT OR IGNORE INTO `' . $table_name . '` (' . join(",", $keys) . ') VALUES (' . join(",", $values) . ') ON CONFLICT DO NOTHING';					
	$sql = 'REPLACE INTO `' . $table_name . '` (' . join(",", $keys) . ') VALUES (' . join(",", $values) . ')';					

	return $sql;
}

//----------------------------------------------------------------------------------------
// https://gist.github.com/fcingolani/5364532
//----------------------------------------------------------------------------------------
// Load one BHL dump file into a table, creating the table from the file's own header.
//
// The BHL dumps are plain tab-separated text: no quoting convention, no embedded tabs
// or newlines - verified across every row of part.txt, which splits cleanly into its
// 24 columns. So the parse is explode(), NOT fgetcsv(): fgetcsv treats " as an
// enclosure and silently merges any row containing one into its neighbour. On part.txt
// that lost 59 of 404,793 rows, and the damage is merged records rather than missing
// ones, which is worse.
//
// $path may be .gz; it is read through the compression rather than expanded to disk,
// which matters because page.txt is 5 GB uncompressed.
function import_csv_to_sqlite(&$pdo, $path, $options = array())
{
	$table     = isset($options['table'])     ? $options['table']     : preg_replace('/\.txt(\.gz)?$/', '', basename($path));
	$delimiter = isset($options['delimiter']) ? $options['delimiter'] : "\t";
	$batch     = isset($options['batch'])     ? (int)$options['batch'] : 50000;
	$verbose   = isset($options['verbose'])   ? $options['verbose']   : true;

	$gz = preg_match('/\.gz$/', $path);

	$handle = $gz ? @gzopen($path, 'rb') : @fopen($path, 'r');

	if ($handle === false)
	{
		throw new Exception('Cannot open ' . $path);
	}

	$line = $gz ? gzgets($handle) : fgets($handle);

	if ($line === false)
	{
		throw new Exception('Empty file: ' . $path);
	}

	// The BHL files start with a UTF-8 BOM, which would otherwise become part of the
	// first column's name.
	$line = preg_replace('/^\xEF\xBB\xBF/', '', $line);

	$fields = array();

	foreach (explode($delimiter, rtrim($line, "\r\n")) as $name)
	{
		$fields[] = preg_replace('/[^A-Za-z0-9_]/', '', $name);
	}

	$count = count($fields);

	if ($count === 0)
	{
		throw new Exception('No columns found in ' . $path);
	}

	// Match the affinity the hand-written schema used: identifiers and sequence
	// numbers as INTEGER, everything else TEXT. Keeps the indexes compact and keeps
	// typeof() answering what callers expect.
	$columns = array();

	foreach ($fields as $name)
	{
		$columns[] = '"' . $name . '" ' . (preg_match('/(ID|Order)$/', $name) ? 'INTEGER' : 'TEXT');
	}

	// Drop rather than CREATE IF NOT EXISTS: the point of this importer is to rebuild
	// a table from the current dump, and against an existing table of a different
	// shape every insert would fail.
	$pdo->exec('DROP TABLE IF EXISTS "' . $table . '"');
	$pdo->exec('CREATE TABLE "' . $table . '" (' . join(', ', $columns) . ')');

	$placeholders = join(', ', array_fill(0, $count, '?'));
	$names        = '"' . join('", "', $fields) . '"';

	$stmt = $pdo->prepare('INSERT INTO "' . $table . '" (' . $names . ') VALUES (' . $placeholders . ')');

	$rows    = 0;
	$skipped = 0;

	$pdo->beginTransaction();

	while (($line = ($gz ? gzgets($handle) : fgets($handle))) !== false)
	{
		$line = rtrim($line, "\r\n");

		if ($line === '')
		{
			continue;
		}

		$values = explode($delimiter, $line);

		// A row that does not match the header is a parse problem, not data. Count it
		// and carry on rather than aborting a multi-hour import - but never pad it
		// out, which would silently shift every value into the wrong column.
		if (count($values) !== $count)
		{
			$skipped++;
			continue;
		}

		foreach ($values as $k => $v)
		{
			if ($v === '\\N' || $v === '')
			{
				$values[$k] = null;
			}
		}

		$stmt->execute($values);
		$rows++;

		// Commit periodically. page.txt is 68 million rows and a single transaction
		// that size builds a rollback journal to match.
		if ($batch > 0 && ($rows % $batch) === 0)
		{
			$pdo->commit();
			$pdo->beginTransaction();

			if ($verbose)
			{
				fwrite(STDERR, '  ' . $table . ': ' . number_format($rows) . " rows\r");
			}
		}
	}

	$pdo->commit();

	$gz ? gzclose($handle) : fclose($handle);

	if ($verbose)
	{
		fwrite(STDERR, '  ' . $table . ': ' . number_format($rows) . ' rows'
			. ($skipped > 0 ? ', ' . number_format($skipped) . ' SKIPPED (column count mismatch)' : '')
			. "        \n");
	}

	return array(
		'table'         => $table,
		'fields'        => $fields,
		'inserted_rows' => $rows,
		'skipped_rows'  => $skipped,
	);
}

?>
