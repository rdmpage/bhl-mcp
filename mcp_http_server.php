<?php
// mcp_http_server.php
// MCP server over HTTP (Streamable HTTP transport), sharing mcp_handler.php with
// the stdio server.
//
// Deploy behind Apache with the .htaccess rewrite, so the MCP endpoint is /mcp.
// Standalone: php -S localhost:3000 mcp_http_server.php   -- but note the built-in
// server is single-threaded and serialises requests, so it is for poking at only,
// never for anything more than one client at a time.
//
// This implements the JSON half of Streamable HTTP: every request gets a single
// application/json response. SSE streaming, sessions and resumability are all
// optional in the spec and none of them are used here - which also means the
// server is completely stateless, and PHP's process-per-request model fits it.

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

require_once(dirname(__FILE__) . '/mcp_handler.php');

//----------------------------------------------------------------------------------------
// Configuration

// Browser origins allowed to call this endpoint. Empty is the right default: the
// Claude app connects from Anthropic's cloud, server to server, and sends no Origin
// header at all. Only add entries here for a browser-based client such as the MCP
// Inspector, and then name the exact origin - never '*'.
$MCP_ALLOWED_ORIGINS = array();

// Protocol versions this server will answer for. A client asking for anything else
// gets a 400, per the spec.
$MCP_PROTOCOL_VERSIONS = array(
	'2024-11-05',
	'2025-03-26',
	'2025-06-18',
	'2025-11-25',
	'2026-07-28',
);

// Nothing legitimate posts a large body here; the biggest real request is a long
// search string.
$MCP_MAX_BODY = 262144;

//----------------------------------------------------------------------------------------
// Helpers

function mcp_http_error($status, $code, $message)
{
	http_response_code($status);
	header('Content-Type: application/json');

	echo json_encode(array(
		'jsonrpc' => '2.0',
		'id'      => null,
		'error'   => array('code' => $code, 'message' => $message),
	), JSON_UNESCAPED_SLASHES);

	exit;
}

function mcp_header($name)
{
	$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

	return isset($_SERVER[$key]) ? $_SERVER[$key] : null;
}

//----------------------------------------------------------------------------------------
// Origin check
//
// The spec requires this: it is what stops a web page the user happens to be
// visiting from driving the server through their browser (a DNS rebinding attack).
// A request with no Origin is not a browser request, so it passes - that is the
// normal case for an MCP client.

$origin = mcp_header('Origin');

if ($origin !== null && !in_array($origin, $MCP_ALLOWED_ORIGINS))
{
	http_response_code(403);
	header('Content-Type: application/json');

	echo json_encode(array(
		'jsonrpc' => '2.0',
		'id'      => null,
		'error'   => array('code' => -32600, 'message' => 'Origin not allowed'),
	), JSON_UNESCAPED_SLASHES);

	exit;
}

if ($origin !== null)
{
	header('Access-Control-Allow-Origin: ' . $origin);
	header('Vary: Origin');
	header('Access-Control-Allow-Methods: POST, OPTIONS');
	header('Access-Control-Allow-Headers: Content-Type, MCP-Protocol-Version');
}

//----------------------------------------------------------------------------------------
// Method dispatch

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method === 'OPTIONS')
{
	http_response_code(204);
	exit;
}

// GET is where a client opens an SSE stream. This server does not offer one, and the
// spec's answer for that is 405 - not a friendly HTML page, which a client probing
// the endpoint can mistake for the old HTTP+SSE transport. The body is just for
// humans who paste the URL into a browser.
if ($method === 'GET' || $method === 'HEAD' || $method === 'DELETE')
{
	http_response_code(405);
	header('Allow: POST, OPTIONS');
	header('Content-Type: text/plain; charset=utf-8');

	if ($method === 'HEAD')
	{
		exit;
	}

	echo "BHL MCP server - Streamable HTTP endpoint\n\n";
	echo "This endpoint speaks MCP over JSON-RPC. It accepts POST only;\n";
	echo "GET is reserved for SSE streams, which this server does not offer.\n\n";
	echo "  curl -X POST " . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/mcp') . " \\\n";
	echo "    -H 'Content-Type: application/json' \\\n";
	echo "    -H 'Accept: application/json, text/event-stream' \\\n";
	echo "    -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\",\"params\":{}}'\n\n";
	echo "Tools (" . count(getToolDefinitions()) . "):\n";

	foreach (getToolDefinitions() as $tool)
	{
		echo '  - ' . $tool['name'] . "\n";
	}

	exit;
}

if ($method !== 'POST')
{
	http_response_code(405);
	header('Allow: POST, OPTIONS');
	echo "Method Not Allowed\n";
	exit;
}

//----------------------------------------------------------------------------------------
// POST - the actual transport

// Absent means a client older than 2025-06-18, which the spec says to treat as
// 2025-03-26 rather than reject.
$version = mcp_header('MCP-Protocol-Version');

if ($version !== null && !in_array($version, $MCP_PROTOCOL_VERSIONS))
{
	mcp_http_error(400, -32600,
		'Unsupported MCP-Protocol-Version: ' . $version
		. '. Supported: ' . join(', ', $MCP_PROTOCOL_VERSIONS));
}

$input = file_get_contents('php://input');

if (strlen($input) > $MCP_MAX_BODY)
{
	mcp_http_error(413, -32600, 'Request body too large');
}

$request = json_decode($input, true);

if ($request === null)
{
	mcp_http_error(400, -32700, 'Parse error: Invalid JSON');
}

$response = handleMcpRequest($request);

// handleMcpRequest returns null for a notification - something with no id, which
// gets no reply. The spec is specific that this is 202 with an empty body.
if ($response === null)
{
	http_response_code(202);
	exit;
}

header('Content-Type: application/json');
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

?>
