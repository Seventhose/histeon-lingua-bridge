/* Lingua Bridge: the search on a static copy (C8). A page with ?s= that is not WordPress's page of results is the
 * static copy answering a search: the results come from the index of the page's language. */
(function () {
	'use strict';
	var C = window.lbStaticSearch;
	var query = new URLSearchParams(window.location.search).get('s');
	if (!C || !query || !query.trim() || document.body.classList.contains('search')) return;
	var words = query.toLowerCase().split(/\s+/).filter(Boolean);
	var fold = function (text) { return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
	var folded = words.map(fold);
	fetch(C.index, { credentials: 'same-origin' })
		.then(function (r) { return r.ok ? r.text() : ''; })
		.then(function (html) {
			var found = /<script type="application\/json" id="lb-search-index">([\s\S]*?)<\/script>/.exec(html);
			var items = [];
			try { items = found ? JSON.parse(found[1]) : []; } catch (e) { items = []; }
			var hits = items.filter(function (item) {
				var text = fold(item.t + ' ' + item.e);
				return folded.every(function (w) { return text.indexOf(w) !== -1; });
			}).slice(0, 50);
			show(hits);
		})
		.catch(function () { show([]); });

	function show(hits) {
		var box = document.createElement('section');
		box.className = 'lb-static-search';
		box.setAttribute('aria-labelledby', 'lb-static-search-title');
		box.setAttribute('lang', C.lang);
		box.style.cssText = 'margin:1.5rem auto;padding:1rem 1.25rem;max-inline-size:60rem;border:1px solid currentColor;border-radius:.5rem;background:var(--wp--preset--color--base,Canvas);color:var(--wp--preset--color--contrast,CanvasText)';
		var title = document.createElement('h2');
		title.id = 'lb-static-search-title';
		title.tabIndex = -1;
		title.textContent = C.title + ': ' + query;
		box.appendChild(title);
		if (!hits.length) {
			var none = document.createElement('p');
			none.textContent = C.none;
			box.appendChild(none);
		} else {
			var list = document.createElement('ol');
			hits.forEach(function (hit) {
				var li = document.createElement('li');
				var a = document.createElement('a');
				a.href = hit.u;
				a.textContent = hit.t;
				li.appendChild(a);
				if (hit.e) {
					var p = document.createElement('p');
					p.textContent = hit.e;
					li.appendChild(p);
				}
				list.appendChild(li);
			});
			box.appendChild(list);
		}
		var close = document.createElement('button');
		close.type = 'button';
		close.textContent = C.close;
		close.addEventListener('click', function () { box.remove(); });
		box.appendChild(close);
		var main = document.querySelector('main') || document.body;
		main.insertBefore(box, main.firstChild);
		title.focus();
	}
})();
