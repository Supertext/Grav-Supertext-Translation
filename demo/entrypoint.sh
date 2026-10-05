#!/bin/sh
# Runs on every start. Persistent data lives on the volume at /data (Railway volume);
# Grav core, plugins and themes always come from the image.
set -e

GRAV=${GRAV_ROOT:-/var/www/html}
DATA=${DATA_DIR:-/data}
DEMO=${DEMO_SRC:-/usr/src/demo}
SEED_USER=${SEED_USER:-/usr/src/grav-user}
mkdir -p "$DATA/user"

# Pages, accounts, config and data are kept on the volume. First start: seed them.
for dir in pages accounts config data; do
  if [ ! -d "$DATA/user/$dir" ]; then
    if [ "$dir" = pages ]; then
      cp -a "$DEMO/user/pages" "$DATA/user/pages"
      echo "[demo] seeded the sample pages"
    else
      cp -a "$SEED_USER/$dir" "$DATA/user/$dir" 2>/dev/null || mkdir -p "$DATA/user/$dir"
    fi
  fi
  rm -rf "$GRAV/user/$dir"
  ln -s "$DATA/user/$dir" "$GRAV/user/$dir"
done

# Languages, proxy headers, plugin settings (kept when changed in the admin).
php "$DEMO/configure.php" "$GRAV" "$DEMO"

# Demo accounts from DEMO_ADMIN_* / DEMO_EDITOR_* (created once, never changed).
php "$DEMO/seed-accounts.php" "$GRAV"

chown -R www-data:www-data "$DATA" 2>/dev/null || true
chown -R www-data:www-data "$GRAV/cache" "$GRAV/logs" "$GRAV/tmp" "$GRAV/backup" "$GRAV/images" "$GRAV/assets" 2>/dev/null || true
rm -rf "$GRAV/cache/"* 2>/dev/null || true

# Local test runs stop here (DEMO_NO_SERVER=1).
[ -z "$DEMO_NO_SERVER" ] || exit 0

# mod_php needs exactly one MPM; Railway's build can leave several enabled.
a2dismod -q mpm_event mpm_worker >/dev/null 2>&1 || true
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
a2enmod -q mpm_prefork >/dev/null 2>&1 || true

# Listen on Railway's port.
sed -i "s/^Listen .*/Listen ${PORT:-8080}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT:-8080}>/" /etc/apache2/sites-available/000-default.conf

# Hand SUPERTEXT_API_KEY to PHP under Apache.
[ -n "$SUPERTEXT_API_KEY" ] && echo "PassEnv SUPERTEXT_API_KEY" > /etc/apache2/conf-enabled/supertext-env.conf || rm -f /etc/apache2/conf-enabled/supertext-env.conf

exec apache2-foreground
