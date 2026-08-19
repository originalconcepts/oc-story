#!/usr/bin/env bash
#
# Build the distributable plugin package: build/oc-story.zip
#
# The zip contains a single top-level `oc-story/` folder (what WordPress
# installs), with dev-only paths from .distignore stripped out. The GitHub
# release workflow attaches this zip as the `oc-story.zip` asset that the
# updater looks for.
#
set -euo pipefail

SLUG="oc-story"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/build"
STAGE="$OUT/$SLUG"

rm -rf "$OUT"
mkdir -p "$STAGE"

# Copy the plugin, excluding dev-only paths.
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/." "$STAGE/"

# Zip from the build dir so the archive root is `oc-story/`.
cd "$OUT"
rm -f "$SLUG.zip"
zip -rqX "$SLUG.zip" "$SLUG"

echo "Built $OUT/$SLUG.zip"
unzip -l "$OUT/$SLUG.zip" | tail -1
