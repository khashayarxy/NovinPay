import {test} from 'node:test';
import assert from 'node:assert/strict';
import {existsSync, mkdtempSync, readFileSync, writeFileSync, rmSync, realpathSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {pathToFileURL} from 'node:url';

test('Chromium: map stays inert until explicit activation, loads once and recovers from failure', t => {
    const executable = (process.env.CHROME_BIN ? [process.env.CHROME_BIN] : process.platform === 'win32'
        ? ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']
        : ['/usr/bin/google-chrome', '/usr/bin/chromium']).find(existsSync);
    if (!executable) {t.skip('Chromium unavailable; real map acceptance remains pending'); return;}
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-click-map-'));
    try {
        const module = 'data:text/javascript;base64,' + readFileSync(new URL('../../resources/js/click-map.js', import.meta.url)).toString('base64');
        const html = `<!doctype html><meta charset="utf-8"><body>
        <div data-map-preview data-map-url="https://neshan.org/maps/iframe/places/approved#location">
          <div data-map-placeholder><button data-map-activate hidden>مشاهده در نقشه</button></div>
          <p data-map-status role="status"></p><a href="https://nshn.ir/example">نشان</a>
        </div><script type="module">
        try {
          const {initClickMap} = await import(${JSON.stringify(module)});
          const check = (value, message) => {if (!value) throw Error(message);};
          // Suppress external traffic while retaining real DOM/EventTarget methods.
          let requested = [];
          Object.defineProperty(HTMLIFrameElement.prototype, 'src', {set(value) {requested.push(value);}, get() {return requested.at(-1);}});
          let timers = new Map(), next = 0;
          window.setTimeout = fn => {timers.set(++next, fn); return next;};
          window.clearTimeout = id => timers.delete(id);
          initClickMap(); initClickMap();
          const button = document.querySelector('button'), root = document.querySelector('[data-map-preview]');
          check(!button.hidden && !document.querySelector('iframe') && requested.length === 0, 'no eager provider request');
          root.dispatchEvent(new Event('touchmove', {bubbles: true, cancelable: true}));
          check(requested.length === 0, 'scroll must not activate');
          button.focus(); button.click();
          check(requested.length === 1 && button.disabled, 'explicit activation');
          button.click(); check(requested.length === 1, 'no duplicate initialization');
          let frame = document.querySelector('iframe');
          check(frame.title && frame.referrerPolicy === 'strict-origin-when-cross-origin', 'accessible approved frame');
          frame.dispatchEvent(new Event('error'));
          check(!document.querySelector('iframe') && !button.disabled && !document.querySelector('[data-map-placeholder]').hidden, 'error recovery');
          check(document.querySelector('[data-map-status]').textContent.includes('بارگذاری نشد') && document.querySelector('a').href, 'useful external fallback');
          button.click(); frame = document.querySelector('iframe'); frame.dispatchEvent(new Event('load'));
          check(requested.length === 2 && timers.size === 0, 'retry loads once and clears timer');
          check(document.querySelector('a'), 'fallback survives cross-origin load');
          frame.dispatchEvent(new Event('error'));
          button.click();
          [...timers.values()][0]();
          check(!document.querySelector('iframe') && !button.disabled, 'timeout restores a usable preview');
          frame.dispatchEvent(new Event('load'));
          check(document.querySelector('[data-map-status]').textContent.includes('بارگذاری نشد'), 'stale load must not erase failure');
          document.body.textContent = 'CLICK_MAP_BROWSER_PASS';
        } catch (error) {document.body.textContent = 'CLICK_MAP_BROWSER_FAIL: ' + error.stack;}
        </script>`;
        const fixture = path.join(directory, 'fixture.html'); writeFileSync(fixture, html);
        const result = spawnSync(executable, ['--headless=new', '--disable-gpu', '--disable-background-networking', '--no-first-run', '--user-data-dir=' + path.join(directory, 'profile'), '--virtual-time-budget=1000', '--dump-dom', pathToFileURL(fixture).href], {encoding: 'utf8', timeout: 20000, maxBuffer: 1024 * 1024, windowsHide: true});
        assert.ifError(result.error); assert.equal(result.status, 0);
        assert.match(result.stdout, /CLICK_MAP_BROWSER_PASS/, result.stdout.slice(-2500));
    } finally {
        if (realpathSync(path.dirname(directory)) !== realpathSync(tmpdir()) || !path.basename(directory).startsWith('novinpay-click-map-')) throw Error('Unexpected cleanup target');
        rmSync(directory, {recursive: true, force: true, maxRetries: 3, retryDelay: 100});
    }
});
