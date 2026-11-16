// C9: Lingua Bridge with Static Bridge, end to end (dev/test.sh with STATIC_BRIDGE_PLUGIN). A full export by Static
// Bridge's own code, as the administrator starts it, then the copy served as a static host serves it
// (dev/static-router.php): every language with its lang, hreflang, switcher and links; then, in a browser, the
// language of the visitor (C2) on the copy. «Perfectly compatible» as a test that stays.
import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fetchRetry } from './fetch.mjs';
import http from 'node:http';

const base = process.env.LB_BASE;
const copy = process.env.LB_STATIC_BASE;
const seed = JSON.parse(readFileSync(process.env.LB_SEED, 'utf8'));
const compose = (...args) => execFileSync('docker', ['compose', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const wp = (...args) => compose('run', '--rm', '-T', 'cli', 'wp', ...args);
const lang = (html) => /<html[^>]*\blang="([^"]+)"/.exec(html)?.[1];
const alternates = (html) => Object.fromEntries([...html.matchAll(/<link rel="alternate" hreflang="([a-zA-Z-]+)" href="([^"]+)"/g)].map((m) => [m[1], new URL(m[2], copy).pathname]));
// The links of the first switcher of the page (a copy may write them relative to the site).
const switcher = (html) => [...(/<nav class="lb-language-switcher[^>]*>([\s\S]*?)<\/nav>/.exec(html)?.[1] ?? '').matchAll(/<a hreflang="([a-z-]+)" lang="[^"]*" href="([^"]+)" data-lb-choice/g)].map((m) => [m[1], new URL(m[2], copy).pathname]);

// The administrator's session, as a browser gets it (the test cookie first, then the login form).
const login = async () => {
  const jar = new Map();
  const keep = (response) => response.headers.getSetCookie().forEach((c) => { const [pair] = c.split(';'); const [k, v] = pair.split('='); jar.set(k, v); });
  const cookie = () => [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
  keep(await fetchRetry(`${base}/wp-login.php`));
  jar.set('wordpress_test_cookie', 'WP%20Cookie%20check');
  const body = new URLSearchParams({ log: 'admin', pwd: process.env.LB_ADMIN_PASSWORD, testcookie: '1', 'wp-submit': 'Log In', redirect_to: `${base}/wp-admin/` });
  keep(await fetch(`${base}/wp-login.php`, { method: 'POST', body, headers: { cookie: cookie() }, redirect: 'manual' }));
  return cookie;
};

const exportAll = async () => {
  wp('plugin', 'activate', 'static-bridge', '--quiet');
  const cookie = await login();
  const admin = await (await fetch(`${base}/wp-admin/admin.php?page=static-bridge`, { headers: { cookie: cookie() } })).text();
  const nonce = /var StaticBridgeAdmin = \{[^;]*?"nonce":"([a-f0-9]+)"/.exec(admin)?.[1];
  assert.ok(nonce, 'the nonce of Static Bridge\'s screen');
  const ajax = async (action) => (await fetch(`${base}/wp-admin/admin-ajax.php`, { method: 'POST', headers: { cookie: cookie() }, body: new URLSearchParams({ action, nonce }) })).json();
  const started = await ajax('static_bridge_start');
  assert.ok(started.success, `the export starts: ${JSON.stringify(started.data ?? started).slice(0, 300)}`);
  // Static Bridge goes on by itself (2.2.14: requests in the background); a step from here only when it stands still
  // for a while, as the screen does: two steps at once would collide («another rendering is in progress»).
  let job = started.data;
  let still = 0;
  for (let i = 0; i < 600 && !['done', 'complete', 'completed', 'failed', 'cancelled'].includes(job?.status); i++) {
    await new Promise((r) => setTimeout(r, 500));
    const status = await ajax('static_bridge_status');
    const before = job?.processed;
    job = status.data ?? job;
    still = job?.processed === before && !job?.workerBusy ? still + 1 : 0;
    if (still >= 6) {
      job = (await ajax('static_bridge_tick')).data ?? job;
      still = 0;
    }
  }
  return job;
};

// After the export Static Bridge serves the copy from Apache itself (its .htaccess rules), as on a real site: the
// suites that run later want WordPress, so it is turned off again, its copy no longer served.
after(() => {
  try { wp('plugin', 'deactivate', 'static-bridge', '--quiet'); } catch { /* already off */ }
  try { compose('exec', '-T', 'wordpress', 'rm', '-f', 'wp-content/static-bridge/.enabled'); } catch { /* none */ }
});

test('a full static copy: every language with its lang, hreflang, switcher and links', { timeout: 600000 }, async () => {
  wp('option', 'patch', 'update', 'lingua_bridge_settings', 'switcher_menu', '');
  try {
    const job = await exportAll();
    assert.match(String(job?.status), /done|complete/, `the export ends: ${JSON.stringify(job).slice(0, 400)}`);
    compose('--profile', 'static', 'up', '-d', '--force-recreate', 'static');
    const page = async (path) => {
      for (let i = 0; i < 20; i++) {
        try {
          const r = await fetch(copy + path, { redirect: 'manual' });
          return { status: r.status, html: await r.text() };
        } catch { await new Promise((r) => setTimeout(r, 250)); }
      }
      throw new Error(`the copy does not answer: ${copy}${path}`);
    };
    const expected = [
      // path, language, versions (hreflang), the switcher's links
      ['/', 'it', { it: '/', en: '/en/', 'x-default': '/' }],
      ['/en/', 'en', { it: '/', en: '/en/', 'x-default': '/' }],
      [seed.paths.about_it, 'it', { it: seed.paths.about_it, en: seed.paths.about_en, 'x-default': seed.paths.about_it }],
      [seed.paths.about_en, 'en', { it: seed.paths.about_it, en: seed.paths.about_en, 'x-default': seed.paths.about_it }],
      [seed.paths.post_en, 'en', { it: seed.paths.post_it, en: seed.paths.post_en, 'x-default': seed.paths.post_it }],
      ['/category/news/', 'it', { it: '/category/news/', en: '/en/category/news/', 'x-default': '/category/news/' }],
      ['/en/category/news/', 'en', { it: '/category/news/', en: '/en/category/news/', 'x-default': '/category/news/' }],
      ['/en/eventi/', 'en', { it: '/eventi/', en: '/en/eventi/', 'x-default': '/eventi/' }],
    ];
    for (const [path, language, versions] of expected) {
      const p = await page(path);
      assert.equal(p.status, 200, `${path} is in the copy`);
      assert.equal(lang(p.html), language, `${path}: lang`);
      assert.deepEqual(alternates(p.html), versions, `${path}: hreflang`);
      const links = switcher(p.html);
      assert.deepEqual(links.map(([code]) => code), ['it', 'en'], `${path}: a switcher with both languages`);
      assert.deepEqual(Object.fromEntries(links), { it: versions.it, en: versions.en }, `${path}: the switcher leads to the versions`);
    }
    // Links inside an English page lead to English pages; a list with no English content has no versions.
    const about = await page(seed.paths.about_en);
    assert.match(about.html, /<nav class="lb-test-menu">[\s\S]*?href="[^"]*\/en\/about\/"/, 'the menu of an English page leads to English pages');
    assert.deepEqual(alternates((await page('/tag/solo-it/')).html), {}, 'a list with no English content names no English version');
  } finally {
    wp('option', 'patch', 'update', 'lingua_bridge_settings', 'switcher_menu', 'nav-block');
  }
});

test('the language of the visitor on the static copy: suggest, then redirect', { timeout: 120000 }, async () => {
  if (!process.env.LB_PLAYWRIGHT) return;
  const { chromium } = createRequire(import.meta.url)(process.env.LB_PLAYWRIGHT);
  const browser = await chromium.launch();
  try {
    const bar = await (await browser.newContext({ locale: 'en-US' })).newPage();
    await bar.goto(`${copy}${seed.paths.about_it}`);
    const link = bar.locator('.lb-language-hint a');
    await link.waitFor();
    assert.equal(new URL(await link.getAttribute('href')).pathname, seed.paths.about_en, 'the bar of the copy leads to the English page');
    // The redirect is a setting in the page: a copy exported with it on carries it.
    wp('option', 'patch', 'insert', 'lingua_bridge_settings', 'visitor_language', 'redirect');
    await exportAll();
    const html = await (await fetch(`${copy}${seed.paths.about_it}`)).text();
    assert.match(html, /"mode":"redirect"/, 'the copy carries the setting');
    assert.match(html, /<script[^>]*src="[^"]*language-hint\.js[^"]*"[^>]*>/);
    assert.doesNotMatch(/<script[^>]*language-hint-js"[^>]*>/.exec(html)?.[0] ?? '', /defer/, 'in the head, before the page is drawn');
    const context = await browser.newContext({ locale: 'en-US', userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' });
    await context.addInitScript(() => Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false }));
    const page = await context.newPage();
    await page.goto(`${copy}${seed.paths.about_it}`);
    await page.waitForURL(`**${seed.paths.about_en}`);
    assert.equal(new URL(page.url()).pathname, seed.paths.about_en, 'an English browser lands on the English page of the copy');
  } finally {
    try { wp('option', 'patch', 'delete', 'lingua_bridge_settings', 'visitor_language'); } catch { /* not set */ }
    await browser.close();
  }
});

// C5, C6, C7 on Apache with Static Bridge's own rules, in Strict mode (a page the copy has not is a 404 there, not
// WordPress): a renamed English page still leads to its new address, the sitemaps still answer with their versions,
// and the domain of a language is never answered with the main site's copy.
test('Strict mode: redirects in the rules, sitemaps from WordPress, the copy never on a language\'s domain', { timeout: 300000 }, async () => {
  const port = new URL(base).port;
  const raw = (path, host = `localhost:${port}`) => new Promise((resolve, reject) => {
    const request = http.request({ host: '127.0.0.1', port, path, headers: { Host: host } }, (response) => {
      let body = '';
      response.setEncoding('utf8');
      response.on('data', (chunk) => { body += chunk; });
      response.on('end', () => resolve({ status: response.statusCode, location: response.headers.location ?? '', html: body }));
    });
    request.on('error', reject);
    request.end();
  });
  const sb = (method) => wp('eval', `$p = null; foreach ( $GLOBALS['wp_filter']['static_bridge_queue_paths']->callbacks as $c ) { foreach ( $c as $f ) { if ( is_array( $f['function'] ) && $f['function'][0] instanceof Static_Bridge_Plugin ) { $p = $f['function'][0]; } } } $m = new ReflectionMethod( $p, '${method}' ); $m->setAccessible( true ); var_export( $m->invoke( $p ) );`);
  wp('plugin', 'activate', 'static-bridge', '--quiet');
  const ids = JSON.parse(wp('eval', '--user=admin', "$it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB c5 copia', 'post_name' => 'lb-c5-copia', 'meta_input' => array( '_lb_test' => 1 ) ) ); $en = LinguaBridge\\Translations::create_draft( $it, 'en' ); update_post_meta( $en, '_lb_test', 1 ); wp_update_post( array( 'ID' => $en, 'post_name' => 'lb-c5-old', 'post_status' => 'publish' ) ); wp_update_post( array( 'ID' => $en, 'post_name' => 'lb-c5-new' ) ); $cat = wp_insert_term( 'LB c5 cat', 'category', array( 'slug' => 'lb-c5-cat' ) ); $cat = $cat['term_id']; $term = LinguaBridge\\Terms::create( $cat, 'en', 'LB c5 cat EN', 'lb-c5-cat-en' ); $post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'LB c5 articolo', 'post_category' => array( $cat ), 'meta_input' => array( '_lb_test' => 1 ) ) ); $post_en = LinguaBridge\\Translations::create_draft( $post, 'en' ); update_post_meta( $post_en, '_lb_test', 1 ); wp_publish_post( $post_en ); echo wp_json_encode( array( $it, $en, $post, $post_en, $cat, $term ) );"));
  // A full export after the change: until then Static Bridge keeps the changed addresses with WordPress.
  const job = await exportAll();
  assert.match(String(job?.status), /done|complete/, 'the export ends');
  compose('exec', '-T', 'wordpress', 'touch', 'wp-content/static-bridge/.enabled', 'wp-content/static-bridge/.strict');
  try {
    assert.equal((await raw('/no-such-page-lb/')).status, 404, 'Strict: a page the copy has not is a 404');
    assert.equal(sb('update_routing_rules'), 'true', 'Static Bridge writes its rules again');
    const moved = await raw('/en/lb-c5-old/');
    assert.equal(moved.status, 301, 'C5: the old address of the English page, in Strict');
    assert.match(moved.location, /\/en\/lb-c5-new\/$/);
    const term = await raw('/en/category/lb-c5-cat/');
    assert.equal(term.status, 301, 'C5: the original category under /en/, in Strict');
    assert.match(term.location, /\/en\/category\/lb-c5-cat-en\/$/);
    // C6: the sitemaps are WordPress's, also in Strict: the language sitemap with its versions.
    const lingua = await raw('/wp-sitemap-lingua-1.xml');
    assert.equal(lingua.status, 200, 'C6: the language sitemap answers in Strict');
    assert.match(lingua.html, /<xhtml:link rel="alternate" hreflang="it"/);
    assert.equal((await raw('/wp-sitemap.xml')).status, 200);
    // C7: English on its own domain; Static Bridge learns the host from Lingua Bridge and leaves it to WordPress.
    const host = `en.localhost:${port}`;
    wp('option', 'patch', 'insert', 'lingua_bridge_settings', 'language_domains', `{"en":"${host}"}`, '--format=json');
    assert.equal(sb('update_routing_rules'), 'true');
    const home = await raw('/', host);
    assert.equal(home.status, 200, 'the root of the English domain');
    assert.match(home.html, /<html[^>]*lang="en/, 'C7: the English home, not the copy of the Italian one');
    assert.equal((await raw('/', `localhost:${port}`)).status, 200, 'the main site still has its copy');
  } finally {
    try { wp('option', 'patch', 'delete', 'lingua_bridge_settings', 'language_domains'); } catch { /* not set */ }
    compose('exec', '-T', 'wordpress', 'rm', '-f', 'wp-content/static-bridge/.strict');
    wp('post', 'delete', String(ids[3]), String(ids[2]), String(ids[1]), String(ids[0]), '--force', '--quiet');
    wp('term', 'delete', 'category', String(ids[5]), String(ids[4]), '--quiet');
  }
});

// C8: a search on the copy: the static host answers /en/?s= with the English home, the script finds in the index.
test('the search on the static copy, from the index of the language', { timeout: 120000 }, async () => {
  const index = await fetch(`${copy}/en/lingua-bridge-cerca/`);
  assert.equal(index.status, 200, 'the copy has the English index');
  assert.match(await index.text(), /LB English article/);
  if (!process.env.LB_PLAYWRIGHT) return;
  const { chromium } = createRequire(import.meta.url)(process.env.LB_PLAYWRIGHT);
  const browser = await chromium.launch();
  try {
    const page = await (await browser.newContext({ locale: 'en-US' })).newPage();
    await page.goto(`${copy}/en/?s=english+article`);
    const hit = page.locator('.lb-static-search a').first();
    await hit.waitFor();
    assert.equal(await hit.textContent(), 'LB English article');
    assert.equal(new URL(await hit.getAttribute('href'), copy).pathname, seed.paths.post_en);
    await page.goto(`${copy}/en/?s=nothingatallhere`);
    await page.locator('.lb-static-search').waitFor();
    assert.equal(await page.locator('.lb-static-search a').count(), 0, 'no result: a sentence, no links');
  } finally {
    await browser.close();
  }
});
