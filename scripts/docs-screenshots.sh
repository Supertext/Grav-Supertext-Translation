#!/bin/sh
# Regenerates the screenshots in docs/images/ from the demo, against the stand-in
# Supertext API (scripts/stand-in-api), which returns real translations of the sample pages.
#
#   composer docs:screenshots
#
# Needs PHP 8.3 with curl, Python 3 with Playwright (pip install playwright && playwright install chromium).
# Builds a throwaway Grav site in .cache/screenshots (CACHE_DIR=… to put it elsewhere).
set -e
cd "$(dirname "$0")/.."
REPO=$(pwd)
CACHE=${CACHE_DIR:-$REPO/.cache}
WORK="$CACHE/screenshots"
GRAV_VERSION=${GRAV_VERSION:-2.2.14}
GRAV_PORT=${GRAV_PORT:-8090}
API_PORT=${API_PORT:-8089}

mkdir -p "$CACHE"
if [ ! -f "$CACHE/grav-admin-$GRAV_VERSION.zip" ]; then
  echo "Downloading Grav $GRAV_VERSION…"
  # GitHub release asset, as in demo/Dockerfile (getgrav.org only serves the latest version).
  curl -fsSL -o "$CACHE/grav-admin-$GRAV_VERSION.zip" "https://github.com/getgrav/grav/releases/download/$GRAV_VERSION/grav-admin-v$GRAV_VERSION.zip"
fi
rm -rf "$WORK" && mkdir -p "$WORK"
unzip -q "$CACHE/grav-admin-$GRAV_VERSION.zip" -d "$WORK"
mv "$WORK/grav-admin" "$WORK/grav"
cp -a "$WORK/grav/user" "$WORK/seed-user"

# The plugin, as the demo image installs it.
mkdir -p "$WORK/grav/user/plugins/supertext-translation"
cp -r supertext-translation.php supertext-translation.yaml blueprints.yaml languages.yaml classes admin-next lib \
  "$WORK/grav/user/plugins/supertext-translation/"

# Throwaway accounts for this local site only.
ADMIN_PASSWORD="Shot$(php -r 'echo bin2hex(random_bytes(6));')9"
EDITOR_PASSWORD="Shot$(php -r 'echo bin2hex(random_bytes(6));')8"
export ADMIN_PASSWORD EDITOR_PASSWORD
GRAV_ROOT="$WORK/grav" DEMO_SRC="$REPO/demo" SEED_USER="$WORK/seed-user" DATA_DIR="$WORK/data" DEMO_NO_SERVER=1 \
  DEMO_ADMIN_EMAIL=admin@demo.example DEMO_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
  DEMO_EDITOR_EMAIL=editor@demo.example DEMO_EDITOR_PASSWORD="$EDITOR_PASSWORD" \
  sh demo/entrypoint.sh

php -S "127.0.0.1:$API_PORT" scripts/stand-in-api/router.php > "$WORK/stand-in.log" 2>&1 &
API_PID=$!
# The stand-in also answers the Commando Center's reports, so nothing is reported to Supertext.
(cd "$WORK/grav" && SUPERTEXT_API_KEY="Supertext-Auth-Key stand-in" SUPERTEXT_API_URL="http://127.0.0.1:$API_PORT/v1/" \
  SUPERTEXT_REPORT_URL="http://127.0.0.1:$API_PORT/api/v1/report" \
  php -S "127.0.0.1:$GRAV_PORT" system/router.php > "$WORK/grav.log" 2>&1) &
GRAV_PID=$!
trap 'kill $API_PID $GRAV_PID 2>/dev/null || true' EXIT
sleep 2

BASE="http://127.0.0.1:$GRAV_PORT" OUT="$REPO/docs/images" python3 scripts/screenshots.py

if grep -q "no translation for" "$WORK/stand-in.log"; then
  echo "Some text had no stand-in translation (see $WORK/stand-in.log); add it to scripts/stand-in-api/translations.php." >&2
  exit 1
fi
echo "Screenshots written to docs/images/"
