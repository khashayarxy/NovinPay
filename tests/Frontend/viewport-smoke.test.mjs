import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import {existsSync, mkdtempSync, readFileSync, realpathSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawn} from 'node:child_process';

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

const findChrome = () => (process.env.CHROME_BIN ? [process.env.CHROME_BIN] : process.platform === 'win32'
    ? ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']
    : ['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser']).find(existsSync);

const loadCssBundle = () => {
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
        '* { box-sizing: border-box; } html, body { margin: 0; padding: 0; }',
        compiledAppCss,
        read('public/css/storefront-premium.css'),
        read('public/css/store-navigation.css'),
        read('public/css/storefront-refinement.css'),
    ].join('\n').replace(/@font-face\s*\{[^}]*\}/g, '');
};

const startFixtureServer = async routes => {
    const server = http.createServer((req, res) => {
        const pathname = new URL(req.url || '/', 'http://127.0.0.1').pathname;
        const html = routes[pathname];
        if (!html) {
            res.writeHead(404, {'Content-Type': 'text/plain; charset=utf-8'});
            res.end('Not found');
            return;
        }
        res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
        res.end(html);
    });
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });
    const addr = server.address();
    return {
        origin: `http://127.0.0.1:${addr.port}`,
        close: () => new Promise(resolve => server.close(() => resolve())),
    };
};

test('Support chat FAQ tab does not auto-focus search input and repair combobox normalizes Persian text & blurs', () => {
    const customerV2 = readFileSync(new URL('../../resources/support-chat/support-client/customer-v2.js', import.meta.url), 'utf8');
    assert.ok(!customerV2.includes('searchInput?.focus'), 'FAQ tab switch must never auto-focus #supportFaqSearch');

    const appJs = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    assert.ok(appJs.includes('normalizePersianSearch'), 'app.js must define normalizePersianSearch');
    assert.ok(appJs.includes('inputEl.blur()'), 'Province/city combobox must call inputEl.blur() on selection');
    assert.ok(!appJs.includes('lastAutoOtpPhone'), 'Step 4 must not auto-send OTP on step entry');
});

