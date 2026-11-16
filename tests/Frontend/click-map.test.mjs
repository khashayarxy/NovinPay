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
          <div data-map-viewport><div data-map-placeholder aria-hidden="true"></div><div class="np-map-preview__action"><button data-map-activate hidden>مشاهده در نقشه</button></div><p data-map-status role="status"></p></div><a data-map-fallback hidden href="https://nshn.ir/example">نشان</a>
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
          let observe;
          window.IntersectionObserver = class {
            constructor(callback) {observe = callback;}
            observe() {}
            disconnect() {}
          };
          initClickMap(); initClickMap();
          const button = document.querySelector('button'), root = document.querySelector('[data-map-preview]');
          const viewport = document.querySelector('[data-map-viewport]');
          const overlay = document.querySelector('[data-map-placeholder]');
          const fallback = document.querySelector('[data-map-fallback]');
          root.addEventListener('load', event => {if (event.isTrusted) event.stopImmediatePropagation();}, true);
          check(!button.hidden && !document.querySelector('iframe') && requested.length === 0, 'no eager provider request');
          observe([{target: viewport, isIntersecting: false}]);
          check(requested.length === 0, 'far viewport stays unloaded');
          observe([{target: viewport, isIntersecting: true}]);
          let frame = document.querySelector('iframe');
          check(requested.length === 1 && frame.inert && frame.tabIndex === -1 && !overlay.hidden && fallback.hidden, 'near viewport loads an inert preview');
          frame.dispatchEvent(new Event('load'));
          check(!overlay.hidden && fallback.hidden && frame.inert, 'loaded map remains gated');
          button.focus(); button.click();
          check(overlay.hidden && button.hidden && !frame.inert && frame.tabIndex === 0 && !fallback.hidden && document.activeElement === frame, 'keyboard activation enables the loaded map');
          check(requested.length === 1 && timers.size === 0, 'no duplicate initialization');
          check(frame.title && frame.loading === 'lazy' && frame.referrerPolicy === 'strict-origin-when-cross-origin', 'accessible approved frame');
          // A separate instance exercises click-before-load, timeout and retry.
          const second = root.cloneNode(true);
          second.removeAttribute('data-map-bound');
          second.removeAttribute('data-map-active');
          second.removeAttribute('data-map-state');
          second.querySelector('iframe').remove();
          second.querySelector('[data-map-placeholder]').hidden = false;
          document.body.append(second);
          initClickMap();
          const retry = second.querySelector('button');
          retry.click();
          frame = second.querySelector('iframe');
          check(retry.disabled && !second.querySelector('[data-map-placeholder]').hidden, 'loading retains overlay');
          [...timers.values()][0]();
          check(!second.querySelector('iframe') && !retry.disabled && second.dataset.mapState === 'error', 'timeout recovers');
          frame.dispatchEvent(new Event('load'));
          check(second.dataset.mapState === 'error', 'stale load cannot erase failure');
          retry.click();
          frame = second.querySelector('iframe');
          frame.dispatchEvent(new Event('error'));
          check(!second.querySelector('iframe') && !retry.disabled, 'error remains retryable');
          retry.click(); frame = second.querySelector('iframe');
          frame.dispatchEvent(new Event('load'));
          check(second.querySelector('[data-map-placeholder]').hidden && timers.size === 0, 'successful retry activates');
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
