<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ClientMcp\McpServerFactory;
use ClientMcp\OpenApiParser;
use GuzzleHttp\Client as GuzzleClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Mcp\Server\Transport\StreamableHttpTransport;

// Load environment variables
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key && !isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Required configuration
$openapiSpecUrl = $_ENV['OPENAPI_SPEC_URL'] ?? '';
$apiBaseUrl = $_ENV['API_BASE_URL'] ?? '';
$sessionDir = $_ENV['SESSION_DIR'] ?? '/tmp/mcp_client_sessions';
$cacheDir = $_ENV['CACHE_DIR'] ?? '/tmp/mcp_client_cache';
$serverName = $_ENV['SERVER_NAME'] ?? 'MCP Proxy';
$serverVersion = $_ENV['SERVER_VERSION'] ?? '1.0.0';

if (empty($openapiSpecUrl) || empty($apiBaseUrl)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Missing required environment variables: OPENAPI_SPEC_URL and API_BASE_URL must be set',
    ]);
    exit(1);
}

// Create required directories
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0775, true);
}
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

// Parse OpenAPI spec and build MCP server
try {
    $parser = new OpenApiParser($cacheDir, new GuzzleClient([
        'timeout' => 30,
        'connect_timeout' => 10,
    ]));
    $toolDefs = $parser->parse($openapiSpecUrl);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Failed to parse OpenAPI spec: ' . $e->getMessage(),
    ]);
    exit(1);
}

// Build the MCP server
try {
    $factory = new McpServerFactory($apiBaseUrl);
    $sessionStore = new \Mcp\Server\Session\FileSessionStore($sessionDir, 3600);
    $server = $factory->build($serverName, $serverVersion, $toolDefs, $sessionStore);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Failed to build MCP server: ' . $e->getMessage(),
    ]);
    exit(1);
}

// Handle OAuth protected resource metadata endpoint
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// OAuth 2.1 Protected Resource Metadata endpoint (RFC 9700)
if ($requestMethod === 'GET' && $requestUri === '/.well-known/oauth-protected-resource') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, max-age=0');

    // Extract authorization server from the spec URL or use a sensible default
    $authServer = $_ENV['OAUTH_AUTHORIZATION_SERVER'] ?? '';
    if (empty($authServer)) {
        // Derive from the API base URL
        $parsed = parse_url($apiBaseUrl);
        $authServer = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . '/oauth';
    }

    echo json_encode([
        'resource' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'mcp-proxy'),
        'authorization_servers' => [$authServer],
        'scopes_supported' => ['read', 'write', 'admin'],
        'bearer_methods_supported' => ['header'],
        'resource_signing_alg_values_supported' => ['RS256', 'ES256'],
        'resource_documentation' => $openapiSpecUrl,
    ], JSON_PRETTY_PRINT);
    exit(0);
}

// Handle CORS preflight
if ($requestMethod === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, Mcp-Session-Id, X-API-KEY, sessionid');
    header('Access-Control-Max-Age: 86400');
    exit(0);
}

// Create PSR-7 request/response
$requestFactory = Psr17FactoryDiscovery::findRequestFactory();
$responseFactory = Psr17FactoryDiscovery::findResponseFactory();
$streamFactory = Psr17FactoryDiscovery::findStreamFactory();

$content = file_get_contents('php://input');
$psrRequest = $requestFactory->createRequest($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/')
    ->withHeader('Accept', $_SERVER['HTTP_ACCEPT'] ?? 'application/json')
    ->withHeader('Content-Type', $_SERVER['CONTENT_TYPE'] ?? 'application/json');

if ($content) {
    $psrRequest = $psrRequest->withBody($streamFactory->createStream($content));
}

// Add headers that may not be in $_SERVER
$headersToForward = [
    'Authorization' => 'Authorization',
    'X-API-KEY' => 'X-API-KEY',
    'sessionid' => 'sessionid',
    'Mcp-Session-Id' => 'Mcp-Session-Id',
    'Mcp-Protocol-Version' => 'Mcp-Protocol-Version',
    'Last-Event-ID' => 'Last-Event-ID',
];

foreach ($headersToForward as $headerName => $serverKey) {
    $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
    if (isset($_SERVER[$headerKey])) {
        $psrRequest = $psrRequest->withHeader($headerName, $_SERVER[$headerKey]);
    }
}

// Create and run the transport
$transport = new StreamableHttpTransport(
    $psrRequest,
    $responseFactory,
    $streamFactory,
    [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Accept, Authorization, Content-Type, Mcp-Session-Id, X-API-KEY, sessionid',
        'Access-Control-Expose-Headers' => 'Mcp-Session-Id',
    ],
);

try {
    $server->run($transport, false);
    $response = $transport->listen();

    // Send the response
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header("$name: $value", false);
        }
    }
    echo $response->getBody();
} catch (\Throwable $e) {
    error_log('MCP server error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
    exit(1);
}
