// The always-on router: with Lingua Bridge deactivated, language URLs keep working (dev/test.sh, real WordPress).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fetchRetry } from './fetch.mjs';

const base = process.env.LB_BASE;
const seed = JSON.parse(readFileSync(process.env.LB_SEED, 'utf8'));
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
const evalPhp = (php) => wp('eval', php);
const page = async (path) => {
  const r = await fetchRetry(base + path, { redirect: 'manual' });
  return { status: r.status, location: r.headers.get('location') ?? '', html: await r.text() };
};

test('without the router, deactivating keeps the content but drops /en/ (the documented limit)', async () => {
  wp('plugin', 'deactivate', 'lingua-bridge', '--quiet');
  try {
    // WordPress no longer knows /en/: it guesses the English page's own root URL (a 301), or answers 404.
    const en = await page(seed.paths.about_en);
    assert.ok(en.status === 404 || (en.status === 301 && /\/about\/?$/.test(en.location)), `${en.status} ${en.location}`);
    assert.doesNotMatch(en.html, /lang="en"/);
    assert.equal((await page(seed.paths.about_it)).status, 200, 'the content is still ordinary WordPress content');
  } finally {
    wp('plugin', 'activate', 'lingua-bridge', '--quiet');
  }
});

test('with the router, /en/, translated links, lang and hreflang survive a deactivation', async () => {
  const sync = evalPhp("$s = get_option('lingua_bridge_settings'); $s['keep_routing'] = true; update_option('lingua_bridge_settings', $s); echo LinguaBridge\\Router::sync(true) ?: 'ok', '|', LinguaBridge\\Router::installed() ? 'installed' : 'missing';");
  assert.equal(sync.trim(), 'ok|installed');
  wp('plugin', 'deactivate', 'lingua-bridge', '--quiet');
  try {
    const en = await page(seed.paths.about_en);
    assert.equal(en.status, 200);
    assert.match(en.html, /LB-ABOUT-EN/);
    assert.match(en.html, /<html[^>]*lang="en"/);
    assert.match(en.html, /hreflang="it"/);
    const home = await page('/en/');
    assert.equal(home.status, 200);
    assert.match(home.html, /LB-HOME-EN/);
    assert.equal((await page('/')).status, 200);
  } finally {
    wp('plugin', 'activate', 'lingua-bridge', '--quiet');
    // Back as it was: the router file goes, the setting too.
    const off = evalPhp("$s = get_option('lingua_bridge_settings'); $s['keep_routing'] = false; update_option('lingua_bridge_settings', $s); echo LinguaBridge\\Router::sync(false) ?: 'ok', '|', LinguaBridge\\Router::installed() ? 'installed' : 'removed';");
    assert.equal(off.trim(), 'ok|removed');
  }
  // With the plugin active again, everything as before.
  assert.equal((await page(seed.paths.about_en)).status, 200);
});
