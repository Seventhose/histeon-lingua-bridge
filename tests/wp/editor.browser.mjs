// The visual editor on the site, in a real browser (dev/test.sh with Playwright): from the admin bar to the
// English page, a text of the content, a text of the theme, an image's alt, then a new translation created,
// translated and published without leaving the site.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fetchRetry } from './fetch.mjs';

const base = process.env.LB_BASE;
const seed = JSON.parse(readFileSync(process.env.LB_SEED, 'utf8'));
const { chromium } = createRequire(import.meta.url)(process.env.LB_PLAYWRIGHT);
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();

test('translate a page where it is: content, theme texts, alt, a new translation published', async () => {
  const browser = await chromium.launch();
  const created = [];
  try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await page.goto(`${base}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', process.env.LB_ADMIN_PASSWORD);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

    // C10: an SEO description, for the panel «Meta della pagina».
    wp('post', 'meta', 'update', String(seed.ids.about_it), 'rank_math_description', 'Descrizione SEO italiana');
    wp('post', 'meta', 'update', String(seed.ids.about_en), 'rank_math_description', 'Descrizione SEO italiana');

    // From the original page, the admin bar opens the English page in translation mode.
    await page.goto(`${base}${seed.paths.about_it}`);
    await page.hover('#wp-admin-bar-lingua-bridge-translate');
    await Promise.all([page.waitForNavigation(), page.click('#wp-admin-bar-lingua-bridge-translate-en a')]);
    assert.match(page.url(), new RegExp(`lb_translate=${seed.ids.about_en}`));
    const panel = page.locator('#lb-editor');
    await panel.getByRole('dialog').waitFor();
    const say = panel.getByRole('status');

    // C10: the SEO texts are not on the page: their own panel, the original beside each.
    const seo = panel.locator('details.seo');
    await seo.locator('summary').click();
    assert.match(await seo.textContent(), /Descrizione SEO italiana/);
    await seo.locator('textarea').fill('English SEO description');
    await seo.getByRole('button', { name: 'Salva', exact: true }).click();
    await say.filter({ hasText: 'meta della pagina' }).waitFor();
    assert.equal(wp('post', 'meta', 'get', String(seed.ids.about_en), 'rank_math_description'), 'English SEO description');
    await seo.locator('summary').click();

    // A text of the content.
    await page.getByText('LB-ABOUT-EN', { exact: true }).click();
    await panel.locator('#lb-ed-text').fill('LB-ABOUT-EN edited');
    await panel.getByRole('button', { name: 'Salva', exact: true }).click();
    await say.filter({ hasText: 'Salvato nel contenuto' }).waitFor();
    await page.locator('p').filter({ hasText: /^LB-ABOUT-EN edited$/ }).waitFor();
    assert.match(wp('post', 'get', String(seed.ids.about_en), '--field=post_content'), /<p>LB-ABOUT-EN edited<\/p>/);

    // A text of the theme: a string, on every English page.
    await page.locator('p.lb-fixed').click();
    await panel.locator('#lb-ed-text').fill('Fixed text edited');
    await panel.getByRole('button', { name: 'Salva', exact: true }).click();
    await say.filter({ hasText: 'testo del sito' }).waitFor();
    const elsewhere = await (await fetchRetry(`${base}${seed.paths.post_en}`)).text();
    assert.match(elsewhere, /<p class="lb-fixed">Fixed text edited<\/p>/);

    // An image's alt.
    await page.locator('img.lb-fixed-img').click({ force: true });
    const alt = panel.locator('input[aria-label="alt"]');
    await alt.fill('Logo of the site');
    await alt.locator('xpath=following-sibling::button').click();
    await say.filter({ hasText: 'testo del sito' }).waitFor();
    assert.equal(await page.locator('img.lb-fixed-img').getAttribute('alt'), 'Logo of the site');

    // The memory may know these sentences from an earlier run: forgotten, so the page starts untranslated.
    const forget = () => wp('eval', "global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->prefix}lingua_bridge_memory WHERE source IN ('Ciao mondo', 'Marchio LB', 'LB pagina ux', 'LB solo italiano')\");");
    forget();
    // One click from the site: a page without English becomes a draft and opens here, its texts marked,
    // counted, reached one after the other with the original in view.
    const ux = Number(wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=LB pagina ux', '--post_name=lb-pagina-ux', '--post_content=<!-- wp:paragraph --><p>Ciao mondo</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Marchio LB</p><!-- /wp:paragraph -->', '--porcelain'));
    created.push(ux);
    await page.goto(`${base}/lb-pagina-ux/`);
    await page.hover('#wp-admin-bar-lingua-bridge-translate');
    await Promise.all([page.waitForNavigation(), page.click('#wp-admin-bar-lingua-bridge-translate-en a')]);
    const id = Number(new URL(page.url()).searchParams.get('lb_translate'));
    assert.ok(id > 0, `no detour through «Crea traduzione»: ${page.url()}`);
    created.push(id);
    await panel.getByText('Bozza creata').waitFor();
    assert.ok((await page.locator('[data-lb-todo]').count()) >= 3, 'title and both paragraphs are marked');
    const progressText = await panel.locator('.progress').textContent();
    assert.match(progressText, /\d+ di \d+ testi tradotti/);
    const next = panel.getByRole('button', { name: 'Prossimo da tradurre' });
    const textarea = panel.locator('#lb-ed-text');
    await next.click();
    await panel.locator('.original', { hasText: 'LB pagina ux' }).waitFor();
    await textarea.fill('LB UX page');
    await textarea.press('Control+Enter');
    await panel.locator('.original', { hasText: 'Ciao mondo' }).waitFor();
    await textarea.fill('Hello world');
    await textarea.press('Control+Enter');
    await panel.locator('.original', { hasText: 'Marchio LB' }).waitFor();
    await panel.getByRole('button', { name: 'Lascia così' }).click();
    await panel.getByText('Tutti i testi di questa pagina sono tradotti.').first().waitFor();
    assert.equal(await page.locator('[data-lb-todo]').count(), 0);
    assert.equal(wp('post', 'get', String(id), '--field=post_title'), 'LB UX page');
    assert.match(wp('post', 'get', String(id), '--field=post_content'), /<p>Hello world<\/p>[\s\S]*<p>Marchio LB<\/p>/);
    await panel.getByRole('button', { name: 'Pubblica la traduzione' }).click();
    await say.filter({ hasText: 'Traduzione pubblicata.' }).waitFor();
    assert.equal(wp('post', 'get', String(id), '--field=post_status'), 'publish');

    // The block editor: «Lingue» in the document panel, one click to translate on the site.
    await page.goto(`${base}/wp-admin/post.php?post=${seed.ids.about_it}&action=edit`);
    await page.waitForFunction(() => window.wp && window.wp.data && window.wp.data.select('core/editor'));
    await page.evaluate(() => { try { window.wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false); } catch (e) {} });
    const languages = page.locator('.lingua-bridge-languages');
    if (!(await languages.isVisible())) await page.evaluate(() => window.wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/document'));
    await languages.getByRole('button', { name: 'Lingue' }).click({ trial: true }).catch(() => undefined);
    await languages.waitFor();
    const translateLink = languages.getByRole('link', { name: 'Traduci sul sito' });
    if (!(await translateLink.isVisible())) await languages.getByRole('button', { name: 'Lingue' }).click();
    assert.match(await translateLink.getAttribute('href'), new RegExp(`lb_translate=${seed.ids.about_en}`));

    // Translation mode is only for whoever can edit: a visitor gets the normal home page.
    const anonymous = await (await fetchRetry(`${base}/?lb_translate=${seed.ids.about_en}`)).text();
    assert.doesNotMatch(anonymous, /lingua-bridge-editor/);
  } finally {
    await browser.close();
    for (const id of created) wp('post', 'delete', String(id), '--force');
    wp('eval', "global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->prefix}lingua_bridge_memory WHERE source IN ('Ciao mondo', 'Marchio LB', 'LB pagina ux', 'LB solo italiano')\");");
    // Back as the seed made them: the other suites (any order) read these texts.
    wp('eval', `
      $p = get_post(${seed.ids.about_en});
      wp_update_post(wp_slash(array('ID' => $p->ID, 'post_content' => str_replace('LB-ABOUT-EN edited', 'LB-ABOUT-EN', $p->post_content))));
      global $wpdb;
      foreach (array('Testo fisso del tema' => 'Fixed theme text', 'Logo del sito' => 'Site logo') as $source => $translation) {
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . LinguaBridge\\Strings::table() . " WHERE language = 'en' AND source_hash = %s", LinguaBridge\\Strings::key('html', $source)));
        LinguaBridge\\Strings::save($id, $translation, 'published');
      }
    `);
  }
});
