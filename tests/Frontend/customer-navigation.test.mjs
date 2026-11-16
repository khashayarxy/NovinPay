import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {pathToFileURL} from 'node:url';

test('Chromium: server-rendered customer state survives initialization, delayed lookups and history', t => {
    const executable = (process.env.CHROME_BIN ? [process.env.CHROME_BIN] : process.platform === 'win32'
        ? ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']
        : ['/usr/bin/google-chrome', '/usr/bin/chromium']).find(existsSync);
    if (!executable) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-customer-header-'));
    try {
        const module = 'data:text/javascript;base64,' + readFileSync(new URL('../../resources/js/customer-navigation.js', import.meta.url)).toString('base64');
        const authenticated = '<div data-customer-entry data-customer-state="authenticated"><details data-customer-menu><summary aria-controls="customer-links">Account</summary><nav id="customer-links"><a href="/account">Dashboard</a><form method="post" action="/account/logout"><input type="hidden" name="_token" value="synthetic"><button>Logout</button></form></nav></details></div>';
        const guest = '<div data-customer-entry data-customer-state="guest"><a href="/login" data-customer-guest>Guest</a></div>';
        for (const initialState of ['authenticated', 'guest']) {
            const fixture = path.join(directory, initialState + '.html');
            writeFileSync(fixture, `<!doctype html><meta charset="utf-8">
            <header data-mobile-header>
              <details class="np-mobile-menu"><summary>Navigation</summary></details>
              <div data-customer-header data-session-url="/customer-header">${initialState === 'authenticated' ? authenticated : guest}</div>
            </header><button id="outside">Outside</button><script type="module">
            try {
                const {initCustomerNavigation} = await import(${JSON.stringify(module)});
                const check = (condition, message) => { if (!condition) throw Error(message); };
                const settle = () => new Promise(resolve => setTimeout(resolve, 20));
                const host = document.querySelector('[data-customer-header]');
                const authenticated = ${JSON.stringify(authenticated)}, guest = ${JSON.stringify(guest)};
                let calls = 0, fail = false, hold = false, finish, response = authenticated;
                window.matchMedia = () => ({matches: true, addEventListener() {}});
                window.fetch = async (url, options) => {
                    calls++;
                    check(options.credentials === 'same-origin' && options.cache === 'no-store' && options.redirect === 'manual', 'private lookup');
                    if (hold) await new Promise(resolve => { finish = resolve; });
                    if (fail) throw Error('Synthetic offline');
                    return new Response(response, {headers: {'Content-Type': 'text/html'}});
                };
                const initial = host.querySelector('[data-customer-entry]');
                check(initial.dataset.customerState === '${initialState}', 'correct HTML before enhancement');
                initCustomerNavigation(); initCustomerNavigation(); await settle();
                check(calls === 0 && initial === host.firstElementChild, 'no startup fetch or replacement');
                window.dispatchEvent(new Event('focus')); await settle();
                let menu = host.querySelector('[data-customer-menu]');
                check(menu && !menu.open && !host.querySelector('[data-customer-guest]'), 'authenticated menu');
                let toggle = menu.querySelector('summary');
                toggle.focus(); toggle.click(); await settle();
                check(menu.open && toggle.getAttribute('aria-expanded') === 'true', 'native open');
                toggle.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape', bubbles:true})); await settle();
                check(!menu.open && document.activeElement === toggle, 'Escape restores focus');
                toggle.click(); await settle();
                document.querySelector('#outside').dispatchEvent(new PointerEvent('pointerdown', {bubbles:true}));
                check(!menu.open, 'outside tap closes');
                const navigation = document.querySelector('.np-mobile-menu');
                navigation.open = true; await settle(); toggle.click(); await settle();
                check(!navigation.open && menu.open, 'only one disclosure opens');
                navigation.open = true; await settle();
                check(!menu.open, 'navigation closes account');
                hold = true; response = authenticated.replace('value="synthetic"', 'value="rotated"');
                const current = host.firstElementChild;
                window.dispatchEvent(new Event('focus')); const prior = calls;
                window.dispatchEvent(new Event('focus')); await settle();
                check(calls === prior && current === host.firstElementChild && !host.querySelector('[data-customer-guest]'), 'delayed lookup never flashes guest');
                finish(); await settle(); hold = false;
                check(current === host.firstElementChild && current.querySelector('[name=_token]').value === 'rotated', 'same state preserves DOM and refreshes CSRF');
                fail = true; window.dispatchEvent(new Event('focus')); await settle();
                check(current === host.firstElementChild, 'offline preserves rendered state');
                fail = false; response = guest;
                window.dispatchEvent(new Event('focus')); await settle();
                check(host.querySelector('[data-customer-guest]') && !host.querySelector('[data-customer-menu]'), 'actual logout changes state');
                response = authenticated;
                const restored = new Event('pageshow'); Object.defineProperty(restored, 'persisted', {value:true});
                window.dispatchEvent(restored); await settle();
                check(host.querySelector('[data-customer-menu]'), 'BFCache revalidates');
                menu = host.querySelector('[data-customer-menu]');
                const form = menu.querySelector('form');
                const submit = () => form.dispatchEvent(new Event('submit', {bubbles:true,cancelable:true}));
                check(submit() && !submit(), 'duplicate logout blocked');
                hold = true; response = guest;
                window.dispatchEvent(new Event('focus'));
                window.dispatchEvent(new Event('pagehide')); finish(); await settle();
                check(host.querySelector('[data-customer-menu]') && !host.querySelector('[data-customer-guest]'), 'late reply cannot default to guest');
                document.body.textContent = 'CUSTOMER_HEADER_BROWSER_PASS';
            } catch (error) {document.body.textContent = 'CUSTOMER_HEADER_BROWSER_FAIL: ' + error.stack;}
            </script>`);
            const result = spawnSync(executable, ['--headless=new', '--disable-gpu', '--disable-background-networking', '--no-first-run',
                '--user-data-dir=' + path.join(directory, initialState + '-profile'), '--virtual-time-budget=3000', '--dump-dom', pathToFileURL(fixture).href],
            {encoding:'utf8', timeout:20000, maxBuffer:1024 * 1024, windowsHide:true});
            assert.ifError(result.error);
            assert.equal(result.status, 0);
            assert.match(result.stdout, /CUSTOMER_HEADER_BROWSER_PASS/, result.stdout.slice(-2000));
        }
    } finally {
        if (realpathSync(path.dirname(directory)) !== realpathSync(tmpdir()) || !path.basename(directory).startsWith('novinpay-customer-header-')) throw Error('Unexpected cleanup target');
        rmSync(directory, {recursive:true, force:true, maxRetries:3, retryDelay:100});
    }
});
