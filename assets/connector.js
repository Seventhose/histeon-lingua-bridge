/* Lingua Bridge — the admin tab side of the AI connector (CONNECTOR.md).
 * The tab connects to connector/lingua-bridge-connector.mjs on 127.0.0.1, waits for the agent's operations
 * (long polling) and relays each one to WordPress (admin-ajax, same origin, nonce): WordPress checks it again
 * with the permissions of the user of this tab. Only the listed operations, with only their arguments, pass. */
(function () {
	'use strict';

	const root = document.querySelector('[data-lb-ai]');
	const config = window.LinguaBridgeConnector;
	if (!root || !config) return;

	const toggle = root.querySelector('[data-lb-toggle]');
	const status = root.querySelector('[data-lb-status]');
	const log = root.querySelector('[data-lb-log]');
	const copy = root.querySelector('[data-lb-copy]');
	const instructions = root.querySelector('[data-lb-instructions]');
	const linkedKey = 'linguaBridgeConnector:linked';
	const labels = config.labels;

	let state = 'off'; // off | waiting | linked
	let tab = null;
	let key = null;
	let generation = 0;
	let retry = null;

	/** The link of this tab, kept for a reload: on/off and the last tab id and key, to replace the old link. */
	function remember(on) {
		try {
			if (on) sessionStorage.setItem(linkedKey, JSON.stringify({ tab, key }));
			else sessionStorage.removeItem(linkedKey);
		} catch (e) { /* private mode: the link just does not survive a reload */ }
	}
	function remembered() {
		try {
			const value = JSON.parse(sessionStorage.getItem(linkedKey) || 'null');
			return value && typeof value === 'object' ? value : null;
		} catch (e) {
			return null;
		}
	}

	function setState(next, message) {
		state = next;
		root.dataset.state = next;
		toggle.textContent = next === 'linked' ? labels.disconnect : next === 'waiting' ? labels.waitingButton : labels.connect;
		toggle.setAttribute('aria-pressed', next === 'off' ? 'false' : 'true');
		status.textContent = message;
	}

	function note(operation, ok, detail) {
		const empty = log.querySelector('[data-lb-empty]');
		if (empty) empty.remove();
		const item = document.createElement('li');
		item.className = ok ? 'is-ok' : 'is-error';
		const time = document.createElement('time');
		time.textContent = new Date().toLocaleTimeString();
		const name = document.createElement('code');
		name.textContent = operation;
		item.append(time, ' ', name, ' ', detail);
		log.prepend(item);
		while (log.children.length > 50) log.lastElementChild.remove();
	}

	/** A short, plain-text summary of a result for the log (never HTML from the site). */
	function summary(operation, result) {
		if (!result || typeof result !== 'object') return '';
		if (operation === 'listWork') return labels.items.replace('%d', String((result.items || []).length));
		if (operation === 'getSource' || operation === 'getPost') return '#' + result.post_id + ' ' + String(result.title || '').slice(0, 80);
		if (operation === 'saveDraft' || operation === 'saveCorrection') return labels.draft.replace('%d', String(result.post_id));
		return '';
	}

	function bridge(path, init) {
		const headers = Object.assign({}, init && init.headers);
		if (key) headers['X-LB-Tab-Key'] = key;
		return fetch(config.bridge + path + (tab ? (path.indexOf('?') === -1 ? '?' : '&') + 'tab=' + encodeURIComponent(tab) : ''), Object.assign({}, init, { headers, cache: 'no-store', credentials: 'omit' }));
	}

	async function wordpress(operation, args) {
		const body = new FormData();
		body.append('action', 'lingua_bridge_connector');
		body.append('nonce', config.nonce);
		body.append('op', operation);
		body.append('args', JSON.stringify(args));
		const response = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
		const payload = await response.json().catch(() => null);
		if (!payload || !payload.success) {
			throw new Error((payload && payload.data && payload.data.message) || labels.refused);
		}
		return payload.data;
	}

	/** The same allowlist as WordPress and the local connector: a third check, before anything leaves the tab. */
	function allowed(message) {
		if (!message || typeof message.method !== 'string' || !Object.prototype.hasOwnProperty.call(config.operations, message.method)) return false;
		const args = message.args;
		if (!args || typeof args !== 'object' || Array.isArray(args)) return false;
		return Object.keys(args).every((name) => config.operations[message.method].indexOf(name) !== -1);
	}

	async function handle(message) {
		let answer;
		if (!allowed(message)) {
			answer = { id: message && message.id, ok: false, error: labels.unknownOperation };
			note(String(message && message.method), false, labels.unknownOperation);
		} else {
			try {
				const result = await wordpress(message.method, message.args);
				answer = { id: message.id, ok: true, result };
				note(message.method, true, summary(message.method, result));
			} catch (error) {
				answer = { id: message.id, ok: false, error: error.message };
				note(message.method, false, error.message);
			}
		}
		await bridge('/tab/result', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(answer) });
	}

	async function loop(mine) {
		while (mine === generation && state === 'linked') {
			let response;
			try {
				response = await bridge('/tab/next', { method: 'GET' });
			} catch (error) {
				if (mine !== generation) return;
				tab = null;
				key = null;
				setState('waiting', labels.lost);
				return schedule(mine);
			}
			if (mine !== generation) return;
			if (response.status === 200) {
				const message = await response.json().catch(() => null);
				await handle(message).catch(() => undefined);
			} else if (response.status !== 204) {
				// The connector was restarted and no longer knows this tab: introduce it again.
				tab = null;
				key = null;
				setState('waiting', labels.lost);
				return schedule(mine);
			}
		}
	}

	function schedule(mine) {
		clearTimeout(retry);
		retry = setTimeout(() => {
			if (mine === generation && state !== 'off') void hello(mine);
		}, 3000);
	}

	const encoder = new TextEncoder();
	const base64url = (bytes) => btoa(String.fromCharCode.apply(null, Array.from(new Uint8Array(bytes)))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	let hmacKey = null;

	/** HMAC-SHA256 with the pairing secret, as the connector computes it (parts joined by "|"). */
	async function proof(parts) {
		if (!hmacKey) {
			hmacKey = await crypto.subtle.importKey('raw', encoder.encode(config.pair), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
		}
		return base64url(await crypto.subtle.sign('HMAC', hmacKey, encoder.encode(parts.join('|'))));
	}

	function same(a, b) {
		if (typeof a !== 'string' || a.length !== b.length) return false;
		let diff = 0;
		for (let i = 0; i < a.length; i += 1) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
		return diff === 0;
	}

	async function hello(mine) {
		if (!window.isSecureContext || !window.crypto || !crypto.subtle) {
			generation += 1;
			remember(false);
			setState('off', labels.insecure);
			return;
		}
		const saved = remembered();
		const previous = saved && saved.tab && saved.key ? { tab: String(saved.tab), key: String(saved.key) } : undefined;
		const nonce = Date.now().toString(36) + '.' + base64url(crypto.getRandomValues(new Uint8Array(18)));
		let response;
		try {
			response = await fetch(config.bridge + '/tab/hello', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ site: config.site, user: config.user, languages: config.languages, previous, nonce, proof: await proof(['tab', nonce, window.location.origin]) }),
				cache: 'no-store',
				credentials: 'omit',
			});
		} catch (error) {
			if (mine !== generation) return;
			setState('waiting', labels.waiting);
			schedule(mine);
			return;
		}
		try {
			if (response.status === 401) {
				// A connector started with another pairing: retrying will not help until it is restarted.
				generation += 1;
				setState('off', labels.unpaired);
				return;
			}
			if (!response.ok) throw new Error(String(response.status));
			const data = await response.json();
			if (mine !== generation) return;
			// The connector proves it knows the pairing, for this tab id and key: otherwise it is not ours.
			if (typeof data.tab !== 'string' || typeof data.key !== 'string' || !same(data.proof, await proof(['bridge', nonce, data.tab, data.key]))) {
				generation += 1;
				setState('off', labels.impostor);
				return;
			}
			tab = data.tab;
			key = data.key;
			remember(true);
			setState('linked', labels.linked);
			void loop(mine);
		} catch (error) {
			if (mine !== generation) return;
			setState('waiting', labels.waiting);
			schedule(mine);
		}
	}

	function connect() {
		generation += 1;
		// Keep the last link (if any): the next hello replaces it.
		if (!remembered()) remember(true);
		setState('waiting', labels.waiting);
		void hello(generation);
	}

	function disconnect() {
		generation += 1;
		clearTimeout(retry);
		remember(false);
		farewell();
		tab = null;
		key = null;
		setState('off', labels.off);
	}

	toggle.addEventListener('click', () => (state === 'off' ? connect() : disconnect()));

	if (copy && instructions) {
		copy.addEventListener('click', async () => {
			try {
				await navigator.clipboard.writeText(instructions.textContent);
				copy.textContent = labels.copied;
			} catch (error) {
				const range = document.createRange();
				range.selectNodeContents(instructions);
				const selection = window.getSelection();
				selection.removeAllRanges();
				selection.addRange(range);
				copy.textContent = labels.selectAndCopy;
			}
			setTimeout(() => {
				copy.textContent = labels.copy;
			}, 2500);
		});
	}

	/** A goodbye that survives the page closing: a simple request (no custom header, so no preflight). */
	function farewell() {
		if (!tab || !key) return;
		const url = config.bridge + '/tab/bye?tab=' + encodeURIComponent(tab);
		const body = JSON.stringify({ key });
		if (!(navigator.sendBeacon && navigator.sendBeacon(url, body))) {
			void fetch(url, { method: 'POST', keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' }, body }).catch(() => undefined);
		}
	}

	window.addEventListener('pagehide', farewell);

	setState('off', labels.off);
	if (remembered()) connect();
})();
