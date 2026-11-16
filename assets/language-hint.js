/*
 * Lingua Bridge: the language of the visitor (N8, C2). Reads the hreflang links of the page, so it works in a static
 * copy and behind any cache too; the server never answers differently to different browsers.
 *
 * - A language chosen with a switcher (a link with data-lb-choice, or with hreflang inside a switcher) is kept in the
 *   browser, without a cookie, and always wins: nothing more happens to that visitor.
 * - "suggest": a small bar towards the version in the browser's language; closed once, it stays closed.
 * - "redirect": on the first page of a visit, when that page is the original (x-default) and has a published version
 *   in the browser's language, the visitor goes there. Never for crawlers or automated browsers, never in a preview,
 *   an editor or a frame; otherwise the bar, as in "suggest".
 */
(function () {
	'use strict';
	var HINT = 'lingua-bridge-language-hint';
	var CHOICE = 'lingua-bridge-choice';
	var ENTERED = 'lingua-bridge-entered';
	var config = window.lbLanguageHint || {};
	var store = function (storage, key, value) {
		try {
			if (undefined === value) {
				return window[storage].getItem(key);
			}
			window[storage].setItem(key, value);
		} catch (e) {}
		return null;
	};
	var base = function (code) {
		return String(code || '').toLowerCase().split('-')[0];
	};

	// The visitor's own choice, from any switcher of the page (also the Etch component, which copies its links).
	document.addEventListener('click', function (event) {
		var link = event.target && event.target.closest ? event.target.closest('a[data-lb-choice], .lb-language-switcher a[hreflang], .lb-menu-switcher a[hreflang], [data-lb-etch-language-switcher] a[lang], .lb-language-hint a[hreflang]') : null;
		var code = link ? link.getAttribute('data-lb-choice') || link.getAttribute('hreflang') || link.getAttribute('lang') : '';
		if (code) {
			store('localStorage', CHOICE, String(code).toLowerCase());
		}
	}, true);

	var first = !store('sessionStorage', ENTERED);
	store('sessionStorage', ENTERED, '1');
	if (store('localStorage', CHOICE)) {
		return;
	}

	var current = base(document.documentElement.lang);
	var versions = {};
	var xDefault = '';
	Array.prototype.forEach.call(document.querySelectorAll('link[rel="alternate"][hreflang]'), function (link) {
		var code = link.getAttribute('hreflang').toLowerCase();
		if (code === 'x-default') {
			xDefault = link.href;
		} else {
			versions[code] = link.href;
			versions[base(code)] = versions[base(code)] || link.href;
		}
	});
	var wanted = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language];
	var target = '';
	for (var i = 0; i < wanted.length; i++) {
		var code = String(wanted[i] || '').toLowerCase();
		// The visitor reads this page's language first: nothing to do.
		if (base(code) === current) {
			return;
		}
		if (versions[code] || versions[base(code)]) {
			target = versions[code] ? code : base(code);
			break;
		}
	}
	if (!target) {
		return;
	}

	var same = function (a, b) {
		var x = document.createElement('a');
		var y = document.createElement('a');
		x.href = a;
		y.href = b;
		return x.host === y.host && x.pathname.replace(/\/+$/, '') === y.pathname.replace(/\/+$/, '');
	};
	var robot = navigator.webdriver || /bot|crawl|spider|slurp|archiver|facebookexternalhit|embedly|preview|lighthouse|headless/i.test(navigator.userAgent || '');
	var editing = window.self !== window.top || /[?&](preview|preview_id|lb_translate|customize_changeset_uuid|etch|elementor-preview)=/.test(window.location.search);
	if (config.mode === 'redirect' && first && !robot && !editing && xDefault && same(xDefault, window.location.href)) {
		window.location.replace(versions[target]);
		return;
	}
	if (store('localStorage', HINT)) {
		return;
	}

	var show = function () {
		var names = config.names || {};
		var bar = document.createElement('div');
		bar.className = 'lb-language-hint';
		bar.setAttribute('role', 'region');
		bar.setAttribute('aria-label', names[target] || target);
		// Next to a floating switcher in the same corner, the bar takes the other one.
		var left = document.querySelector('.lb-language-switcher--bottom-right');
		bar.style.cssText = 'position:fixed;z-index:99999;inset:' + (left ? 'auto auto 1rem 1rem' : 'auto 1rem 1rem auto') + ';display:flex;gap:.5rem;align-items:center;padding:.5rem .75rem;border-radius:.4rem;background:Canvas;color:CanvasText;box-shadow:0 2px 10px rgb(0 0 0/.25);font:inherit';
		var link = document.createElement('a');
		link.href = versions[target];
		link.hreflang = target;
		link.lang = target;
		link.textContent = '\u{1F310} ' + (names[target] || target.toUpperCase()) + ' →';
		var close = document.createElement('button');
		close.type = 'button';
		close.textContent = '×';
		close.setAttribute('aria-label', config.close || '×');
		close.style.cssText = 'border:0;background:none;color:inherit;font:inherit;font-size:1.25em;line-height:1;cursor:pointer';
		close.addEventListener('click', function () {
			store('localStorage', HINT, '1');
			bar.remove();
		});
		bar.appendChild(link);
		bar.appendChild(close);
		document.body.appendChild(bar);
	};
	if (document.body && document.readyState !== 'loading') {
		show();
	} else {
		document.addEventListener('DOMContentLoaded', show);
	}
})();
