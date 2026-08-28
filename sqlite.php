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

?>
