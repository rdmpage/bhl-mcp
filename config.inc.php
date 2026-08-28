<?php

// $Id: //

/**
 * @file config.php
 *
 * Global configuration variables (may be added to by other modules).
 *
 */

global $config;

// Date timezone
date_default_timezone_set('UTC');

// Cache----------------------------------------------------------------------------------
$config['cache'] = dirname(__FILE__) . '/cache';

// Environment----------------------------------------------------------------------------
// In development this is a PHP file that is in .gitignore, when deployed these parameters
// will be set on the server
if (file_exists(dirname(__FILE__) . '/env.php'))
{
	include 'env.php';
}

// Database-------------------------------------------------------------------------------
// Paths are absolute so the db is found regardless of the current working directory.
$config['bhldb']    = dirname(__FILE__) . '/bhl.db';
$config['bhlftsdb'] = dirname(__FILE__) . '/bhl-fts.db';
$config['bhlgeodb'] = dirname(__FILE__) . '/bhl-geo.db';

$config['bhlpdo'] = new PDO('sqlite:' . $config['bhldb']);
$config['bhlpdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Full-text/fuzzy indexes live in a separate file so re-importing a BHL dump
// never forces an index rebuild. Attached as "fts", e.g. fts.title_fts.
if (file_exists($config['bhlftsdb']))
{
	$config['bhlpdo']->exec("ATTACH DATABASE '" . $config['bhlftsdb'] . "' AS fts");
	$config['has_fts'] = true;
}
else
{
	$config['has_fts'] = false;
}

// Point localities (BioStor and friends) also live outside bhl.db: they are not
// BHL's data, and must survive a BHL re-import. Attached as "geo".
if (file_exists($config['bhlgeodb']))
{
	$config['bhlpdo']->exec("ATTACH DATABASE '" . $config['bhlgeodb'] . "' AS geo");
	$config['has_geo'] = true;
}
else
{
	$config['has_geo'] = false;
}

?>
