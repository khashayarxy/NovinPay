import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { existsSync } from 'node:fs';

test('shared quota companion bounds transient retries and preserves authorization failures', (t) => {
    const probe = spawnSync('pwsh', ['-NoProfile', '-Command', '$PSVersionTable.PSVersion.Major'], { encoding: 'utf8' });
    if (probe.error?.code === 'ENOENT') {
        t.skip('PowerShell is unavailable for the native quota companion regression');
        return;
    }
    const fixture = fileURLToPath(new URL('../Fixtures/recovery-quota.ps1', import.meta.url));
    const result = spawnSync('pwsh', ['-NoProfile', '-NonInteractive', '-File', fixture], { encoding: 'utf8', timeout: 20_000 });
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /QUOTA_COMPANION_REGRESSION_PASS/);
});

test('source activation receives a bound quota reply through the shared private directory', (t) => {
    const probe = spawnSync('pwsh', ['-NoProfile', '-Command', '$PSVersionTable.PSVersion.Major'], { encoding: 'utf8' });
    if (probe.error?.code === 'ENOENT') {
        t.skip('PowerShell is unavailable');
        return;
    }
    const fixture = fileURLToPath(new URL('../Fixtures/source-quota.ps1', import.meta.url));
    const result = spawnSync('pwsh', ['-NoProfile', '-NonInteractive', '-File', fixture], { encoding: 'utf8', timeout: 20_000 });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /SOURCE_QUOTA_WORKFLOW_PASS/);
    const application = spawnSync('pwsh', ['-NoProfile', '-NonInteractive', '-File', fixture, '-Application'], { encoding: 'utf8', timeout: 20_000 });
    assert.equal(application.status, 0, application.stderr || application.stdout);
    assert.match(application.stdout, /SOURCE_QUOTA_WORKFLOW_PASS/);
});

test('durable capture detects concurrent writes even when bytes are restored', (t) => {
    if (process.platform !== 'linux') {
        t.skip('This native monitor regression requires Linux inotify');
        return;
    }
    const fixture = fileURLToPath(new URL('../Fixtures/recovery-capture.py', import.meta.url));
    const result = spawnSync('python3', ['-B', fixture], { encoding: 'utf8', timeout: 15_000 });
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /CAPTURE_MONITOR_REGRESSION_PASS/);
});

test('source activation emits a structured STOP when the CLI cannot launch', (t) => {
    if (existsSync('/opt/cpanel/ea-php84/root/usr/bin/php')) {
        t.skip('The missing-CLI fixture runs outside cPanel');
        return;
    }
    const shell = process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : 'bash';
    const script = fileURLToPath(new URL('../../scripts/release/activate-source.sh', import.meta.url));
    const result = spawnSync(shell, [script, '/nonexistent-np-manifest.json', 'synthetic-pin'], { encoding: 'utf8', timeout: 10_000 });
    assert.notEqual(result.status, 0);
    const report = JSON.parse(result.stdout.trim().split('\n').at(-1));
    assert.equal(report.result, 'SOURCE_RELEASE_STOP');
    assert.equal(report.retrySafe, false);
});