test('Chromium: mobile /products filter & sort sheets slide up/down and close via X, backdrop tap, and Escape without freezing scroll or touch', async t => {
    const executable = findChrome();
    if (!executable) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-filter-freeze-'));
    let server = null;
    try {
        const css = loadCssBundle();
        const scrollLockJs = readFileSync(new URL('../../public/js/storefront-scroll-lock.js', import.meta.url), 'utf8');
        const premiumJs = readFileSync(new URL('../../public/js/storefront-premium.js', import.meta.url), 'utf8');

        const productsMobileHtml = `<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <style>${css}</style>
        <body class="public-site premium-storefront" style="min-height:2200px">
            <header class="np-header"><div class="np-container np-header__inner"><a class="np-logo-link" href="/">نوین‌پی</a></div></header>
            <main id="main-content">
                <div class="np-container public-section public-catalogue">
                    <header class="store-page-intro"><h1>فروشگاه کارتخوان</h1><p>دستگاه مناسب کسب‌وکار خود را پیدا کنید.</p></header>
                    <div class="catalog-mobile-bar">
                        <form class="catalog-search" action="/products" method="get">
                            <div class="catalog-search-field"><input id="q-mobile" name="q" type="search" class="input" placeholder="نام یا مدل کالا"></div>
                            <div class="catalog-toolbar">
                                <button type="button" class="btn btn-secondary catalog-filter-trigger" data-storefront-open="catalog-filter-dialog" aria-haspopup="dialog"><span>فیلترها</span></button>
                                <button type="button" class="btn btn-secondary catalog-sort-trigger" data-storefront-open="catalog-sort-dialog" aria-haspopup="dialog"><span>ترتیب: تازه‌ترین</span></button>
                            </div>
                        </form>
                    </div>
                    <div class="catalog-layout">
                        <aside class="catalog-sidebar"><div class="catalog-sidebar__card"><form data-desktop-filter-form><input type="radio" name="category" value="" checked data-auto-submit-filter></form></div></aside>
                        <section class="catalog-main">
                            <div class="public-product-grid">
                                <article id="sample-product-card" class="public-product-card" style="height:260px;padding:16px"><h3>Newland ME31 (4G)</h3></article>
                                <article class="public-product-card" style="height:260px;padding:16px"><h3>PAX S910 Mini</h3></article>
                            </div>
                        </section>
                    </div>
                    <dialog class="store-filter-dialog store-sheet-dialog" id="catalog-sort-dialog" data-storefront-dialog aria-labelledby="sort-title">
                        <div class="sheet-drag-handle" aria-hidden="true"><span></span></div>
                        <header class="overlay-heading"><h2 id="sort-title">مرتب‌سازی محصولات</h2><button type="button" data-storefront-close aria-label="بستن">×</button></header>
                        <nav class="catalog-sort-list"><a class="catalog-sort-option" href="#sort-new" aria-current="true"><span>تازه‌ترین</span></a></nav>
                    </dialog>
                    <dialog class="store-filter-dialog store-sheet-dialog" id="catalog-filter-dialog" data-storefront-dialog aria-labelledby="filter-title">
                        <div class="sheet-drag-handle" aria-hidden="true"><span></span></div>
                        <header class="overlay-heading"><h2 id="filter-title">فیلتر محصولات</h2><button type="button" data-storefront-close aria-label="بستن فیلترها">×</button></header>
                        <form action="#apply" method="get" class="catalog-sheet-form">
                            <div class="catalog-sheet-body">
                                <fieldset class="catalog-filter-fieldset"><legend>دسته‌بندی</legend>
                                    <div class="catalog-filter-chips">
                                        <label class="catalog-filter-chip"><input type="radio" name="category" value="" checked><span>همه محصولات</span></label>
                                        <label class="catalog-filter-chip" id="pos-chip"><input type="radio" id="pos-radio" name="category" value="pos"><span>کارتخوان سیار</span></label>
                                    </div>
                                </fieldset>
                            </div>
                            <footer class="catalog-sheet-footer">
                                <a href="#reset-filters" id="reset-filters-link" class="btn btn-secondary">حذف فیلترها</a>
                                <button class="btn btn-primary" type="submit">مشاهده نتایج</button>
                            </footer>
                        </form>
                    </dialog>
                </div>
            </main>
            <nav class="global-store-nav" data-mobile-bottom-nav><a href="/">خانه</a><a href="/products" aria-current="page">فروشگاه</a></nav>
            <script>
                window.__runtimeErrors = [];
                window.addEventListener('error', e => window.__runtimeErrors.push(e.message || String(e.error)));
                window.addEventListener('unhandledrejection', e => window.__runtimeErrors.push(String(e.reason)));
                window.requestAnimationFrame = fn => setTimeout(fn, 16);
            </script>
            <script>${scrollLockJs}</script>
            <script>${premiumJs}</script>
            <script>
            ;(async () => {
                const wait = (ms = 60) => new Promise(r => setTimeout(r, ms));
                const check = (cond, msg) => { if (!cond) throw new Error(msg); };
                await wait(40);
                check(window.__runtimeErrors.length === 0, 'Uncaught startup errors: ' + window.__runtimeErrors.join('; '));
                check(document.documentElement.scrollWidth <= innerWidth, 'Horizontal overflow on /products mobile: ' + document.documentElement.scrollWidth + ' > ' + innerWidth);

                const filterBtn = document.querySelector('[data-storefront-open="catalog-filter-dialog"]');
                const sortBtn = document.querySelector('[data-storefront-open="catalog-sort-dialog"]');
                const filterDialog = document.getElementById('catalog-filter-dialog');
                const sortDialog = document.getElementById('catalog-sort-dialog');
                const card = document.getElementById('sample-product-card');

                const assertUnlockedAndInteractive = async label => {
                    check(!filterDialog.open && !sortDialog.open, label + ': dialog.open must be false');
                    check(!filterDialog.classList.contains('is-closing') && !sortDialog.classList.contains('is-closing'), label + ': is-closing must be cleared');
                    check(!document.documentElement.classList.contains('storefront-overlay-open') && document.documentElement.style.overflowY === '', label + ': scroll lock must be released');
                    scrollTo({top: 0, behavior: 'instant'});
                    await wait(20);
                    const rect = card.getBoundingClientRect();
                    const hit = document.elementFromPoint(rect.left + 30, rect.top + 30);
                    check(hit && card.contains(hit), label + ': product card blocked by overlay (hit=' + (hit?.tagName || 'null') + '.' + (hit?.className || '') + ')');
                    scrollTo({top: 260, behavior: 'instant'});
                    window.dispatchEvent(new Event('scroll'));
                    await wait(30);
                    check(scrollY === 260, label + ': page failed to scroll after close (scrollY=' + scrollY + ')');
                    scrollTo({top: 0, behavior: 'instant'});
                    await wait(20);
                };

                // 1) Open filter -> verify bottom-sheet slide animation & inside options -> close via X button
                filterBtn.click();
                const openImmediate = filterDialog.open;
                await wait(50);
                check(filterDialog.open && document.documentElement.classList.contains('storefront-overlay-open'), '1: filter dialog opens and locks scroll (imm=' + openImmediate + ', open=' + filterDialog.open + ', errs=' + window.__runtimeErrors.join('|') + ', cls=' + document.documentElement.className + ')');
                if (innerWidth <= 767 && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    const animIn = getComputedStyle(filterDialog).animationName;
                    check(animIn === 'np-cart-in-bottom', '1: filter drawer uses np-cart-in-bottom slide animation (got ' + animIn + ', vw=' + innerWidth + ')');
                }
                document.getElementById('pos-chip').click();
                check(document.getElementById('pos-radio').checked, '1: filter chip option responds inside drawer');
                filterDialog.querySelector('[data-storefront-close]').click();
                if (innerWidth <= 767 && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    check(getComputedStyle(filterDialog).animationName === 'np-cart-out-bottom', '1: filter drawer uses np-cart-out-bottom slide animation on close');
                }
                await wait(340);
                await assertUnlockedAndInteractive('1-close-button');

                // 2) Reopen filter -> close via outside backdrop tap (touch-like click with detail=0 AND standard detail=1)
                filterBtn.click();
                await wait(260);
                check(filterDialog.open, '2a: filter dialog reopens for backdrop tap (detail=0)');
                const sheetBoundsA = filterDialog.getBoundingClientRect();
                const backdropY = Math.max(8, Math.floor(sheetBoundsA.top / 2));
                filterDialog.dispatchEvent(new PointerEvent('pointerdown', {clientX: 30, clientY: backdropY, bubbles: true}));
                filterDialog.dispatchEvent(new MouseEvent('click', {clientX: 30, clientY: backdropY, detail: 0, bubbles: true}));
                await wait(340);
                await assertUnlockedAndInteractive('2a-backdrop-touch-tap');

                filterBtn.click();
                await wait(260);
                check(filterDialog.open, '2b: filter dialog reopens for backdrop tap (detail=1)');
                const sheetBoundsB = filterDialog.getBoundingClientRect();
                const backdropYB = Math.max(8, Math.floor(sheetBoundsB.top / 2));
                filterDialog.dispatchEvent(new PointerEvent('pointerdown', {clientX: 40, clientY: backdropYB, bubbles: true}));
                filterDialog.dispatchEvent(new MouseEvent('click', {clientX: 40, clientY: backdropYB, detail: 1, bubbles: true}));
                await wait(340);
                await assertUnlockedAndInteractive('2b-backdrop-click');

                // 3) Reopen filter -> verify reset link is hit-testable -> close via Escape key -> verify unlocked
                filterBtn.click();
                await wait(60);
                await Promise.all(filterDialog.getAnimations().map(a => a.finished.catch(() => {})));
                check(filterDialog.open, '3: filter dialog reopens for Escape');
                const resetLink = document.getElementById('reset-filters-link');
                const resetRect = resetLink.getBoundingClientRect();
                const resetHit = document.elementFromPoint(Math.round(resetRect.left + resetRect.width / 2), Math.round(resetRect.top + resetRect.height / 2));
                check(resetHit && resetLink.contains(resetHit), '3: reset filters button is hit-testable inside sheet');
                resetLink.click();
                check(location.hash === '#reset-filters', '3: reset filters link navigates/activates cleanly');
                if (filterDialog.open) {
                    filterDialog.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
                    await wait(340);
                }
                await assertUnlockedAndInteractive('3-after-reset');

                filterBtn.click();
                await wait(50);
                check(filterDialog.open, '3b: filter dialog open before pure Escape test');
                filterDialog.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
                await wait(340);
                await assertUnlockedAndInteractive('3b-escape-key');

                // 4) Open sort sheet -> close via X -> verify unlocked & zero runtime errors
                sortBtn.click();
                await wait(50);
                check(sortDialog.open, '4: sort dialog opens');
                sortDialog.querySelector('[data-storefront-close]').click();
                await wait(340);
                await assertUnlockedAndInteractive('4-sort-close');

                check(window.__runtimeErrors.length === 0, 'Runtime errors during interaction: ' + window.__runtimeErrors.join('; '));
                document.body.textContent = 'PRODUCTS_MOBILE_FILTER_SMOKE_PASS';
            })().catch(err => { document.body.textContent = 'PRODUCTS_MOBILE_FILTER_SMOKE_FAIL: ' + (err?.stack || err?.message || err); });
            </script>
        </body></html>`;

        server = await startFixtureServer({'/products': productsMobileHtml});

        const result = await runChrome(executable, [
            '--headless=new', '--disable-gpu', '--disable-background-networking',
            '--window-size=390,844', '--no-first-run', '--user-data-dir=' + path.join(directory, 'profile'),
            '--virtual-time-budget=9500', '--dump-dom', server.origin + '/products',
        ]);

        assert.equal(result.status, 0, result.stderr.slice(-500));
        assert.ok(/PRODUCTS_MOBILE_FILTER_SMOKE_PASS/.test(result.stdout), result.stdout.slice(-900));
    } finally {
        await server?.close();
        if (realpathSync(path.dirname(directory)) !== realpathSync(tmpdir()) || !path.basename(directory).startsWith('novinpay-filter-freeze-')) {
            throw new Error('Unexpected cleanup path');
        }
        rmSync(directory, {recursive: true, force: true, maxRetries: 3, retryDelay: 100});
    }
});

