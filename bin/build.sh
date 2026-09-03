#!/usr/bin/env bash
#
# Build a clean, deploy-ready zip of Woo Exports for the WordPress
# "Upload Plugin" screen. Excludes dev-only files (the internal primer +
# deploy runbook under docs/, tests, and build tooling) but KEEPS vendor/,
# which is a committed runtime dependency (PhpSpreadsheet) the XLSX writer
# needs.
#
# A manual Finder "Compress" or `zip -r` of the folder does NOT honor
# .distignore — this script enforces the exclusions for that workflow.
#
# Usage:  bash bin/build.sh [output-dir]
#         (default output dir: the plugin's parent, wp-content/plugins/)
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="$(basename "$PLUGIN_DIR")"
PARENT="$(dirname "$PLUGIN_DIR")"
OUT_DIR="${1:-$PARENT}"

MAIN_FILE="$(grep -lE "^[[:space:]]*\* Plugin Name:" "$PLUGIN_DIR"/*.php | head -1)"
VERSION="$(grep -m1 -E "^[[:space:]]*\*[[:space:]]*Version:" "$MAIN_FILE" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" | head -1)"
[ -z "$VERSION" ] && VERSION="unknown"

# The plugin header version and the plugin's VERSION constant are both live:
# the header drives the WordPress plugin list and update checks, the constant
# drives asset cache-busting in wp_enqueue_*. Bumping one but not the other
# ships stale CSS/JS to every site that takes the update, so refuse to build.
# The constant is auto-detected, so this block is identical across plugins;
# a plugin that defines no VERSION constant simply skips the check.
CONST_LINE="$(grep -m1 -oE "define\( *'[A-Z0-9_]*VERSION' *, *'[0-9]+\.[0-9]+\.[0-9]+'" "$MAIN_FILE" || true)"
if [ -n "$CONST_LINE" ]; then
  CONST_NAME="$(printf '%s' "$CONST_LINE" | grep -oE "'[A-Z0-9_]*VERSION'" | tr -d "'")"
  CONST_VERSION="$(printf '%s' "$CONST_LINE" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" | tail -1)"
  if [ "$VERSION" != "$CONST_VERSION" ]; then
    echo "ERROR: version mismatch in $(basename "$MAIN_FILE")." >&2
    echo "       Plugin header: $VERSION" >&2
    echo "       $CONST_NAME: $CONST_VERSION" >&2
    echo "       Bump both before building." >&2
    exit 1
  fi
fi

ZIP="$OUT_DIR/${SLUG}-v${VERSION}.zip"
rm -f "$ZIP"

cd "$PARENT"
zip -r -X "$ZIP" "$SLUG" \
  -x "$SLUG/docs/*" \
  -x "$SLUG/CLAUDE.md" \
  -x "$SLUG/tests/*" \
  -x "$SLUG/bin/*" \
  -x "$SLUG/composer.json" \
  -x "$SLUG/composer.lock" \
  -x "$SLUG/phpunit.xml.dist" \
  -x "$SLUG/.phpunit.cache/*" \
  -x "$SLUG/.phpunit.result.cache" \
  -x "$SLUG/.distignore" \
  -x "$SLUG/.git/*" \
  -x "$SLUG/.gitignore" \
  -x "$SLUG/.github/*" \
  -x "$SLUG/README.md" \
  -x "$SLUG/SECURITY.md" \
  -x "$SLUG/.claude/*" \
  -x "*.DS_Store" >/dev/null

echo "Built clean plugin zip (vendor/ included):"
echo "  $ZIP"
echo
echo "Top level:"
unzip -Z1 "$ZIP" | sed "s|^$SLUG/||" | awk -F/ 'NF<=1 || $2=="" {print} NF>1 {print $1"/"}' | sort -u | sed 's/^/  /'
