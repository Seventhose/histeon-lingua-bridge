// The settings screen and the setup, in a real browser (dev/test.sh with Playwright): tabs that keep each
// other's values, languages from a list, the glossary as a table, the three steps of the setup.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fetchRetry } from './fetch.mjs';

const base = process.env.LB_BASE;
const { chromium } = createRequire(import.meta.url)(process.env.LB_PLAYWRIGHT);
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();

test('settings in tabs, languages from a list, glossary as a table, setup in three steps', async () => {
  const saved = wp('option', 'get', 'lingua_bridge_settings', '--format=json');
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.goto(`${base}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', process.env.LB_ADMIN_PASSWORD);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    const settings = (tab) => page.goto(`${base}/wp-admin/admin.php?page=lingua-bridge-settings&tab=${tab}`);
    const save = () => Promise.all([page.waitForNavigation(), page.locator('.lb-settings__form').getByRole('button', { name: 'Salva', exact: true }).click()]);

    // Languages: add French from the list, rename English, then remove French.
    await settings('languages');
    await page.selectOption('#lb-add-language', 'fr');
    await page.fill('#lb-lang-en', 'Inglese');
    await save();
    await page.getByText('Impostazioni salvate.').waitFor();
    assert.equal(await page.inputValue('#lb-lang-fr'), 'Français');
    assert.equal(await page.inputValue('#lb-lang-en'), 'Inglese');
    assert.equal(await page.locator('input[name="lb_languages[en][remove]"]').count(), 0, 'English has contents: no remove box');
    await page.check('input[name="lb_languages[fr][remove]"]');
    await page.fill('#lb-lang-en', 'English');
    await save();
    assert.equal(await page.locator('#lb-lang-fr').count(), 0);

    // AI: a glossary row; the URL tab keeps its value.
    await settings('ai');
    await page.locator('input[name="translation_glossary[0][source_term]"]').fill('Chi siamo');
    await page.locator('select[name="translation_glossary[0][target_language]"]').selectOption('en');
    await page.locator('input[name="translation_glossary[0][target_term]"]').fill('About us');
    await save();
    assert.equal(await page.inputValue('input[name="translation_glossary[0][target_term]"]'), 'About us');
    // U6: «Aggiungi riga» adds an empty row, no saving needed.
    const rows = await page.locator('.lb-glossary tbody tr').count();
    await page.getByRole('button', { name: 'Aggiungi riga' }).click();
    assert.equal(await page.locator('.lb-glossary tbody tr').count(), rows + 1);
    await page.locator(`input[name="translation_glossary[${rows}][source_term]"]`).fill('Chiostro');
    await page.locator(`select[name="translation_glossary[${rows}][target_language]"]`).selectOption('en');
    await page.locator(`input[name="translation_glossary[${rows}][target_term]"]`).fill('Cloister');
    await save();
    assert.ok(await page.locator('.lb-glossary input[value="Cloister"]').count(), 'the added row is saved');
    await settings('urls');
    assert.equal(await page.isChecked('#lb-prefix-language-urls'), true, 'saving the AI tab left the URLs alone');

    // The setup: three steps, then the dashboard.
    await page.goto(`${base}/wp-admin/admin.php?page=lingua-bridge-settings&setup=1`);
    await page.getByRole('heading', { name: 'Le lingue del sito' }).waitFor();
    // U1: a search among the 67 languages; the chosen ones are shown as tags.
    await page.fill('#lb-setup-search', 'deutsch');
    const found = await page.locator('.lb-setup__languages label:visible').allTextContents();
    assert.ok(found.length >= 1 && found.every((t) => /Deutsch/.test(t)), `search: ${found.join(' | ')}`);
    await page.fill('#lb-setup-search', '');
    assert.match(await page.locator('.lb-setup__chosen').textContent(), /English/);
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Avanti' }).click()]);
    await page.getByRole('heading', { name: 'Gli indirizzi' }).waitFor();
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Avanti' }).click()]);
    await page.getByRole('heading', { name: 'Le indicazioni per l’IA' }).waitFor();
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Fine' }).click()]);
    await page.getByText('Lingua Bridge è configurato.').waitFor();
    const after = JSON.parse(wp('option', 'get', 'lingua_bridge_settings', '--format=json'));
    assert.deepEqual(Object.keys(after.languages).sort(), ['en', 'it']);
    assert.equal(after.default_language, 'it');
    assert.equal(after.prefix_language_urls, true);
  } finally {
    await browser.close();
    execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', 'option', 'update', 'lingua_bridge_settings', saved, '--format=json'], { cwd: process.env.LB_DEV, stdio: 'ignore' });
  }
});

test('strings: translate a menu label and a new text, see them on the English page', async () => {
  const seed = JSON.parse((await import('node:fs')).readFileSync(process.env.LB_SEED, 'utf8'));
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.goto(`${base}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', process.env.LB_ADMIN_PASSWORD);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    const strings = (status) => page.goto(`${base}/wp-admin/admin.php?page=lingua-bridge-strings&language=en&status=${status}`);
    const row = (text) => page.locator('tr', { has: page.locator('.lb-strings__source', { hasText: text }) });

    await strings('published');
    await row('Contatti').locator('textarea').fill('Contact us');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Salva e pubblica le modifiche' }).click()]);
    const english = await (await fetchRetry(`${base}${seed.paths.about_en}`)).text();
    assert.match(english, />Contact us</);

    // A text added by hand: missing, then translated and published.
    await page.locator('#lb-string-new').fill('Testo aggiunto LB');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Aggiungi', exact: true }).click()]);
    await strings('missing');
    await row('Testo aggiunto LB').locator('textarea').fill('Added text LB');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Salva e pubblica le modifiche' }).click()]);
    await strings('published');
    assert.equal(await row('Testo aggiunto LB').locator('textarea').inputValue(), 'Added text LB');
    await Promise.all([page.waitForNavigation(), row('Testo aggiunto LB').getByRole('button', { name: 'Rimuovi in tutte le lingue' }).click()]);
    assert.equal(await row('Testo aggiunto LB').count(), 0);

    await strings('published');
    await row('Contatti').locator('textarea').fill('Contacts');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Salva e pubblica le modifiche' }).click()]);
  } finally {
    await browser.close();
  }
});
