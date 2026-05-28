#!/usr/bin/env bash
#
# Build the client-mcp-proxy.mcpb bundle.
#
# Produces: mcpb/dist/client-mcp-proxy-<version>.mcpb
#
# Requirements on the build machine:
#   * php >= 8.2
#   * composer
#   * npx (Node.js >= 16) — used to fetch @anthropic-ai/mcpb if `mcpb`
#     is not already on PATH
#   * jq (optional; used for nicer version parsing — falls back to grep)
#
set -euo pipefail

# ----- locate paths --------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MCPB_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PROJECT_ROOT="$(cd "$MCPB_DIR/.." && pwd)"
BUILD_DIR="$MCPB_DIR/build"
DIST_DIR="$MCPB_DIR/dist"

MANIFEST="$MCPB_DIR/manifest.json"

if [ ! -f "$MANIFEST" ]; then
    echo "ERROR: manifest.json not found at $MANIFEST" >&2
    exit 1
fi

# ----- parse version + name from manifest ---------------------------------

if command -v jq >/dev/null 2>&1; then
    NAME="$(jq -r '.name' "$MANIFEST")"
    VERSION="$(jq -r '.version' "$MANIFEST")"
else
    NAME="$(grep -E '^\s*"name"\s*:' "$MANIFEST" | head -1 | sed -E 's/.*"name"\s*:\s*"([^"]+)".*/\1/')"
    VERSION="$(grep -E '^\s*"version"\s*:' "$MANIFEST" | head -1 | sed -E 's/.*"version"\s*:\s*"([^"]+)".*/\1/')"
fi

if [ -z "${NAME:-}" ] || [ -z "${VERSION:-}" ]; then
    echo "ERROR: could not parse name/version from $MANIFEST" >&2
    exit 1
fi

OUT_FILE="$DIST_DIR/${NAME}-${VERSION}.mcpb"

# ----- verify required tools ----------------------------------------------

for tool in php composer; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "ERROR: required tool '$tool' not found on PATH" >&2
        exit 1
    fi
done

PHP_MAJOR_MINOR="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
if ! php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; then
    echo "ERROR: PHP $PHP_MAJOR_MINOR is too old. PHP >= 8.2 is required." >&2
    exit 1
fi

# ----- clean + scaffold ---------------------------------------------------

echo ">> Cleaning $BUILD_DIR"
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/server"
mkdir -p "$DIST_DIR"

# ----- copy bundle root files --------------------------------------------

echo ">> Copying manifest + bundle docs"
cp "$MANIFEST" "$BUILD_DIR/manifest.json"

for asset in icon.png icon.svg README.md; do
    if [ -f "$MCPB_DIR/$asset" ]; then
        cp "$MCPB_DIR/$asset" "$BUILD_DIR/$asset"
    fi
done

# ----- copy PHP project into server/ -------------------------------------

echo ">> Copying PHP project files into server/"
cd "$PROJECT_ROOT"

# Only include files needed at runtime.
cp -R bin    "$BUILD_DIR/server/"
cp -R src    "$BUILD_DIR/server/"
cp -R public "$BUILD_DIR/server/"
cp composer.json "$BUILD_DIR/server/"
[ -f composer.lock ] && cp composer.lock "$BUILD_DIR/server/"
[ -f .env.example ] && cp .env.example "$BUILD_DIR/server/"
[ -f LICENSE     ] && cp LICENSE     "$BUILD_DIR/server/"
[ -f README.md   ] && cp README.md   "$BUILD_DIR/server/PROJECT-README.md"

# ----- install production composer dependencies --------------------------

echo ">> Installing composer production dependencies"
(
    cd "$BUILD_DIR/server"
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --classmap-authoritative
)

# Strip composer/vendor cruft that isn't needed at runtime.
find "$BUILD_DIR/server/vendor" \
    -type d \
    \( -name 'tests' -o -name 'test' -o -name '.github' -o -name 'docs' -o -name 'examples' \) \
    -prune -exec rm -rf {} +

find "$BUILD_DIR/server/vendor" \
    -type f \
    \( -name '*.md' -o -name '*.markdown' -o -name 'phpunit.xml*' -o -name '.travis.yml' -o -name '.editorconfig' \) \
    -delete || true

# ----- ensure bin/mcp is executable (Unix) --------------------------------

chmod +x "$BUILD_DIR/server/bin/mcp" || true

# ----- pack via mcpb ------------------------------------------------------

echo ">> Packing bundle"
PACK_CMD=()
if command -v mcpb >/dev/null 2>&1; then
    PACK_CMD=(mcpb pack "$BUILD_DIR" "$OUT_FILE")
elif command -v npx >/dev/null 2>&1; then
    PACK_CMD=(npx --yes @anthropic-ai/mcpb pack "$BUILD_DIR" "$OUT_FILE")
else
    echo "ERROR: neither 'mcpb' nor 'npx' is available. Install with:" >&2
    echo "       npm install -g @anthropic-ai/mcpb" >&2
    exit 1
fi

"${PACK_CMD[@]}"

# ----- summary -------------------------------------------------------------

echo ""
echo "✓ Bundle built successfully"
echo "  name:    $NAME"
echo "  version: $VERSION"
echo "  output:  $OUT_FILE"
echo "  size:    $(du -h "$OUT_FILE" | cut -f1)"
