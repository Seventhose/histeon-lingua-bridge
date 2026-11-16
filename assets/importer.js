(function () {
	'use strict';

	const __ = window.wp.i18n.__;
	const sprintf = window.wp.i18n.sprintf;
	const sourceSelect = document.getElementById('lb-import-source');
	const startButton = document.getElementById('lb-import-start');
	const stopButton = document.getElementById('lb-import-stop');
	const statusNode = document.getElementById('lb-import-status');
	const progressNode = document.getElementById('lb-import-progress');

	if (!sourceSelect || !startButton || !stopButton || !statusNode || !progressNode || !window.LinguaBridgeImport) {
		return;
	}

	const emptyCounts = () => ({
		terms: 0,
		menus: 0,
		groups_seen: 0,
		posts_seen: 0,
		imported: 0,
		drafts: 0,
		already: 0,
		skipped: 0,
		conflicts: 0,
	});

	let cursor = 0;
	let counts = emptyCounts();
	let activeSource = '';
	let running = false;
	let stopRequested = false;
	const progressKey = 'linguaBridgeImport:' + window.location.origin;

	function persistProgress() {
		try {
			if (!activeSource) {
				sessionStorage.removeItem(progressKey);
				return;
			}
			sessionStorage.setItem(progressKey, JSON.stringify({ source: activeSource, cursor: cursor, counts: counts }));
		} catch (_) {
			// Import remains safe and resumable in the current page when storage is unavailable.
		}
	}

	function restoreProgress() {
		try {
			const saved = JSON.parse(sessionStorage.getItem(progressKey) || 'null');
			if (!saved || typeof saved.source !== 'string' || !Number.isSafeInteger(Number(saved.cursor)) || Number(saved.cursor) < 0) {
				return;
			}
			const option = Array.from(sourceSelect.options).find(function (item) {
				return item.value === saved.source && !item.disabled;
			});
			if (!option) {
				return;
			}
			sourceSelect.value = saved.source;
			activeSource = saved.source;
			cursor = Number(saved.cursor);
			Object.keys(counts).forEach(function (key) {
				const value = saved.counts && Number(saved.counts[key]);
				counts[key] = Number.isFinite(value) && value >= 0 ? value : 0;
			});
			startButton.textContent = __('Riprendi', 'lingua-bridge');
			statusNode.textContent = window.LinguaBridgeImport.labels.paused + ' ' + summary();
		} catch (_) {
			try { sessionStorage.removeItem(progressKey); } catch (ignored) {}
		}
	}

	function summary() {
		const inspected = counts.groups_seen || counts.posts_seen;
		return [
			sprintf(__('Esaminati: %d', 'lingua-bridge'), inspected),
			sprintf(__('Gruppi collegati: %d', 'lingua-bridge'), counts.imported),
			sprintf(__('Bozze create: %d', 'lingua-bridge'), counts.drafts),
			sprintf(__('Già presenti: %d', 'lingua-bridge'), counts.already),
			sprintf(__('Non importati: %d', 'lingua-bridge'), counts.skipped),
			sprintf(__('Conflitti: %d', 'lingua-bridge'), counts.conflicts),
			sprintf(__('Termini collegati: %d', 'lingua-bridge'), counts.terms),
			sprintf(__('Menu delle lingue: %d', 'lingua-bridge'), counts.menus),
		].join(' · ');
	}

	function mergeCounts(batchCounts) {
		Object.keys(counts).forEach(function (key) {
			counts[key] += Number(batchCounts[key] || 0);
		});
	}

	function setIdle(message) {
		running = false;
		startButton.disabled = false;
		startButton.textContent = activeSource ? __('Riprendi', 'lingua-bridge') : __('Avvia', 'lingua-bridge');
		sourceSelect.disabled = false;
		stopButton.hidden = true;
		stopButton.disabled = false;
		progressNode.hidden = true;
		if (message) {
			statusNode.textContent = message;
		}
		persistProgress();
	}

	async function runBatch() {
		if (stopRequested) {
			setIdle(window.LinguaBridgeImport.labels.paused + ' ' + summary());
			return;
		}

		const payload = new FormData();
		payload.append('action', 'lingua_bridge_import_batch');
		payload.append('nonce', window.LinguaBridgeImport.nonce);
		payload.append('source', activeSource);
		payload.append('cursor', String(cursor));

		try {
			const response = await fetch(window.LinguaBridgeImport.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: payload,
			});
			const result = await response.json();
			if (!response.ok || !result.success) {
				const message = result && result.data && result.data.message
					? result.data.message
					: window.LinguaBridgeImport.labels.error;
				setIdle(message + ' ' + summary());
				return;
			}

			cursor = Number(result.data.cursor || cursor);
			mergeCounts(result.data.counts || {});
			persistProgress();
			statusNode.textContent = window.LinguaBridgeImport.labels.working + ' ' + summary();
			if (result.data.done) {
				setIdle(__('Importazione completata.', 'lingua-bridge') + ' ' + summary());
				activeSource = '';
				persistProgress();
				startButton.textContent = __('Avvia', 'lingua-bridge');
				return;
			}
			if (stopRequested) {
				setIdle(window.LinguaBridgeImport.labels.paused + ' ' + summary());
				return;
			}
			window.setTimeout(runBatch, 50);
		} catch (error) {
			const detail = error && error.message ? ' ' + error.message : '';
			setIdle(window.LinguaBridgeImport.labels.error + detail + ' ' + summary());
		}
	}

	startButton.addEventListener('click', function () {
		if (running) {
			return;
		}
		const selectedSource = sourceSelect.value;
		if (activeSource !== selectedSource) {
			cursor = 0;
			counts = emptyCounts();
		}
		activeSource = selectedSource;
		persistProgress();
		running = true;
		stopRequested = false;
		startButton.disabled = true;
		sourceSelect.disabled = true;
		stopButton.hidden = false;
		progressNode.hidden = false;
		statusNode.textContent = window.LinguaBridgeImport.labels.working;
		runBatch();
	});

	stopButton.addEventListener('click', function () {
		stopRequested = true;
		stopButton.disabled = true;
	});

	sourceSelect.addEventListener('change', function () {
		if (!running) {
			activeSource = '';
			cursor = 0;
			counts = emptyCounts();
			persistProgress();
			statusNode.textContent = '';
			startButton.textContent = __('Avvia', 'lingua-bridge');
		}
	});

	restoreProgress();
}());
