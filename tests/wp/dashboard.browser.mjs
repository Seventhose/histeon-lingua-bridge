// The dashboard as a to-do list, and automatic translation with the owner's key (the fake service of the dev
// fixture), in a real browser (dev/test.sh).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';

const base = process.env.LB_BASE;
const { chromium } = createRequire(import.meta.url)(process.env.LB_PLAYWRIGHT);
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const p = (text) => `<!-- wp:paragraph --><p>${text}</p><!-- /wp:paragraph -->`;
const page = (slug, title, content) => Number(wp('post', 'create', '--post_type=page', '--post_status=publish', `--post_title=${title}`, `--post_name=${slug}`, `--post_content=${content}`, '--porcelain'));
const englishOf = (id) => Number(wp('eval', `$r = LinguaBridge\\Storage::find(${id}); $m = $r ? LinguaBridge\\Storage::find_group_language($r['group_uuid'], 'en') : null; echo $m ? $m['object_id'] : 0;`));

test('dashboard: to-do filters, group actions, progress, automatic translation', async () => {
  // A new site has no machine translation settings yet: nothing to restore then, the option is removed.
  const saved = wp('eval', "echo wp_json_encode(get_option('lingua_bridge_mt', null));");
  wp('eval', "LinguaBridge\\MachineTranslation::save_settings(array('provider' => 'openai', 'endpoint' => 'http://wordpress/wp-json/lb-mock/v1', 'model' => 'mock', 'key' => 'lb-test-key'));");
  const browser = await chromium.launch();
  const created = [];
  try {
    const one = page('lb-dash', 'LB dash', p('LB dash uno') + p('LB dash due'));
    const two = page('lb-dash-due', 'LB dash 2', p('LB dash tre'));
    created.push(one, two);
    const tab = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await tab.goto(`${base}/wp-login.php`);
    await tab.fill('#user_login', 'admin');
    await tab.fill('#user_pass', process.env.LB_ADMIN_PASSWORD);
    await Promise.all([tab.waitForNavigation(), tab.click('#wp-submit')]);

    // What English misses, then translated as a group.
    await tab.goto(`${base}/wp-admin/admin.php?page=lingua-bridge&language=en&state=missing`);
    await tab.getByRole('link', { name: 'LB dash', exact: true }).waitFor();
    assert.equal(await tab.getByRole('link', { name: 'LB articolo italiano' }).count(), 0, 'translated contents are not in «da tradurre»');
    await tab.getByRole('checkbox', { name: 'LB dash', exact: true }).check();
    await tab.selectOption('#lb-bulk-action', 'machine');
    await tab.selectOption('#lb-bulk-language', 'en');
    await Promise.all([tab.waitForNavigation(), tab.getByRole('button', { name: 'Applica' }).click()]);
    // The service works in the queue (F2): the dashboard asks for steps while it is open, then shows it done.
    await tab.getByText(/In coda: 1 contenuti/).waitFor();
    await tab.getByText(/In attesa: 0 · fatti: 1 · non riusciti: 0/).waitFor({ timeout: 60_000 });
    const en = englishOf(one);
    created.push(en);
    assert.equal(wp('post', 'get', String(en), '--field=post_status'), 'draft');
    assert.equal(wp('post', 'get', String(en), '--field=post_title'), '[en] LB dash');
    assert.match(wp('post', 'get', String(en), '--field=post_content'), /\[en\] LB dash uno[\s\S]*\[en\] LB dash due/);

    // Its badge: a draft, 100% translated, opening on the site.
    await tab.goto(`${base}/wp-admin/admin.php?page=lingua-bridge`);
    const badge = tab.locator('tr', { has: tab.getByRole('link', { name: 'LB dash', exact: true }) }).locator('a.lb-state', { hasText: /^EN ·/ });
    assert.match(await badge.textContent(), /100%/);
    assert.match(await badge.getAttribute('href'), new RegExp(`lb_translate=${en}`));

    // One click from the dashboard, then «Traduci automaticamente» on the page.
    const row = tab.locator('tr', { has: tab.getByRole('link', { name: 'LB dash 2', exact: true }) });
    await Promise.all([tab.waitForNavigation(), row.getByRole('link', { name: /EN · Crea e traduci/ }).click()]);
    const en2 = Number(new URL(tab.url()).searchParams.get('lb_translate'));
    created.push(en2);
    const panel = tab.locator('#lb-editor');
    await tab.getByText('LB dash tre', { exact: true }).click();
    await panel.getByRole('button', { name: 'Proposta automatica' }).click();
    await tab.waitForFunction(() => document.querySelector('#lb-editor').shadowRoot.querySelector('textarea').value === '[en] LB dash tre');
    assert.doesNotMatch(wp('post', 'get', String(en2), '--field=post_content'), /\[en\]/, 'a proposal only fills the field: nothing saved');
    await Promise.all([tab.waitForNavigation(), panel.getByRole('button', { name: 'Traduci automaticamente' }).click()]);
    await panel.getByText('Tutti i testi di questa pagina sono tradotti.').first().waitFor();
    assert.match(wp('post', 'get', String(en2), '--field=post_content'), /\[en\] LB dash tre/);
  } finally {
    await browser.close();
    for (const id of created.filter(Boolean).reverse()) wp('eval', `LinguaBridge\\Storage::delete(${id}); wp_delete_post(${id}, true);`);
    if (saved === 'null') wp('option', 'delete', 'lingua_bridge_mt');
    else execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', 'option', 'update', 'lingua_bridge_mt', saved, '--format=json'], { cwd: process.env.LB_DEV, stdio: 'ignore' });
  }
});
