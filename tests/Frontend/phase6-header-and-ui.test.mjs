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

const loadStorefrontCss = () => {
    const read = rel => readFileSync(new URL('../../' + rel, import.meta.url), 'utf8');
    let compiledAppCss = '';
    const manifestPath = new URL('../../public/build/manifest.json', import.meta.url);
    if (existsSync(manifestPath)) {
        const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
        const appCssFile = manifest['resources/css/app.css']?.file;
        if (appCssFile && existsSync(new URL('../../public/build/' + appCssFile, import.meta.url))) {
            compiledAppCss = read('public/build/' + appCssFile);
        }
    }
    if (!compiledAppCss) {
        compiledAppCss = [
            read('resources/css/home.css'),
            read('resources/css/public.css'),
            read('resources/css/customer-account.css'),
            read('resources/css/app.css').replace(/^@(?:import|source)\b[^\r\n]*;/gm, ''),
        ].join('\n');
    }
    return [
        compiledAppCss,
        read('public/css/store-navigation.css'),
        read('public/css/storefront-refinement.css'),
    ].join('\n').replace(/@font-face\s*\{[^}]*\}/g, '');
};

test('Chromium: desktop header stays single-row with exactly one compact search icon across 1280/1366/1440/1920px and opens search modal cleanly, while mobile 390px shows mobile search icon', async t => {
    const executable = findChrome();
    if (!executable) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-header-check-'));
    let server = null;
    try {
        const css = loadStorefrontCss();
        const scrollLockJs = readFileSync(new URL('../../public/js/storefront-scroll-lock.js', import.meta.url), 'utf8');
        const premiumJs = readFileSync(new URL('../../public/js/storefront-premium.js', import.meta.url), 'utf8');

        const premiumCss = readFileSync(new URL('../../public/css/storefront-premium.css', import.meta.url), 'utf8');
        const buildHeaderFixture = (premium = false) => `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <style>${css}\n${premium ? premiumCss : ''}</style></head>
        <body class="public-site ${premium ? 'premium-storefront' : 'homepage'}">
            <header class="np-header" data-mobile-header>
                <div class="np-container np-header__inner">
                    <a href="/" class="np-logo-link" aria-label="صفحهٔ اصلی نوین‌پی"><span class="np-wordmark" style="display:block;width:168px;height:34px;background:#098cf7;border-radius:6px"></span></a>
                    <nav aria-label="ناوبری اصلی" class="np-nav">
                        <a href="/" aria-current="page">صفحه اصلی</a>
                        <div class="np-nav__item np-nav__item--dropdown" data-desktop-shop-dropdown><a href="/products" class="np-nav__trigger"><span>فروشگاه</span></a></div>
                        <div class="np-nav__item np-nav__item--dropdown" data-desktop-repair-dropdown><a href="/repair" class="np-nav__trigger"><span>تعمیرات</span></a></div>
                        <a href="/contact">تماس با ما</a>
                        <a href="/articles">مقاله‌ها</a>
                    </nav>
                    <div class="np-header__actions">
                        <div class="np-mobile-menu"><button type="button" data-navigation-toggle aria-label="فهرست ناوبری">☰</button></div>
                    </div>
                    <a class="np-customer-trigger store-mobile-search" href="/products" data-storefront-open="search-dialog" aria-haspopup="dialog" aria-controls="search-dialog" aria-label="جستجوی محصولات">
                        <svg width="20" height="20" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" stroke="currentColor" fill="none"/></svg>
                    </a>
                    <div class="np-customer-header" data-customer-header>
                        <div class="premium-desktop-tools store-desktop-tools" aria-label="ابزارهای فروشگاه">
                            <a class="np-customer-trigger" id="desktop-search-btn" href="/products" data-storefront-open="search-dialog" aria-haspopup="dialog" aria-label="جستجوی محصولات">
                                <svg width="20" height="20" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" stroke="currentColor" fill="none"/></svg>
                            </a>
                            <a class="np-customer-trigger" id="desktop-cart-btn" href="/cart" data-storefront-open="cart-dialog" aria-haspopup="dialog" aria-label="سبد خرید">
                                <svg width="20" height="20" viewBox="0 0 24 24"><circle cx="10" cy="20" r="2"/></svg>
                            </a>
                        </div>
                        <div data-customer-entry data-customer-state="authenticated">
                            <details class="np-customer-menu" data-customer-menu>
                                <summary class="np-customer-trigger"><span class="np-customer-account-label">حساب کاربری</span></summary>
                            </details>
                        </div>
                    </div>
                </div>
            </header>
            <main id="main-content" style="min-height:600px">
                <aside class="purchase"><div class="purchase-stock-row">
                    <div class="stock is-available"><strong>موجودی:</strong> <span class="stock-availability-note">۷ عدد</span></div>
                    <div class="np-inline-stepper np-inline-stepper--purchase"><button type="button" class="np-inline-stepper__btn">+</button><span>۱</span><button type="button" class="np-inline-stepper__btn">×</button></div>
                </div></aside>
            </main>
            <nav class="global-store-nav" data-mobile-bottom-nav aria-label="راهبری فروشگاه"><a href="/">خانه</a></nav>
            <dialog id="search-dialog" class="search-dialog store-search-dialog" data-storefront-dialog aria-labelledby="search-title">
                <div class="overlay-heading"><h2 id="search-title">جستجو در فروشگاه</h2><button type="button" data-storefront-close aria-label="بستن جستجو">×</button></div>
                <form action="/products" method="get">
                    <label for="product-search">نام یا مدل دستگاه</label>
                    <div class="store-search-input-row">
                        <input id="product-search" name="q" type="search" placeholder="مثلاً ME31">
                        <button type="submit" class="primary">جستجو</button>
                    </div>
                    <div class="search-quick-suggestions"><span>جستجوی سریع</span><a href="/products?q=ME31">ME31</a></div>
                </form>
            </dialog>
            <script>${scrollLockJs}</script>
            <script>${premiumJs}</script>
            <script>
            window.addEventListener('load', async () => {
                const isDesktop = window.innerWidth >= 851;
                const inner = document.querySelector('.np-header__inner');
                const logo = document.querySelector('.np-logo-link');
                const nav = document.querySelector('.np-nav');
                const customerHeader = document.querySelector('.np-customer-header');
                const mobileSearch = document.querySelector('.store-mobile-search');
                const desktopSearch = document.getElementById('desktop-search-btn');
                const allSearchTriggers = [...document.querySelectorAll('[data-storefront-open="search-dialog"]')];
                const visibleSearchTriggers = allSearchTriggers.filter(el => {
                    const st = getComputedStyle(el);
                    const r = el.getBoundingClientRect();
                    return st.display !== 'none' && st.visibility !== 'hidden' && r.width > 0 && r.height > 0;
                });

                const innerRect = inner.getBoundingClientRect();
                const logoRect = logo.getBoundingClientRect();
                const navRect = nav.getBoundingClientRect();
                const custRect = customerHeader.getBoundingClientRect();

                let dialogOpened = false;
                if (visibleSearchTriggers.length === 1) {
                    visibleSearchTriggers[0].click();
                    await new Promise(r => setTimeout(r, 60));
                    const dlg = document.getElementById('search-dialog');
                    const inp = document.getElementById('product-search');
                    dialogOpened = Boolean(dlg?.open && inp && inp.getBoundingClientRect().width > 80);
                }

                const payload = {
                    viewportWidth: window.innerWidth,
                    isDesktop,
                    innerHeight: Math.round(innerRect.height),
                    visibleSearchCount: visibleSearchTriggers.length,
                    visibleSearchWidth: visibleSearchTriggers[0] ? Math.round(visibleSearchTriggers[0].getBoundingClientRect().width) : 0,
                    mobileSearchDisplay: getComputedStyle(mobileSearch).display,
                    desktopSearchDisplay: getComputedStyle(desktopSearch).display,
                    logoCenterY: Math.round(logoRect.top + logoRect.height / 2),
                    navCenterY: Math.round(navRect.top + navRect.height / 2),
                    custCenterY: Math.round(custRect.top + custRect.height / 2),
                    dialogOpened,
                    navWidth: navRect.width,
                    navHeight: navRect.height,
                    navBorder: getComputedStyle(nav).borderWidth,
                    navPadding: getComputedStyle(nav).padding,
                    navGap: getComputedStyle(nav).gap,
                    navLineHeight: getComputedStyle(nav).lineHeight,
                    searchRow: document.querySelector('.store-search-input-row').getBoundingClientRect().toJSON(),
                    suggestions: document.querySelector('.search-quick-suggestions').getBoundingClientRect().toJSON(),
                    stock: document.querySelector('.purchase-stock-row .stock').getBoundingClientRect().toJSON(),
                    stepper: document.querySelector('.np-inline-stepper--purchase').getBoundingClientRect().toJSON(),
                };
                document.body.setAttribute('data-probe', JSON.stringify(payload));
            });
            </script>
        </body></html>`;

        server = http.createServer((req, res) => {
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
            res.end(buildHeaderFixture(req.url === '/product'));
        });
        await new Promise((resolve, reject) => {
            server.once('error', reject);
            server.listen(0, '127.0.0.1', resolve);
        });
        const origin = `http://127.0.0.1:${server.address().port}`;

        const homeMetrics = new Map();
        const viewports = [[1280, 800], [1366, 768], [1440, 900], [1920, 1080], [390, 844]];
        const scenarios = ['/', '/product'].flatMap(page => viewports.map(([w, h]) => [page, w, h]));
        for (const [page, w, h] of scenarios) {
            const res = await runChrome(executable, [
                '--headless=new',
                '--disable-gpu',
                '--no-sandbox',
                `--user-data-dir=${path.join(directory, `p-${page === '/' ? 'home' : 'product'}-${w}`)}`,
                `--window-size=${w},${h}`,
                '--virtual-time-budget=2500',
                '--dump-dom',
                origin + page,
            ]);
            assert.equal(res.status, 0, `Chromium failed at ${w}x${h}: ${res.stderr}`);
            const match = res.stdout.match(/data-probe="([^"]+)"/);
            assert.ok(match, `Probe missing at ${w}x${h}`);
            const probe = JSON.parse(match[1].replace(/&quot;/g, '"'));

            assert.equal(probe.visibleSearchCount, 1, `Expected exactly 1 visible search trigger at ${w}x${h}, got ${probe.visibleSearchCount}`);
            assert.ok(probe.visibleSearchWidth <= 50, `Search trigger must stay compact (<=50px) at ${w}x${h}, got ${probe.visibleSearchWidth}px`);
            assert.ok(probe.innerHeight <= 88, `Header must remain single-row (<=88px) at ${w}x${h}, got ${probe.innerHeight}px`);
            assert.equal(probe.dialogOpened, true, `Search modal must open cleanly when clicked at ${w}x${h}`);

            assert.ok(probe.suggestions.top >= probe.searchRow.bottom, 'Quick search must sit below input and submit row');
            assert.ok(probe.searchRow.width >= probe.suggestions.width - 1, 'Search row must fill dialog body');
            if (page === '/product') {
                assert.equal(probe.stock.height, 42, 'Stock pill must be exactly 42px');
                assert.equal(probe.stepper.height, 42, 'Stepper must be exactly 42px');
                assert.ok(Math.abs(probe.stock.top - probe.stepper.top) <= 0.5, 'Stock and stepper must share the same top edge');
            }
            if (probe.isDesktop) {
                const keys = ['navWidth', 'navHeight', 'navBorder', 'navPadding', 'navGap', 'navLineHeight'];
                const metrics = Object.fromEntries(keys.map(key => [key, probe[key]]));
                if (page === '/') homeMetrics.set(w, metrics);
                else assert.deepEqual(metrics, homeMetrics.get(w), 'Product nav must match homepage dimensions and styles');
                assert.equal(probe.navBorder, '0px');
                assert.equal(probe.mobileSearchDisplay, 'none', `.store-mobile-search must be hidden at ${w}x${h}`);
                assert.ok(Math.abs(probe.logoCenterY - probe.navCenterY) <= 6, `Logo and nav must share single row at ${w}x${h}`);
                assert.ok(Math.abs(probe.logoCenterY - probe.custCenterY) <= 6, `Logo and customer tools must share single row (no wrapping) at ${w}x${h}`);
            } else {
                assert.ok(['flex', 'inline-flex'].includes(probe.mobileSearchDisplay), `.store-mobile-search must be visible at ${w}x${h}, got ${probe.mobileSearchDisplay}`);
            }
        }
    } finally {
        if (server) await new Promise(r => server.close(() => r()));
        try { rmSync(directory, {recursive: true, force: true}); } catch {}
    }
});
