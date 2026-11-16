/* Lingua Bridge — the visual editor on the site (docs/FRONTEND-EDITOR.md). In translation mode a panel shows,
 * for any text clicked on the page (or an image's alt), its original and its translation; texts still to
 * translate are marked on the page, counted, and reached one after the other («Prossimo da tradurre», Ctrl+Enter
 * saves and goes on). The server decides where a text goes: the translation's content, or the site's strings.
 * Everything here is text: nothing typed or read is ever inserted as HTML. The panel lives in a shadow root. */
(function () {
	'use strict';

	const C = window.LinguaBridgeEditor;
	if (!C || !document.body || typeof document.body.attachShadow !== 'function') return;
	const L = C.labels;
	const ATTRIBUTES = ['alt', 'title', 'placeholder', 'aria-label'];
	const SKIP = 'script, style, textarea, noscript, code, pre, svg, #wpadminbar, #lb-editor';
	const normalize = (s) => String(s || '').replace(/\s+/g, ' ').trim();
	const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/** What the server knows of each text of the page: its original and whether it is translated. */
	const known = new Map();
	(Array.isArray(C.texts) ? C.texts : []).forEach((t) => {
		const key = normalize(t.current);
		if (!key) return;
		const previous = known.get(key);
		// The same text twice: «to translate» wins, so it is never hidden.
		if (!previous || (previous.state !== 'todo' && t.state === 'todo')) known.set(key, Object.assign({}, t));
	});

	const host = document.createElement('div');
	host.id = 'lb-editor';
	document.body.appendChild(host);
	const root = host.attachShadow({ mode: 'open' });
	const style = document.createElement('style');
	style.textContent = `
		:host { all: initial; --a: #2271b1; --a-dark: #135e96; --a-soft: #eaf2fa; --ink: #1e1e1e; --muted: #5f6368; --line: #e2e4e7; --ok: #0a7d32; --ok-soft: #e6f4ea; --warn: #8a5a00; --warn-soft: #fdf3dc; --bad: #b3261e; }
		* { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif; }
		.box { position: fixed; z-index: 2147483000; pointer-events: none; border: 2px solid var(--a); border-radius: 6px; background: rgba(34, 113, 177, .08); box-shadow: 0 0 0 4px rgba(34, 113, 177, .12); transition: all .08s ease-out; }
		.box[hidden] { display: none; }
		aside { position: fixed; z-index: 2147483001; right: 20px; bottom: 20px; width: min(420px, calc(100vw - 32px)); max-height: min(84vh, 720px); overflow: auto; background: #fff; color: var(--ink); border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 24px 48px -12px rgba(16, 24, 40, .25), 0 2px 6px rgba(16, 24, 40, .06); font-size: 13px; line-height: 1.5; animation: lb-in .18s ease-out; }
		@keyframes lb-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
		header { position: sticky; top: 0; z-index: 1; display: flex; flex-wrap: wrap; gap: 6px 8px; align-items: center; padding: 14px 16px 12px; background: rgba(255, 255, 255, .96); border-bottom: 1px solid var(--line); backdrop-filter: blur(6px); }
		h2 { flex: 1 1 auto; margin: 0; font-size: 15px; font-weight: 650; }
		.chip { padding: 2px 9px; border-radius: 999px; background: var(--warn-soft); color: var(--warn); font-size: 11px; font-weight: 600; }
		.chip.published { background: var(--ok-soft); color: var(--ok); }
		.progress { flex-basis: 100%; display: flex; align-items: center; gap: 10px; color: var(--muted); font-size: 12px; font-variant-numeric: tabular-nums; }
		.bar { flex: 1; height: 8px; border-radius: 999px; background: #eceef0; overflow: hidden; }
		.bar span { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--a), var(--ok)); transition: width .3s ease; }
		.tools { display: flex; flex-wrap: wrap; gap: 6px; padding: 10px 16px; border-bottom: 1px solid var(--line); background: #fafafa; }
		.body { padding: 12px 16px 16px; }
		button, a.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 34px; padding: 0 12px; border: 1px solid #c3c4c7; border-radius: 8px; background: #fff; color: var(--ink); font-size: 13px; font-weight: 500; text-decoration: none; cursor: pointer; transition: background-color .12s ease, border-color .12s ease, box-shadow .12s ease; }
		button:hover, a.btn:hover { border-color: var(--a); color: var(--a-dark); }
		button.primary { border-color: var(--a); background: var(--a); color: #fff; }
		button.primary:hover { background: var(--a-dark); border-color: var(--a-dark); color: #fff; }
		button:disabled { opacity: .5; cursor: default; }
		button:focus-visible, a.btn:focus-visible, textarea:focus-visible, input:focus-visible { outline: 2px solid var(--a); outline-offset: 2px; }
		label, .label { display: block; margin: 12px 0 6px; color: var(--muted); font-size: 11px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; }
		.original { padding: 10px 12px; background: #f6f7f7; border-radius: 10px; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 14px; }
		.original.unknown { color: var(--muted); font-style: italic; }
		.terms { margin: 8px 0 0; padding: 0; list-style: none; display: grid; gap: 2px; font-size: 12px; color: var(--muted); }
		.terms b { color: var(--ink); }
		textarea, input { width: 100%; padding: 10px 12px; border: 1px solid #c3c4c7; border-radius: 10px; font-size: 14px; line-height: 1.5; color: var(--ink); background: #fff; transition: border-color .12s ease, box-shadow .12s ease; }
		textarea:focus, input:focus { border-color: var(--a); box-shadow: 0 0 0 3px rgba(34, 113, 177, .15); outline: none; }
		textarea { min-height: 84px; resize: vertical; }
		.row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 10px; }
		.row input { flex: 1; }
		.hint, .note { margin: 0 0 8px; color: var(--muted); }
		.note { padding: 8px 10px; background: var(--warn-soft); color: var(--warn); border-radius: 10px; }
		.small { margin: 8px 0 0; color: var(--muted); font-size: 12px; }
		.status { min-height: 1.4em; margin: 10px 0 0; color: var(--ok); font-weight: 500; }
		.status.error { color: var(--bad); }
		.memory { margin: 10px 0 0; padding: 10px 12px; background: var(--a-soft); border-radius: 10px; }
		.memory p { margin: 0 0 6px; white-space: pre-wrap; overflow-wrap: anywhere; }
		.memory .alike { color: var(--muted); font-size: 12px; } .memory mark { background: #fde68a; color: inherit; }
		.seo { margin: 8px 0; } .seo summary { cursor: pointer; font-weight: 600; } .seo-field { display: grid; gap: 4px; margin: 8px 0; } .seo-field p { margin: 0; color: var(--muted); }
		.changes { margin: 8px 0 0; padding: 0; list-style: none; display: grid; gap: 6px; max-height: 240px; overflow: auto; }
		.changes li { padding: 8px 10px; border: 1px solid var(--line); border-radius: 10px; }
		.changes .was { color: var(--bad); text-decoration: line-through; }
		.changes .is { color: var(--ok); }
		.mode { margin: 14px 0 0; padding-top: 10px; border-top: 1px solid var(--line); color: var(--muted); font-size: 12px; }
		.mode button { min-height: 28px; margin-inline-start: 4px; padding: 0 10px; font-size: 12px; }
		kbd { padding: 1px 5px; border: 1px solid var(--line); border-bottom-width: 2px; border-radius: 4px; background: #fafafa; font: 11px ui-monospace, monospace; }
		[hidden] { display: none !important; }
		@media (prefers-reduced-motion: reduce) { .box, .bar span, aside, button, textarea { transition: none; animation: none; } }
	`;
	root.appendChild(style);

	const el = (tag, props = {}, children = []) => {
		const node = document.createElement(tag);
		Object.entries(props).forEach(([k, v]) => {
			if (k === 'text') node.textContent = v;
			else node.setAttribute(k, v);
		});
		children.forEach((c) => node.appendChild(c));
		return node;
	};

	const box = el('div', { class: 'box', hidden: '' });
	const chip = el('span', { class: 'chip' + (C.status === 'publish' ? ' published' : ''), text: C.status === 'publish' ? L.published : L.draft });
	const barFill = el('span');
	const progressText = el('span');
	const progress = el('div', { class: 'progress', role: 'status' }, [el('div', { class: 'bar', 'aria-hidden': 'true' }, [barFill]), progressText]);
	const next = el('button', { type: 'button', class: 'primary', text: L.next });
	const toggle = el('button', { type: 'button', 'aria-pressed': 'true', text: L.pause });
	const publish = el('button', { type: 'button', text: L.publish });
	const status = el('p', { class: 'status', role: 'status', 'aria-live': 'polite' });
	const hint = el('p', { class: 'hint', text: L.pick });
	const original = el('div', { class: 'original' });
	const terms = el('ul', { class: 'terms', hidden: '' });
	const input = el('textarea', { id: 'lb-ed-text', lang: C.code });
	const memoryText = el('p', { lang: C.code });
	const useMemory = el('button', { type: 'button', text: L.useMemory });
	const memoryLabel = el('div', { class: 'label', text: L.memory });
	const memorySource = el('p', { class: 'alike', hidden: '' });
	const memoryBox = el('div', { class: 'memory', hidden: '' }, [memoryLabel, memorySource, memoryText, useMemory]);
	const save = el('button', { type: 'button', class: 'primary', text: L.save });
	const suggest = el('button', { type: 'button', hidden: '', text: L.suggest });
	const keep = el('button', { type: 'button', text: L.keep });
	const textPart = el('div', { hidden: '' }, [
		el('div', { class: 'label', text: L.original }), original, terms, memoryBox,
		el('label', { for: 'lb-ed-text', text: L.translation }), input,
		el('div', { class: 'row' }, [save, keep, suggest]),
		el('p', { class: 'small', text: L.saveNext }),
	]);
	const attrs = el('div', { hidden: '' });
	const notes = el('div');
	const modeLine = el('p', { class: 'mode' });
	const applyMemory = el('button', { type: 'button', hidden: '' });
	const machinePage = el('button', { type: 'button', hidden: '', text: L.machinePage });
	const panel = el('aside', { role: 'dialog', 'aria-label': L.title }, [
		el('header', {}, [el('h2', { text: `${L.title} · ${C.language}` }), chip, progress]),
		el('div', { class: 'tools' }, [next, applyMemory, machinePage, toggle].concat(C.canPublish ? [publish] : []).concat([el('a', { class: 'btn', href: C.editUrl, text: L.edit }), el('a', { class: 'btn', href: C.exitUrl, text: L.exit })])),
		el('div', { class: 'body' }, [notes, hint, textPart, attrs, status, modeLine]),
	]);
	root.append(box, panel);

	if (C.created) {
		notes.appendChild(el('p', { class: 'note', text: L.createdNote }));
		if (Array.isArray(C.created.missing) && C.created.missing.length) {
			notes.appendChild(el('p', { class: 'note', text: L.missingNote.replace('%s', C.created.missing.join('; ')) }));
		}
	}

	if (C.created && C.created.memory) notes.appendChild(el('p', { class: 'note', text: L.fromMemory.replace('%d', String(C.created.memory)) }));
	if (Array.isArray(C.structure) && C.structure.length) notes.appendChild(el('p', { class: 'note', text: L.structureNote.replace('%s', C.structure.join(' · ')) }));

	/** The original changed since the translation was brought up to date: what changed, and what to do. */
	if (C.updates) {
		const u = C.updates;
		const changes = Array.isArray(u.changes) ? u.changes : [];
		const box = el('div', { class: 'note' });
		box.appendChild(el('p', { text: u.known ? L.changed.replace('%d', String(changes.length + (u.title ? 1 : 0))) : L.changedUnknown }));
		const list = el('ul', { class: 'changes' });
		if (u.title) list.appendChild(el('li', {}, [el('strong', { text: L.titleChanged + ': ' }), el('span', { class: 'was', text: u.title.before }), document.createTextNode(' → '), el('span', { class: 'is', text: u.title.original })]));
		changes.slice(0, 30).forEach((c) => {
			const item = el('li');
			if (c.before) item.append(el('span', { class: 'was', text: c.before }), document.createTextNode(' → '));
			item.append(el('span', { class: 'is', text: c.original }));
			if (c.translation) {
				const go = el('button', { type: 'button', text: L.go });
				go.addEventListener('click', () => jumpTo(c.translation, c.original));
				item.append(document.createTextNode(' '), go);
			} else if (!c.before) {
				item.append(el('span', { class: 'small', text: ` (${L.newSentence})` }));
			}
			list.appendChild(item);
		});
		if (list.childElementCount) box.appendChild(list);
		const actions = el('div', { class: 'row' });
		if (u.known && u.aligned && C.status === 'draft') {
			const rebuild = el('button', { type: 'button', class: 'primary', text: L.updateDraft });
			rebuild.addEventListener('click', async () => {
				try {
					await send('lingua_bridge_editor_update', {});
					window.location.reload();
				} catch (error) {
					say(L.error.replace('%s', error.message), true);
				}
			});
			actions.appendChild(rebuild);
		}
		const mark = el('button', { type: 'button', text: L.markUpdated });
		mark.addEventListener('click', async () => {
			try {
				await send('lingua_bridge_editor_mark_updated', {});
				box.remove();
				say(L.updated);
			} catch (error) {
				say(L.error.replace('%s', error.message), true);
			}
		});
		actions.appendChild(mark);
		box.appendChild(actions);
		notes.appendChild(box);
	}

	// C10: the SEO texts (title, description, social) are not on the page: a panel of their own.
	if (Array.isArray(C.seo) && C.seo.length) {
		const seoBox = el('details', { class: 'seo' }, [el('summary', { text: L.seoTitle })]);
		C.seo.forEach((field, i) => {
			const id = `lb-ed-seo-${i}`;
			const area = el('textarea', { id, lang: C.code, rows: field.type === 'textarea' ? '3' : '1' });
			area.value = field.current;
			const saveSeo = el('button', { type: 'button', text: L.save });
			saveSeo.addEventListener('click', async () => {
				try {
					await send('lingua_bridge_editor_seo', { key: field.key, value: area.value });
					say(L.seoSaved);
				} catch (error) {
					say(L.error.replace('%s', error.message), true);
				}
			});
			seoBox.appendChild(el('div', { class: 'seo-field' }, [
				el('label', { for: id, text: field.label }),
				el('p', { class: 'small', lang: C.sourceCode, text: field.original }),
				area,
				saveSeo,
			]));
		});
		notes.appendChild(seoBox);
	}

	modeLine.textContent = C.independent ? L.independentNote : L.follows;
	const switchMode = el('button', { type: 'button', text: C.independent ? L.makeFollow : L.makeIndependent });
	switchMode.addEventListener('click', async () => {
		try {
			await send('lingua_bridge_editor_mode', { independent: C.independent ? '0' : '1' });
			window.location.reload();
		} catch (error) {
			say(L.error.replace('%s', error.message), true);
		}
	});
	modeLine.appendChild(switchMode);

	let active = true;
	let picked = null;
	/** The texts of the page the server knows, in page order: { node | element+attribute, entry }. */
	let places = [];

	const editable = (node) => node && node.nodeType === 1 && !node.closest(SKIP);
	const directText = (element) => Array.from(element.childNodes).find((n) => n.nodeType === 3 && n.textContent.trim() !== '');
	const textAttributes = (element) => ATTRIBUTES.filter((a) => (element.getAttribute(a) || '').trim() !== '');

	/** Finds the known texts on the page again, marks those to translate, updates the count. */
	function scan() {
		document.querySelectorAll('[data-lb-todo]').forEach((e) => e.removeAttribute('data-lb-todo'));
		places = [];
		const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
		while (walker.nextNode()) {
			const n = walker.currentNode;
			const entry = known.get(normalize(n.textContent));
			if (entry && n.parentElement && editable(n.parentElement)) places.push({ node: n, element: n.parentElement, entry });
		}
		document.querySelectorAll(ATTRIBUTES.map((a) => `[${a}]`).join(',')).forEach((e) => {
			if (!editable(e)) return;
			ATTRIBUTES.forEach((a) => {
				const entry = e.hasAttribute(a) ? known.get(normalize(e.getAttribute(a))) : null;
				if (entry) places.push({ element: e, attribute: a, entry });
			});
		});
		places.sort((a, b) => (a.element === b.element ? 0 : a.element.compareDocumentPosition(b.element) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1));
		let todo = 0;
		places.forEach((p) => {
			if (p.entry.state === 'todo') {
				todo += 1;
				p.element.setAttribute('data-lb-todo', '');
			}
		});
		const total = places.length;
		const done = total - todo;
		barFill.style.width = total ? `${Math.round((done / total) * 100)}%` : '100%';
		progressText.textContent = todo === 0 ? L.allDone : L.progress.replace('%1$d', String(done)).replace('%2$d', String(total));
		next.disabled = todo === 0;
		const withMemory = places.filter((p) => p.entry.state === 'todo' && p.entry.memory).length;
		applyMemory.hidden = withMemory === 0;
		applyMemory.textContent = L.applyMemory.replace('%d', String(withMemory));
		machinePage.hidden = !(C.machine && C.status === 'draft' && !C.independent && todo > 0);
	}

	/** A sentence to update: the text of the translation on the page, opened in the panel. */
	function jumpTo(translation, newOriginal) {
		const place = places.find((p) => p.node && normalize(p.node.textContent) === normalize(translation));
		const target = place || (() => {
			const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
			while (walker.nextNode()) {
				const n = walker.currentNode;
				if (n.parentElement && editable(n.parentElement) && normalize(n.textContent) === normalize(translation)) return { node: n, element: n.parentElement };
			}
			return null;
		})();
		if (!target) return;
		target.element.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
		pick(target.element, target.node);
		original.textContent = newOriginal;
		original.classList.remove('unknown');
	}

	function say(message, error) {
		status.textContent = message;
		status.classList.toggle('error', !!error);
	}

	function frame(element) {
		if (!element) {
			box.hidden = true;
			return;
		}
		const r = element.getBoundingClientRect();
		Object.assign(box.style, { top: `${r.top - 2}px`, left: `${r.left - 2}px`, width: `${r.width + 4}px`, height: `${r.height + 4}px` });
		box.hidden = false;
	}

	function textNodeAt(x, y, element) {
		let node = null;
		if (document.caretPositionFromPoint) {
			const pos = document.caretPositionFromPoint(x, y);
			node = pos && pos.offsetNode;
		} else if (document.caretRangeFromPoint) {
			const range = document.caretRangeFromPoint(x, y);
			node = range && range.startContainer;
		}
		return node && node.nodeType === 3 && node.textContent.trim() !== '' && element.contains(node) ? node : directText(element);
	}

	/** Every text node (or attribute) of the page with the same text: the same text, the same translation. */
	function replaceEverywhere(oldText, newText, attribute) {
		const target = normalize(oldText);
		if (attribute) {
			document.querySelectorAll(`[${attribute}]`).forEach((e) => {
				if (editable(e) && normalize(e.getAttribute(attribute)) === target) e.setAttribute(attribute, newText);
			});
			return;
		}
		const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
		const nodes = [];
		while (walker.nextNode()) {
			const n = walker.currentNode;
			if (n.parentElement && editable(n.parentElement) && normalize(n.textContent) === target) nodes.push(n);
		}
		nodes.forEach((n) => {
			const lead = n.textContent.match(/^\s*/)[0];
			const trail = n.textContent.match(/\s*$/)[0];
			n.textContent = lead + newText + trail;
		});
	}

	/** The text now has this translation: the same original, done. */
	function remember(oldText, newText, state) {
		const entry = known.get(normalize(oldText)) || { original: '', where: 'string' };
		known.delete(normalize(oldText));
		known.set(normalize(newText), Object.assign({}, entry, { current: newText, state }));
	}

	async function send(action, values) {
		const body = new FormData();
		body.append('action', action);
		body.append('nonce', C.nonce);
		body.append('post', String(C.post));
		Object.entries(values).forEach(([k, v]) => body.append(k, v));
		const response = await fetch(C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
		const payload = await response.json().catch(() => null);
		if (!payload || !payload.success) throw new Error((payload && payload.data && payload.data.message) || String(response.status));
		return payload.data;
	}

	async function saveText(oldText, newText, attribute) {
		say('');
		try {
			const result = await send('lingua_bridge_editor_save', { old: oldText, new: newText, attribute: attribute || '', components: (window.LinguaBridgeEditorComponents || []).join(',') });
			replaceEverywhere(oldText, result.text, attribute);
			remember(oldText, result.text, 'done');
			scan();
			say(result.where === 'content' ? L.inContent.replace('%d', String(result.count)) : (result.where === 'component' ? L.inComponent.replace('%s', result.component || '') : L.asString));
			return result.text;
		} catch (error) {
			say(L.error.replace('%s', error.message), true);
			return null;
		}
	}

	/** The glossary terms the original contains, with their expected rendering. */
	function showTerms(text) {
		terms.replaceChildren();
		const lower = text.toLocaleLowerCase();
		(Array.isArray(C.glossary) ? C.glossary : []).forEach((g) => {
			if (g.term && lower.includes(String(g.term).toLocaleLowerCase())) {
				const item = el('li');
				item.append(`${L.glossaryHint}: ${g.term} → `, el('b', { text: g.use }), g.note ? ` (${g.note})` : '');
				terms.appendChild(item);
			}
		});
		terms.hidden = terms.childElementCount === 0;
	}

	function pick(element, node) {
		picked = { element, node };
		hint.hidden = true;
		textPart.hidden = !node;
		if (node) {
			const current = node.textContent.trim();
			const entry = known.get(normalize(current));
			const source = entry && entry.original ? entry.original : '';
			original.textContent = source || L.noOriginal;
			original.classList.toggle('unknown', !source);
			original.setAttribute('lang', source ? C.sourceCode || '' : '');
			showTerms(source);
			input.value = current;
			keep.hidden = !(entry && entry.state === 'todo');
			keep.textContent = entry && entry.review ? L.confirm : L.keep;
			if (entry && entry.review) say(L.reviewNote);
			const alike = entry && entry.state === 'todo' && !entry.memory && entry.similar ? entry.similar : null;
			memoryBox.hidden = !(entry && entry.state === 'todo' && (entry.memory || alike));
			memoryText.textContent = entry && entry.memory ? entry.memory : (alike ? alike.target : '');
			memoryLabel.textContent = alike ? L.similar.replace('%d', String(alike.percent)) : L.memory;
			// C25: the remembered sentence, its words that the original does not have marked.
			memorySource.hidden = !alike;
			memorySource.replaceChildren();
			if (alike) {
				const own = new Set(String(entry.original).toLowerCase().split(/\s+/));
				String(alike.source).split(/(\s+)/).forEach((word) => {
					memorySource.appendChild(/\S/.test(word) && !own.has(word.toLowerCase()) ? el('mark', { text: word }) : document.createTextNode(word));
				});
			}
			suggest.hidden = !(C.machine && source);
		}
		attrs.replaceChildren();
		const names = textAttributes(element);
		attrs.hidden = names.length === 0;
		if (names.length) {
			attrs.appendChild(el('div', { class: 'label', text: L.attribute }));
			names.forEach((name) => {
				const value = element.getAttribute(name);
				const entry = known.get(normalize(value));
				if (entry && entry.original && normalize(entry.original) !== normalize(value)) {
					attrs.appendChild(el('p', { class: 'small', text: `${L.original}: ${entry.original}` }));
				}
				const field = el('input', { type: 'text', value, 'aria-label': name, lang: C.code });
				const button = el('button', { type: 'button', text: L.save });
				button.addEventListener('click', async () => {
					const saved = await saveText(element.getAttribute(name), field.value, name);
					if (saved !== null) field.value = saved;
				});
				attrs.appendChild(el('div', { class: 'row' }, [el('code', { text: name }), field, button]));
			});
		}
		say('');
		frame(element);
		(node ? input : attrs.querySelector('input'))?.focus();
		// Still the original's words: selected, so typing replaces them.
		if (node && !keep.hidden) input.select();
	}

	/** The next text to translate after the one in hand (or from the top), brought into view and opened. */
	function goNext() {
		const todo = places.filter((p) => p.entry.state === 'todo');
		if (!todo.length) {
			say(L.allDone);
			return;
		}
		let target = todo[0];
		if (picked) {
			const after = todo.find((p) => p.element !== picked.element && picked.element.compareDocumentPosition(p.element) & Node.DOCUMENT_POSITION_FOLLOWING);
			if (after) target = after;
		}
		target.element.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
		pick(target.element, target.node || null);
	}

	save.addEventListener('click', async () => {
		if (!picked || !picked.node) return;
		await saveText(picked.node.textContent, input.value);
	});
	keep.addEventListener('click', async () => {
		if (!picked || !picked.node) return;
		const text = picked.node.textContent.trim();
		try {
			await send('lingua_bridge_editor_keep', { text });
			remember(text, text, 'kept');
			scan();
			say(L.kept);
			goNext();
		} catch (error) {
			say(L.error.replace('%s', error.message), true);
		}
	});
	input.addEventListener('keydown', async (event) => {
		if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
			event.preventDefault();
			if (!picked || !picked.node) return;
			const saved = await saveText(picked.node.textContent, input.value);
			if (saved !== null) goNext();
		}
	});
	next.addEventListener('click', goNext);
	useMemory.addEventListener('click', () => {
		input.value = memoryText.textContent;
		input.focus();
	});
	machinePage.addEventListener('click', async () => {
		machinePage.disabled = true;
		try {
			const result = await send('lingua_bridge_editor_machine', {});
			say(L.machineDone.replace('%d', String(result.count)));
			window.location.reload();
		} catch (error) {
			machinePage.disabled = false;
			say(L.error.replace('%s', error.message), true);
		}
	});
	suggest.addEventListener('click', async () => {
		if (!picked || !picked.node) return;
		const entry = known.get(normalize(picked.node.textContent));
		if (!entry || !entry.original) return;
		suggest.disabled = true;
		try {
			const result = await send('lingua_bridge_editor_suggest', { text: entry.original });
			input.value = result.text;
			input.focus();
		} catch (error) {
			say(L.error.replace('%s', error.message), true);
		}
		suggest.disabled = false;
	});
	applyMemory.addEventListener('click', async () => {
		try {
			await send('lingua_bridge_editor_memory', {});
			window.location.reload();
		} catch (error) {
			say(L.error.replace('%s', error.message), true);
		}
	});

	toggle.addEventListener('click', () => {
		active = !active;
		toggle.textContent = active ? L.pause : L.resume;
		toggle.setAttribute('aria-pressed', active ? 'true' : 'false');
		hint.hidden = !active || !!picked;
		document.documentElement.toggleAttribute('data-lb-paused', !active);
		frame(null);
	});

	publish.addEventListener('click', async () => {
		try {
			await send('lingua_bridge_editor_publish', {});
			chip.textContent = L.published;
			chip.classList.add('published');
			publish.remove();
			say(L.publishedNow);
		} catch (error) {
			say(L.error.replace('%s', error.message), true);
		}
	});

	document.addEventListener('mouseover', (event) => {
		if (!active || picked) return;
		const target = event.target;
		frame(editable(target) && (directText(target) || textAttributes(target).length) ? target : null);
	}, true);

	document.addEventListener('click', (event) => {
		if (!active || event.composedPath().includes(host)) return;
		const target = event.target;
		if (!editable(target)) return;
		const node = textNodeAt(event.clientX, event.clientY, target);
		if (!node && !textAttributes(target).length) return;
		event.preventDefault();
		event.stopPropagation();
		pick(target, node);
	}, true);

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			picked = null;
			textPart.hidden = true;
			attrs.hidden = true;
			hint.hidden = !active;
			frame(null);
		}
	});
	window.addEventListener('scroll', () => frame(picked ? picked.element : null), { passive: true });

	scan();
})();
