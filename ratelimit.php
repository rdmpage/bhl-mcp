<?php

// ratelimit.php
//
// A budget on how fast this server will fetch from BHL's S3 bucket.
//
// page_text and page_image fetch a page from BHL on a cache miss. On a public
// endpoint that means anyone who can reach us can make us fetch, and a client stuck
// in a loop would hammer someone else's infrastructure on our behalf. The cap is
// global rather than per-client, because what needs protecting is BHL, and BHL sees
// one caller here regardless of how many people are connected.
//
// Cache hits never reach this - only fetches that would cross the network do, so a
// workshop rereading the same pages costs nothing.

// One fetch per second sustained, with 120 banked so a room of people all starting
// at once doesn't trip it. That is a polite ceiling for S3 and far above anything
// human browsing produces.
define('BHL_FETCH_PER_SECOND', 1.0);
define('BHL_FETCH_BURST', 120);

//----------------------------------------------------------------------------------------
// Take one token from the bucket. Returns false if the budget is spent.
//
// This is a token bucket kept in a single file: refill by elapsed time, spend one,
// write back. PHP is process-per-request and several may land at once, so the whole
// read-modify-write is done under an exclusive flock - without it two concurrent
// requests both read the same count and both spend it.
function bhl_fetch_allowed()
{
	global $config;

	$path = $config['cache'] . '/fetch-budget.json';

	$fp = @fopen($path, 'c+');

	if ($fp === false)
	{
		// Fail open, loudly. The realistic cause is an unwritable cache directory on
		// first run, and a rate limiter that silently breaks every page fetch is a
		// worse failure than a missing ceiling - but it must not pass unnoticed.
		error_log('bhl_fetch_allowed(): cannot open ' . $path . ' - fetch budget NOT enforced');
		return true;
	}

	if (!flock($fp, LOCK_EX))
	{
		fclose($fp);
		error_log('bhl_fetch_allowed(): cannot lock ' . $path . ' - fetch budget NOT enforced');
		return true;
	}

	$now = time();

	// Read with stream_get_contents rather than fread($fp, filesize($path)):
	// filesize() consults PHP's stat cache, which within a single process still
	// reports the size from before our own write. That made every call see an
	// empty file, reset the bucket to full, and never enforce anything.
	rewind($fp);
	$raw = stream_get_contents($fp);

	$state = ($raw === false || $raw === '') ? null : json_decode($raw, true);

	if (!is_array($state) || !isset($state['tokens']) || !isset($state['ts']))
	{
		// First run, or the file was truncated or corrupted. Start full.
		$state = array('tokens' => BHL_FETCH_BURST, 'ts' => $now);
	}

	// Refill for the time that has passed, capped at the burst size. max(0, ...)
	// guards against a clock that has gone backwards.
	$elapsed = max(0, $now - (int)$state['ts']);
	$tokens  = min(BHL_FETCH_BURST, (float)$state['tokens'] + ($elapsed * BHL_FETCH_PER_SECOND));

	$allowed = ($tokens >= 1.0);

	if ($allowed)
	{
		$tokens -= 1.0;
	}
	else
	{
		error_log('bhl_fetch_allowed(): BHL fetch budget exhausted, serving from cache only');
	}

	rewind($fp);
	ftruncate($fp, 0);
	fwrite($fp, json_encode(array('tokens' => $tokens, 'ts' => $now)));
	fflush($fp);

	flock($fp, LOCK_UN);
	fclose($fp);

	return $allowed;
}

?>
