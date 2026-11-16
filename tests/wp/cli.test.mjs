// The `wp lingua` commands (N6 of the 1.5.10 verification) on a real WordPress (dev/test.sh): they run as the
// user given with --user, never as nobody, and they write drafts only.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';

const run = (...args) => spawnSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8' });
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });

test('wp lingua: status, create and xliff as a user, nothing without one', () => {
  const nobody = run('lingua', 'status');
  assert.notEqual(nobody.status, 0, 'without --user the command stops');
  assert.match(nobody.stderr, /--user/);

  const status = JSON.parse(wp('lingua', 'status', '--user=admin', '--format=json'));
  assert.deepEqual(status.map((row) => row.lingua).sort(), ['en', 'it']);
  assert.equal(status.find((row) => row.lingua === 'it').originale, 'sì');

  const id = wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=LB cli', '--post_content=<p>Ciao dalla riga di comando</p>', '--porcelain').trim();
  try {
    const created = run('lingua', 'create', id, '--to=en', '--user=admin');
    assert.equal(created.status, 0, created.stderr);
    const draft = Number(created.stdout.match(/→ (\d+)/)?.[1]);
    assert.ok(draft > 0, created.stdout);
    assert.equal(wp('post', 'get', String(draft), '--field=post_status').trim(), 'draft', 'a draft, never published');
    assert.match(wp('lingua', 'xliff', 'export', id, '--to=en', '--user=admin'), /Ciao dalla riga di comando/);
    assert.notEqual(run('lingua', 'create', id, '--to=xx', '--user=admin').status, 0, 'an unknown language is refused');
    wp('post', 'delete', String(draft), '--force');
  } finally {
    wp('post', 'delete', id, '--force');
  }
});
