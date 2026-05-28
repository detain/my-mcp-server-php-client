# Client MCP Proxy

[![CI](https://github.com/detain/my-mcp-server-php-client/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/my-mcp-server-php-client/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/my-mcp-server-php-client/branch/master/graph/badge.svg?token=5eb4570d-c9b2-414e-a96f-e73f0bff397b)](https://codecov.io/gh/detain/my-mcp-server-php-client)
[![Codacy Grade](https://app.codacy.com/project/badge/Grade/36bc9feadcc342e78413ae8395041e44)](https://app.codacy.com/gh/detain/my-mcp-server-php-client/dashboard)
[![Codacy Coverage](https://app.codacy.com/project/badge/Coverage/36bc9feadcc342e78413ae8395041e44)](https://app.codacy.com/gh/detain/my-mcp-server-php-client/coverage)
[![PHP Version](https://img.shields.io/badge/php-%E2%89%A58.2-8892BF.svg?logo=php)](https://php.net/)
[![Tests](https://img.shields.io/badge/tests-90%20passing-success.svg?logo=phpunit)](#running-tests)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%206-1ABC9C.svg)](https://phpstan.org)
[![License](https://img.shields.io/badge/license-proprietary-red.svg)](#license)

A standalone MCP (Model Context Protocol) proxy server that proxies requests to a
MyAdmin API. This server fetches its tool definitions from a remote OpenAPI spec
and handles MCP protocol communication via either STDIO (for local clients like
Claude Desktop / Cursor) or Streamable HTTP transport (for remote clients).

## Features

- **Dual transport** — `bin/mcp` is a single launcher that runs STDIO (default) or
  spawns an HTTP server via `--http`.
- **Streamable HTTP transport** — Standard MCP 2025 protocol support.
- **Dynamic tool loading** — Fetches tool definitions from a remote OpenAPI spec
  (JSON or YAML auto-detected).
- **Conditional cache refresh** — Cache invalidated via `Last-Modified` HEAD check,
  with stale-cache fallback if the remote fetch fails.
- **File-based session persistence** — Sessions stored on disk with TTL expiry
  (via the upstream MCP SDK).
- **OAuth 2.1 metadata** — Both protected-resource (RFC 9700) and
  authorization-server (RFC 8414) `.well-known` endpoints.
- **Auth header forwarding** — Passes `Authorization` (Bearer), `X-API-KEY`, and
  `sessionid` through to the upstream API.
- **TLS controls** — Configurable CA bundle and peer/host verification (CLI flags
  or env vars).
- **CORS** — Preflight + response headers for browser-based MCP clients.

## Requirements

- PHP 8.2+
- Composer

## Installation

```bash
# Clone/copy the project
cp -r client-mcp-proxy /path/to/client-mcp-proxy
cd /path/to/client-mcp-proxy

# Install dependencies
composer install
```

## Configuration

Copy `.env.example` to `.env` and edit:

```bash
cp .env.example .env
```

### Environment Variables

All variables have working defaults — set them only to override.

| Variable | Default | Description |
|---|---|---|
| `OPENAPI_SPEC_URL` | `https://my.interserver.net/openapi.json` | URL to fetch OpenAPI spec from (JSON or YAML) |
| `API_BASE_URL` | `https://my.interserver.net/apiv2` | Base URL of the upstream API |
| `SESSION_DIR` | `/tmp/mcp_client_sessions` | Directory for session storage |
| `CACHE_DIR` | `/tmp/mcp_client_cache` | Directory for cached tool definitions |
| `SERVER_NAME` | `MCP Proxy` | Name advertised in MCP handshake |
| `SERVER_VERSION` | `1.0.0` | Version advertised in MCP handshake |
| `API_KEY` | — | API key (STDIO mode auth) |
| `SESSION_ID` | — | Session ID (STDIO mode auth) |
| `BEARER_TOKEN` | — | Bearer token (STDIO mode auth) |
| `CA_CERT_FILE` | — | Path to a CA bundle (PEM). Applied via `ini_set` to `curl.cainfo` and `openssl.cafile`, and passed to Guzzle (sets `CURLOPT_CAINFO`). Leave empty to use the system trust store. |
| `SSL_VERIFY` | `true` | Verify TLS peer + host on outbound calls. Maps to `CURLOPT_SSL_VERIFYPEER` + `CURLOPT_SSL_VERIFYHOST`. |

## Running the Server

Three ways to bring up the proxy:

### 1. STDIO mode via `bin/mcp` (Claude Desktop / Cursor / local CLI)

```bash
chmod +x bin/mcp
bin/mcp                  # STDIO is the default
bin/mcp --stdio          # explicit
```

### 2. HTTP mode via `bin/mcp` (spawns PHP built-in server)

```bash
bin/mcp --http                          # binds 127.0.0.1:8080
bin/mcp --http --host=0.0.0.0 --port=9000
```

`--http` runs `php -S {host}:{port} -t public/` in the background, redirects
its output to `mcp-server-out.log` / `mcp-server-err.log` in the project root,
and exits. The spawned server keeps running until killed.

### 3. Your own web server (production)

Configure Apache, Nginx, or LiteSpeed to serve the `public/` directory.

Example Apache vhost:
```apache
<VirtualHost *:443>
    ServerName mcp-proxy.example.com
    DocumentRoot /path/to/client-mcp-proxy/public

    <Directory /path/to/client-mcp-proxy/public>
        AllowOverride None
        Require all granted
    </Directory>

    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ /index.php [QSA,L]

    CGIPassAuth On
    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
</VirtualHost>
```

Example Nginx config:
```nginx
server {
    listen 443 ssl;
    server_name mcp-proxy.example.com;
    root /path/to/client-mcp-proxy/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_pass_header Authorization;
    }
}
```

## `bin/mcp` CLI Reference

```
Usage: bin/mcp [options]

Options:
  -h, --help              Show help and exit
  -v, -vv, -vvv           Verbose output to STDERR (1=info, 2=debug, 3=trace)

  --stdio                 Run STDIO transport (default)
  --http                  Spawn `php -S` against public/ in the background and exit
  --host=HOST             HTTP bind host                (default: 127.0.0.1)
  --port=PORT             HTTP bind port                (default: 8080)

  --openapi-spec=URL      Override OPENAPI_SPEC_URL
  --base-url=URL          Override API_BASE_URL
  --api-key=KEY           Override API_KEY (STDIO auth)
  --verify=true|false     Override SSL_VERIFY
  --ca-cert=PATH          Override CA_CERT_FILE
```

**Precedence:** CLI flags > environment variables > built-in defaults.

`--stdio` and `--http` are mutually exclusive. `--help` and `--http` both work
in a fresh checkout without `composer install`.

### Examples

```bash
# STDIO with verbose info logging
bin/mcp -v

# STDIO with a one-off API key override
bin/mcp --api-key=sk_live_xxx

# HTTP on all interfaces, port 9090
bin/mcp --http --host=0.0.0.0 --port=9090

# STDIO pointing at a different spec, with a self-signed CA bundle
bin/mcp --openapi-spec=https://staging.example/openapi.json --ca-cert=/etc/ssl/staging-ca.pem

# Disable TLS verification (testing only)
bin/mcp --http --verify=false
```

## API Endpoints

### MCP Protocol Endpoint

```
POST   /          Send MCP JSON-RPC messages
GET    /          SSE streaming endpoint (server-initiated responses)
DELETE /          Close MCP session
OPTIONS /         CORS preflight
```

### OAuth Metadata Endpoints

```
GET /.well-known/oauth-protected-resource     RFC 9700 protected resource metadata
GET /.well-known/oauth-authorization-server   RFC 8414 authorization server metadata
```

The proxy advertises `/oauth/bshaffer` on the same host as the authorization
server. Supported scopes: `admin`, `admin_login`, `read`, `write`.

## Authentication

The proxy forwards the first valid auth credential it finds to the upstream API:

- **Bearer Token**: `Authorization: Bearer <token>`
- **API Key**: `X-API-KEY: <key>`
- **Session ID**: `sessionid: <session_id>`

For STDIO mode you can also pass `API_KEY` / `SESSION_ID` / `BEARER_TOKEN` via env
or `--api-key=` on the CLI; they're used as a fallback when no incoming auth header
is present.

## Claude Desktop / Cursor Integration

Add to `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS),
`%APPDATA%\Claude\claude_desktop_config.json` (Windows), or
`~/.config/Claude/claude_desktop_config.json` (Linux):

```json
{
  "mcpServers": {
    "myadmin-client": {
      "command": "php",
      "args": ["/path/to/client-mcp-proxy/bin/mcp"],
      "env": {
        "OPENAPI_SPEC_URL": "https://my.interserver.net/openapi.json",
        "API_BASE_URL": "https://my.interserver.net/apiv2",
        "API_KEY": "your_api_key"
      }
    }
  }
}
```

Same JSON works for Cursor (Settings → MCP Servers).

You can also pass overrides via CLI args instead of env, e.g.:

```json
"args": ["/path/to/client-mcp-proxy/bin/mcp", "--api-key=sk_live_xxx", "-v"]
```

## MCPB Bundle (one-click install)

Instead of editing `claude_desktop_config.json` by hand, you can build a
[MCP Bundle (`.mcpb`)](https://github.com/anthropics/mcpb) — a single-file
installer that Claude Desktop (and other MCPB hosts) can install with one click.

```bash
mcpb/scripts/build-bundle.sh
# → mcpb/dist/client-mcp-proxy-<version>.mcpb
```

The bundle wraps `bin/mcp` as a `binary`-type MCPB server with all Composer
production dependencies pre-installed. End users only need PHP 8.2+ on their
`PATH`; everything else ships inside the `.mcpb`.

See [`mcpb/README.md`](mcpb/README.md) for build requirements, the full list
of user-config fields, and packaging details.

## Tool Caching

Tool definitions from the OpenAPI spec are cached in `CACHE_DIR` and invalidated
when the remote spec's `Last-Modified` header advances. If the remote fetch fails,
the proxy serves from stale cache rather than crashing.

To force a refresh, delete the cache files:

```bash
rm -f /tmp/mcp_client_cache/mcp_tools_*.php
```

Or programmatically:

```php
use ClientMcp\OpenApiParser;

$parser = new OpenApiParser('/tmp/mcp_client_cache');
$parser->clearCache('https://my.interserver.net/openapi.json');
```

## Troubleshooting

### "Failed to fetch OpenAPI spec"

- Verify `OPENAPI_SPEC_URL` is reachable from the server.
- If you're behind a private CA, set `CA_CERT_FILE=/path/to/ca.pem`.
- For self-signed dev backends, set `SSL_VERIFY=false` (or `--verify=false`).

### "Missing required configuration"

- Defaults are provided for `OPENAPI_SPEC_URL` and `API_BASE_URL`. This error only
  fires if you explicitly set them to an empty value in `.env` or the environment.

### `bin/mcp --http` says "exited immediately"

- Port already in use, permission denied (binding < 1024 without root), or PHP
  failed to start. Check `mcp-server-err.log` in the project root.

### Session issues

- Ensure `SESSION_DIR` is writable.
- Sessions expire after 1 hour by default.

## Running Tests

Run the full CI pipeline locally:

```bash
composer install
composer ci          # lint + phpstan + tests
```

Or individual steps:

```bash
composer test                   # PHPUnit
composer test-coverage          # HTML coverage report at coverage/index.html
composer test-coverage-clover   # coverage.xml (for Codecov / Codacy / etc.)
composer phpstan                # PHPStan at level 6
composer lint                   # php -l on src/, tests/, bin/mcp, public/index.php
```

The test suite (in `tests/`) covers `OpenApiParser` and `McpServerFactory` at
100% line / method / class coverage, plus process-based smoke tests for
`bin/mcp` (`--help`, `--http`, conflict detection). PHPUnit coverage is
scoped to `src/` via `phpunit.xml.dist`; entry-point scripts are exercised
by `tests/Integration/`.

GitHub Actions runs the same `composer ci` pipeline on every push and PR
across PHP 8.2 / 8.3 / 8.4, and uploads Clover coverage to Codecov.

## License

Proprietary - InterServer Inc.
