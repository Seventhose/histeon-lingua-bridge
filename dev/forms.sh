#!/usr/bin/env sh
# Only the Fluent Forms suite (tests/wp/forms.test.mjs, and tests/wp/crm.test.mjs when FluentCRM is copied next to it)
# on the dev stack, with the same plugins dev/test.sh mounts: for working on the forms without the whole series. Needs
# Fluent Forms copied into dev/.third-party.
set -eu
cd "$(dirname "$0")"
[ -f .third-party/fluentform/fluentform.php ] || { echo 'Fluent Forms is not in dev/.third-party'; exit 1; }
COMPOSE_FILE=compose.yml
[ -f ../../builder/plugin/xb.php ] && COMPOSE_FILE="$COMPOSE_FILE:compose.histos.yml" && export HISTOS_PLUGIN=../../builder/plugin
[ -f .third-party/woocommerce/woocommerce.php ] && COMPOSE_FILE="$COMPOSE_FILE:compose.woo.yml" && export WOOCOMMERCE_PLUGIN="$(pwd)/.third-party/woocommerce"
[ -f .third-party/slim-seo/slim-seo.php ] && [ -f .third-party/seo-by-rank-math/rank-math.php ] && COMPOSE_FILE="$COMPOSE_FILE:compose.seo.yml"
[ -f .third-party/secure-custom-fields/secure-custom-fields.php ] && COMPOSE_FILE="$COMPOSE_FILE:compose.fields.yml"
[ -f .third-party/woocommerce-payments/woocommerce-payments.php ] && [ -f .third-party/woocommerce/woocommerce.php ] && COMPOSE_FILE="$COMPOSE_FILE:compose.woopayments.yml"
[ -f .third-party/wp-graphql/wp-graphql.php ] && COMPOSE_FILE="$COMPOSE_FILE:compose.graphql.yml"
[ -f .third-party/polylang/polylang.php ] && COMPOSE_FILE="$COMPOSE_FILE:compose.polylang.yml"
export COMPOSE_FILE="$COMPOSE_FILE:compose.forms.yml"
suites=../tests/wp/forms.test.mjs
[ -f .third-party/fluent-crm/fluent-crm.php ] && export COMPOSE_FILE="$COMPOSE_FILE:compose.crm.yml" && suites="$suites ../tests/wp/crm.test.mjs"
./up.sh > /dev/null
. ./.env
LB_BASE="http://localhost:${LB_PORT}" LB_DEV="$(pwd)" node --test --test-concurrency=1 "$@" $suites
