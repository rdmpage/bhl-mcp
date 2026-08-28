#!/usr/bin/env php
<?php
// mcp_server.php
// MCP stdio server for BHL. Uses the shared handler in mcp_handler.php.
//
// stdout carries the protocol and nothing else, so display_errors is off and every
// diagnostic goes to stderr. See mcp_run_tool() for the matching guard around the
// query functions themselves.

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

fwrite(STDERR, "[bhl-mcp] Starting stdio server\n");

require_once(dirname(__FILE__) . '/mcp_handler.php');

//----------------------------------------------------------------------------------------
// MCP framing
//
// Claude sends line-delimited JSON; some test clients send LSP-style Content-Length
// framing. Sniff the first character and handle whichever turned up.

function readMessage()
{
	while (true)
	{
		$line = fgets(STDIN);

		if ($line === false)
		{
			return null;
		}

		$line = rtrim($line, "\r\n");

		if ($line === '')
		{
			continue;
		}

		if ($line[0] === '{' || $line[0] === '[')
		{
			$data = json_decode($line, true);

			return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
		}

		// Content-Length framing: read headers to the blank line, then the body.
		$headers = array();
		$h = $line;

		while (true)
		{
			$parts = explode(':', $h, 2);

			if (count($parts) === 2)
			{
				$headers[strtolower(trim($parts[0]))] = trim($parts[1]);
			}

			$next = fgets(STDIN);

			if ($next === false)
			{
				return null;
			}

			$h = rtrim($next, "\r\n");

			if ($h === '')
			{
				break;
			}
		}

		if (!isset($headers['content-length']))
		{
			return null;
		}

		$body = '';
		$remaining = (int)$headers['content-length'];

		while ($remaining > 0)
		{
			$chunk = fread(STDIN, $remaining);

			if ($chunk === false || $chunk === '')
			{
				return null;
			}

			$body .= $chunk;
			$remaining -= strlen($chunk);
		}

		$data = json_decode($body, true);

		return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
	}
}

function sendMessage($msg)
{
	$json = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	fwrite(STDOUT, $json . "\n");
	fflush(STDOUT);
}

//----------------------------------------------------------------------------------------
// Main loop

while (!feof(STDIN))
{
	$request = readMessage();

	if ($request === null)
	{
		if (feof(STDIN))
		{
			break;
		}

		continue;
	}

	$response = handleMcpRequest($request);

	if ($response !== null)
	{
		sendMessage($response);
	}
}

fwrite(STDERR, "[bhl-mcp] Server stopped\n");
