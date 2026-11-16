// XLIFF in a real browser (dev/test.sh with Playwright): download the file of what English misses, translate it
// as an agency would, upload it, read the result; the drafts it made are removed afterwards.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';

const base = process.env.LB_BASE;
const { chromium } = createRequire(import.meta.url)(process.env.LB_PLAYWRIGHT);
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();

test('XLIFF: download, translate, upload, a draft per content', async () => {
  const browser = await chromium.launch();
  const drafts = [];
  try {
    const page = await browser.newPage({ acceptDownloads: true });
    await page.goto(`${base}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', process.env.LB_ADMIN_PASSWORD);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    await page.goto(`${base}/wp-admin/admin.php?page=lingua-bridge-xliff`);
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Scarica il file XLIFF' }).click()]);
    assert.match(download.suggestedFilename(), /^lingua-bridge-en-\d{8}-\d{6}\.xlf$/);
    const xml = readFileSync(await download.path(), 'utf8');
    assert.match(xml, /<file original="lingua-bridge:post:\d+" source-language="it" target-language="en"/);
    assert.match(xml, /<source>LB solo italiano<\/source>/);

    // The agency: a target for every source.
    const translated = xml.replace(/<source>([^<]*)<\/source>(\s*)(?!<target)/g, (all, text, space) => `<source>${text}</source>${space}<target>EN ${text}</target>${space}`);
    const file = join(mkdtempSync(join(tmpdir(), 'lb-xliff-')), 'translated.xlf');
    writeFileSync(file, translated);
    await page.setInputFiles('#lb-xliff-file', file);
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Importa', exact: true }).click()]);
    const row = page.locator('.lb-xliff__results tr', { hasText: 'LB solo italiano' });
    await row.getByText('Bozza salvata.').waitFor();
    for (const href of await page.locator('.lb-xliff__results a', { hasText: 'Apri la bozza' }).evaluateAll((links) => links.map((a) => a.href))) {
      drafts.push(Number(new URL(href).searchParams.get('post')));
    }
    const only = Number(new URL(await row.locator('a', { hasText: 'Apri la bozza' }).getAttribute('href')).searchParams.get('post'));
    assert.equal(wp('post', 'get', String(only), '--field=post_title'), 'EN LB solo italiano');
    assert.equal(wp('post', 'get', String(only), '--field=post_status'), 'draft');
  } finally {
    await browser.close();
    for (const id of drafts) wp('eval', `LinguaBridge\\Storage::delete(${id}); wp_delete_post(${id}, true);`);
  }
});
