#!/usr/bin/env sh
# Lingua Bridge on a real WordPress (dev/compose.yml): seeds bilingual content, then runs the suites.
# With Histeon next to it (HISTOS_PLUGIN, default ../../builder/plugin) the integration is tested too.
set -eu
cd "$(dirname "$0")"
HISTOS_PLUGIN="${HISTOS_PLUGIN:-../../builder/plugin}"
if [ -f "$HISTOS_PLUGIN/xb.php" ]; then
  export HISTOS_PLUGIN COMPOSE_FILE="compose.yml:compose.histos.yml"
fi
# With WooCommerce (WOOCOMMERCE_PLUGIN, default dev/.third-party/woocommerce, never in the repository) the shop is tested too.
WOOCOMMERCE_PLUGIN="${WOOCOMMERCE_PLUGIN:-$(pwd)/.third-party/woocommerce}"
if [ -f "$WOOCOMMERCE_PLUGIN/woocommerce.php" ]; then
  export WOOCOMMERCE_PLUGIN COMPOSE_FILE="${COMPOSE_FILE:-compose.yml}:compose.woo.yml"
fi
# With Slim SEO and Rank Math copied into dev/.third-party (never in the repository) their sitemaps are tested too.
if [ -f .third-party/slim-seo/slim-seo.php ] && [ -f .third-party/seo-by-rank-math/rank-math.php ]; then
  export COMPOSE_FILE="${COMPOSE_FILE:-compose.yml}:compose.seo.yml" LB_SEO=1
fi
# With Secure Custom Fields copied into dev/.third-party its repeaters and options pages are tested too.
if [ -f .third-party/secure-custom-fields/secure-custom-fields.php ]; then
  export COMPOSE_FILE="${COMPOSE_FILE:-compose.yml}:compose.fields.yml" LB_FIELDS=1
fi
# With WooPayments next to WooCommerce (downloaded from wordpress.org into dev/.third-party, never in the repository):
# its multi-currency, for the currency of each language. Only where the test stack for it exists (compose.woopayments.yml
# and its test helper are not part of every copy of the repository).
if [ -f compose.woopayments.yml ] && [ -f ../tests/wp/currency.test.mjs ] && [ -f .third-party/woocommerce-payments/woocommerce-payments.php ] && [ -n "${WOOCOMMERCE_PLUGIN:-}" ] && [ -f "$WOOCOMMERCE_PLUGIN/woocommerce.php" ]; then
  export COMPOSE_FILE="${COMPOSE_FILE:-compose.yml}:compose.woopayments.yml" LB_WCPAY=1
fi
# With WPGraphQL downloaded into dev/.third-party (never in the repository) the language in GraphQL is tested too.
if [ -f .third-party/wp-graphql/wp-graphql.php ]; then
  export COMPOSE_FILE="${COMPOSE_FILE:-compose.yml}:compose.graphql.yml" LB_GRAPHQL=1
fi
# With Polylang copied into dev/.third-party its terms and menus are imported in a test of their own.
if [ -f .third-party/polylang/polylang.php ]; then
  export COMPOSE_FILE="${COMPOSE_FILE:-compose.yml}:compose.polylang.yml" LB_POLYLANG=1
fi
# With Fluent Forms copied into dev/.third-party its forms are tested in the languages too.
if [ -f .third-party/fluentform/fluentform.php ]; then
  export COMPOSE_FILE="${COMPOSE_FILE:-compose.yml}:compose.forms.yml" LB_FORMS=1
  # With FluentCRM copied next to it the language of a contact and its automatic emails are tested too.
  if [ -f .third-party/fluent-crm/fluent-crm.php ]; then
    export COMPOSE_FILE="$COMPOSE_FILE:compose.crm.yml" LB_CRM=1
  fi
fi
# With Static Bridge (STATIC_BRIDGE_PLUGIN: its plugin folder) the routes of a static copy are tested too.
if [ -n "${STATIC_BRIDGE_PLUGIN:-}" ] && [ -f "$STATIC_BRIDGE_PLUGIN/static-bridge.php" ]; then
  export STATIC_BRIDGE_PLUGIN COMPOSE_FILE="${COMPOSE_FILE:-compose.yml}:compose.static.yml"
