// What a static copy (Static Bridge) and search engines need to find every language: the lists of each language
// are named in a sitemap, and each one answers in its language (dev/test.sh).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fetchRetry } from './fetch.mjs';

const base = process.env.LB_BASE;
const text = async (path) => {
  const r = await fetchRetry(base + path, { redirect: 'manual' });
  return { status: r.status, body: await r.text() };
};
const locs = (xml) => [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => new URL(m[1]).pathname);

test('the sitemap index names the lists of each language', async () => {
  const index = await text('/wp-sitemap.xml');
  assert.equal(index.status, 200);
  assert.ok(locs(index.body).includes('/wp-sitemap-lingua-1.xml'), 'lingua sitemap in the index');
  const posts = await text('/wp-sitemap-posts-post-1.xml');
  assert.ok(locs(posts.body).includes('/en/english-article/'), 'English posts are in the post sitemap with their prefix');
});

test('every list in the language sitemap answers in that language, with its content only', async () => {
  const lingua = await text('/wp-sitemap-lingua-1.xml');
  assert.equal(lingua.status, 200);
  const paths = locs(lingua.body);
  assert.ok(paths.includes('/en/category/news/'), paths.join(' '));
  assert.ok(paths.includes('/en/eventi/'), paths.join(' '));
  for (const path of paths) {
    const page = await text(path);
    assert.equal(page.status, 200, path);
    assert.match(page.body, /<html[^>]*\blang="en"/, path);
  }
  const news = await text('/en/category/news/');
  assert.match(news.body, /LB English article/);
  assert.doesNotMatch(news.body, /LB articolo italiano|LB solo italiano/);
});

test('C3: the lists of the language sitemap name their versions (xhtml:link)', async () => {
  const lingua = await text('/wp-sitemap-lingua-1.xml');
  assert.match(lingua.body, /<urlset[^>]*xmlns:xhtml="http:\/\/www.w3.org\/1999\/xhtml"/);
  const news = lingua.body.match(/<url><loc>[^<]*\/en\/category\/news\/<\/loc>(.*?)<\/url>/s);
  assert.ok(news, 'the English list is in the sitemap');
  assert.match(news[1], /<xhtml:link rel="alternate" hreflang="it" href="[^"]*\/category\/news\/"\/>/);
  assert.match(news[1], /<xhtml:link rel="alternate" hreflang="en" href="[^"]*\/en\/category\/news\/"\/>/);
  assert.match(news[1], /<xhtml:link rel="alternate" hreflang="x-default" href="[^"]*\/category\/news\/"\/>/);
});
