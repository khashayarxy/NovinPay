import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {pathToFileURL} from 'node:url';

test('Chromium: customer disclosure, private lookup, logout and session recovery', t => {
    const executable = (process.env.CHROME_BIN ? [process.env.CHROME_BIN] : process.platform === 'win32'
        ? ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']
        : ['/usr/bin/google-chrome', '/usr/bin/chromium']).find(existsSync);
    if (!executable) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-customer-header-'));
    try {
        const module = 'data:text/javascript;base64,' + readFileSync(new URL('../../resources/js/customer-navigation.js', import.meta.url)).toString('base64');
        const fixture = path.join(directory, 'fixture.html');
        writeFileSync(fixture, `<!doctype html><meta charset="utf-8">
        <header data-mobile-header>
          <details class="np-mobile-menu"><summary>Navigation</summary></details>
          <div data-customer-header data-session-url="/customer-header"><a href="/login" data-customer-guest>Guest</a></div>
        </header><button id="outside">Outside</button><script type="module">
        try {
            const {initCustomerNavigation} = await import(${JSON.stringify(module)});
            const check = (condition, message) => { if (!condition) throw Error(message); };
            const settle = () => new Promise(resolve => setTimeout(resolve, 20));
            let mobile = false, change, calls = 0, status = 200, fail = false, hold = false, finish;
            window.matchMedia = () => ({get matches() {return mobile;}, addEventListener(name, callback) {change = callback;}});
            const markup = '<details data-customer-menu><summary aria-controls="customer-links">Account</summary><nav id="customer-links"><a href="/account">Dashboard</a><form method="post" action="/account/logout"><input type="hidden" name="_token" value="synthetic"><button>Logout</button></form></nav></details>';
            window.fetch = async (url, options) => {
                calls++;
                check(options.credentials === 'same-origin' && options.cache === 'no-store' && options.redirect === 'manual', 'private same-origin request');
                if (hold) await new Promise(resolve => { finish = resolve; });
                if (fail) throw Error('Synthetic offline');
                return new Response(markup, {status, headers: {'Content-Type': 'text/html'}});
            };
            initCustomerNavigation(); initCustomerNavigation();
            check(calls === 0, 'desktop makes no session lookup');
            mobile = true; change(); await settle();
            let menu = document.querySelector('[data-customer-menu]'), guest = document.querySelector('[data-customer-guest]');
            check(calls === 1 && menu && !menu.open && guest.hidden, 'mobile initializes once');
            let toggle = menu.querySelector('summary');
            toggle.focus(); toggle.click(); await settle();
            check(menu.open && toggle.getAttribute('aria-expanded') === 'true', 'native activation opens account');
            toggle.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true})); await settle();
            check(!menu.open && toggle.getAttribute('aria-expanded') === 'false' && document.activeElement === toggle, 'Escape closes and restores focus');
            toggle.click(); await settle();
            document.querySelector('#outside').dispatchEvent(new PointerEvent('pointerdown', {bubbles:true}));
            check(!menu.open, 'outside tap closes');
            toggle.click(); await settle(); document.querySelector('#outside').focus();
            check(!menu.open, 'normal keyboard tab exit closes');
            const navigation = document.querySelector('.np-mobile-menu');
            navigation.open = true; await settle(); toggle.click(); await settle();
            check(!navigation.open && menu.open, 'only one disclosure remains open');
            navigation.open = true; await settle();
            check(!menu.open, 'opening navigation closes account');
            const form = menu.querySelector('form');
            const submit = () => form.dispatchEvent(new Event('submit', {bubbles:true,cancelable:true}));
            check(submit() && !submit(), 'logout accepts one submission');
            status = 302; window.dispatchEvent(new Event('focus')); await settle();
            check(!guest.hidden && !document.querySelector('[data-customer-menu]'), 'logout refresh restores guest');
            fail = true; window.dispatchEvent(new Event('focus')); await settle();
            check(!guest.hidden && !document.querySelector('[data-customer-menu]'), 'offline keeps usable login link');
            fail = false; status = 200; hold = true;
            window.dispatchEvent(new Event('focus')); const prior = calls;
            window.dispatchEvent(new Event('focus'));
            check(calls === prior, 'overlapping requests suppressed');
            window.dispatchEvent(new Event('pagehide')); finish(); await settle();
            check(!document.querySelector('[data-customer-menu]') && !guest.hidden, 'late response cannot restore cached private UI');
            hold = false;
            const restored = new Event('pageshow'); Object.defineProperty(restored, 'persisted', {value:true});
            window.dispatchEvent(restored); await settle();
            check(guest.hidden && !!document.querySelector('[data-customer-menu]'), 'BFCache revalidates session');
            mobile = false; change();
            check(!document.querySelector('[data-customer-menu]'), 'desktop resize closes private UI');
            document.body.textContent = 'CUSTOMER_HEADER_BROWSER_PASS';
        } catch (error) {document.body.textContent = 'CUSTOMER_HEADER_BROWSER_FAIL: ' + error.stack;}
        </script>`);
        const result = spawnSync(executable, ['--headless=new', '--disable-gpu', '--disable-background-networking', '--no-first-run',
            '--user-data-dir=' + path.join(directory, 'profile'), '--virtual-time-budget=3000', '--dump-dom', pathToFileURL(fixture).href],
        {encoding:'utf8', timeout:20000, maxBuffer:1024 * 1024, windowsHide:true});
        assert.ifError(result.error);
        assert.equal(result.status, 0);
        assert.match(result.stdout, /CUSTOMER_HEADER_BROWSER_PASS/, result.stdout.slice(-2000));
    } finally {
        if (realpathSync(path.dirname(directory)) !== realpathSync(tmpdir()) || !path.basename(directory).startsWith('novinpay-customer-header-')) throw Error('Unexpected cleanup target');
        rmSync(directory, {recursive:true, force:true, maxRetries:3, retryDelay:100});
    }
});
