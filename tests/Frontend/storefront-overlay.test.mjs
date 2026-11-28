import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {pathToFileURL} from 'node:url';

test('Chromium: storefront layers retain the page geometry and cart reads retain unconfirmed content', t => {
    const executable = (process.env.CHROME_BIN ? [process.env.CHROME_BIN] : process.platform === 'win32'
        ? ['C:/Program Files/Google/Chrome/Application/chrome.exe']
        : ['/usr/bin/google-chrome', '/usr/bin/chromium']).find(existsSync);
    if (!executable) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-storefront-overlays-'));
    try {
        const source = name => readFileSync(new URL('../../public/js/' + name + '.js', import.meta.url), 'utf8');
        const fixture = path.join(directory, 'fixture.html');
        writeFileSync(fixture, `<!doctype html><html dir="rtl"><meta charset="utf-8">
        <style>
            body { margin:0; min-height:2200px; }
            header { position:sticky; top:0; height:48px; background:#fff; }
            main { width:calc(100% - 32px); max-width:800px; margin-inline:auto; height:400px; }
            #fixed { position:fixed; bottom:0; width:50%; left:25%; }
            [data-cart-status]:empty { visibility:hidden; }
        </style>
        <header><button data-storefront-open="cart-dialog">Cart</button><button data-storefront-open="search">Search</button></header>
        <main id="background">Page</main><div id="fixed">Floating</div>
        <dialog id="cart-dialog" data-storefront-dialog data-cart-url="/cart">
            <button data-storefront-close>Close</button>
            <p data-cart-status role="status" hidden></p><div data-live-cart></div><p data-cart-error hidden></p>
            <footer data-cart-footer hidden><div data-cart-summary></div><button data-cart-retry hidden>Retry</button></footer>
            <template data-empty-cart><p class="empty-cart">Empty</p></template>
            <button data-storefront-open="search">Search from cart</button>
        </dialog>
        <dialog id="search" data-storefront-dialog><button data-storefront-close>Close</button><input></dialog>
        <script>
        let reads = 0, writes = 0, finishRead = null;
        window.fetch = (url, options) => {
            if (options.method === 'POST') {
                ++writes;
                return Promise.resolve(new Response(JSON.stringify({count:1}), {headers:{'Content-Type':'application/json'}}));
            }
            ++reads;
            return new Promise((resolve, reject) => {
                finishRead = code => resolve(new Response('<section data-storefront-cart><p>Sample line</p><form data-cart-mutation method="post" action="/cart/change"><button name="scope" value="device" type="submit">Change</button></form></section>', {status:code,headers:{'Content-Type':'text/html'}}));
                options.signal.addEventListener('abort', reject, {once:true});
            });
        };
        </script>
        <script>${source('storefront-scroll-lock')}</script>
        <script>${source('storefront-premium')}</script>
        <script>
        ;(async () => {
            const wait = (time = 20) => new Promise(resolve => setTimeout(resolve,time));
            const check = (condition, message) => { if (!condition) throw Error(message); };
            const cart = document.querySelector('#cart-dialog'), search = document.querySelector('#search');
            const opener = document.querySelector('header button');
            const geo = () => JSON.stringify([
                scrollY,...['header','#background','#fixed'].map(selector => {
                    const {x,y,width,height} = document.querySelector(selector).getBoundingClientRect();
                    return [x,y,width,height];
                })
            ]);
            scrollTo(0,240);
            const expected = geo();
            check(innerWidth > document.documentElement.clientWidth, 'classic scrollbar is required for this test');
            opener.click();
            check(cart.open && reads === 1 && geo() === expected, 'drawer opens immediately and locks without shifting');
            check(!cart.querySelector('[data-cart-status]').textContent, 'fast reads have no waiting text');
            finishRead(200); await wait();
            const line = cart.querySelector('[data-live-cart] p');
            check(line?.textContent === 'Sample line', 'confirmed cart renders');
            const mutation = cart.querySelector('form button');
            mutation.click(); mutation.click(); await wait(220);
            check(reads === 2 && writes === 1, 'one mutation and one reconciliation read');
            check(line.isConnected && line.parentElement.parentElement.inert && cart.querySelector('[data-cart-status]').textContent, 'pending read retains and labels unconfirmed content');
            finishRead(503); await wait();
            check(line.isConnected && line.parentElement.parentElement.inert && !cart.querySelector('[data-cart-retry]').hidden, 'failed read leaves old content unconfirmed');
            cart.querySelector('[data-cart-retry]').click(); await wait();
            check(reads === 3 && geo() === expected, 'explicit retry starts one read without moving the page');
            cart.querySelector('[data-storefront-open="search"]').click();
            check(!cart.open && search.open && !line.isConnected && geo() === expected, 'switch keeps lock and discards old cart');
            finishRead(200); await wait();
            check(!cart.querySelector('[data-live-cart]').childElementCount, 'late response cannot populate a hidden drawer');
            window.StorefrontOverlays.close(); await wait(100);
            check(cart.open && geo() === expected && reads === 4, 'Back returns to the previous layer with a fresh read');
            window.StorefrontOverlays.close(); await wait(120);
            check(!cart.open && opener === document.activeElement && geo() === expected, 'last Back restores focus and the original geometry');
            document.body.textContent = 'STOREFRONT_OVERLAYS_PASS';
        })().catch(error => { document.body.textContent = 'STOREFRONT_OVERLAYS_FAIL: '+error.message; });
        </script></html>`);
        const result = spawnSync(executable, ['--headless=new', '--disable-gpu', '--disable-background-networking',
            '--no-first-run', '--user-data-dir=' + path.join(directory, 'profile'), '--virtual-time-budget=4500',
            '--dump-dom', pathToFileURL(fixture).href], {encoding:'utf8', timeout:20000, maxBuffer:1024 * 1024, windowsHide:true});
        assert.ifError(result.error);
        assert.equal(result.status, 0);
        assert.ok(/<body[^>]*>STOREFRONT_OVERLAYS_PASS<\/body>/.test(result.stdout), result.stdout.slice(-400));
    } finally {
        if (realpathSync(path.dirname(directory)) !== realpathSync(tmpdir()) || !path.basename(directory).startsWith('novinpay-storefront-overlays-')) throw Error('Unexpected cleanup target');
        rmSync(directory, {recursive:true, force:true, maxRetries:3, retryDelay:100});
    }
});

