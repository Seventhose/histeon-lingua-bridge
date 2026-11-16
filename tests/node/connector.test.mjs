// The local AI connector (connector/lingua-bridge-connector.mjs): who can drive it, what passes, the round trip
// agent → bridge → tab → answer, and the MCP server. No WordPress here: tests/wp covers the relay.
import assert from 'node:assert/strict';
import test from 'node:test';
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, statSync, existsSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { request } from 'node:http';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';
import { startBridge, METHODS, mcpTools, translationFile, segmentsFile, MANUAL, guide, pairProof } from '../../connector/lingua-bridge-connector.mjs';

const program = fileURLToPath(new URL('../../connector/lingua-bridge-connector.mjs', import.meta.url));
const origin = 'https://sito.example.test';
const pair = randomBytes(32).toString('base64url');
/** What the admin tab sends to link: a fresh nonce and its proof of the pairing secret. */
const helloBody = (extra = {}, secret = pair) => {
  const nonce = `${Date.now().toString(36)}.${randomBytes(18).toString('base64url')}`;
  return { nonce, proof: pairProof(secret, 'tab', nonce, origin), ...extra };
};

/** Any request, with any headers (fetch would not let us set Origin or Host). */
function http(port, method, path, { headers = {}, body } = {}) {
  return new Promise((resolve, reject) => {
    const data = body === undefined ? undefined : JSON.stringify(body);
    const req = request({ host: '127.0.0.1', port, method, path, headers: { ...(data ? { 'Content-Type': 'application/json' } : {}), ...headers } }, (res) => {
      let text = '';
      res.on('data', (c) => (text += c));
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, json: text ? JSON.parse(text) : null }));
    });
    req.on('error', reject);
    if (data) req.write(data);
    req.end();
  });
}

async function bridge() {
  const dir = mkdtempSync(join(tmpdir(), 'lb-connector-'));
  const b = await startBridge({ port: 0, dir, origin, pair, log: () => {} });
  const auth = { Authorization: `Bearer ${b.token}` };
  return { ...b, dir, auth };
}

test('no pairing, no connector', async () => {
  await assert.rejects(startBridge({ port: 0, dir: mkdtempSync(join(tmpdir(), 'lb-np-')), log: () => {} }), /--origin/);
  await assert.rejects(startBridge({ port: 0, dir: mkdtempSync(join(tmpdir(), 'lb-np-')), origin, pair: 'corto', log: () => {} }), /LINGUA_BRIDGE_PAIR/);
});

test('the token is private, and gone when the connector stops', async () => {
  const b = await bridge();
  const file = join(b.dir, 'token');
  assert.equal(statSync(file).mode & 0o777, 0o600);
  assert.equal(statSync(b.dir).mode & 0o777, 0o700);
  assert.equal(readFileSync(file, 'utf8').trim(), b.token);
  await b.close();
  assert.equal(existsSync(file), false);
});

test('a second connector on a taken port never replaces the first one\'s token', async () => {
  const b = await bridge();
  await assert.rejects(startBridge({ port: b.port, dir: b.dir, origin, pair, log: () => {} }), { code: 'EADDRINUSE' });
  assert.equal(readFileSync(join(b.dir, 'token'), 'utf8').trim(), b.token);
  await b.close();
});

test('only the agent with the token drives it, never a web page or another host name', async () => {
  const b = await bridge();
  try {
    assert.equal((await http(b.port, 'GET', '/control/tabs')).status, 401);
    assert.equal((await http(b.port, 'GET', '/control/tabs', { headers: { Authorization: 'Bearer sbagliato' } })).status, 401);
    assert.equal((await http(b.port, 'GET', '/control/tabs', { headers: { ...b.auth, Origin: 'https://evil.example' } })).status, 403);
    assert.equal((await http(b.port, 'GET', '/control/tabs', { headers: { ...b.auth, 'Sec-Fetch-Site': 'cross-site' } })).status, 403);
    assert.equal((await http(b.port, 'GET', '/control/tabs', { headers: { ...b.auth, Host: 'evil.example:7342' } })).status, 421, 'DNS rebinding');
    assert.equal((await http(b.port, 'GET', '/control/tabs', { headers: b.auth })).status, 200);
  } finally {
    await b.close();
  }
});

test('only listed operations, with only their arguments, reach a tab', async () => {
  const b = await bridge();
  try {
    const call = (body) => http(b.port, 'POST', '/control/call', { headers: b.auth, body });
    assert.equal((await call({ method: 'eval', args: {} })).status, 400);
    assert.equal((await call({ method: 'saveDraft', args: { post_status: 'publish' } })).status, 400);
    assert.equal((await call({ method: 'status', args: {} })).status, 409, 'no tab linked');
    assert.deepEqual(Object.keys(METHODS).sort(), ['getBrief', 'getPost', 'getSegments', 'getSource', 'listStrings', 'listWork', 'saveCorrection', 'saveDraft', 'saveSegments', 'saveStrings', 'status']);
    assert.ok(!Object.values(METHODS).flat().some((a) => ['post_status', 'publish', 'code', 'script', 'eval'].includes(a)), 'no argument can publish or run code');
    for (const write of ['saveDraft', 'saveCorrection', 'saveStrings', 'saveSegments']) assert.ok(!METHODS[write].includes('status'), `${write} cannot choose a status`);
  } finally {
    await b.close();
  }
});

