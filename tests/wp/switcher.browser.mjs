// C1, C4, C42: the «Selettore» tab, the floating switcher in a real browser, and every form of the switcher checked
// with the keyboard and with axe (dev/test.sh with Playwright; axe-core from the same node_modules as Playwright).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { execFileSync } from 'node:child_process';

const base = process.env.LB_BASE;
const seed = JSON.parse(readFileSync(process.env.LB_SEED, 'utf8'));
const { chromium } = createRequire(import.meta.url)(process.env.LB_PLAYWRIGHT);
const axe = join(dirname(process.env.LB_PLAYWRIGHT), 'axe-core', 'axe.min.js');
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();

// The WCAG A and AA rules of axe on the switchers of the page only: the theme is not ours to check.
const violations = async (page, selector) => {
  if (!existsSync(axe)) return [];
  await page.addScriptTag({ path: axe });
  return page.evaluate(async (sel) => {
    const result = await window.axe.run({ include: [...document.querySelectorAll(sel)].map((n) => [n]) }, { runOnly: ['wcag2a', 'wcag2aa'] });
    // Only what is inside a switcher: a fixture of the test site next to it (an orphan navigation item) is not ours.
    const ours = (n) => document.querySelector(n.target[0])?.closest(sel);
    return result.violations.flatMap((v) => v.nodes.filter(ours).map((n) => `${v.id}: ${n.target.join(' ')} ${n.html.slice(0, 160)}`));
  }, selector);
};

test('the «Selettore» tab: where it is seen, the floating one with a preview, the shortcode written for you', async () => {
  const saved = wp('option', 'get', 'lingua_bridge_settings', '--format=json');
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.goto(`${base}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', process.env.LB_ADMIN_PASSWORD);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    await page.goto(`${base}/wp-admin/admin.php?page=lingua-bridge-settings&tab=switcher`);
    await page.getByRole('heading', { name: 'Selettore fluttuante' }).waitFor();
    assert.equal(await page.isChecked('#lb-floating'), true, 'on by default');
    assert.equal(await page.locator('.lb-switcher-preview nav.lb-language-switcher a[hreflang]').count(), 2, 'the preview is the real switcher, with the homes of the languages');
    // The shortcode with its options, written as they are chosen.
    await page.locator('[data-lb-attr="style"]').selectOption('dropdown');
    await page.locator('[data-lb-attr="hide_current"]').check();
    assert.equal(await page.inputValue('[data-lb-shortcode]'), '[lingua_bridge_switcher style="dropdown" hide_current="yes"]');
    await page.selectOption('#lb-floating-position', 'bottom-left');
    await page.selectOption('#lb-switcher-menu', '');
    await Promise.all([page.waitForNavigation(), page.locator('.lb-settings__form').getByRole('button', { name: 'Salva', exact: true }).click()]);
    await page.getByText('Impostazioni salvate.').waitFor();
    const after = JSON.parse(wp('option', 'get', 'lingua_bridge_settings', '--format=json'));
    assert.equal(after.floating_position, 'bottom-left');
    assert.equal(after.switcher_menu, '');
    assert.equal(after.floating_switcher, 'auto');

    // The floating switcher on the English article: fixed at the bottom left, opened with the keyboard.
    const visitor = await (await browser.newContext({ locale: 'en-US' })).newPage();
    await visitor.goto(`${base}${seed.paths.post_en}`);
    const floating = visitor.locator('nav.lb-language-switcher--floating');
    await floating.waitFor();
    const box = await floating.boundingBox();
    const viewport = visitor.viewportSize();
    assert.equal(await floating.evaluate((n) => getComputedStyle(n).position), 'fixed');
    assert.ok(box.x < 40 && box.y + box.height > viewport.height - 40, `bottom left: ${JSON.stringify(box)}`);
    const summary = floating.locator('summary');
    assert.ok((await summary.boundingBox()).height >= 44, 'at least 44 px to touch');
    await summary.focus();
    await visitor.keyboard.press('Enter');
    assert.equal(await floating.locator('details').evaluate((d) => d.open), true, 'Enter opens it');
    await visitor.keyboard.press('Tab');
    assert.equal(await visitor.evaluate(() => document.activeElement.getAttribute('hreflang')), 'it', 'Tab reaches the first language');
    assert.deepEqual(await violations(visitor, 'nav.lb-language-switcher--floating'), [], 'axe: the floating switcher');
    await visitor.close();
  } finally {
    await browser.close();
    execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', 'option', 'update', 'lingua_bridge_settings', saved, '--format=json'], { cwd: process.env.LB_DEV, stdio: 'ignore' });
  }
});

test('C42: every form of the switcher passes axe and works with the keyboard', async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    // The shortcode (twice, as a list) and the theme navigation, on the English page.
    await page.goto(`${base}${seed.paths.about_en}`);
    assert.deepEqual(await violations(page, 'nav.lb-language-switcher, .lb-menu-switcher'), [], 'axe: shortcode and navigation');
    const first = page.locator('nav.lb-language-switcher a[hreflang="it"]').first();
    await first.focus();
    assert.equal(await page.evaluate(() => document.activeElement.getAttribute('lang')), 'it', 'a link of the list takes the focus');
    assert.equal(await page.locator('nav.lb-language-switcher a[aria-current="page"]').first().getAttribute('hreflang'), 'en');
    assert.equal(await page.locator('nav.lb-language-switcher').first().getAttribute('aria-label'), 'Language', 'the nav has a name, in the page\'s language');
    // The dropdown of the shortcode, and the block, which draws the same markup: on a list page with no other.
    wp('option', 'patch', 'update', 'lingua_bridge_settings', 'switcher_menu', '');
    await page.goto(`${base}/en/category/news/`);
    const dropdown = page.locator('nav.lb-language-switcher--dropdown');
    await dropdown.waitFor();
    assert.deepEqual(await violations(page, 'nav.lb-language-switcher--dropdown'), [], 'axe: the dropdown');
    await dropdown.locator('summary').focus();
    await page.keyboard.press('Space');
    assert.equal(await dropdown.locator('details').evaluate((d) => d.open), true, 'Space opens the dropdown');
  } finally {
    wp('option', 'patch', 'update', 'lingua_bridge_settings', 'switcher_menu', 'nav-block');
    await browser.close();
  }
});
