#!/usr/bin/env sh
# Starts the Lingua Bridge test WordPress and installs it on first run. The admin password stays in dev/.env.
set -eu
cd "$(dirname "$0")"
if [ ! -f .env ]; then
  {
    echo "LB_DB_PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
    echo "LB_ADMIN_PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
    echo "LB_PORT=8895"
  } > .env
  chmod 600 .env
fi
. ./.env
export LB_PORT
docker compose up -d --wait db wordpress
until docker compose exec -T wordpress test -f /var/www/html/wp-config.php; do sleep 1; done
# The always-on router is written to mu-plugins by WordPress itself (www-data): the folder must be writable.
docker compose exec -T -u root wordpress sh -c 'chown www-data:www-data /var/www/html/wp-content/mu-plugins'
wp() { docker compose run --rm -T cli wp "$@"; }
if ! wp core is-installed 2>/dev/null; then
  wp core install --url="http://localhost:${LB_PORT}" --title="Lingua Bridge Dev" --admin_user=admin \
    --admin_password="$LB_ADMIN_PASSWORD" --admin_email=admin@example.test --skip-email
fi
wp rewrite structure '/%postname%/' --quiet
wp plugin activate lingua-bridge --quiet
# The test administrator reads Italian (Lingua Bridge is written in Italian; the browser checks use its labels),
# while the site itself stays en_US for the routing checks. Other languages get English.
wp user meta update admin locale it_IT --quiet
echo "Lingua Bridge dev: http://localhost:${LB_PORT} (utente 'admin', password in dev/.env)"
