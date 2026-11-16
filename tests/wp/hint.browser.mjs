// N8, C2: the language of the visitor, in a real browser (dev/test.sh with Playwright). Suggest (the default): an
// English browser on the Italian page sees «English →», never a redirect; closed, the bar stays closed. Redirect: on
// the first page of a visit, the original page takes an English browser to the English one; the choice made with a
// switcher always wins; never for automated browsers, on another page of the visit or from a page in a language.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';

const base = process.env.LB_BASE;
const seed = JSON.parse(readFileSync(process.env.LB_SEED, 'utf8'));
const { chromium } = createRequire(import.meta.url)(process.env.LB_PLAYWRIGHT);
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
// Puts the setting back to its default; already there is fine.
const forget = (key) => { try { wp('option', 'patch', 'delete', 'lingua_bridge_settings', key); } catch { /* not set */ } };
const path = (page) => new URL(page.url()).pathname;
// A person's browser: not automated, not headless (the script leaves those alone).
const person = async (browser, locale) => {
  const context = await browser.newContext({ locale, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' });
  await context.addInitScript(() => Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false }));
  return context;
};

test('suggest, the default: a bar towards the version in that language, never a redirect', async () => {
  const browser = await chromium.launch();
  try {
    wp('option', 'patch', 'insert', 'lingua_bridge_settings', 'visitor_language', 'off');
    const off = await (await browser.newContext({ locale: 'en-US' })).newPage();
    await off.goto(`${base}${seed.paths.about_it}`);
    await off.waitForLoadState('load');
    assert.equal(await off.locator('.lb-language-hint').count(), 0, 'off: nothing');
    forget('visitor_language');
    const english = await browser.newContext({ locale: 'en-US' });
    const page = await english.newPage();
    const response = await page.goto(`${base}${seed.paths.about_it}`);
    assert.equal(response.status(), 200, 'no redirect');
    assert.equal(path(page), seed.paths.about_it);
    const link = page.locator('.lb-language-hint a');
    await link.waitFor();
    assert.equal(await link.getAttribute('hreflang'), 'en');
    assert.match(await link.textContent(), /English/);
    assert.equal(new URL(await link.getAttribute('href')).pathname, seed.paths.about_en);
    await page.getByRole('button', { name: 'Close' }).or(page.getByRole('button', { name: 'Chiudi' })).click();
    await page.reload();
    await page.waitForLoadState('load');
    assert.equal(await page.locator('.lb-language-hint').count(), 0, 'closed once, it stays closed');
    const italian = await (await browser.newContext({ locale: 'it-IT' })).newPage();
    await italian.goto(`${base}${seed.paths.about_it}`);
    await italian.waitForLoadState('load');
    assert.equal(await italian.locator('.lb-language-hint').count(), 0, 'nothing for a browser in the page\'s language');
  } finally {
    forget('visitor_language');
    await browser.close();
  }
});

test('redirect: the first page of a visit, from the original, unless the visitor chose', async () => {
  const browser = await chromium.launch();
  wp('option', 'patch', 'insert', 'lingua_bridge_settings', 'visitor_language', 'redirect');
  try {
    // An English browser enters on the Italian page (x-default): it lands on the English one.
    const english = await person(browser, 'en-US');
    const page = await english.newPage();
    await page.goto(`${base}${seed.paths.about_it}`);
    await page.waitForURL(`**${seed.paths.about_en}`);
    assert.equal(path(page), seed.paths.about_en);
    // Another page of the same visit: it stays where the visitor goes.
    await page.goto(`${base}${seed.paths.post_it}`);
    await page.waitForLoadState('load');
    assert.equal(path(page), seed.paths.post_it, 'only the first page of a visit');

    // The choice made with the switcher wins, in every later visit.
    const chooser = await person(browser, 'en-US');
    const first = await chooser.newPage();
    await first.goto(`${base}${seed.paths.about_en}`);
    await Promise.all([first.waitForURL(`**${seed.paths.about_it}`), first.locator('nav.lb-language-switcher a[hreflang="it"]').first().click()]);
    assert.equal(await first.evaluate(() => localStorage.getItem('lingua-bridge-choice')), 'it', 'the choice is kept in the browser');
    const later = await chooser.newPage();
    await later.goto(`${base}${seed.paths.about_it}`);
    await later.waitForLoadState('load');
    assert.equal(path(later), seed.paths.about_it, 'a new visit: the chosen language stays');
    assert.equal(await later.locator('.lb-language-hint').count(), 0, 'and no bar either');

    // A page in a language (here from another site) is never left; a language the site has not: nothing.
    const italian = await person(browser, 'it-IT');
    const linked = await italian.newPage();
    await linked.goto(`${base}${seed.paths.about_en}`, { referer: 'https://example.org/' });
    await linked.waitForLoadState('load');
    assert.equal(path(linked), seed.paths.about_en, 'a link to the English page keeps its language');
    const japanese = await (await person(browser, 'ja-JP')).newPage();
    await japanese.goto(`${base}${seed.paths.about_it}`);
    await japanese.waitForLoadState('load');
    assert.equal(path(japanese), seed.paths.about_it, 'no Japanese version: it stays');

    // An automated browser (navigator.webdriver, as crawlers that run scripts) only gets the bar.
    const robot = await (await browser.newContext({ locale: 'en-US' })).newPage();
    await robot.goto(`${base}${seed.paths.about_it}`);
    await robot.locator('.lb-language-hint').waitFor();
    assert.equal(path(robot), seed.paths.about_it, 'never for an automated browser');

    // C3 made the blog home name its versions: there too the visitor goes to the home of the language.
    wp('option', 'update', 'show_on_front', 'posts');
    const home = await (await person(browser, 'en-GB')).newPage();
    await home.goto(`${base}/`);
    await home.waitForURL(`${base}/en/`);
    assert.equal(path(home), '/en/');
  } finally {
    wp('option', 'update', 'show_on_front', 'page');
    forget('visitor_language');
    await browser.close();
  }
});
