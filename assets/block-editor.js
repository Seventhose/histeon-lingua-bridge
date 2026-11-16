/* Lingua Bridge — «Lingue» in the block editor's document panel: each language of this content, its state, and
 * one click to translate it on the site (or to create it and translate it there). Data from PHP, shown as text. */
(function (wp) {
	'use strict';

	const C = window.LinguaBridgeBlockEditor;
	if (!C || !wp || !wp.plugins || !wp.element) return;
	const el = wp.element.createElement;
	const Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	if (!Panel) return;
	const L = C.labels;
	const states = { original: L.original, this: L.this, published: L.published, draft: L.draft, stale: L.stale, missing: L.missing };
	const link = (href, text, primary) => el('a', { className: 'components-button ' + (primary ? 'is-primary' : 'is-secondary') + ' is-small', href }, text);

	function Languages() {
		const saved = wp.data && wp.data.select('core/editor') ? wp.data.select('core/editor').getCurrentPostAttribute('status') !== 'auto-draft' : true;
		return el(
			Panel,
			{ name: 'lingua-bridge-languages', title: L.title, className: 'lingua-bridge-languages' },
			el(
				'ul',
				{ style: { margin: 0, padding: 0, listStyle: 'none', display: 'grid', gap: '8px' } },
				C.languages.map((item) =>
					el(
						'li',
						{ key: item.code, style: { display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '6px' } },
						el('strong', { style: { minWidth: '72px' } }, item.label),
						el('span', { className: 'lb-state lb-state-' + item.state, style: { color: '#50575e', fontSize: '12px', flex: '1 1 auto' } }, (states[item.state] || item.state) + (item.independent ? ' · ' + L.independent : '')),
						item.translate ? link(item.translate, L.translate, item.state === 'draft' || item.state === 'stale') : null,
						item.create ? link(item.create, L.create, true) : null,
						item.other ? link(item.other, L.other, false) : null,
						item.edit ? link(item.edit, L.open, false) : null
					)
				)
			),
			saved ? null : el('p', { style: { color: '#50575e', marginTop: '8px' } }, L.saveFirst),
			C.slugNote ? el('p', { style: { color: '#50575e', marginTop: '8px' } }, C.slugNote) : null,
			Array.isArray(C.structure) && C.structure.length
				? el('div', { style: { marginTop: '8px' } }, el('strong', null, L.structure), el('ul', { style: { margin: '4px 0 0 16px', listStyle: 'disc' } }, C.structure.map((note, i) => el('li', { key: i }, note))))
				: null
		);
	}

	wp.plugins.registerPlugin('lingua-bridge-languages', { render: Languages });
})(window.wp);
