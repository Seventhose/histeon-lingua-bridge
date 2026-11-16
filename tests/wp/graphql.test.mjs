// The language in WPGraphQL (1.13.0), with WPGraphQL in dev/.third-party (dev/test.sh):
// the field `lingua` on the translatable types and `where: { lingua }` on the connections, as in the REST API.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fetchRetry } from './fetch.mjs';

const base = process.env.LB_BASE;
const seed = JSON.parse(readFileSync(process.env.LB_SEED, 'utf8'));
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const query = async (q) => {
  const r = await fetchRetry(`${base}/graphql`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ query: q }) });
  const body = await r.json();
  assert.equal(body.errors, undefined, JSON.stringify(body.errors));
  return body.data;
};

before(() => { wp('plugin', 'activate', 'wp-graphql', '--quiet'); });
after(() => { wp('plugin', 'deactivate', 'wp-graphql', '--quiet'); });

test('C33: each content says its language and its versions', async () => {
  const data = await query(`{ page(id: ${seed.ids.about_it}, idType: DATABASE_ID) { lingua { code original group versions { code databaseId link } } } }`);
  const lingua = data.page.lingua;
  assert.equal(lingua.code, 'it');
  assert.equal(lingua.original, true);
  assert.ok(lingua.group);
  const en = lingua.versions.find((v) => v.code === 'en');
  assert.equal(en?.databaseId, seed.ids.about_en);
  assert.match(en.link, new RegExp(`${seed.paths.about_en}$`));
  const english = await query(`{ page(id: ${seed.ids.about_en}, idType: DATABASE_ID) { lingua { code original } } }`);
  assert.deepEqual(english.page.lingua, { code: 'en', original: false });
});

test('C33: the connections take where: { lingua }', async () => {
  const ids = async (field, lingua) => (await query(`{ ${field}(first: 100, where: { lingua: "${lingua}" }) { nodes { databaseId lingua { code } } } }`))[field].nodes;
  const en = await ids('posts', 'en');
  assert.ok(en.some((n) => n.databaseId === seed.ids.post_en), 'the English post');
  assert.ok(en.every((n) => n.lingua.code === 'en'), 'English only');
  const it = await ids('posts', 'it');
  assert.ok(it.some((n) => n.databaseId === seed.ids.post_it));
  assert.ok(!it.some((n) => n.databaseId === seed.ids.post_en), 'no English post among the Italian ones');
  const originals = await ids('pages', 'originals');
  assert.ok(originals.some((n) => n.databaseId === seed.ids.about_it));
  assert.ok(!originals.some((n) => n.databaseId === seed.ids.about_en), 'a translation is no original');
  const all = (await query('{ posts(first: 100) { nodes { databaseId } } }')).posts.nodes;
  assert.ok(all.some((n) => n.databaseId === seed.ids.post_en) && all.some((n) => n.databaseId === seed.ids.post_it), 'without the argument, every language');
});
