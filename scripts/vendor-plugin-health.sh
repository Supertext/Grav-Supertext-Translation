#!/bin/sh
# Copies the PHP part of Supertext Plugin Health (version reports to the Supertext Commando
# Center, the API key check result and the ping answer) from the Commando Center repository into
# lib/supertext-plugin-health, with its namespace changed from Supertext\PluginHealth to
# Supertext\GravTranslation\PluginHealth (sub-namespaces such as Supertext\PluginHealth\Http too).
# The plugin ships this copy, like the SDK copy in lib/supertext-sdk, so sites install nothing
# besides the plugin.
#
#   scripts/vendor-plugin-health.sh [commit-or-branch]   (default: main)
#   PLUGIN_HEALTH_DIR=/path/to/clone scripts/vendor-plugin-health.sh   (use a local clone as it is)
set -eu
REF="${1:-main}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -n "${PLUGIN_HEALTH_DIR:-}" ]; then
    SRC="$PLUGIN_HEALTH_DIR"
else
    git clone --quiet https://github.com/Supertext/Commando-Center-Supertext-Translation "$TMP/center"
    git -C "$TMP/center" checkout --quiet "$REF"
    SRC="$TMP/center"
fi
COMMIT="$(git -C "$SRC" rev-parse HEAD)"

DEST="$ROOT/lib/supertext-plugin-health"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$SRC/plugin-health/php/src" "$DEST/src"
cp "$SRC/plugin-health/php/LICENSE" "$DEST/LICENSE"
find "$DEST/src" -name '*.php' -exec sed -i 's/Supertext\\PluginHealth\\/Supertext\\GravTranslation\\PluginHealth\\/g; s/Supertext\\PluginHealth;/Supertext\\GravTranslation\\PluginHealth;/g' {} +
printf '%s\n' "$COMMIT" > "$DEST/COMMIT"
VERSION="$(sed -n "s/.*PLUGIN_HEALTH_VERSION = '\\([^']*\\)'.*/\\1/p" "$DEST/src/PluginHealth.php")"
printf '%s\n' "$VERSION" > "$DEST/VERSION"
cat > "$DEST/README.md" <<EOF
# Supertext Plugin Health (bundled copy)

PHP part of [Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health),
version $VERSION, commit \`$COMMIT\`, namespace \`Supertext\\GravTranslation\\PluginHealth\` instead of \`Supertext\\PluginHealth\`.

Don't edit these files: fix it in Commando-Center-Supertext-Translation/plugin-health, then run \`scripts/vendor-plugin-health.sh\`.
EOF
if grep -rq 'Supertext\\PluginHealth' "$DEST/src"; then
    echo "vendor-plugin-health: unprefixed Supertext\\PluginHealth reference left in $DEST/src" >&2
    exit 1
fi
echo "lib/supertext-plugin-health: Supertext Plugin Health (PHP) $VERSION from commit $COMMIT"
