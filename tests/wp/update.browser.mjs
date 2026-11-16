// Translation memory, updates of the changed sentences and independent pages, in a real browser (dev/test.sh).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';

const base = process.env.LB_BASE;
const { chromium } = createRequire(import.meta.url)(process.env.LB_PLAYWRIGHT);
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const p = (text) => `<!-- wp:paragraph --><p>${text}</p><!-- /wp:paragraph -->`;
const page = (slug, title, content) => Number(wp('post', 'create', '--post_type=page', '--post_status=publish', `--post_title=${title}`, `--post_name=${slug}`, `--post_content=${content}`, '--porcelain'));

test('memory, changed sentences, independent pages', async () => {
  const browser = await chromium.launch();
  const created = [];
  try {
    const tab = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await tab.goto(`${base}/wp-login.php`);
    await tab.fill('#user_login', 'admin');
    await tab.fill('#user_pass', process.env.LB_ADMIN_PASSWORD);
    await Promise.all([tab.waitForNavigation(), tab.click('#wp-submit')]);
    const panel = tab.locator('#lb-editor');
    const textarea = panel.locator('#lb-ed-text');
    const openEnglish = async (slug) => {
      await tab.goto(`${base}/${slug}/`);
      await tab.hover('#wp-admin-bar-lingua-bridge-translate');
      await Promise.all([tab.waitForNavigation(), tab.click('#wp-admin-bar-lingua-bridge-translate-en a')]);
      return Number(new URL(tab.url()).searchParams.get('lb_translate'));
    };
    const translateNext = async (original, translation) => {
      await panel.locator('.original', { hasText: original }).waitFor();
      await textarea.fill(translation);
      await textarea.press('Control+Enter');
    };

    // A page translated, then its original changes: only the changed sentences are to redo.
    const it = page('lb-agg', 'LB agg', p('LB uno') + p('LB due'));
    created.push(it);
    const en = await openEnglish('lb-agg');
    created.push(en);
    await panel.getByRole('button', { name: 'Prossimo da tradurre' }).click();
    await translateNext('LB agg', 'LB upd');
    await translateNext('LB uno', 'LB one');
    await translateNext('LB due', 'LB two');
    await panel.getByText('Tutti i testi di questa pagina sono tradotti.').first().waitFor();

    wp('post', 'update', String(it), `--post_content=${p('LB uno')}${p('LB due modificato')}${p('LB tre')}`);
    await tab.goto(`${base}/?lb_translate=${en}`);
    await panel.getByText(/2 frasi nuove o cambiate/).waitFor();
    assert.equal(await panel.locator('.changes .was', { hasText: 'LB due' }).count(), 1, 'the edited sentence shows what it was');
    await Promise.all([tab.waitForNavigation(), panel.getByRole('button', { name: 'Aggiorna la bozza' }).click()]);
    await panel.locator('.progress').waitFor();
    assert.equal(await tab.locator('[data-lb-todo]').count(), 2, 'only the two changed sentences are to translate');
    assert.match(wp('post', 'get', String(en), '--field=post_content'), /<p>LB one<\/p>[\s\S]*<p>LB due modificato<\/p>[\s\S]*<p>LB tre<\/p>/);
    assert.equal(await panel.getByText(/frasi nuove o cambiate/).count(), 0, 'up to date again');

    // A sentence the memory learns afterwards: offered, and applied to the whole page in one click.
    const z = page('lb-quattro', 'LB quattro', p('LB quattro frase'));
    created.push(z);
    const zen = await openEnglish('lb-quattro');
    created.push(zen);
    wp('eval', "LinguaBridge\\Memory::learn('LB quattro frase', 'LB four sentence', 'en');");
    await tab.goto(`${base}/?lb_translate=${zen}`);
    await tab.getByText('LB quattro frase', { exact: true }).click();
    await panel.locator('.memory', { hasText: 'LB four sentence' }).waitFor();
    await Promise.all([tab.waitForNavigation(), panel.getByRole('button', { name: /Applica la memoria \(1\)/ }).click()]);
    await tab.locator('p', { hasText: /^LB four sentence$/ }).waitFor();

    // A different page for this language: blank, independent, built in its own editor.
    const w = page('lb-w', 'LB W', p('LB pagina W'));
    created.push(w);
    await tab.goto(`${base}/wp-admin/admin.php?page=lingua-bridge-create&source_id=${w}&target_language=en&lb_mode=blank`);
    assert.equal(await tab.isChecked('input[name="lb_mode"][value="blank"]'), true);
    await Promise.all([tab.waitForNavigation(), tab.getByRole('button', { name: 'Crea bozza' }).click()]);
    const wen = Number(new URL(tab.url()).searchParams.get('post'));
    created.push(wen);
    assert.equal(wp('post', 'get', String(wen), '--field=post_content'), '');
    assert.equal(wp('post', 'meta', 'get', String(wen), '_lingua_bridge_mode'), 'independent');
  } finally {
    await browser.close();
    for (const id of created.filter(Boolean).reverse()) wp('eval', `LinguaBridge\\Storage::delete(${id}); wp_delete_post(${id}, true);`);
    wp('eval', "global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->prefix}lingua_bridge_memory WHERE source LIKE 'LB %'\");");
  }
});
