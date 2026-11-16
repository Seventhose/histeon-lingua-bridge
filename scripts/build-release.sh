#!/usr/bin/env bash
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PLUGIN_DIR="$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)"
RELEASE_DIR="$PLUGIN_DIR/dist"
PLUGIN_FILE="$PLUGIN_DIR/lingua-bridge.php"
CONNECTOR_DIR="$PLUGIN_DIR/connector"

plugin_version="$(sed -n 's/^ \* Version: //p' "$PLUGIN_FILE" | head -n 1)"
stable_tag="$(sed -n 's/^Stable tag: //p' "$PLUGIN_DIR/readme.txt" | head -n 1)"

constant_version="$(sed -n "s/^define( 'LINGUA_BRIDGE_VERSION', '\(.*\)' );/\1/p" "$PLUGIN_FILE")"
if [ -z "$plugin_version" ] || [ "$plugin_version" != "$constant_version" ] || [ "$plugin_version" != "$stable_tag" ]; then
	printf 'Release versions do not match: header=%s constant=%s stable-tag=%s\n' "$plugin_version" "$constant_version" "$stable_tag" >&2
	exit 1
fi

php "$PLUGIN_DIR/tests/php/run.php"
find "$PLUGIN_DIR"/includes "$PLUGIN_DIR" -maxdepth 1 -name '*.php' -type f -exec php -l {} \;
node --check "$CONNECTOR_DIR/lingua-bridge-connector.mjs"
node --check "$PLUGIN_DIR/assets/connector.js"
node --check "$PLUGIN_DIR/assets/importer.js"
node --check "$PLUGIN_DIR/assets/language-switcher-block.js"
node --test "$PLUGIN_DIR"/tests/node/*.test.mjs

mkdir -p "$RELEASE_DIR"
plugin_zip="$RELEASE_DIR/lingua-bridge-$plugin_version.zip"
rm -f "$plugin_zip"

stage_dir="$(mktemp -d)"
trap 'rm -rf "$stage_dir"' EXIT
mkdir -p "$stage_dir/lingua-bridge"
# What a site installs, and nothing else: an allowlist, so the repository's other folders (release/, dev/,
# backup-etch/, codex-plugin/, tests, node_modules) can never end up in the ZIP.
for item in lingua-bridge.php readme.txt LICENSE.txt CONNECTOR.md includes assets etch connector languages; do
	cp -a "$PLUGIN_DIR/$item" "$stage_dir/lingua-bridge/"
done
# The translation maps are sources (docs: scripts/i18n.py): only the compiled files ship.
rm -rf "$stage_dir/lingua-bridge/languages/en"
# The connector is one file with no dependencies: nothing else from its folder ships.
find "$stage_dir/lingua-bridge/connector" -mindepth 1 ! -name lingua-bridge-connector.mjs ! -name README.md ! -name LICENSE.txt -exec rm -rf {} +
find "$stage_dir/lingua-bridge" -exec touch -h -t 202610040000 {} +
(cd "$stage_dir" && zip -X -qr "$plugin_zip" lingua-bridge)
unzip -t "$plugin_zip"
cp "$CONNECTOR_DIR/lingua-bridge-connector.mjs" "$RELEASE_DIR/lingua-bridge-connector-$plugin_version.mjs"
# Every artifact in dist, by file name only (no local paths in a published file).
(cd "$RELEASE_DIR" && sha256sum -- *.zip *.tgz *.mjs > SHA256SUMS && cat SHA256SUMS)