fi
# The checks without WordPress first (the release build runs them too): no stack needed, they fail fast.
php ../tests/php/run.php > /dev/null || { php ../tests/php/run.php | grep -E '^FAIL|tests,'; exit 1; }
node --test ../tests/node/*.test.mjs > /dev/null || { echo 'FAIL tests/node'; exit 1; }
# The rebuild cards are checked only where they exist (they are not part of every copy of the repository).
if [ -f ../scripts/schede.mjs ]; then
  node ../scripts/schede.mjs controlla > /dev/null || { node ../scripts/schede.mjs controlla | tail -20; exit 1; }
fi
./up.sh > /dev/null
. ./.env
docker compose run --rm -T cli wp eval-file /lb-tests/seed.php 2>/dev/null | tail -n 1 > .seed.json
docker compose run --rm -T cli wp eval-file /lb-tests/run.php 2>/dev/null
docker compose run --rm -T cli wp eval-file /lb-tests/connector-run.php 2>/dev/null
docker compose run --rm -T cli wp eval-file /lb-tests/xliff-run.php 2>/dev/null
docker compose run --rm -T cli wp eval-file /lb-tests/string-import-run.php 2>/dev/null
docker compose run --rm -T cli wp eval-file /lb-tests/memory-run.php 2>/dev/null
docker compose run --rm -T cli wp eval-file /lb-tests/machine-run.php 2>/dev/null
docker compose run --rm -T cli wp eval-file /lb-tests/updater-run.php 2>/dev/null
docker compose run --rm -T cli wp eval-file /lb-tests/auto-run.php 2>/dev/null
docker compose run --rm -T cli wp eval-file /lb-tests/rest-run.php 2>/dev/null
if [ -n "${LB_POLYLANG:-}" ]; then
  docker compose run --rm -T cli wp plugin activate polylang --quiet 2>/dev/null
  docker compose run --rm -T cli wp eval-file /lb-tests/polylang-run.php 2>/dev/null || { docker compose run --rm -T cli wp plugin deactivate polylang --quiet 2>/dev/null; exit 1; }
  docker compose run --rm -T cli wp plugin deactivate polylang --quiet 2>/dev/null
fi
if [ -n "${LB_FIELDS:-}" ]; then
  docker compose run --rm -T cli wp plugin activate secure-custom-fields --quiet 2>/dev/null
  docker compose run --rm -T cli wp eval-file /lb-tests/acf-run.php 2>/dev/null
  docker compose run --rm -T cli wp plugin deactivate secure-custom-fields --quiet 2>/dev/null
fi
if [ -n "${WOOCOMMERCE_PLUGIN:-}" ] && [ -f "$WOOCOMMERCE_PLUGIN/woocommerce.php" ]; then
  docker compose run --rm -T cli wp plugin activate woocommerce --quiet 2>/dev/null
  docker compose run --rm -T cli wp eval-file /lb-tests/woocommerce-run.php 2>/dev/null
fi
tests="../tests/wp/http.test.mjs ../tests/wp/router.test.mjs ../tests/wp/static.test.mjs ../tests/wp/cli.test.mjs"
if [ -n "${STATIC_BRIDGE_PLUGIN:-}" ]; then
  docker compose run --rm -T cli wp plugin activate static-bridge --quiet 2>/dev/null
  docker compose run --rm -T cli wp eval-file /lb-tests/static-bridge-run.php 2>/dev/null
  # Off again until its own suite: with a copy left by an earlier run Apache would serve it to the other suites.
  docker compose run --rm -T cli wp plugin deactivate static-bridge --quiet 2>/dev/null
  docker compose exec -T wordpress rm -f wp-content/static-bridge/.enabled
  # C9: a full export by Static Bridge, then its copy served as a static host would.
  tests="$tests ../tests/wp/static-copy.test.mjs"
fi
if [ -n "${COMPOSE_FILE:-}" ]; then
  docker compose run --rm -T cli wp plugin activate xb --quiet 2>/dev/null
  docker compose run --rm -T cli wp eval-file /lb-tests/histos-seed.php 2>/dev/null | tail -n 1 > .histos.json
  docker compose run --rm -T -e LB_HISTOS_SEED="$(cat .histos.json)" cli wp eval-file /lb-tests/histos-run.php 2>/dev/null
  tests="$tests ../tests/wp/histos.test.mjs"
fi
# The AI connector in a real browser, when Playwright is at hand (the Histeon checkout has it).
if [ -n "${WOOCOMMERCE_PLUGIN:-}" ] && [ -f "$WOOCOMMERCE_PLUGIN/woocommerce.php" ]; then
  tests="$tests ../tests/wp/woocommerce.test.mjs"
fi
if [ -n "${LB_SEO:-}" ]; then
  tests="$tests ../tests/wp/seo.test.mjs"
fi
if [ -n "${LB_FORMS:-}" ]; then
  tests="$tests ../tests/wp/forms.test.mjs"
fi
if [ -n "${LB_CRM:-}" ]; then
  tests="$tests ../tests/wp/crm.test.mjs"
fi
if [ -n "${LB_GRAPHQL:-}" ]; then
  tests="$tests ../tests/wp/graphql.test.mjs"
fi
if [ -n "${LB_WCPAY:-}" ]; then
  tests="$tests ../tests/wp/currency.test.mjs"
fi
LB_PLAYWRIGHT="${LB_PLAYWRIGHT:-$(cd .. && pwd)/../builder/node_modules/playwright-core}"
if [ -d "$LB_PLAYWRIGHT" ]; then
  tests="$tests ../tests/wp/connector.browser.mjs ../tests/wp/settings.browser.mjs ../tests/wp/editor.browser.mjs ../tests/wp/xliff.browser.mjs ../tests/wp/update.browser.mjs ../tests/wp/dashboard.browser.mjs ../tests/wp/hint.browser.mjs ../tests/wp/switcher.browser.mjs"
fi
LB_PLAYWRIGHT="$LB_PLAYWRIGHT" LB_ADMIN_PASSWORD="$LB_ADMIN_PASSWORD" LB_BASE="http://localhost:${LB_PORT}" LB_SEED="$(pwd)/.seed.json" LB_HISTOS_SEED_FILE="$(pwd)/.histos.json" LB_STATIC_BASE="http://localhost:${LB_STATIC_PORT:-8896}" LB_DEV="$(pwd)" node --test --test-concurrency=1 $tests
