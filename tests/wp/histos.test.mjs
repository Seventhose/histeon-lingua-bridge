// Histeon + Lingua Bridge on the public site (dev/test.sh when Histeon is mounted).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fetchRetry } from './fetch.mjs';

const base = process.env.LB_BASE;
const seed = JSON.parse(readFileSync(process.env.LB_HISTOS_SEED_FILE, 'utf8'));
const html = async (path) => (await fetchRetry(base + path)).text();

test('a Histeon page in English shows the English component, English lists and data.lingua', async () => {
  const en = await html(seed.paths.page_en);
  assert.match(en, /LB-COMPONENT-HELLO/);
  assert.doesNotMatch(en, /LB-COMPONENTE-CIAO/);
  assert.match(en, /LINGUA:en\|2/);
  assert.match(en, /VOCE:LB English article/);
  assert.doesNotMatch(en, /VOCE:LB articolo italiano/);
});

test('the Italian page stays Italian', async () => {
  const it = await html(seed.paths.page_it);
  assert.match(it, /LB-COMPONENTE-CIAO/);
  assert.match(it, /LINGUA:it\|2/);
  assert.match(it, /VOCE:LB articolo italiano/);
  assert.doesNotMatch(it, /VOCE:LB English article/);
});
