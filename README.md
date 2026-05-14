# Client MCP Proxy

A standalone MCP (Model Context Protocol) proxy server that proxies requests to a MyAdmin API.
This server fetches its tool definitions from a remote OpenAPI spec and handles MCP protocol
communication via Streamable HTTP transport.

## Features

- **Streamable HTTP transport** - Standard MCP 2025 protocol support
- **Dynamic tool loading** - Fetches tool definitions from remote OpenAPI spec
- **File-based session persistence** - Sessions stored on filesystem
- **OAuth 2.1 protected resource metadata** - RFC 9700 compliant endpoint
- **Auth header forwarding** - Supports X-API-KEY, sessionid, and Bearer tokens
- **Tool caching** - Caches parsed OpenAPI tools for performance

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

Copy `.env.example` to `.env` and configure:

```bash
cp .env.example .env
```

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `OPENAPI_SPEC_URL` | Yes | - | URL to fetch OpenAPI spec from (JSON or YAML) |
| `API_BASE_URL` | Yes | - | Base URL of the API to proxy to |
| `SESSION_DIR` | No | `/tmp/mcp_client_sessions` | Directory for session storage |
| `CACHE_DIR` | No | `/tmp/mcp_client_cache` | Directory for cached tool definitions |
| `SERVER_NAME` | No | `MCP Proxy` | Name advertised in MCP handshake |
| `SERVER_VERSION` | No | `1.0.0` | Version advertised in MCP handshake |
| `OAUTH_AUTHORIZATION_SERVER` | No | Derived from `API_BASE_URL` | OAuth authorization server URL |

## Running the Server

### Development (PHP built-in server)

```bash
cd public
php -S localhost:8080
```

### Production

Configure your web server (Apache/Nginx) to point to the `public/` directory,
or use PHP-FPM with a proper web server configuration.

Example Apache vhost:
```apache
<VirtualHost *:443>
    ServerName mcp-proxy.example.com
    DocumentRoot /path/to/client-mcp-proxy/public

    <Directory /path/to/client-mcp-proxy/public>
        AllowOverride None
        Require all granted
    </Directory>

    # Rewrite rules for clean URLs
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ /index.php [QSA,L]

    # Ensure proper headers reach PHP
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
        # Forward authorization headers
        fastcgi_pass_header Authorization;
    }
}
```

## API Endpoints

### MCP Protocol Endpoint

```
POST /           - Send MCP JSON-RPC messages
GET /            - OAuth protected resource metadata
DELETE /         - Close MCP session
OPTIONS /        - CORS preflight
```

### OAuth Protected Resource Metadata

```
GET /.well-known/oauth-protected-resource
```

Returns RFC 9700 compliant protected resource metadata.

## Authentication

The proxy forwards authentication credentials from incoming requests to the backend API:

- **Bearer Token**: `Authorization: Bearer <token>`
- **API Key**: `X-API-KEY: <key>`
- **Session ID**: `sessionid: <session_id>`

These are passed through to the backend API via the corresponding headers.

## STDIO Transport (Claude Desktop / Cursor)

The proxy supports stdio transport for local AI tool integration, suitable for use with Claude Desktop, Cursor, and other MCP clients that communicate over stdio.

### Usage

```bash
# Install dependencies
composer install

# Make the CLI executable
chmod +x bin/mcp

# Run with environment variables
OPENAPI_SPEC_URL=https://my.interserver.net/spec/openapi.yaml \
API_BASE_URL=https://my.interserver.net/apiv2 \
API_KEY=your_api_key \
bin/mcp
```

### Environment Variables for STDIO Mode

| Variable | Required | Description |
|----------|----------|-------------|
| `OPENAPI_SPEC_URL` | Yes | URL to fetch OpenAPI spec from |
| `API_BASE_URL` | Yes | Base URL of the API to proxy to |
| `API_KEY` | No | API key for authentication |
| `SESSION_ID` | No | Session ID for authentication |
| `BEARER_TOKEN` | No | Bearer token for authentication |
| `SESSION_DIR` | No | Directory for session storage |
| `CACHE_DIR` | No | Directory for cached tool definitions |
| `SERVER_NAME` | No | MCP server name |
| `SERVER_VERSION` | No | MCP server version |

### Claude Desktop Configuration

Add to your Claude Desktop configuration file:

**macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`
**Windows:** `%APPDATA%\Claude\claude_desktop_config.json`
**Linux:** `~/.config/Claude/claude_desktop_config.json`

```json
{
  "mcpServers": {
    "myadmin-client": {
      "command": "php",
      "args": ["/path/to/client-mcp-proxy/bin/mcp"],
      "env": {
        "OPENAPI_SPEC_URL": "https://my.interserver.net/spec/openapi.yaml",
        "API_BASE_URL": "https://my.interserver.net/apiv2",
        "API_KEY": "your_api_key"
      }
    }
  }
}
```

### Cursor Configuration

Add to Cursor settings (Settings → MCP Servers):

```json
{
  "mcpServers": {
    "myadmin-client": {
      "command": "php",
      "args": ["/path/to/client-mcp-proxy/bin/mcp"],
      "env": {
        "OPENAPI_SPEC_URL": "https://my.interserver.net/spec/openapi.yaml",
        "API_BASE_URL": "https://my.interserver.net/apiv2",
        "API_KEY": "your_api_key"
      }
    }
  }
}
```

### Notes

- Errors are logged to STDERR (per MCP stdio specification)
- The server exits cleanly on EOF (end-of-file) from STDIN
- Sessions are stored in `SESSION_DIR` for state management

## Tool Caching

Tool definitions from the OpenAPI spec are cached in `CACHE_DIR`. The cache is invalidated
when the remote spec's `Last-Modified` header is newer than the cache file. To force a cache
refresh, delete the cache files:

```bash
rm -f /tmp/mcp_client_cache/mcp_tools_*.php
```

## Troubleshooting

### "Failed to fetch OpenAPI spec"

- Verify `OPENAPI_SPEC_URL` is accessible from the server
- Check network connectivity and firewall rules
- Ensure the spec URL returns valid JSON or YAML

### "Missing required environment variables"

- Copy `.env.example` to `.env`
- Set both `OPENAPI_SPEC_URL` and `API_BASE_URL`

### Session issues

- Ensure `SESSION_DIR` is writable
- Check disk space if sessions fail to create
- Sessions expire after 1 hour by default

## License

Proprietary - InterServer Inc.