test('Chromium: multi-viewport smoke across 390x844, 768x1024, and 1440x900 for /, /products, /repair, and /repair/confirmation', async t => {
    const executable = findChrome();
    if (!executable) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-viewport-smoke-'));
    let server = null;
    try {
        const css = loadCssBundle();
        const scrollLockJs = readFileSync(new URL('../../public/js/storefront-scroll-lock.js', import.meta.url), 'utf8');
        const premiumJs = readFileSync(new URL('../../public/js/storefront-premium.js', import.meta.url), 'utf8');

        const shell = (title, bodyContent, extraBodyClass = '') => `<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>${title}</title><style>${css}</style>
            <body class="public-site premium-storefront ${extraBodyClass}">
                <header class="np-header"><div class="np-container np-header__inner">
                    <a class="np-logo-link" href="/">نوین‌پی</a>
                    <div class="np-header__actions"><div class="np-mobile-menu"><button type="button" aria-label="فهرست ناوبری">☰</button></div></div>
                    ${extraBodyClass.includes('product-detail-page')
                        ? '<a class="np-customer-trigger store-mobile-cart" href="/cart" data-storefront-open="cart-dialog" aria-label="سبد خرید"><svg width="20" height="20" viewBox="0 0 24 24"></svg><span class="store-cart-count" data-store-cart-count><bdi>۲</bdi></span></a>'
                        : '<a class="np-customer-trigger store-mobile-search" href="/products" aria-label="جستجوی محصولات">🔍</a>'}
                    <div class="np-customer-header"><div class="store-desktop-tools"><a class="np-customer-trigger" href="/cart">سبد</a></div></div>
                </div></header>
                <main id="main-content">${bodyContent}</main>
                <nav class="global-store-nav" data-mobile-bottom-nav><a href="/">خانه</a><a href="/products">فروشگاه</a></nav>
                <script>window.__errs=[];window.addEventListener('error',e=>window.__errs.push(e.message));window.requestAnimationFrame=fn=>setTimeout(fn,16);</script>
                <script>${scrollLockJs}</script>
                <script>${premiumJs}</script>
            </body></html>`;

        const routes = {
            '/': shell('خانه', `
                <section class="np-hero"><div class="np-container np-hero__grid">
                    <div class="np-hero__copy"><h1>فروش و تعمیرات تخصصی کارتخوان سیار</h1><p class="np-hero__description">ارسال سریع به سراسر ایران همراه با پشتیبانی فنی نوین‌پی.</p></div>
                    <div class="np-hero__art"><div style="width:100%;max-width:260px;height:180px;background:#eef6ff;border-radius:18px"></div></div>
                </div></section>
                <section class="np-container public-section" style="min-height:900px"><h2>خدمات نوین‌پی</h2></section>
            `),
            '/products': shell('محصولات', `
                <div class="np-container public-section public-catalogue" style="min-height:1200px">
                    <header class="store-page-intro"><h1>فروشگاه کارتخوان</h1></header>
                    <div class="catalog-mobile-bar"><div class="catalog-toolbar">
                        <button type="button" class="btn btn-secondary catalog-filter-trigger" data-storefront-open="catalog-filter-dialog">فیلترها</button>
                    </div></div>
                    <div class="catalog-layout">
                        <aside class="catalog-sidebar"><div class="catalog-sidebar__card"><h2>فیلترها</h2></div></aside>
                        <section class="catalog-main"><div class="public-product-grid">
                            <article id="vp-product-card" class="public-product-card" style="padding:16px"><h3>Newland ME31 (4G)</h3></article>
                            <article class="public-product-card" style="padding:16px"><h3>PAX S910 Mini</h3></article>
                        </div></section>
                    </div>
                    <dialog class="store-filter-dialog store-sheet-dialog" id="catalog-filter-dialog" data-storefront-dialog>
                        <header class="overlay-heading"><h2>فیلتر محصولات</h2><button type="button" data-storefront-close>×</button></header>
                        <div class="catalog-sheet-body"><p>گزینه‌های فیلتر</p></div>
                        <footer class="catalog-sheet-footer"><a href="#clear" class="btn btn-secondary">حذف فیلترها</a><button class="btn btn-primary" type="button">مشاهده نتایج</button></footer>
                    </dialog>
                </div>
            `),
            '/repair': shell('درخواست تعمیر', `
                <div class="repair-entry-bg"><div class="np-container public-section repair-entry"><div class="repair-entry-shell">
                    <header class="store-page-intro repair-hero-intro"><div class="repair-hero-intro__row"><div><h1>ثبت درخواست تعمیر کارتخوان</h1></div></div></header>
                    <div class="repair-split-card">
                        <section class="repair-split-form">
                            <ol class="repair-progress repair-progress--4">
                                <li><button type="button" class="repair-progress__item" aria-current="step"><span class="repair-progress__num">۱</span><span class="repair-progress__label">مشخصات دستگاه</span></button></li>
                                <li><button type="button" class="repair-progress__item"><span class="repair-progress__num">۲</span><span class="repair-progress__label">مشکل دستگاه</span></button></li>
                                <li><button type="button" class="repair-progress__item"><span class="repair-progress__num">۳</span><span class="repair-progress__label">تماس و تحویل</span></button></li>
                                <li><button type="button" class="repair-progress__item"><span class="repair-progress__num">۴</span><span class="repair-progress__label">بازبینی و ثبت</span></button></li>
                            </ol>
                            <div class="repair-handover-grid">
                                <label class="repair-choice-card repair-choice-card--handover"><input type="radio" name="hm" checked><span class="repair-choice-card__title">خودم ارسال میکنم</span><span class="repair-choice-card__sub">ارسال از سراسر کشور با پست یا تیپاکس</span></label>
                                <label class="repair-choice-card repair-choice-card--handover"><input type="radio" name="hm"><span class="repair-choice-card__title">خودم حضوری میارم</span><span class="repair-choice-card__sub">تحویل مستقیم در فروشگاه نوین‌پی شهریار</span></label>
                                <label class="repair-choice-card repair-choice-card--handover"><input type="radio" name="hm"><span class="repair-choice-card__title">به پیک شما تحویل میدم</span><span class="repair-choice-card__sub">دریافت دستگاه از محل شما توسط پیک نوین‌پی</span></label>
                            </div>
                            <div class="repair-courier-alt" style="margin-top:14px">
                                <label class="repair-choice-card repair-choice-card--accessory repair-courier-alt__toggle" for="other_person_handover">
                                    <input type="checkbox" id="other_person_handover" data-other-person-toggle>
                                    <span class="repair-choice-card__check"></span>
                                    <span class="repair-choice-card__title">فرد دیگری دستگاه را به پیک تحویل می‌دهد</span>
                                </label>
                                <div class="public-field repair-courier-alt__field" data-other-person-field hidden>
                                    <input id="responsive_phone" type="tel" class="input repair-rtl-phone-input" dir="ltr" placeholder="۰۹۱۲۳۴۵۶۷۸۹">
                                </div>
                            </div>
                        </section>
                        <aside class="repair-split-trust"><div class="repair-split-trust__top"><span class="repair-trust-card__eyebrow">مرکز تخصصی تعمیرات نوین‌پی</span><h2>تعمیر مطمئن، شفاف و با ضمانت</h2></div></aside>
                    </div>
                </div></div></div>
                <script>
                    const toggle = document.querySelector('[data-other-person-toggle]');
                    const field = document.querySelector('[data-other-person-field]');
                    toggle?.addEventListener('change', () => { field.hidden = !toggle.checked; });
                </script>
            `),
            '/repair/confirmation': shell('تأیید تعمیر', `
                <div class="repair-entry-bg" data-repair-confirmed><div class="np-container public-section repair-entry repair-confirm-page"><div class="repair-entry-shell">
                    <div class="repair-split-card repair-confirm-split">
                        <section class="repair-split-form repair-confirm-main">
                            <div class="repair-confirm-head"><span class="repair-confirm-badge">✓</span><h1 id="confirmation-title">درخواست تعمیر شما ثبت شد</h1></div>
                            <div class="repair-confirm-stepper">
                                <ol class="repair-progress repair-progress--4 repair-confirm-progress">
                                    <li data-stage="complete"><div class="repair-progress__item" data-step-done="true"><span class="repair-progress__num">✓</span><span class="repair-progress__label">ثبت درخواست</span></div></li>
                                    <li data-stage="current"><div class="repair-progress__item" aria-current="step"><span class="repair-progress__num">۲</span><span class="repair-progress__label">دریافت دستگاه</span></div></li>
                                    <li data-stage="upcoming"><div class="repair-progress__item"><span class="repair-progress__num">۳</span><span class="repair-progress__label">بررسی و اعلام هزینه</span></div></li>
                                    <li data-stage="upcoming"><div class="repair-progress__item"><span class="repair-progress__num">۴</span><span class="repair-progress__label">تعمیر و تحویل</span></div></li>
                                </ol>
                                <p class="repair-confirm-stepper__note">وضعیت فعلی: <strong>دستگاه هنوز تحویل نشده است</strong></p>
                            </div>
                            <div class="repair-confirmation-code"><span class="repair-confirmation-code__label">کد پیگیری</span><p class="public-reference" dir="ltr">48291037</p></div>
                            <div class="public-actions repair-confirm-actions"><a href="#track" class="np-button np-button--primary">پیگیری وضعیت تعمیر</a><a href="tel:09362896373" class="np-button np-button--outline">تماس با نوین‌پی</a></div>
                        </section>
                        <aside class="repair-split-trust repair-confirm-aside">
                            <div class="repair-split-trust__top"><span class="repair-trust-card__eyebrow">راهنمای تحویل دستگاه</span><h2>گام بعدی برای تحویل دستگاه</h2>
                            <div class="repair-shipping-card"><div class="repair-shipping-card__head"><span class="repair-shipping-card__title">اطلاعات گیرنده جهت ارسال</span><button type="button" class="repair-shipping-copy-btn">کپی اطلاعات ارسال</button></div></div></div>
                        </aside>
                    </div>
                </div></div></div>
            `),
            '/products/sample-pos': shell('جزئیات محصول', `
                <div class="storefront-content np-container" style="min-height:1400px;padding-block:18px">
                    <div class="product-layout">
                        <div class="gallery card" style="height:260px">تصویر دستگاه</div>
                        <div class="product-overview"><section class="identity card"><h1>Newland ME31 (4G)</h1></section></div>
                        <div class="purchase-column" data-purchase-column>
                            <aside class="purchase card" id="product-purchase">
                                <div class="price-group"><p class="muted">قیمت فروش دستگاه</p><p class="price"><bdi>۱۰٬۵۰۰٬۰۰۰</bdi> <small>تومان</small></p></div>
                                <div class="product-cart-control" data-product-primary-action data-product-cart-control data-product-slug="me31" data-product-qty="0" data-product-max="5">
                                    <div class="purchase-stock-row"><div class="stock is-available"><strong>موجود در انبار</strong></div></div>
                                    <form id="product-cart-form" class="product-cart-form" action="/cart/products/me31" method="post"><input type="hidden" name="quantity" value="1"><button class="primary" type="submit">خرید محصول</button></form>
                                </div>
                                <ul class="services"><li><strong>۶ ماه گارانتی نوین‌پی</strong></li></ul>
                                <dl class="purchase-meta"><div><dt>شناسه کالا</dt><dd><bdi dir="ltr">ME31-4G</bdi></dd></div></dl>
                            </aside>
                        </div>
                    </div>
                </div>
                <div class="sticky-contact" data-product-dock inert>
                    <div class="product-dock-action" data-product-cart-control data-product-slug="me31" data-product-qty="0" data-product-max="5">
                        <button class="primary" type="submit" form="product-cart-form" data-dock-add-btn>افزودن به سبد خرید</button>
                        <div class="product-dock-in-cart" data-dock-in-cart hidden>
                            <div class="np-inline-stepper np-inline-stepper--dock" data-inline-stepper hidden>
                                <button type="button" class="np-inline-stepper__btn" data-stepper-inc>+</button>
                                <span class="np-inline-stepper__count" data-stepper-count>۱</span>
                                <button type="button" class="np-inline-stepper__btn" data-stepper-dec>−</button>
                            </div>
                            <a class="product-dock-cart-link" href="/cart" data-dock-cart-link>مشاهده سبد خرید</a>
                        </div>
                    </div>
                    <div class="product-dock-price"><span class="product-dock-price__label">قیمت فروش</span><p class="price"><bdi>۱۰٬۵۰۰٬۰۰۰</bdi> <small>تومان</small></p></div>
                </div>
            `, 'product-detail-page'),
            '/runner': `<!doctype html><meta charset="utf-8"><body style="margin:0"><iframe id="vp" style="border:0;display:block"></iframe>
            <script>
            ;(async () => {
                const frame = document.getElementById('vp');
                const viewports = [[390, 844], [768, 1024], [1440, 900]];
                const pages = ['/', '/products', '/products/sample-pos', '/repair', '/repair/confirmation'];
                const wait = (ms = 45) => new Promise(r => setTimeout(r, ms));
                const loadPage = (page, w, h) => new Promise((resolve, reject) => {
                    frame.width = String(w); frame.height = String(h);
                    frame.style.width = w + 'px'; frame.style.height = h + 'px';
                    frame.onload = () => resolve();
                    frame.onerror = () => reject(new Error('Failed loading ' + page));
                    frame.src = page + '?vp=' + w + 'x' + h;
                });

                for (const [w, h] of viewports) {
                    for (const page of pages) {
                        await loadPage(page, w, h);
                        await wait(45);
                        const win = frame.contentWindow, doc = frame.contentDocument;
                        if (win.__errs.length) throw new Error(page + '@' + w + 'x' + h + ' JS errors: ' + win.__errs.join('; '));
                        if (doc.documentElement.scrollWidth > w + 1) {
                            throw new Error(page + '@' + w + 'x' + h + ' horizontal overflow: scrollWidth=' + doc.documentElement.scrollWidth + ' > ' + w);
                        }
                        if (doc.documentElement.classList.contains('storefront-overlay-open') || win.getComputedStyle(doc.documentElement).overflowY === 'hidden') {
                            throw new Error(page + '@' + w + 'x' + h + ' stuck scroll lock on load');
                        }
                        if (doc.documentElement.scrollHeight > h + 20) {
                            win.scrollTo({top: 80, behavior: 'instant'});
                            if (win.scrollY < 10) throw new Error(page + '@' + w + 'x' + h + ' failed to scroll vertically');
                            win.scrollTo({top: 0, behavior: 'instant'});
                        }

                        if (page === '/products' && w < 1024) {
                            const trigger = doc.querySelector('[data-storefront-open="catalog-filter-dialog"]');
                            const dlg = doc.getElementById('catalog-filter-dialog');
                            trigger.click();
                            await wait(50);
                            if (!dlg.open) throw new Error('/products@' + w + 'x' + h + ' filter dialog did not open');
                            if (w < 768 && !win.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                                if (win.getComputedStyle(dlg).animationName !== 'np-cart-in-bottom') {
                                    throw new Error('/products@' + w + 'x' + h + ' expected np-cart-in-bottom animation');
                                }
                            }
                            dlg.querySelector('[data-storefront-close]').click();
                            await wait(340);
                            if (dlg.open || doc.documentElement.classList.contains('storefront-overlay-open')) {
                                throw new Error('/products@' + w + 'x' + h + ' filter dialog failed to close via X');
                            }
                            if (w === 390) {
                                trigger.click();
                                await wait(50);
                                const b = dlg.getBoundingClientRect();
                                const tapY = Math.max(8, Math.floor(b.top / 2));
                                dlg.dispatchEvent(new win.PointerEvent('pointerdown', {clientX: 24, clientY: tapY, bubbles: true}));
                                dlg.dispatchEvent(new win.MouseEvent('click', {clientX: 24, clientY: tapY, detail: 0, bubbles: true}));
                                await wait(340);
                                if (dlg.open || doc.documentElement.classList.contains('storefront-overlay-open')) {
                                    throw new Error('/products@390x844 filter dialog failed to close via backdrop tap');
                                }
                                trigger.click();
                                await wait(50);
                                dlg.dispatchEvent(new win.KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
                                await wait(340);
                                if (dlg.open || doc.documentElement.classList.contains('storefront-overlay-open')) {
                                    throw new Error('/products@390x844 filter dialog failed to close via Escape');
                                }
                            }
                        }

                        if (page === '/repair' || page === '/repair/confirmation') {
                            const formPane = doc.querySelector('.repair-split-form').getBoundingClientRect();
                            const trustPane = doc.querySelector('.repair-split-trust').getBoundingClientRect();
                            if (w >= 960 && Math.abs(formPane.top - trustPane.top) > 4) {
                                throw new Error(page + '@' + w + 'x' + h + ' expected side-by-side split card');
                            }
                            if (w < 960 && trustPane.top + 4 < formPane.bottom) {
                                throw new Error(page + '@' + w + 'x' + h + ' expected stacked split card on mobile/tablet');
                            }
                            const labels = [...doc.querySelectorAll('.repair-progress__label')].map(el => el.getBoundingClientRect());
                            if (labels.length === 4) {
                                const nums = [...doc.querySelectorAll('.repair-progress__num')].map(el => el.getBoundingClientRect());
                                if (Math.abs(nums[0].top - nums[3].top) > 4) {
                                    throw new Error(page + '@' + w + 'x' + h + ' expected horizontal 4-column stepper circles');
                                }
                                for (let i = 0; i < labels.length; i++) {
                                    for (let j = i + 1; j < labels.length; j++) {
                                        const a = labels[i], b = labels[j];
                                        const overlapX = Math.min(a.right, b.right) - Math.max(a.left, b.left);
                                        const overlapY = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
                                        if (overlapX > 1 && overlapY > 1) {
                                            throw new Error('Stepper label overlap on ' + page + '@' + w + 'x' + h);
                                        }
                                    }
                                }
                            }
                        }

                        if (page === '/repair/confirmation' && w < 768) {
                            const mainBox = doc.querySelector('.repair-confirm-main').getBoundingClientRect();
                            const codeBox = doc.querySelector('.repair-confirmation-code').getBoundingClientRect();
                            if (codeBox.width < mainBox.width - 40) {
                                throw new Error('/repair/confirmation@' + w + 'x' + h + ' tracking code card should be full-width on mobile (got ' + codeBox.width + ' inside ' + mainBox.width + ')');
                            }
                        }

                        if (page === '/products/sample-pos') {
                            const bottomNav = doc.querySelector('.global-store-nav');
                            const mobileCart = doc.querySelector('.store-mobile-cart');
                            const dock = doc.querySelector('[data-product-dock]');
                            const bodyPriceGroup = doc.querySelector('.purchase .price-group');
                            const bodyCartForm = doc.querySelector('.purchase .product-cart-form');
                            if (w < 768) {
                                if (win.getComputedStyle(bottomNav).display !== 'none') {
                                    throw new Error('/products/sample-pos@' + w + 'x' + h + ' expected .global-store-nav hidden on mobile');
                                }
                                const cartRect = mobileCart.getBoundingClientRect();
                                if (cartRect.width < 36 || cartRect.left > w / 2) {
                                    throw new Error('/products/sample-pos@' + w + 'x' + h + ' expected .store-mobile-cart visible on top-left of mobile header');
                                }
                                const dockRect = dock.getBoundingClientRect();
                                const cw = doc.documentElement.clientWidth;
                                if (dock.inert || Math.abs(dockRect.bottom - win.innerHeight) > 2 || Math.abs(dockRect.width - cw) > 2) {
                                    throw new Error('/products/sample-pos@' + w + 'x' + h + ' expected full-width sticky buy bar at bottom=0 (inert=' + dock.inert + ', bottom=' + dockRect.bottom + ', width=' + dockRect.width + ', cw=' + cw + ')');
                                }
                                if (win.getComputedStyle(bodyPriceGroup).display !== 'none' || win.getComputedStyle(bodyCartForm).display !== 'none') {
                                    throw new Error('/products/sample-pos@' + w + 'x' + h + ' expected redundant body price/buy form hidden on <768px');
                                }
                            } else {
                                if (win.getComputedStyle(bodyPriceGroup).display === 'none' || win.getComputedStyle(bodyCartForm).display === 'none') {
                                    throw new Error('/products/sample-pos@' + w + 'x' + h + ' expected desktop purchase card visible on >=768px');
                                }
                            }
                        }

                        if (page === '/repair') {
                            const toggle = doc.querySelector('[data-other-person-toggle]');
                            const field = doc.querySelector('[data-other-person-field]');
                            const phone = doc.getElementById('responsive_phone');
                            if (!field.hidden) throw new Error('Expected alternate phone field hidden initially');
                            toggle.click();
                            if (field.hidden || win.getComputedStyle(phone).textAlign !== 'right') {
                                throw new Error('Expected right-aligned alternate phone field visible when checked');
                            }
                        }
                    }
                }
                document.body.textContent = 'VIEWPORT_SMOKE_ALL_PASS';
            })().catch(err => { document.body.textContent = 'VIEWPORT_SMOKE_FAIL: ' + (err?.stack || err?.message || err); });
            </script></body>`,
        };

        server = await startFixtureServer(routes);

        const result = await runChrome(executable, [
            '--headless=new', '--disable-gpu', '--disable-background-networking',
            '--window-size=1600,1200', '--no-first-run', '--user-data-dir=' + path.join(directory, 'profile'),
            '--virtual-time-budget=9500', '--dump-dom', server.origin + '/runner',
        ]);

        assert.equal(result.status, 0, result.stderr.slice(-500));
        assert.ok(/VIEWPORT_SMOKE_ALL_PASS/.test(result.stdout), result.stdout.slice(-900));
    } finally {
        await server?.close();
        if (realpathSync(path.dirname(directory)) !== realpathSync(tmpdir()) || !path.basename(directory).startsWith('novinpay-viewport-smoke-')) {
            throw new Error('Unexpected cleanup path');
        }
        rmSync(directory, {recursive: true, force: true, maxRetries: 3, retryDelay: 100});
    }
});
