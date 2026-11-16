#!/usr/bin/env sh
# C41: Lingua Bridge on a multisite, in a stack of its own (project lb-ms, port LB_MS_PORT, of serie 8897), never the
# stack of dev/test.sh. MODE=subdir (of serie) or subdomain. Three sites: the main one, «uno» with Lingua Bridge
# active (Italian and English), «due» without it. Then tests/wp/multisite.test.mjs; with KEEP=1 the stack stays up.
set -eu
cd "$(dirname "$0")"
MODE="${MODE:-subdir}"
. ./.env
export LB_PORT="${LB_MS_PORT:-8897}" COMPOSE_FILE=compose.yml
dc() { docker compose -p "lb-ms-$MODE" "$@"; }
wp() { dc run --rm -T cli wp "$@"; }
# Always removed at the end, also after an error (a half-made network would be found again next time).
trap '[ -n "${KEEP:-}" ] || dc down -v > /dev/null 2>&1' EXIT
dc up -d --wait db wordpress > /dev/null
until dc exec -T wordpress test -f /var/www/html/wp-config.php; do sleep 1; done
dc exec -T -u root wordpress sh -c 'chown www-data:www-data /var/www/html/wp-content/mu-plugins'
if ! wp core is-installed 2>/dev/null; then
  extra=""
  domain=localhost
  # WordPress refuses subdomains of localhost: a name of its own, never resolved (the tests send the Host).
  [ "$MODE" = subdomain ] && extra="--subdomains" && domain=lb-ms.test
  # A network has no port in its domain: the sites are http://localhost/… (or uno.lb-ms.test), the tests reach them
  # on the published port with that Host.
  wp core multisite-install --url="http://$domain" --title="LB multisito" --admin_user=admin \
    --admin_password="$LB_ADMIN_PASSWORD" --admin_email=admin@example.test --skip-email $extra --quiet
  wp rewrite structure '/%postname%/' --quiet
  wp site create --slug=uno --title=Uno --quiet
  wp site create --slug=due --title=Due --quiet
fi
uno="$(wp site list --field=url | sed -n 2p)"
due="$(wp site list --field=url | sed -n 3p)"
wp plugin activate lingua-bridge --url="$uno" --quiet
wp eval --url="$uno" 'update_option( LinguaBridge\Languages::OPTION, array_merge( LinguaBridge\Languages::defaults(), array( "default_language" => "it", "prefix_language_urls" => true, "languages" => array( "it" => "Italiano", "en" => "English" ) ) ) ); flush_rewrite_rules( false );'
wp rewrite structure '/%postname%/' --url="$uno" --quiet
wp rewrite structure '/%postname%/' --url="$due" --quiet
ids="$(wp eval --user=admin --url="$uno" '$it = wp_insert_post( array( "post_type" => "page", "post_status" => "publish", "post_title" => "LB MS pagina", "post_name" => "lb-ms-pagina", "post_content" => "<!-- wp:paragraph --><p>LB-MS-IT</p><!-- /wp:paragraph -->" ) ); $en = LinguaBridge\Translations::create_draft( $it, "en" ); wp_update_post( array( "ID" => $en, "post_status" => "publish", "post_name" => "lb-ms-page", "post_content" => "<!-- wp:paragraph --><p>LB-MS-EN</p><!-- /wp:paragraph -->" ) ); echo wp_json_encode( array( wp_parse_url( get_permalink( $it ), PHP_URL_PATH ), wp_parse_url( get_permalink( $en ), PHP_URL_PATH ) ) );')"
wp eval --url="$due" 'wp_insert_post( array( "post_type" => "page", "post_status" => "publish", "post_title" => "LB MS due", "post_name" => "en", "post_content" => "<!-- wp:paragraph --><p>LB-MS-DUE-EN</p><!-- /wp:paragraph -->" ) );' > /dev/null
status=0
LB_MS_UNO="$uno" LB_MS_DUE="$due" LB_MS_IDS="$ids" LB_MS_MODE="$MODE" LB_MS_PROJECT="lb-ms-$MODE" LB_DEV="$(pwd)" node --test ../tests/wp/multisite.test.mjs || status=$?
exit $status