test('Chromium: mobile bottom navigation still auto-hides on scroll after closing cart drawer', t => {
    const executable = (process.env.CHROME_BIN ? [process.env.CHROME_BIN] : process.platform === 'win32'
        ? ['C:/Program Files/Google/Chrome/Application/chrome.exe']
        : ['/usr/bin/google-chrome', '/usr/bin/chromium']).find(existsSync);
    if (!executable) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-storefront-overlays-'));
    try {
        const source = name => readFileSync(new URL('../../public/js/' + name + '.js', import.meta.url), 'utf8');
        const fixture = path.join(directory, 'mobile-nav-fixture.html');
        writeFileSync(fixture, `<!doctype html><html dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <style>
            body { margin:0; min-height:2400px; }
            nav[data-mobile-bottom-nav] { position:fixed; bottom:0; inset-inline:0; height:56px; background:#fff; }
            dialog.store-cart-drawer[open].is-closing { animation: close-out 40ms linear forwards; }
            @keyframes close-out { from { transform: translateY(0); } to { transform: translateY(100%); } }
        </style>
        <nav data-mobile-bottom-nav><a href="/cart" data-storefront-open="cart-dialog">Cart</a></nav>
        <dialog id="cart-dialog" class="store-cart-drawer" data-storefront-dialog data-cart-url="/cart" data-cart-preload>
            <button data-storefront-close>Close</button>
            <p data-cart-status role="status" hidden></p><div data-live-cart></div><p data-cart-error hidden></p>
            <footer data-cart-footer hidden><div data-cart-summary></div><button data-cart-retry hidden>Retry</button></footer>
            <template data-empty-cart><p class="empty-cart">Empty</p></template>
        </dialog>
        <script>window.requestAnimationFrame = fn => setTimeout(fn, 16);</script>
        <script>${source('storefront-scroll-lock')}</script>
        <script>${source('storefront-premium')}</script>
        <script>
        ;(async () => {
            const wait = (time = 150) => new Promise(resolve => setTimeout(resolve, time));
            const check = (condition, message) => { if (!condition) throw Error(message); };
            const nav = document.querySelector('[data-mobile-bottom-nav]');
            const cart = document.querySelector('#cart-dialog');
            const opener = nav.querySelector('[data-storefront-open="cart-dialog"]');
            await wait();
            scrollTo(0, 260); window.dispatchEvent(new Event('scroll')); await wait();
            check(nav.hasAttribute('data-scroll-hidden'), 'mobile bottom nav hides when scrolling down initially (scrollY=' + scrollY + ', w=' + innerWidth + ', matches=' + matchMedia('(max-width: 850px)').matches + ')');
            scrollTo(0, 0); window.dispatchEvent(new Event('scroll')); await wait();
            check(!nav.hasAttribute('data-scroll-hidden'), 'mobile bottom nav reappears at top');
            opener.click(); await wait();
            check(cart.open && document.documentElement.classList.contains('storefront-overlay-open'), 'cart drawer opens and locks scroll');
            cart.querySelector('[data-storefront-close]').click(); await wait(220);
            check(!cart.open && !cart.classList.contains('is-closing') && !document.documentElement.classList.contains('storefront-overlay-open'), 'cart drawer closes and releases overlay lock');
            check(document.activeElement === opener, 'focus restores to opener on close');
            scrollTo(0, 260); window.dispatchEvent(new Event('scroll')); await wait();
            check(nav.hasAttribute('data-scroll-hidden') && nav.inert, 'mobile bottom nav auto-hides again when scrolling down after closing cart drawer');
            scrollTo(0, 120); window.dispatchEvent(new Event('scroll')); await wait();
            check(!nav.hasAttribute('data-scroll-hidden') && !nav.inert, 'mobile bottom nav reappears when scrolling back up');
            document.body.textContent = 'MOBILE_SCROLL_HIDE_PASS';
        })().catch(error => { document.body.textContent = 'MOBILE_SCROLL_HIDE_FAIL: ' + error.message; });
        </script></html>`);
        const result = spawnSync(executable, ['--headless=new', '--disable-gpu', '--disable-background-networking',
            '--window-size=390,844', '--no-first-run', '--user-data-dir=' + path.join(directory, 'profile'),
            '--virtual-time-budget=6000', '--dump-dom', pathToFileURL(fixture).href],
            {encoding:'utf8', timeout:20000, maxBuffer:1024 * 1024, windowsHide:true});
        assert.ifError(result.error);
        assert.equal(result.status, 0);
        assert.ok(/<body[^>]*>MOBILE_SCROLL_HIDE_PASS<\/body>/.test(result.stdout), result.stdout.slice(-400));
    } finally {
        if (realpathSync(path.dirname(directory)) !== realpathSync(tmpdir()) || !path.basename(directory).startsWith('novinpay-storefront-overlays-')) throw Error('Unexpected cleanup target');
        rmSync(directory, {recursive:true, force:true, maxRetries:3, retryDelay:100});
    }
});
