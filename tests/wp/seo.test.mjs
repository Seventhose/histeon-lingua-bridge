// C11: the versions of each content in the sitemaps of the SEO plugins (dev/test.sh when dev/.third-party has Slim
// SEO and Rank Math, copied from a site, never in the repository). Slim SEO through its own sitemap over HTTP; Rank
// Math through the filters its generator calls (its sitemap answers only after its setup wizard).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fetchRetry } from './fetch.mjs';

const base = process.env.LB_BASE;
const seed = JSON.parse(readFileSync(process.env.LB_SEED, 'utf8'));
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const entry = (xml, path) => new RegExp(`<url>\\s*<loc>[^<]*${path.replace(/\//g, '\\/')}</loc>([\\s\\S]*?)</url>`).exec(xml)?.[1] ?? '';

test('Slim SEO: each page with its versions, each translation an entry of its own', async () => {
  wp('plugin', 'activate', 'slim-seo', '--quiet');
  wp('rewrite', 'flush');
  try {
    const xml = await (await fetchRetry(`${base}/sitemap-post-type-page.xml`)).text();
    assert.match(xml, /<urlset[^>]*xmlns:xhtml="http:\/\/www.w3.org\/1999\/xhtml"/);
    for (const path of [seed.paths.about_it, seed.paths.about_en]) {
      const links = entry(xml, path);
      assert.match(links, new RegExp(`hreflang="it" href="[^"]*${seed.paths.about_it}"`), path);
      assert.match(links, new RegExp(`hreflang="en" href="[^"]*${seed.paths.about_en}"`), path);
      assert.match(links, new RegExp(`hreflang="x-default" href="[^"]*${seed.paths.about_it}"`), path);
    }
    assert.equal((xml.match(new RegExp(`<loc>[^<]*${seed.paths.about_en}</loc>`, 'g')) ?? []).length, 1, 'the English page once');
    assert.doesNotMatch(entry(xml, '/solo-italiano/'), /xhtml:link/, 'a content in one language has no versions');
  } finally {
    wp('plugin', 'deactivate', 'slim-seo', '--quiet');
    wp('rewrite', 'flush');
  }
});

test('Rank Math: its entries and its urlset get the versions', async () => {
  wp('plugin', 'activate', 'seo-by-rank-math', '--quiet');
  try {
    const out = JSON.parse(wp('eval', `$post = get_post( ${seed.ids.about_en} ); $url = apply_filters( 'rank_math/sitemap/entry', array( 'loc' => get_permalink( $post ) ), 'post', $post ); $xml = apply_filters( 'rank_math/sitemap/url', "\\t<url>\\n\\t\\t<loc>" . $url['loc'] . "</loc>\\n\\t</url>\\n", $url ); $set = apply_filters( 'rank_math/sitemap/page_urlset', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' ); echo wp_json_encode( array( 'xml' => $xml, 'set' => $set ) );`));
    assert.match(out.set, /<urlset xmlns:xhtml="http:\/\/www.w3.org\/1999\/xhtml"/);
    assert.match(out.xml, new RegExp(`<xhtml:link rel="alternate" hreflang="it" href="[^"]*${seed.paths.about_it}"/>[\\s\\S]*hreflang="x-default"[\\s\\S]*</url>`));
  } finally {
    wp('plugin', 'deactivate', 'seo-by-rank-math', '--quiet');
  }
});
