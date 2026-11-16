(function (wp) {
	'use strict';

	if (!wp || !wp.blocks || !wp.element || !wp.i18n) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;

	wp.blocks.registerBlockType('lingua-bridge/language-switcher', {
		title: __('Selettore lingua — Lingua Bridge', 'lingua-bridge'),
		description: __('Mostra le versioni pubblicate del contenuto corrente.', 'lingua-bridge'),
		category: 'widgets',
		icon: 'translation',
		supports: { html: false },
		attributes: {
			style: { type: 'string', default: 'list' },
			label: { type: 'string', default: 'name' },
			hideCurrent: { type: 'boolean', default: false },
			showMissing: { type: 'boolean', default: false },
		},
		edit: function (props) {
			const a = props.attributes;
			const set = props.setAttributes;
			const c = wp.components;
			const panel = wp.blockEditor && c ? el(
				wp.blockEditor.InspectorControls,
				{},
				el(
					c.PanelBody,
					{ title: __('Selettore lingua', 'lingua-bridge') },
					el(c.SelectControl, {
						label: __('Aspetto', 'lingua-bridge'),
						value: a.style,
						options: [
							{ label: __('Elenco', 'lingua-bridge'), value: 'list' },
							{ label: __('Tendina', 'lingua-bridge'), value: 'dropdown' },
						],
						onChange: function (v) { set({ style: v }); },
					}),
					el(c.SelectControl, {
						label: __('Etichetta', 'lingua-bridge'),
						value: a.label,
						options: [
							{ label: __('Nome della lingua', 'lingua-bridge'), value: 'name' },
							{ label: __('Codice', 'lingua-bridge'), value: 'code' },
							{ label: __('Codice e nome', 'lingua-bridge'), value: 'both' },
						],
						onChange: function (v) { set({ label: v }); },
					}),
					el(c.ToggleControl, {
						label: __('Nascondi la lingua corrente', 'lingua-bridge'),
						checked: !!a.hideCurrent,
						onChange: function (v) { set({ hideCurrent: v }); },
					}),
					el(c.ToggleControl, {
						label: __('Mostra anche le lingue senza traduzione (portano alla loro home)', 'lingua-bridge'),
						checked: !!a.showMissing,
						onChange: function (v) { set({ showMissing: v }); },
					})
				)
			) : null;
			return el(
				wp.element.Fragment,
				{},
				panel,
				el(
					'div',
					{ className: 'lb-language-switcher-editor' },
					el('strong', {}, __('Selettore lingua', 'lingua-bridge')),
					el('p', {}, __('Sul sito apparirà quando esistono almeno due versioni pubblicate e collegate.', 'lingua-bridge'))
				)
			);
		},
		save: function () {
			return null;
		},
	});
})(window.wp);
