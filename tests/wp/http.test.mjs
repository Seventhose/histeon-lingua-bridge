// Lingua Bridge on a real WordPress: URLs, homepage, language of lists, hreflang, redirects (dev/test.sh).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fetchRetry } from './fetch.mjs';
import http from 'node:http';
import { execFileSync } from 'node:child_process';

const base = process.env.LB_BASE;
const seed = JSON.parse(readFileSync(process.env.LB_SEED, 'utf8'));
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const get = (path) => fetchRetry(base + path, { redirect: 'manual' });
const page = async (path) => {
  const r = await get(path);
  return { status: r.status, location: r.headers.get('location'), html: await r.text() };
};
const lang = (html) => /<html[^>]*\blang="([^"]+)"/.exec(html)?.[1];

test('the default language lives at the root, the other under its prefix', async () => {
  const it = await page('/');
  assert.equal(it.status, 200);
  assert.match(it.html, /LB-HOME-IT/);
  assert.equal(lang(it.html), 'it');
  const en = await page('/en/');
  assert.equal(en.status, 200);
  assert.match(en.html, /LB-HOME-EN/);
  assert.equal(lang(en.html), 'en');
  assert.match(en.html, /hreflang="it" href="http:\/\/[^/"]+\/"/, 'the original home is the root, not its slug');
  assert.match(en.html, /hreflang="x-default" href="http:\/\/[^/"]+\/"/);
  assert.match(en.html, /href="(?:http:\/\/[^/"]+)?\/" hreflang="it" lang="it" data-lb-choice="it"/, 'and so is the switcher');
  assert.match(en.html, /hreflang="x-default"/);
});

test('the language comes from the path, never from the query string (no cache poisoning)', async () => {
  const forced = await page('/?lingua_bridge_route_language=en&lingua_bridge_home_language=en');
  assert.equal(forced.status, 200);
  assert.equal(lang(forced.html), 'it');
  assert.match(forced.html, /LB-HOME-IT/);
  assert.doesNotMatch(forced.html, /LB-HOME-EN/);
  const about = await page(`${seed.paths.about_it}?lingua_bridge_route_language=en`);
  assert.equal(about.status, 200, 'an Italian page stays itself');
  assert.equal(lang(about.html), 'it');
});

test('translated pages, posts and custom types answer under the prefix', async () => {
  for (const key of ['about_en', 'post_en', 'event_en']) {
    const p = await page(seed.paths[key]);
    assert.equal(p.status, 200, `${key} ${seed.paths[key]}`);
    assert.equal(lang(p.html), 'en', key);
  }
  const it = await page(seed.paths.about_it);
  assert.equal(it.status, 200);
  assert.match(it.html, /LB-ABOUT-IT/);
});

test('links to translations are canonical: no extra redirect hop', async () => {
  for (const key of ['about_en', 'post_en', 'event_en']) {
    assert.ok(seed.paths[key].endsWith('/'), `${key}: ${seed.paths[key]} has no trailing slash`);
    assert.equal((await get(seed.paths[key])).status, 200, key);
  }
});

test('content of one language is not served under the other', async () => {
  assert.equal((await page('/en/chi-siamo/')).status, 404, 'Italian page under /en/');
  assert.equal((await page('/en/solo-italiano/')).status, 404, 'untranslated post under /en/');
});

test('an old root URL of a translation redirects to its prefixed URL', async () => {
  const r = await page('/about/');
  assert.equal(r.status, 301);
  assert.match(r.location ?? '', /\/en\/about\/?$/);
});

test('lists show the language of the page', async () => {
  const it = await page('/category/news/');
  assert.equal(it.status, 200);
  assert.match(it.html, /LB articolo italiano/);
  assert.match(it.html, /LB solo italiano/);
  assert.doesNotMatch(it.html, /LB English article/, 'an English post in the Italian archive');
  const en = await page('/en/category/news/');
  assert.equal(en.status, 200);
  assert.match(en.html, /LB English article/);
  assert.doesNotMatch(en.html, /LB articolo italiano|LB solo italiano/);
  assert.equal(lang(en.html), 'en', 'an /en/ archive is in English');
});

