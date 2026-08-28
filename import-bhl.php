<?php

ini_set('memory_limit', '-1');

$basedir = dirname(__FILE__) . '/bhldata';

// files we want to process
$files = array(
'creator.txt',
'creatoridentifier.txt',
'doi.txt',
'item.txt',
'page.txt',
'pagename.txt',
'part.txt',
'partcreator.txt',
'partpage.txt',
'partidentifier.txt',
'subject.txt',
'title.txt',
'titleidentifier.txt',
);

$show_schema = true;
$show_schema = false;

foreach ($files as $filename)
{
	if (preg_match('/\.txt/', $filename))
	{
		echo "--$filename\n";
		
		$row_count = 0;
		
		$headings = array();
		$all_rows = array();
		
		$table = $filename;
		$table = str_replace('.txt', '', $table);
		
		$keys = array();
		
		switch ($table)
		{		
			case "creator":
				$keys = array('TitleID', 'CreatorID', 'CreatorType', 'CreatorName'); 
				break;

			case "creatoridentifier":
				$keys = array('CreatorID', 'IdentifierName', 'IdentifierValue'); 
				break;

			case "doi":
				$keys = array('EntityType', 'EntityID', 'DOI'); 
				break;

			case "item":
				$keys = array('ItemID', 'TitleID', 'ThumbnailPageID', 'BarCode', 
				'VolumeInfo', 'Year', 'InstitutionName', 'CreationDate', 
				'CopyrightStatus', 'RightsStatement', 'RightsHolder');
				break;
				
			case "page":
				$keys = array('PageID', 'ItemID', 'SequenceOrder', 'PagePrefix', 'PageNumber', 'PageTypeName');
				break;
				
			case "pagename":
				$keys = array('NameBankID', 'NameConfirmed', 'PageID', 'CreationDate');
				break;

			case "part":
				$keys = array('PartID', 'ItemID', 'ContributorName', 'SequenceOrder', 
				'Title', 'ContainerTitle', 'Volume', 'Date', 'PageRange', 'StartPageID',
				'RightsStatus', 'RightsStatement', 'LicenseUrl', 'RightsHolder');
				break;
				
			case "partcreator":
				$keys = array('PartID', 'CreatorID', 'CreatorType', 'CreatorName'); 
				break;

			case "partpage":
				$keys = array('PartID', 'PageID', 'ItemID', 'SequenceOrder');
				break;
				
			case "partidentifier":
				$keys = array('PartID', 'IdentifierName', 'IdentifierValue');
				break;

			case "subject":
				$keys = array('TitleID', 'Subject');
				break;

			case "title":
				$keys = array('TitleID', 'FullTitle', 'ShortTitle');
				break;
				
			case "titleidentifier":
				$keys = array('TitleID', 'IdentifierName', 'IdentifierValue');
				break;
												
			default:
				break;
		}
		
		$file_handle = fopen($basedir . '/' . $filename, "r");
		while (!feof($file_handle)) 
		{
			// don't trim as first column might be empty
			$line = fgets($file_handle);
				
			$row = explode("\t",$line);
			
			$go = is_array($row) && count($row) > 1;
			
			if ($go)
			{
				if ($row_count == 0)
				{
					$headings = $row;	
					
					// deal with BOM!
					$headings[0] = preg_replace('/\xEF\xBB\xBF/', '', $headings[0]);
					
					//print_r($headings);
					
					if ($show_schema)
					{
						echo "CREATE TABLE $table (\n";
						
						$columns = array();
						
						foreach ($keys as $k)
						{
							if (preg_match('/(ID|Order)$/', $k))
							{
								$columns[] = $k . ' INTEGER';
							}
							else
							{
								$columns[] = $k . ' TEXT';
							}
						}
						
						echo join("\n,", $columns);
						
						echo ");\n\n";
						break;
					}
				}
				else
				{
					$obj = new stdclass;
				
					foreach ($row as $k => $v)
					{
						if ($v != '')
						{
							$obj->{$headings[$k]} = $v;
						}
					}
					
					//print_r($obj);
					
					$values = array();
					
					foreach ($keys as $k)
					{
						if (isset($obj->{$k}))
						{
							$v = $obj->{$k};
						}
						else
						{
							$v = null;
						}
					
						if (!$v)
						{
							$values[] = 'NULL';
						}
						elseif (is_array($v))
						{
							$values[] = "'" . str_replace("'", "''", json_encode(array_values($v))) . "'";
						}
						elseif(is_object($v))
						{
							$values[] = "'" . str_replace("'", "''", json_encode($v)) . "'";
						}
						elseif (preg_match('/^POINT/', $v))
						{
							$values[] = "ST_GeomFromText('" . $v . "', 4326)";
						}
						else
						{				
							$values[] = "'" . str_replace("'", "''", mb_convert_encoding($v, 'UTF-8')) . "'";
						}					
					}
					
					$all_rows[] = '(' . join(",", $values) . ')';

				}
			}
			$row_count++;
		}
		
		$chunks = array_chunk($all_rows, 100, true);
		
		foreach ($chunks as $batch)
		{	
			echo "INSERT INTO $table (" . join(",", $keys) . ") VALUES\n";
			echo join(",\n", $batch);
			//echo " ON CONFLICT DO NOTHING";
			echo ";\n\n";	
		}
	}
}

?>
