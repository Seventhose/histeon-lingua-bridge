// C41: Lingua Bridge on a multisite (dev/multisite.sh): active on one site, not on another of the same network.
// Each site has its own tables; the router, written once in mu-plugins, works only for the site that has the plugin.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { execFileSync } from 'node:child_process';

const port = Number(process.env.LB_PORT);
const uno = new URL(process.env.LB_MS_UNO);
const due = new URL(process.env.LB_MS_DUE);
const [itPath, enPath] = JSON.parse(process.env.LB_MS_IDS);
const wp = (...args) => execFileSync('docker', ['compose', '-p', process.env.LB_MS_PROJECT, 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
// A site of the network on the published port, with its own Host (a network has no port in its domain).
const get = (site, path) => new Promise((resolve, reject) => {
  http.get({ host: '127.0.0.1', port, path, headers: { Host: site.host } }, (r) => {
    let body = '';
    r.setEncoding('utf8');
    r.on('data', (c) => { body += c; });
    r.on('end', () => resolve({ status: r.statusCode, location: r.headers.location, html: body }));
  }).on('error', reject);
});

test(`multisite (${process.env.LB_MS_MODE}): the site with Lingua Bridge has its languages`, async () => {
  const it = await get(uno, itPath);
  assert.equal(it.status, 200);
  assert.match(it.html, /LB-MS-IT/);
  assert.match(it.html, /<html[^>]*lang="it"/);
  const en = await get(uno, enPath);
  assert.equal(en.status, 200, `${enPath} on the site with the plugin`);
  assert.match(en.html, /LB-MS-EN/);
  assert.match(en.html, /<html[^>]*lang="en"/);
  assert.match(en.html, /hreflang="it"/);
});

test(`multisite (${process.env.LB_MS_MODE}): the site without it is untouched`, async () => {
  const base = due.pathname.replace(/\/$/, '');
  // On the second site /en/ is a page of its own, not a language.
  const page = await get(due, `${base}/en/`);
  assert.equal(page.status, 200);
  assert.match(page.html, /LB-MS-DUE-EN/);
  assert.doesNotMatch(page.html, /hreflang=/);
  const home = await get(due, `${base}/`);
  assert.equal(home.status, 200);
  assert.doesNotMatch(home.html, /lb-language-switcher|lingua-bridge/);
});

test(`multisite (${process.env.LB_MS_MODE}): tables per site, only where the plugin is`, () => {
  const tables = wp('db', 'tables', '--all-tables', '--format=csv').split(',');
  assert.ok(tables.some((t) => /_2_lingua_bridge_translations$/.test(t)), 'the tables of the site with the plugin');
  assert.ok(!tables.some((t) => /_3_lingua_bridge_/.test(t)), 'none for the site without it');
  assert.ok(!tables.some((t) => /^wp_lingua_bridge_/.test(t)), 'none for the main site');
});

test(`multisite (${process.env.LB_MS_MODE}): the router in mu-plugins works for its site only`, async () => {
  wp('eval', `--url=${uno.href}`, "$s = LinguaBridge\\Languages::settings(); $s['keep_routing'] = true; update_option( LinguaBridge\\Languages::OPTION, $s ); LinguaBridge\\Router::sync( true );");
  wp('plugin', 'deactivate', 'lingua-bridge', `--url=${uno.href}`, '--quiet');
  try {
    const en = await get(uno, enPath);
    assert.equal(en.status, 200, 'with the plugin off the router still serves the English page');
    assert.match(en.html, /LB-MS-EN/);
    const base = due.pathname.replace(/\/$/, '');
    const page = await get(due, `${base}/en/`);
    assert.match(page.html, /LB-MS-DUE-EN/, 'the other site keeps its own /en/ page');
    assert.doesNotMatch(page.html, /hreflang=/);
  } finally {
    wp('plugin', 'activate', 'lingua-bridge', `--url=${uno.href}`, '--quiet');
  }
});