test('search and feeds follow the language of their URL', async () => {
  const it = await page('/?s=LB');
  assert.match(it.html, /LB articolo italiano/);
  assert.doesNotMatch(it.html, /LB English article/);
  const en = await page('/en/?s=LB');
  assert.match(en.html, /LB English article/);
  assert.doesNotMatch(en.html, /LB articolo italiano/);
  const feedIt = await page('/feed/');
  assert.match(feedIt.html, /LB articolo italiano/);
  assert.doesNotMatch(feedIt.html, /LB English article/);
  const feedEn = await page('/en/feed/');
  assert.match(feedEn.html, /LB English article/);
  assert.doesNotMatch(feedEn.html, /LB articolo italiano/);
});

test('editors and sitemaps still see every language', async () => {
  const rest = await (await fetchRetry(`${base}/wp-json/wp/v2/posts?search=LB&per_page=20`)).json();
  const titles = rest.map((p) => p.title.rendered);
  assert.ok(titles.includes('LB English article') && titles.includes('LB articolo italiano'), titles.join(', '));
  const sitemap = await page('/wp-sitemap-posts-post-1.xml');
  assert.equal(sitemap.status, 200);
  assert.match(sitemap.html, /\/en\/english-article\//);
  assert.match(sitemap.html, /\/articolo-italiano\//);
});

test('the sitemaps of the contents name the versions in the other languages', async () => {
  const sitemap = await page('/wp-sitemap-posts-page-1.xml');
  assert.equal(sitemap.status, 200);
  assert.match(sitemap.html, /<urlset[^>]*xmlns:xhtml="http:\/\/www.w3.org\/1999\/xhtml"/);
  const about = sitemap.html.match(/<url><loc>[^<]*\/en\/about\/<\/loc>(.*?)<\/url>/);
  assert.ok(about, 'the English page is in the sitemap');
  assert.match(about[1], /<xhtml:link rel="alternate" hreflang="it" href="[^"]*\/chi-siamo\/"\/>/);
  assert.match(about[1], /<xhtml:link rel="alternate" hreflang="en" href="[^"]*\/en\/about\/"\/>/);
  assert.match(about[1], /<xhtml:link rel="alternate" hreflang="x-default" href="[^"]*\/chi-siamo\/"\/>/);
});

test('the shortcode switcher: compact styles once per page, the current language marked', async () => {
  const p = await page(seed.paths.about_en);
  assert.equal((p.html.match(/<style id="lingua-bridge-switcher">/g) ?? []).length, 1);
  assert.equal((p.html.match(/<nav class="lb-language-switcher"/g) ?? []).length, 2);
  assert.match(p.html, /<a hreflang="en" lang="en" href="[^"]*\/en\/about\/" data-lb-choice="en" aria-current="page">English<\/a>/);
  assert.match(p.html, /<a hreflang="it" lang="it" href="[^"]*\/chi-siamo\/" data-lb-choice="it">Italiano<\/a>/);
});

test('strings outside contents: theme texts, site title, WordPress locale, menus', async () => {
  const en = await page(seed.paths.about_en);
  const it = await page(seed.paths.about_it);
  assert.match(en.html, /<p class="lb-fixed">Fixed theme text<\/p>/);
  assert.match(en.html, /alt="Site logo"/);
  assert.match(en.html, /<script type="text\/plain" id="lb-fixed-script">Testo fisso del tema<\/script>/, 'a script is never touched');
  assert.match(it.html, /<p class="lb-fixed">Testo fisso del tema<\/p>/, 'the original page stays as it is');
  // Escaped as WordPress stores the option: a quote in a translated title never reaches a theme raw.
  assert.match(en.html, /<title>[^<]*Lingua Bridge &quot;Dev&quot; \(EN\)/);
  assert.doesNotMatch(en.html, /Lingua Bridge "Dev"/);
  assert.doesNotMatch(it.html, /\(EN\)/);
  assert.match(en.html, /<meta name="lb-locale" content="en_GB">/, 'English pages use the locale set for English');
  assert.match(it.html, /<meta name="lb-locale" content="en_US">/, 'the original keeps the site locale');
  // Classic menu: the page link goes to the translation with its title, the custom label is translated, home is /en/.
  const menu = /<nav class="lb-test-menu">([\s\S]*?)<\/nav>/.exec(en.html)?.[1] ?? '';
  assert.match(menu, /href="[^"]*\/en\/about\/"[^>]*>About us</);
  assert.match(menu, />Contacts</);
  assert.match(menu, /href="[^"]*\/en\/"[^>]*>Casa</);
  const itMenu = /<nav class="lb-test-menu">([\s\S]*?)<\/nav>/.exec(it.html)?.[1] ?? '';
  assert.match(itMenu, /href="[^"]*\/chi-siamo\/"[^>]*>Chi siamo</);
  assert.match(itMenu, />Contatti</);
  // Navigation block: the link to the original goes to the translation.
  assert.match(en.html, /wp-block-navigation-item[\s\S]*?href="[^"]*\/en\/about\/"[\s\S]*?About us/);
});

test('the language switcher in the theme navigation: versions of the page, the same list elsewhere', async () => {
  const en = await page(seed.paths.about_en);
  const items = [...en.html.matchAll(/<li class="wp-block-navigation-item lb-menu-switcher"><a[^>]*href="([^"]+)"[^>]*hreflang="([a-z-]+)"[^>]*?(aria-current="true")?>/g)];
  assert.deepEqual(items.map((m) => [new URL(m[1]).pathname, m[2], Boolean(m[3])]), [[seed.paths.about_it, 'it', false], [seed.paths.about_en, 'en', true]]);
  // F4: on a list page, the same list in the other languages (before: the homes, and no way back from /en/…).
  const links = async (path) => [...(await page(path)).html.matchAll(/lb-menu-switcher"><a[^>]*href="([^"]+)"[^>]*hreflang="([a-z-]+)"/g)].map((m) => [new URL(m[1]).pathname + new URL(m[1]).search, m[2]]);
  assert.deepEqual(await links('/category/news/'), [['/category/news/', 'it'], ['/en/category/news/', 'en']]);
  assert.deepEqual(await links('/en/category/news/'), [['/category/news/', 'it'], ['/en/category/news/', 'en']], 'from the English list back to the Italian one');
  assert.deepEqual(await links('/en/?s=LB'), [['/?s=LB', 'it'], ['/en/?s=LB', 'en']]);
  // C3: a list with no English content does not lead to an empty English list.
  assert.deepEqual(await links('/tag/solo-it/'), [], 'one language only: no switcher');
});

test('a language on its own domain: its pages there without the prefix, /en/… on the main site redirects', async () => {
  const port = new URL(base).port;
  const host = `en.localhost:${port}`;
  const raw = (path, hostHeader) => new Promise((resolve, reject) => {
    const request = http.request({ host: '127.0.0.1', port, path, headers: { Host: hostHeader } }, (response) => {
      let body = '';
      response.setEncoding('utf8');
      response.on('data', (chunk) => { body += chunk; });
      response.on('end', () => resolve({ status: response.statusCode, location: response.headers.location ?? '', html: body }));
    });
    request.on('error', reject);
    request.end();
  });
  const slug = seed.paths.about_en.replace(/^\/en/, '');
  wp('option', 'patch', 'insert', 'lingua_bridge_settings', 'language_domains', `{"en":"${host}"}`, '--format=json');
  try {
    const english = await raw(slug, host);
    assert.equal(english.status, 200, 'the English page answers on its domain');
    assert.match(english.html, /<html[^>]*lang="en/);
    assert.match(english.html, /LB-ABOUT-EN/);
    assert.match(english.html, new RegExp(`hreflang="en" href="http://${host.replace('.', '\\.')}${slug}"`));
    const moved = await raw(seed.paths.about_en, new URL(base).host);
    assert.equal(moved.status, 301);
    assert.equal(moved.location, `http://${host}${slug}`);
    const home = await raw('/', host);
    assert.equal(home.status, 200, 'the root of the domain is the English home');
    assert.match(home.html, /<html[^>]*lang="en/);
  } finally {
    wp('option', 'patch', 'delete', 'lingua_bridge_settings', 'language_domains');
  }
});

// C3: the lists have hreflang too (before: only single contents and translated terms), for the languages whose list
// has published content; x-default to the original's list; never on a search or on page 2 and after.
test('hreflang on the blog home, the lists of a term, a type, an author', async () => {
  const alternates = (html) => Object.fromEntries([...html.matchAll(/<link rel="alternate" hreflang="([a-zA-Z-]+)" href="([^"]+)"/g)].map((m) => [m[1], new URL(m[2]).pathname]));
  assert.deepEqual(alternates((await page('/category/news/')).html), { it: '/category/news/', en: '/en/category/news/', 'x-default': '/category/news/' });
  assert.deepEqual(alternates((await page('/en/category/news/')).html), { it: '/category/news/', en: '/en/category/news/', 'x-default': '/category/news/' });
  assert.deepEqual(alternates((await page('/eventi/')).html), { it: '/eventi/', en: '/en/eventi/', 'x-default': '/eventi/' });
  const author = new URL((await page('/category/news/')).html.match(/href="([^"]*\/author\/[^"/]+\/)"/)?.[1] ?? `${base}/author/admin/`).pathname;
  assert.deepEqual(alternates((await page(`/en${author}`)).html), { it: author, en: `/en${author}`, 'x-default': author });
  assert.deepEqual(alternates((await page('/tag/solo-it/')).html), {}, 'a list with no English content has no versions');
  assert.deepEqual(alternates((await page('/?s=LB')).html), {}, 'never on a search');
  wp('option', 'update', 'posts_per_page', '1');
  wp('option', 'update', 'show_on_front', 'posts');
  try {
    assert.deepEqual(alternates((await page('/')).html), { it: '/', en: '/en/', 'x-default': '/' }, 'the blog home');
    assert.deepEqual(alternates((await page('/en/')).html), { it: '/', en: '/en/', 'x-default': '/' });
    assert.deepEqual(alternates((await page('/category/news/page/2/')).html), {}, 'never on page 2');
  } finally {
    wp('option', 'update', 'show_on_front', 'page');
    wp('option', 'update', 'posts_per_page', '10');
  }
});

// C1: the floating switcher shows where no other switcher is drawn (here: the navigation one turned off), never
// next to another one; server-side markup, so it is in a static copy too; turned off in one setting.
test('the floating switcher: on a page without another switcher, never next to one', async () => {
  const floating = (html) => /<nav class="lb-language-switcher lb-language-switcher--dropdown lb-language-switcher--floating lb-language-switcher--([a-z-]+)"[^>]*>([\s\S]*?)<\/nav>/.exec(html);
  assert.equal(floating((await page(seed.paths.post_en)).html), null, 'the navigation switcher is there: no floating one');
  wp('option', 'patch', 'update', 'lingua_bridge_settings', 'switcher_menu', '');
  try {
    const post = floating((await page(seed.paths.post_en)).html);
    assert.ok(post, 'a floating switcher on the English article');
    assert.equal(post[1], 'bottom-right');
    assert.match(post[2], /<summary>English<\/summary>/);
    assert.match(post[2], /<a hreflang="it" lang="it" href="[^"]*\/articolo-italiano\/" data-lb-choice="it">Italiano<\/a>/);
    assert.match(post[2], /<a hreflang="en" lang="en" href="[^"]*\/en\/english-article\/" data-lb-choice="en" aria-current="page">English<\/a>/);
    assert.match((await page(seed.paths.post_en)).html, /<style id="lingua-bridge-floating-switcher">/);
    assert.equal(floating((await page(seed.paths.about_en)).html), null, 'the shortcode is on the page: no floating one');
    assert.ok(floating((await page('/en/category/news/')).html), 'a list page has it too');
    assert.equal(floating((await page(seed.paths.only_it)).html), null, 'a content in one language: nothing to switch to');
    wp('option', 'patch', 'update', 'lingua_bridge_settings', 'floating_position', 'top-left');
    assert.equal(floating((await page(seed.paths.post_en)).html)?.[1], 'top-left');
    wp('option', 'patch', 'update', 'lingua_bridge_settings', 'floating_switcher', 'off');
    assert.equal(floating((await page(seed.paths.post_en)).html), null, 'turned off');
  } finally {
    wp('option', 'patch', 'update', 'lingua_bridge_settings', 'switcher_menu', 'nav-block');
    wp('option', 'patch', 'delete', 'lingua_bridge_settings', 'floating_switcher');
    wp('option', 'patch', 'delete', 'lingua_bridge_settings', 'floating_position');
  }
});

// C17: a base translated for English: the English list there, the original's base under /en/ moves there (301), the
// switcher leads back to the original's list.
test('the bases of the addresses in another language', async () => {
  wp('option', 'patch', 'insert', 'lingua_bridge_settings', 'language_bases', '{"en":{"category":"cat"}}', '--format=json');
  wp('rewrite', 'flush');
  try {
    const list = await page('/en/cat/news/');
    assert.equal(list.status, 200);
    assert.match(list.html, /LB English article/);
    assert.match(list.html, /hreflang="en" href="[^"]*\/en\/cat\/news\/"/);
    const moved = await page('/en/category/news/');
    assert.equal(moved.status, 301);
    assert.match(moved.location, /\/en\/cat\/news\/$/);
    assert.match(list.html, /lb-menu-switcher"><a[^>]*href="[^"]*\/category\/news\/"[^>]*hreflang="it"/, 'the switcher leads to the Italian list');
    assert.match((await page('/category/news/')).html, /hreflang="en" href="[^"]*\/en\/cat\/news\/"/, 'the Italian list names the English one');
  } finally {
    wp('option', 'patch', 'delete', 'lingua_bridge_settings', 'language_bases');
    wp('rewrite', 'flush');
  }
});

// C16: a header part saved in the database and translated: an English page draws the English one.
test('a translated template part on the pages of its language', async () => {
  const ids = JSON.parse(wp('eval', '--user=admin', "$t = get_block_template( get_stylesheet() . '//header', 'wp_template_part' ); $it = wp_insert_post( wp_slash( array( 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_title' => 'Header', 'post_name' => 'header', 'post_content' => $t->content . '<!-- wp:paragraph --><p>LB-TESTATA-IT</p><!-- /wp:paragraph -->' ) ) ); wp_set_object_terms( $it, array( get_stylesheet() ), 'wp_theme' ); wp_set_object_terms( $it, array( 'header' ), 'wp_template_part_area' ); $en = LinguaBridge\\Translations::create_draft( $it, 'en' ); $p = get_post( $en ); wp_update_post( wp_slash( array( 'ID' => $en, 'post_status' => 'publish', 'post_content' => str_replace( 'LB-TESTATA-IT', 'LB-TESTATA-EN', $p->post_content ) ) ) ); echo wp_json_encode( array( $it, $en ) );"));
  try {
    const en = (await page(seed.paths.about_en)).html;
    const it = (await page(seed.paths.about_it)).html;
    assert.match(en, /LB-TESTATA-EN/);
    assert.doesNotMatch(en, /LB-TESTATA-IT/);
    assert.match(it, /LB-TESTATA-IT/);
    assert.doesNotMatch(it, /LB-TESTATA-EN/);
  } finally {
    wp('post', 'delete', String(ids[1]), String(ids[0]), '--force', '--quiet');
  }
});

// C34: Arabic without its language pack: the pages in Arabic are right to left (the html dir and the body class).
test('a language written from right to left, without its language pack', async () => {
  wp('option', 'patch', 'insert', 'lingua_bridge_settings', 'languages', 'ar', 'العربية');
  wp('rewrite', 'flush');
  let id;
  try {
    id = wp('eval', '--user=admin', `$ar = LinguaBridge\\Translations::create_draft( ${seed.ids.about_it}, 'ar' ); wp_update_post( array( 'ID' => $ar, 'post_status' => 'publish', 'post_name' => 'lb-ar' ) ); echo $ar;`);
    const ar = await page('/ar/lb-ar/');
    assert.equal(ar.status, 200);
    assert.match(ar.html, /<html[^>]*\bdir="rtl"/);
    assert.equal(lang(ar.html), 'ar');
    assert.match(ar.html, /<body[^>]*class="[^"]*\brtl\b/);
    const en = await page(seed.paths.about_en);
    assert.doesNotMatch(en.html, /<html[^>]*\bdir="rtl"/);
    assert.doesNotMatch(en.html, /<body[^>]*class="[^"]*\brtl\b/);
  } finally {
    if (id) wp('eval', `LinguaBridge\\Storage::delete( ${Number(id)} ); wp_delete_post( ${Number(id)}, true );`);
    wp('option', 'patch', 'delete', 'lingua_bridge_settings', 'languages', 'ar');
    wp('rewrite', 'flush');
  }
});

// C12: x-default to a chosen language, or none; the original's when the page has no version in that language.
test('x-default where the settings say', async () => {
  const xDefault = (html) => /hreflang="x-default" href="([^"]+)"/.exec(html)?.[1];
  try {
    wp('option', 'patch', 'insert', 'lingua_bridge_settings', 'x_default', 'en');
    const it = await page(seed.paths.about_it);
    assert.match(xDefault(it.html) ?? '', new RegExp(seed.paths.about_en.replace(/[/.]/g, '\\$&') + '$'), 'English chosen: x-default is the English page');
    wp('option', 'patch', 'update', 'lingua_bridge_settings', 'x_default', 'none');
    assert.equal(xDefault((await page(seed.paths.about_it)).html), undefined, 'none: no x-default');
    assert.doesNotMatch((await page('/category/news/')).html, /hreflang="x-default"/, 'none on the lists too');
  } finally {
    wp('option', 'patch', 'delete', 'lingua_bridge_settings', 'x_default');
  }
  assert.match(xDefault((await page(seed.paths.about_en)).html) ?? '', new RegExp(seed.paths.about_it.replace(/[/.]/g, '\\$&') + '$'), 'of serie the original');
});

// C30: an image with another file for English: the English page shows that file, with its own srcset.
test('a file of the library in another language', async () => {
  const [it, en] = JSON.parse(wp('option', 'get', 'lb_test_c30', '--format=json'));
  const ids = JSON.parse(wp('eval', '--user=admin', `$src = wp_get_attachment_image_src( ${it}, 'large' ); $html = '<!-- wp:image {"id":${it},"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="' . $src[0] . '" alt="" class="wp-image-${it}"/></figure><!-- /wp:image -->'; $p = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB immagine', 'post_name' => 'lb-immagine', 'post_content' => $html ) ) ); $e = LinguaBridge\\Translations::create_draft( $p, 'en' ); wp_update_post( array( 'ID' => $e, 'post_status' => 'publish', 'post_name' => 'lb-image' ) ); echo wp_json_encode( array( $p, $e, wp_parse_url( get_permalink( $p ), PHP_URL_PATH ), wp_parse_url( get_permalink( $e ), PHP_URL_PATH ) ) );`));
  try {
    const itHtml = (await page(ids[2])).html;
    const enHtml = (await page(ids[3])).html;
    assert.match(itHtml, /lb-cartello-it[^"]*\.png/);
    assert.doesNotMatch(itHtml, /lb-cartello-en/);
    assert.match(enHtml, /<img[^>]*src="[^"]*lb-cartello-en[^"]*\.png"/, 'the English file');
    assert.match(enHtml, new RegExp(`wp-image-${en}\\b`), 'its class');
    assert.doesNotMatch(enHtml, /lb-cartello-it/, 'nothing of the Italian file, srcset included');
  } finally {
    wp('eval', `foreach ( array( ${ids[1]}, ${ids[0]}, ${en}, ${it} ) as $id ) { LinguaBridge\\Storage::delete( $id ); wp_delete_post( $id, true ); } delete_option( 'lb_test_c30' );`);
  }
});

// C8: the index of a language for the search of a static copy: an HTML page with the JSON, never indexed.
test('the index of the static search', async () => {
  const en = await page('/en/lingua-bridge-cerca/');
  assert.equal(en.status, 200);
  assert.match(en.html, /<meta name="robots" content="noindex">/);
  const index = JSON.parse(/<script type="application\/json" id="lb-search-index">([\s\S]*?)<\/script>/.exec(en.html)[1]);
  assert.ok(index.some((item) => item.t === 'LB English article'), 'the English article');
  assert.ok(!index.some((item) => /LB articolo italiano/.test(item.t)), 'no Italian content');
  assert.ok(index.every((item) => item.u.startsWith('/en/')), 'relative addresses of the language');
  const it = await page('/lingua-bridge-cerca/');
  assert.ok(JSON.parse(/id="lb-search-index">([\s\S]*?)<\/script>/.exec(it.html)[1]).some((item) => item.t === 'LB articolo italiano'));
});
