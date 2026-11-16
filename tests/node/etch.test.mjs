// The Etch language switcher: the bundle installs exactly the readable stylesheet, and it stays generic.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../../etch/language-switcher.css', import.meta.url), 'utf8');
const bundle = JSON.parse(readFileSync(new URL('../../etch/language-switcher.bundle.json', import.meta.url), 'utf8'));

test('the bundle carries the readable stylesheet, unchanged', () => {
  assert.equal(bundle.stylesheet.css, css);
});

test('the stylesheet is generic and stable on click', () => {
  assert.doesNotMatch(css, /\.cu-|polylang/, 'no rule of a particular site');
  const open = /\[data-lb-open="true"\] > button\[data-lb-language-trigger\]::after \{([^}]*)\}/.exec(css)[1];
  assert.deepEqual(open.trim().split('\n').map((l) => l.trim().split(':')[0]), ['transform'], 'opening changes only a transform');
  assert.doesNotMatch(/\[aria-current="page"\] \{([^}]*)\}/.exec(css)[1], /font-weight/, 'the current language keeps its width');
});
