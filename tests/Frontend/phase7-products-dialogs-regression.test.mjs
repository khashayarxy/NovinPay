import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import {existsSync, mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawn} from 'node:child_process';

const findChrome = () => (process.env.CHROME_BIN ? [process.env.CHROME_BIN] : process.platform === 'win32'
    ? ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']
    : ['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser']).find(existsSync);

const runChrome = (executable, args, timeoutMs = 25000) => new Promise((resolve, reject) => {
    const child = spawn(executable, args, {windowsHide: true, stdio: ['ignore', 'pipe', 'pipe']});
    let stdout = '';
    let stderr = '';
    const timer = setTimeout(() => {
        child.kill();
        reject(new Error(`Chrome timed out after ${timeoutMs}ms\nstderr: ${stderr.slice(-400)}`));
    }, timeoutMs);
    child.stdout.setEncoding('utf8');
    child.stderr.setEncoding('utf8');
    child.stdout.on('data', chunk => { stdout += chunk; });
    child.stderr.on('data', chunk => { stderr += chunk; });
    child.once('error', err => { clearTimeout(timer); reject(err); });
    child.once('close', status => { clearTimeout(timer); resolve({status, stdout, stderr}); });
});

test('Chromium: /products loads storefront-premium.js and opens/closes all filter, sort, search, and cart dialogs with zero ReferenceError or uncaught exceptions', async t => {
    const executable = findChrome();
    if (!executable) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-products-dialogs-'));
    let server = null;
    try {
        const read = rel => readFileSync(new URL('../../' + rel, import.meta.url), 'utf8');
        const scrollLockJs = read('public/js/storefront-scroll-lock.js');
        const premiumJs = read('public/js/storefront-premium.js');
        const storeNavCss = read('public/css/store-navigation.css');
        const refinementCss = read('public/css/storefront-refinement.css');

        assert.ok(premiumJs.includes("'use strict'"), 'storefront-premium.js must run in strict mode');
        assert.ok(!/\bdialogs\./.test(premiumJs), 'storefront-premium.js must never reference undeclared dialogs identifier');

        const html = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <style>${storeNavCss}\n${refinementCss}</style>
        <script>
        window.__caughtErrors = [];
        window.addEventListener('error', e => {
            window.__caughtErrors.push(String(e.error?.stack || e.message || e));
        });
        window.addEventListener('unhandledrejection', e => {
            window.__caughtErrors.push('Rejection: ' + String(e.reason?.stack || e.reason || e));
        });
        </script>
        </head>
        <body class="public-site">
            <header class="np-header" data-mobile-header>
                <div class="np-container np-header__inner">
                    <a href="/" class="np-logo-link">نوین‌پی</a>
                    <div class="np-customer-header" data-customer-header>
                        <div class="store-desktop-tools">
                            <a id="open-search" class="np-customer-trigger" href="/products" data-storefront-open="search-dialog" aria-haspopup="dialog">جستجو</a>
                            <a id="open-cart" class="np-customer-trigger" href="/cart" data-storefront-open="cart-dialog" aria-haspopup="dialog">سبد</a>
                        </div>
                    </div>
                </div>
            </header>
            <main id="main-content" class="public-catalogue">
                <div class="catalog-toolbar">
                    <button id="open-filter" type="button" data-storefront-open="catalog-filter-dialog" aria-haspopup="dialog">فیلترها</button>
                    <button id="open-sort" type="button" data-storefront-open="catalog-sort-dialog" aria-haspopup="dialog">مرتب‌سازی</button>
                </div>
                <form data-desktop-filter-form action="/products" method="get">
                    <input type="radio" name="category" value="" checked data-auto-submit-filter>
                </form>
                <dialog class="store-filter-dialog store-sheet-dialog" id="catalog-sort-dialog" data-storefront-dialog aria-labelledby="sort-title">
                    <header class="overlay-heading"><h2 id="sort-title">مرتب‌سازی</h2><button type="button" data-storefront-close>بستن</button></header>
                </dialog>
                <dialog class="store-filter-dialog store-sheet-dialog" id="catalog-filter-dialog" data-storefront-dialog aria-labelledby="filter-title">
                    <header class="overlay-heading"><h2 id="filter-title">فیلترها</h2><button type="button" data-storefront-close>بستن</button></header>
                </dialog>
            </main>
            <dialog id="cart-dialog" class="cart-dialog store-cart-drawer" data-storefront-dialog data-cart-url="/cart" data-cart-empty-guest="1" aria-labelledby="cart-title">
                <div class="overlay-heading"><h2 id="cart-title">سبد خرید</h2><button type="button" data-storefront-close>بستن</button></div>
                <p class="store-cart-status" data-cart-status hidden></p>
                <div class="live-cart" data-live-cart></div>
                <p class="store-cart-error" data-cart-error hidden></p>
                <div class="cart-sheet-footer" data-cart-footer hidden><div data-cart-summary></div><div data-cart-view-link></div><button type="button" data-cart-retry hidden>تلاش دوباره</button></div>
                <template data-empty-cart><div class="empty-cart"><h3>سبد خرید شما خالی است</h3></div></template>
            </dialog>
            <dialog id="search-dialog" class="search-dialog store-search-dialog" data-storefront-dialog aria-labelledby="search-title">
                <div class="overlay-heading"><h2 id="search-title">جستجو</h2><button type="button" data-storefront-close>بستن</button></div>
                <form action="/products" method="get"><input id="product-search" name="q" type="search"></form>
            </dialog>
            <script>${scrollLockJs}</script>
            <script>${premiumJs}</script>
            <script>
            window.addEventListener('load', async () => {
                const wait = ms => new Promise(r => setTimeout(r, ms));
                const sequence = [
                    ['open-filter', 'catalog-filter-dialog'],
                    ['open-sort', 'catalog-sort-dialog'],
                    ['open-search', 'search-dialog'],
                    ['open-cart', 'cart-dialog'],
                ];
                const states = [];
                for (const [btnId, dlgId] of sequence) {
                    document.getElementById(btnId).click();
                    await wait(60);
                    const dlg = document.getElementById(dlgId);
                    const wasOpen = Boolean(dlg?.open);
                    dlg?.querySelector('[data-storefront-close]')?.click();
                    await wait(340);
                    const wasClosed = !dlg?.open;
                    states.push({dlgId, wasOpen, wasClosed});
                }
                document.body.setAttribute('data-dialog-report', JSON.stringify({
                    errors: window.__caughtErrors,
                    states,
                }));
            });
            </script>
        </body></html>`;

        server = http.createServer((req, res) => {
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
            res.end(html);
        });
        await new Promise((resolve, reject) => {
            server.once('error', reject);
            server.listen(0, '127.0.0.1', resolve);
        });
        const origin = `http://127.0.0.1:${server.address().port}`;

        const res = await runChrome(executable, [
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox',
            `--user-data-dir=${path.join(directory, 'profile')}`,
            '--window-size=390,844',
            '--virtual-time-budget=3500',
            '--dump-dom',
            origin + '/products',
        ]);
        assert.equal(res.status, 0, `Chromium failed: ${res.stderr}`);
        assert.doesNotMatch(res.stderr, /ReferenceError|Uncaught/i);

        const match = res.stdout.match(/data-dialog-report="([^"]+)"/);
        assert.ok(match, 'Dialog report attribute missing');
        const report = JSON.parse(match[1].replace(/&quot;/g, '"'));
        assert.deepEqual(report.errors, [], `Expected zero JS runtime errors on /products, got: ${JSON.stringify(report.errors)}`);
        for (const item of report.states) {
            assert.equal(item.wasOpen, true, `Dialog ${item.dlgId} failed to open`);
            assert.equal(item.wasClosed, true, `Dialog ${item.dlgId} failed to close`);
        }
    } finally {
        if (server) await new Promise(r => server.close(() => r()));
        try { rmSync(directory, {recursive: true, force: true}); } catch {}
    }
});
