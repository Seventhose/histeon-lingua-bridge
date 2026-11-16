// The AI connector end to end, in a real browser (dev/test.sh, when Playwright is available — the Histeon checkout
// has it; LB_PLAYWRIGHT overrides): log in, open «Traduci con l'IA», link the tab, drive it as the agent, reload,
// unlink. Uses the dev site's own generated admin account (dev/.env), never a real one.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { startBridge, DEFAULT_PORT } from '../../connector/lingua-bridge-connector.mjs';
import { fetchRetry } from './fetch.mjs';

const base = process.env.LB_BASE;
const password = process.env.LB_ADMIN_PASSWORD;
const playwrightPath = process.env.LB_PLAYWRIGHT;
const { chromium } = createRequire(import.meta.url)(playwrightPath);
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();

test('an agent translates through a linked admin tab, as drafts only', async () => {
  const browser = await chromium.launch();
  let bridge = null;
  const created = [];
  const agent = async (method, args = {}) => {
    const r = await fetchRetry(`http://127.0.0.1:${bridge.port}/control/call`, { method: 'POST', headers: { Authorization: `Bearer ${bridge.token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ method, args }) });
    return { status: r.status, ...(await r.json()) };
  };
  const tabs = async () => (await (await fetchRetry(`http://127.0.0.1:${bridge.port}/control/tabs`, { headers: { Authorization: `Bearer ${bridge.token}` } })).json()).tabs;
  try {
    const page = await browser.newPage();
    await page.goto(`${base}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', password);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    await page.goto(`${base}/wp-admin/admin.php?page=lingua-bridge-ai`);
    // What the agent downloads is what the page says: the same SHA-256.
    const shown = (await page.locator('.lb-ai code').last().textContent()).trim();
    const served = Buffer.from(await (await fetchRetry(await page.getByRole('link', { name: 'Scarica il connettore' }).getAttribute('href'))).arrayBuffer());
    assert.equal(createHash('sha256').update(served).digest('hex'), shown);
    assert.match(served.toString('utf8', 0, 200), /Lingua Bridge Connector/);

    // The pairing is in the instructions the user copies: the connector starts with exactly that.
    const instructions = await page.locator('[data-lb-instructions]').textContent();
    const origin = /--origin (\S+)/.exec(instructions)[1];
    const pair = /LINGUA_BRIDGE_PAIR='([^']+)'/.exec(instructions)[1];
    assert.doesNotMatch(instructions, /--pair/, 'the secret is never a command-line argument');
    assert.equal(origin, new URL(base).origin);

    // A program on the port that does not know the pairing is refused by the tab.
    const { createServer } = await import('node:http');
    const impostor = createServer((req, res) => {
      res.writeHead(200, { 'Access-Control-Allow-Origin': origin, 'Access-Control-Allow-Headers': 'Content-Type', 'Content-Type': 'application/json' });
      res.end(req.method === 'OPTIONS' ? '' : JSON.stringify({ tab: 't1', key: 'k', proof: 'falso' }));
    });
    await new Promise((r) => impostor.listen(DEFAULT_PORT, '127.0.0.1', r));
    await page.getByRole('button', { name: 'Collega questa scheda' }).click();
    await page.getByText('non conosce l’accoppiamento').waitFor();
    await new Promise((r) => impostor.close(r));

    bridge = await startBridge({ port: DEFAULT_PORT, dir: mkdtempSync(join(tmpdir(), 'lb-e2e-')), origin, pair, log: () => {} });
    await page.getByRole('button', { name: 'Collega questa scheda' }).click();
    await page.waitForSelector('.lb-ai[data-state="linked"]');
    assert.equal((await tabs()).length, 1);

    const status = await agent('status');
    assert.equal(status.status, 200);
    assert.equal(status.result.site, 'Lingua Bridge Dev');
    const work = await agent('listWork', { language: 'en', state: 'missing' });
    const onlyIt = work.result.items.find((i) => i.title === 'LB solo italiano');
    assert.ok(onlyIt, 'the untranslated post is in the work list');
    const source = await agent('getSource', { post_id: onlyIt.source_id, target_language: 'en' });
    assert.equal(source.result.existing.state, 'missing');
    const saved = await agent('saveDraft', { source_post_id: onlyIt.source_id, language: 'en', title: 'LB English only (e2e)', content: '<!-- wp:paragraph --><p>English</p><!-- /wp:paragraph -->' });
    assert.equal(saved.status, 200);
    created.push(saved.result.post_id);
    assert.equal(wp('post', 'get', String(saved.result.post_id), '--field=post_status'), 'draft');
    await page.getByText(`bozza #${saved.result.post_id} salvata`).waitFor();

    // A WordPress refusal reaches the agent as an error, and the log says so.
    const refused = await agent('getSource', { post_id: 999999, target_language: 'en' });
    assert.equal(refused.status, 422);
    await page.locator('.lb-ai__log .is-error').first().waitFor();

    // A reload keeps the link (the choice holds for the tab); unlinking ends it.
    await page.reload();
    await page.waitForSelector('.lb-ai[data-state="linked"]');
    const after = await agent('status');
    assert.equal(after.status, 200, JSON.stringify({ after, tabs: await tabs() }));
    await page.getByRole('button', { name: 'Scollega' }).click();
    await page.waitForSelector('.lb-ai[data-state="off"]');
    await new Promise((r) => setTimeout(r, 300));
    assert.equal((await tabs()).length, 0);
    assert.equal((await agent('status')).status, 409);
  } finally {
    await browser.close();
    await bridge?.close();
    for (const id of created) wp('post', 'delete', String(id), '--force');
  }
});
