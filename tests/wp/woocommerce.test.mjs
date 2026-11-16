// WooCommerce pages in the page's language (dev/test.sh with WooCommerce): the cart of an English page is the
// translation of the cart page (woocommerce-run.php sets the seed's «Carrello» as the cart page).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fetchRetry } from './fetch.mjs';

const base = process.env.LB_BASE;
const seed = JSON.parse(readFileSync(process.env.LB_SEED, 'utf8'));
const cart = async (path) => /<a class="lb-test-cart" href="([^"]+)"/.exec(await (await fetchRetry(base + path)).text())?.[1];

test('the cart opens in the language of the page', async () => {
  assert.equal(new URL(await cart(seed.paths.about_en)).pathname, seed.paths.cart_en);
  assert.equal(new URL(await cart(seed.paths.about_it)).pathname, seed.paths.cart_it);
});