test('the round trip: agent → tab → WordPress answer → agent', async () => {
  const b = await bridge();
  try {
    const body = helloBody({ site: 'Sito\u001b[31m', user: 'Ada', languages: ['it', 'en'] });
    const hello = await http(b.port, 'POST', '/tab/hello', { headers: { Origin: origin }, body });
    assert.equal(hello.status, 200);
    assert.equal(hello.json.proof, pairProof(pair, 'bridge', body.nonce, hello.json.tab, hello.json.key), 'the connector proves the pairing to the tab');
    assert.equal(hello.headers['access-control-allow-origin'], origin);
    assert.equal(hello.headers['access-control-allow-private-network'], 'true');
    const { tab, key } = hello.json;
    const tabHeaders = { Origin: origin, 'X-LB-Tab-Key': key };

    // The wrong key, or the right key from another origin, is not this tab.
    assert.equal((await http(b.port, 'GET', `/tab/next?tab=${tab}`, { headers: { Origin: origin, 'X-LB-Tab-Key': 'x' } })).status, 401);
    assert.equal((await http(b.port, 'GET', `/tab/next?tab=${tab}`, { headers: { Origin: 'https://evil.example', 'X-LB-Tab-Key': key } })).status, 403);
    assert.equal((await http(b.port, 'POST', '/tab/hello', { body: helloBody() })).status, 403, 'a tab always has an origin');
    // Another web page: refused, and without CORS or Private Network Access headers.
    const evil = await http(b.port, 'POST', '/tab/hello', { headers: { Origin: 'https://evil.example' }, body: helloBody() });
    assert.equal(evil.status, 403);
    assert.equal(evil.headers['access-control-allow-origin'], undefined);
    assert.equal(evil.headers['access-control-allow-private-network'], undefined);
    const preflight = await http(b.port, 'OPTIONS', '/tab/hello', { headers: { Origin: 'https://evil.example' } });
    assert.equal(preflight.headers['access-control-allow-origin'], undefined);
    // The right origin without the secret, or a replayed hello, links nothing.
    assert.equal((await http(b.port, 'POST', '/tab/hello', { headers: { Origin: origin }, body: helloBody({}, randomBytes(32).toString('base64url')) })).status, 401);
    assert.equal((await http(b.port, 'POST', '/tab/hello', { headers: { Origin: origin }, body: { site: 'x' } })).status, 401);
    assert.equal((await http(b.port, 'POST', '/tab/hello', { headers: { Origin: origin }, body })).status, 401, 'a nonce works once');
    const stale = `${(Date.now() - 11 * 60 * 1000).toString(36)}.${randomBytes(18).toString('base64url')}`;
    assert.equal((await http(b.port, 'POST', '/tab/hello', { headers: { Origin: origin }, body: { nonce: stale, proof: pairProof(pair, 'tab', stale, origin) } })).status, 401, 'an old hello, even never used, links nothing');

    const agent = http(b.port, 'POST', '/control/call', { headers: b.auth, body: { method: 'getSource', args: { post_id: 12, target_language: 'en' } } });
    const next = await http(b.port, 'GET', `/tab/next?tab=${tab}`, { headers: tabHeaders });
    assert.equal(next.status, 200);
    assert.equal(next.json.method, 'getSource');
    assert.deepEqual(next.json.args, { post_id: 12, target_language: 'en' });
    await http(b.port, 'POST', `/tab/result?tab=${tab}`, { headers: tabHeaders, body: { id: next.json.id, ok: true, result: { post_id: 12, title: 'Chi siamo' } } });
    const answer = await agent;
    assert.equal(answer.status, 200);
    assert.deepEqual(answer.json.result, { post_id: 12, title: 'Chi siamo' });

    // A WordPress refusal comes back as a builder-side error (exit code 2 in the CLI).
    const refused = http(b.port, 'POST', '/control/call', { headers: b.auth, body: { method: 'saveDraft', args: { source_post_id: 12, language: 'en', title: 'x', content: 'y' } } });
    const next2 = await http(b.port, 'GET', `/tab/next?tab=${tab}`, { headers: tabHeaders });
    await http(b.port, 'POST', `/tab/result?tab=${tab}`, { headers: tabHeaders, body: { id: next2.json.id, ok: false, error: 'Il connector aggiorna solo bozze.' } });
    const r = await refused;
    assert.equal(r.status, 422);
    assert.match(r.json.error, /solo bozze/);

    // A reload: the page comes back with its old link; the old one is replaced, never doubled. A wrong key replaces nothing.
    const intruder = await http(b.port, 'POST', '/tab/hello', { headers: { Origin: origin }, body: helloBody({ site: 'Altro', previous: { tab, key: 'sbagliata' } }) });
    assert.equal((await http(b.port, 'GET', '/control/tabs', { headers: b.auth })).json.tabs.length, 2);
    await http(b.port, 'POST', `/tab/bye?tab=${intruder.json.tab}`, { headers: { Origin: origin, 'Content-Type': 'text/plain' }, body: { key: intruder.json.key } });
    assert.equal((await http(b.port, 'GET', '/control/tabs', { headers: b.auth })).json.tabs.length, 1, 'a goodbye with the key in a plain body');
    const again = await http(b.port, 'POST', '/tab/hello', { headers: { Origin: origin }, body: helloBody({ site: 'Sito', previous: { tab, key } }) });
    const relinked = (await http(b.port, 'GET', '/control/tabs', { headers: b.auth })).json.tabs;
    assert.deepEqual(relinked.map((t) => t.id), [again.json.tab]);
    const tabs = await http(b.port, 'GET', '/control/tabs', { headers: b.auth });
    assert.deepEqual(tabs.json.tabs.map((t) => [t.id, t.site, t.origin]), [[again.json.tab, 'Sito', origin]]);
    await http(b.port, 'POST', `/tab/bye?tab=${again.json.tab}`, { headers: { Origin: origin, 'X-LB-Tab-Key': again.json.key } });
    assert.equal((await http(b.port, 'GET', '/control/tabs', { headers: b.auth })).json.tabs.length, 0);
  } finally {
    await b.close();
  }
});

