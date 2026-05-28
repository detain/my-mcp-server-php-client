# client-mcp-proxy — MCPB bundle

This directory packages [`client-mcp-proxy`](..) as an
[MCP Bundle (`.mcpb`)](https://github.com/anthropics/mcpb) — a single-file,
one-click installable MCP server for Claude Desktop and other MCPB-compatible
hosts.

The bundle wraps the existing PHP server (`bin/mcp`) using the MCPB
`binary` server type. Tools are loaded **dynamically** from the configured
OpenAPI spec at startup (`tools_generated: true`), so no static tool list is
shipped in the manifest.

## Layout

```
mcpb/
├── manifest.json              # Bundle manifest (MCPB 0.3)
├── README.md                  # This file
├── scripts/
│   └── build-bundle.sh        # Build script (Bash; works on macOS/Linux/Git Bash/WSL)
├── build/                     # Created by build-bundle.sh; gitignored
└── dist/                      # Built .mcpb output; gitignored
```

After running the build script you get:

```
mcpb/dist/client-mcp-proxy-<version>.mcpb
```

## Build

Requirements on the **build machine**:

| Tool      | Version  | Why |
|-----------|----------|-----|
| PHP       | >= 8.2   | Sanity-checked by the build script |
| Composer  | any 2.x  | Installs production dependencies into `server/vendor/` |
| Node.js   | >= 16    | Provides `npx`, used to fetch `@anthropic-ai/mcpb` |
| Bash      | any      | Build script. On Windows use Git Bash or WSL. |

Build:

```bash
mcpb/scripts/build-bundle.sh
```

The script:

1. Cleans `mcpb/build/`.
2. Copies `manifest.json` (and `icon.png` / `README.md` if present) to the
   build root.
3. Copies `bin/`, `src/`, `public/`, `composer.json`, `composer.lock`
   into `build/server/`.
4. Runs `composer install --no-dev --optimize-autoloader --classmap-authoritative`
   inside `build/server/` so the bundle ships with its production dependencies
   pre-installed.
5. Strips `tests/`, `docs/`, `examples/`, `*.md`, and `phpunit.xml` from
   `vendor/` to keep the bundle small.
6. Runs `mcpb pack build/ dist/<name>-<version>.mcpb` (or, if `mcpb` is not
   on `PATH`, `npx --yes @anthropic-ai/mcpb pack …`).

## Install

Requirements on the **end-user machine**:

* PHP **8.2 or newer** on `PATH`. The bundle invokes `php`/`php.exe` directly.
* Composer is **not** required — production dependencies are shipped inside
  the `.mcpb` file.

To install:

1. Double-click `client-mcp-proxy-<version>.mcpb`, or open it from Claude
   Desktop's bundle installer.
2. Fill in the user-config fields (see below). At minimum, provide **one**
   of `API Key`, `Bearer Token`, or `Session ID` for the proxy to be able to
   authenticate against the upstream API.
3. The bundle runs `php server/bin/mcp --stdio` and exposes whatever tools
   the upstream OpenAPI spec defines.

### User-config fields

| Field                 | Type       | Default                                            | Notes |
|-----------------------|------------|----------------------------------------------------|-------|
| `API Key`             | string ⚷   | —                                                  | `X-API-KEY` header. |
| `Bearer Token`        | string ⚷   | —                                                  | `Authorization: Bearer …`. |
| `Session ID`          | string ⚷   | —                                                  | `sessionid` header. |
| `OpenAPI Spec URL`    | string     | `https://my.interserver.net/openapi.json`          | JSON or YAML auto-detected. |
| `Upstream API Base URL` | string   | `https://my.interserver.net/apiv2`                 | Where tool calls are forwarded. |
| `Session Directory`   | directory  | `${HOME}/.mcp/client-mcp-proxy/sessions`           | Server session storage. |
| `Cache Directory`     | directory  | `${HOME}/.mcp/client-mcp-proxy/cache`              | Tool-definition cache. |
| `CA Bundle (PEM)`     | file       | —                                                  | Optional CA bundle for outbound TLS. |
| `Verify TLS`          | boolean    | `true`                                             | Disable only for self-signed test endpoints. |

⚷ = stored securely and passed via environment variable.

## Testing the bundle locally without packing

You can run the same command MCPB will run, with the same env vars, to verify
the server works before packing:

```bash
API_KEY=test-key \
OPENAPI_SPEC_URL=https://my.interserver.net/openapi.json \
API_BASE_URL=https://my.interserver.net/apiv2 \
SSL_VERIFY=true \
php bin/mcp --stdio
```

Then send a JSON-RPC `initialize` request on stdin to confirm the handshake.

## Validating the manifest

Once `@anthropic-ai/mcpb` is installed:

```bash
npx --yes @anthropic-ai/mcpb validate mcpb/manifest.json
```

(The `mcpb` CLI validates against the published JSON Schema and reports any
field issues.)

## Adding an icon

Drop a 256×256 (or larger) `icon.png` into `mcpb/` and rebuild — the build
script picks it up automatically. The manifest does not currently declare
an `icon` field; add one if you ship a real icon:

```json
"icon": "icon.png"
```

## What gets shipped

The packed bundle contains:

```
manifest.json
README.md                         (this file, copied verbatim)
server/
├── bin/mcp                       (entry_point)
├── src/                          (ClientMcp\\* classes)
├── public/                       (HTTP entry — unused in stdio mode but harmless)
├── composer.json
├── composer.lock
├── .env.example
└── vendor/                       (composer install --no-dev output)
```

It does **not** ship: `tests/`, `tools/`, `phpstan*`, `phpunit*`,
`.github/`, `.git/`, `coverage/`, or any dev-only Composer packages.