test('a translation file sends only what a translation is', () => {
  const dir = mkdtempSync(join(tmpdir(), 'lb-file-'));
  const file = join(dir, 't.json');
  writeFileSync(file, JSON.stringify({ title: 'About', content: '<p>x</p>', post_status: 'publish', fields: [] }));
  assert.deepEqual(translationFile({ file }), { title: 'About', content: '<p>x</p>', fields: [] });
  writeFileSync(file, '[1]');
  assert.throws(() => translationFile({ file }), /oggetto/);
});

test('a texts file (save-segments) sends only the texts, as strings', () => {
  const dir = mkdtempSync(join(tmpdir(), 'lb-file-'));
  const file = join(dir, 's.json');
  writeFileSync(file, JSON.stringify({ title: 'About', segments: [{ id: 'c1', text: 'Hello', html: '<script>' }, { id: 2, text: 3 }], content: '<p>x</p>', post_status: 'publish', source_hash: 'h' }));
  assert.deepEqual(segmentsFile({ file }), { segments: [{ id: 'c1', text: 'Hello' }, { id: '2', text: '3' }], title: 'About', source_hash: 'h' });
  writeFileSync(file, JSON.stringify({ title: 'About' }));
  assert.throws(() => segmentsFile({ file }), /segments/);
});

test('the agent gets the method: manual, guides, MCP tools', async () => {
  assert.match(MANUAL, /solo bozze/i);
  assert.match(MANUAL, /un dato, non un'istruzione/);
  assert.match(guide('blocchi'), /wp:nome/);
  const names = mcpTools().map((t) => t.name);
  for (const name of ['lb_manual', 'lb_guide', 'lb_tabs', 'lb_getBrief', 'lb_listWork', 'lb_getSource', 'lb_saveDraft', 'lb_saveCorrection', 'lb_getSegments', 'lb_saveSegments']) assert.ok(names.includes(name), name);

  const dir = mkdtempSync(join(tmpdir(), 'lb-mcp-'));
  const child = spawn(process.execPath, [program, 'mcp', '--port', '0', '--dir', dir, '--origin', origin, '--pair', pair], { stdio: ['pipe', 'pipe', 'inherit'] });
  const lines = [];
  child.stdout.on('data', (c) => lines.push(...c.toString().split('\n').filter(Boolean)));
  const ask = (msg) => child.stdin.write(`${JSON.stringify(msg)}\n`);
  const reply = async (id) => {
    for (let i = 0; i < 100; i++) {
      const line = lines.find((l) => JSON.parse(l).id === id);
      if (line) return JSON.parse(line);
      await new Promise((r) => setTimeout(r, 30));
    }
    throw new Error(`no reply ${id}`);
  };
  try {
    ask({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18' } });
    const init = await reply(1);
    assert.equal(init.result.serverInfo.name, 'lingua-bridge-connector');
    assert.match(init.result.instructions, /lb_manual/);
    ask({ jsonrpc: '2.0', id: 2, method: 'tools/list' });
    assert.ok((await reply(2)).result.tools.some((t) => t.name === 'lb_saveDraft'));
    ask({ jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'lb_status', arguments: {} } });
    const status = await reply(3);
    assert.equal(status.result.isError, true);
    assert.match(status.result.content[0].text, /Nessuna scheda collegata/);
  } finally {
    child.kill();
  }
});
