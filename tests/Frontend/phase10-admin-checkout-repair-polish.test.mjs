import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {existsSync, mkdtempSync, readFileSync, rmSync} from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

function findChrome() {
    const candidates = [
        process.env.CHROME_BIN,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
    ].filter(Boolean);
    return candidates.find(p => existsSync(p)) || null;
}

function runChrome(executable, args) {
    return new Promise((resolve, reject) => {
        const child = spawn(executable, args, {stdio: ['ignore', 'pipe', 'pipe']});
        let stdout = '';
        let stderr = '';
        child.stdout.setEncoding('utf8');
        child.stderr.setEncoding('utf8');
        child.stdout.on('data', chunk => { stdout += chunk; });
        child.stderr.on('data', chunk => { stderr += chunk; });
        child.on('error', reject);
        child.on('close', status => resolve({status, stdout, stderr}));
    });
}

function loadStorefrontCssBundle() {
    const stripImports = s => s
        .replace(/@import\s+['"][^'"]+['"];?/g, '')
        .replace(/@source\s+['"][^'"]+['"];?/g, '')
        .replace(/@theme\s+static\s*\{[\s\S]*?\}/g, '')
        .replace(/@apply\s+[^;]+;/g, '');

    return [
        stripImports(readFileSync(path.join(rootDir, 'resources/css/home.css'), 'utf8')),
        stripImports(readFileSync(path.join(rootDir, 'resources/css/public.css'), 'utf8')),
        stripImports(readFileSync(path.join(rootDir, 'resources/css/customer-account.css'), 'utf8')),
        stripImports(readFileSync(path.join(rootDir, 'resources/css/app.css'), 'utf8')),
        readFileSync(path.join(rootDir, 'public/css/store-navigation.css'), 'utf8'),
        readFileSync(path.join(rootDir, 'public/css/storefront-refinement.css'), 'utf8'),
        readFileSync(path.join(rootDir, 'public/css/product-experience.css'), 'utf8'),
    ].join('\n');
}

test('Phase 10 static stylesheet contracts: admin enterprise design system and single support-chat startup state', () => {
    const adminCss = readFileSync(path.join(rootDir, 'resources/css/admin.css'), 'utf8');
    const customerV2Css = readFileSync(path.join(rootDir, 'resources/support-chat/support-client/customer-v2.css'), 'utf8');

    assert.match(adminCss, /\.admin-stat-grid\b/);
    assert.match(adminCss, /\.admin-id-chip\b/);
    assert.match(adminCss, /\.admin-step-strip\b/);
    assert.match(adminCss, /\.admin-site\s+select\.input\b/);
    assert.match(adminCss, /dialog\.admin-calendar\[open\]/);
    assert.match(adminCss, /\.admin-calendar-days\b/);
    assert.match(adminCss, /\.admin-autofill-trap\b/);
    assert.match(customerV2Css, /\[data-support-stable-shell\]\[data-init-state=initializing\]:not\(\[data-connection-state\]\)\s*:is\(\.support-init-dots,\[data-init-text\]\)\{display:none\}/);
});

test('Phase 10 Chromium mobile layout (360x800): checkout stepper state & single-row pills, inline repair-track buttons, bottom cart badge, floating select, and chat startup state', async t => {
    const executable = findChrome();
    if (!executable) {
        t.skip('Chromium/Edge executable not available');
        return;
    }

    const cssBundle = loadStorefrontCssBundle();
    const customerV2Css = readFileSync(path.join(rootDir, 'resources/support-chat/support-client/customer-v2.css'), 'utf8');
    const directory = mkdtempSync(path.join(os.tmpdir(), 'np-phase10-'));
    let server;

    const fixtureHtml = `<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: Tahoma, sans-serif; }
${cssBundle}
</style>
</head>
<body class="public-site">
    <main class="commerce-page" data-checkout-stepper data-initial-step="1">
        <div class="checkout-header">
            <h1>تسویه حساب</h1>
            <ol class="checkout-steps" id="checkout-steps">
                <li class="checkout-step is-active" data-step-indicator="1" tabindex="0"><span class="checkout-step__num">۱</span><span class="checkout-step__label">مشخصات</span></li>
                <li class="checkout-step" data-step-indicator="2" tabindex="0"><span class="checkout-step__num">۲</span><span class="checkout-step__label">آدرس</span></li>
                <li class="checkout-step" data-step-indicator="3" tabindex="0"><span class="checkout-step__num">۳</span><span class="checkout-step__label">نحوه ارسال</span></li>
                <li class="checkout-step" data-step-indicator="4" tabindex="0"><span class="checkout-step__num">۴</span><span class="checkout-step__label">پرداخت</span></li>
            </ol>
        </div>

        <form action="/cart/checkout" method="post" data-store-checkout>
            <div class="checkout-step-panel is-active" data-checkout-step="1">
                <input name="full_name" value="رضا نادری">
                <input name="mobile" value="09124445566">
                <input name="national_code" value="0013520849">
                <button type="button" id="go-step-2" data-checkout-next="2">ادامه به آدرس</button>
            </div>
            <div class="checkout-step-panel" data-checkout-step="2">
                <input type="radio" name="address_mode" value="saved" checked>
                <div class="commerce-select np-float-field" data-float-field>
                    <select id="saved-addr" name="address" class="checkout-input">
                        <option value="addr-1" selected>تهران، شهریار، خیابان ولیعصر</option>
                    </select>
                    <label id="saved-addr-label" for="saved-addr">نشانی ذخیره‌شده</label>
                    <svg id="dup-chevron" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                </div>
                <button type="button" data-checkout-prev="1">بازگشت</button>
                <button type="button" data-checkout-next="3">ادامه</button>
            </div>
            <div class="checkout-step-panel" data-checkout-step="3">
                <input type="radio" name="method" value="pickup" data-label="تحویل حضوری" data-cost-toman="0" checked>
                <button type="button" data-checkout-next="4">ادامه</button>
            </div>
            <div class="checkout-step-panel" data-checkout-step="4">
                <label class="checkout-gateway-card checkout-gateway-card--zibal">
                    <input type="radio" name="payment_method" value="zibal" checked>
                    <span class="checkout-gateway-card__body">
                        <span class="checkout-gateway-card__title-row">
                            <strong>پرداخت آنلاین با درگاه زیبال</strong>
                            <svg id="zibal-logo" class="checkout-gateway-card__logo" width="35" height="42" viewBox="0 0 35 42"></svg>
                        </span>
                    </span>
                </label>
                <button type="submit" data-checkout-submit>پرداخت آنلاین با زیبال</button>
            </div>
        </form>

        <section id="track-section" style="margin-top: 20px;">
            <div class="repair-track-btn-row">
                <button type="button" id="btn-handover" class="np-button np-button--primary repair-track-action-btn" data-toggle-handover-editor aria-expanded="false" aria-controls="repair-track-handover-panel">تغییر نحوهٔ تحویل</button>
                <button type="button" id="btn-another" class="np-button np-button--outline repair-track-action-btn">پیگیری کد دیگر</button>
                <a href="/repair" id="btn-new-repair" class="np-button np-button--outline repair-track-action-btn">ثبت درخواست تعمیر</a>
            </div>
            <div id="repair-track-handover-panel" class="repair-track-inline-handover" hidden>فرم تغییر نحوهٔ تحویل</div>
        </section>

        <section style="margin-top: 20px;">
            <div class="np-float-field" data-float-field>
                <select class="input" id="consultation-topic" name="topic">
                    <option value="">انتخاب موضوع مشاوره…</option>
                </select>
                <label class="label" id="consultation-label" for="consultation-topic">موضوع مشاوره</label>
            </div>
        </section>
    </main>

    <nav class="global-store-nav" id="bottom-nav" data-mobile-bottom-nav>
        <a href="/"><span class="global-store-nav__icon-wrap"><svg viewBox="0 0 24 24"></svg></span><span>خانه</span></a>
        <a href="/products"><span class="global-store-nav__icon-wrap"><svg viewBox="0 0 24 24"></svg></span><span>فروشگاه</span></a>
        <a href="/cart" id="bottom-cart-link">
            <span class="global-store-nav__icon-wrap" id="bottom-cart-wrap">
                <svg id="bottom-cart-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/></svg>
                <span data-store-cart-count class="store-cart-count" id="bottom-cart-badge"><bdi>۲</bdi></span>
            </span>
            <span>سبد خرید</span>
        </a>
        <a href="/repair"><span class="global-store-nav__icon-wrap"><svg viewBox="0 0 24 24"></svg></span><span>تعمیرات</span></a>
        <a href="/account"><span class="global-store-nav__icon-wrap"><svg viewBox="0 0 24 24"></svg></span><span>حساب کاربری</span></a>
    </nav>

    <script type="module" src="/resources/js/app.js"></script>
    <script>
    window.addEventListener('load', async () => {
        await new Promise(r => setTimeout(r, 220));

        const s1 = document.querySelector('[data-step-indicator="1"]');
        const s2 = document.querySelector('[data-step-indicator="2"]');
        const s3 = document.querySelector('[data-step-indicator="3"]');
        const s4 = document.querySelector('[data-step-indicator="4"]');

        const initialStep1Active = s1.classList.contains('is-active');
        const initialStep1Done = s1.classList.contains('is-done');
        const initialStep2Done = s2.classList.contains('is-done');
        const initialStep2Active = s2.classList.contains('is-active');

        const r1 = s1.getBoundingClientRect();
        const r2 = s2.getBoundingClientRect();
        const r3 = s3.getBoundingClientRect();
        const r4 = s4.getBoundingClientRect();
        const maxTopDelta = Math.max(
            Math.abs(r1.top - r2.top),
            Math.abs(r1.top - r3.top),
            Math.abs(r1.top - r4.top)
        );
        const docScrollWidth = document.documentElement.scrollWidth;
        const winInnerWidth = window.innerWidth;

        // Advance to Step 2
        document.getElementById('go-step-2').click();
        await new Promise(r => setTimeout(r, 50));
        const afterNextStep1Done = s1.classList.contains('is-done');
        const afterNextStep2Active = s2.classList.contains('is-active');
        const afterNextStep3Done = s3.classList.contains('is-done');
        const dupChevronDisplay = getComputedStyle(document.getElementById('dup-chevron')).display;

        // Track buttons height, radius, and inline toggle
        const b1 = document.getElementById('btn-handover');
        const b2 = document.getElementById('btn-another');
        const b3 = document.getElementById('btn-new-repair');
        const h1 = b1.getBoundingClientRect().height;
        const h2 = b2.getBoundingClientRect().height;
        const h3 = b3.getBoundingClientRect().height;
        const rad1 = getComputedStyle(b1).borderRadius;
        const rad2 = getComputedStyle(b2).borderRadius;
        const rad3 = getComputedStyle(b3).borderRadius;
        b1.click();
        await new Promise(r => setTimeout(r, 40));
        const handoverOpenedInline = !document.getElementById('repair-track-handover-panel').hidden;

        // Bottom cart badge parity & non-overlap with SVG center
        const svgRect = document.getElementById('bottom-cart-svg').getBoundingClientRect();
        const badgeEl = document.getElementById('bottom-cart-badge');
        const badgeRect = badgeEl.getBoundingClientRect();
        const badgeStyle = getComputedStyle(badgeEl);
        const svgCenterX = svgRect.left + svgRect.width / 2;
        const svgCenterY = svgRect.top + svgRect.height / 2;
        const badgeCoversSvgCenter = (
            svgCenterX >= badgeRect.left &&
            svgCenterX <= badgeRect.right &&
            svgCenterY >= badgeRect.top &&
            svgCenterY <= badgeRect.bottom
        );

        // Consultation floating select
        const topicEl = document.getElementById('consultation-topic');
        const topicRect = topicEl.getBoundingClientRect();
        const topicStyle = getComputedStyle(topicEl);
        const topicLblRect = document.getElementById('consultation-label').getBoundingClientRect();

        window.__mobileProbe = {
            winInnerWidth,
            initialStep1Active,
            initialStep1Done,
            initialStep2Done,
            initialStep2Active,
            maxTopDelta,
            step4Left: r4.left,
            docScrollWidth,
            afterNextStep1Done,
            afterNextStep2Active,
            afterNextStep3Done,
            dupChevronDisplay,
            btnHeights: [h1, h2, h3],
            btnRadii: [rad1, rad2, rad3],
            handoverOpenedInline,
            badgeBg: badgeStyle.backgroundColor,
            badgeShadow: badgeStyle.boxShadow,
            badgeCoversSvgCenter,
            selectHeight: topicRect.height,
            selectAppearance: topicStyle.appearance,
            selectLabelDelta: Math.abs((topicLblRect.top + topicLblRect.height / 2) - topicRect.top),
        };
    });
    </script>
</body>
</html>`;

    const hostHtml = `<!doctype html>
<html lang="fa"><head><meta charset="utf-8"></head><body>
    <iframe id="mobile-360" src="/mobile-fixture" style="width:360px;height:800px;border:0;"></iframe>
    <iframe id="chat-frame" src="/chat-widget" style="width:360px;height:500px;border:0;"></iframe>
    <script>
    window.addEventListener('load', async () => {
        const mf = document.getElementById('mobile-360');
        for (let i = 0; i < 40 && !mf.contentWindow.__mobileProbe; i++) {
            await new Promise(r => setTimeout(r, 50));
        }
        const chatDoc = document.getElementById('chat-frame').contentDocument;
        const skeletonDisplay = getComputedStyle(chatDoc.querySelector('.support-skeleton')).display;
        const dotsDisplay = getComputedStyle(chatDoc.querySelector('.support-init-dots')).display;
        const textDisplay = getComputedStyle(chatDoc.querySelector('[data-init-text]')).display;
        document.body.setAttribute('data-probe', JSON.stringify({
            ...mf.contentWindow.__mobileProbe,
            skeletonDisplay,
            dotsDisplay,
            textDisplay,
        }));
    });
    </script>
</body></html>`;

    const chatHtml = `<!doctype html>
<html lang="fa" dir="rtl" data-support-surface="customer-v2">
<head><meta charset="utf-8"><style>${customerV2Css}</style></head>
<body>
    <div class="chat-window active" id="chatWindow" data-support-stable-shell data-init-state="initializing">
        <section class="support-conversation">
            <section id="supportInit" class="support-init-status">
                <div class="support-skeleton"><div class="support-skeleton__bubble support-skeleton__bubble--in"></div></div>
                <span class="support-init-dots"><i></i><i></i><i></i></span>
                <span data-init-text>در حال آماده‌سازی گفتگو…</span>
            </section>
        </section>
    </div>
</body></html>`;

    try {
        server = http.createServer((req, res) => {
            const reqPath = (req.url || '/').split('?')[0];
            if (reqPath === '/chat-widget') {
                res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
                res.end(chatHtml);
                return;
            }
            if (reqPath.startsWith('/resources/js/')) {
                const baseName = path.posix.basename(reqPath);
                const jsFile = baseName.endsWith('.js') ? baseName : `${baseName}.js`;
                const fullPath = path.join(rootDir, 'resources/js', jsFile);
                if (existsSync(fullPath)) {
                    const jsBody = readFileSync(fullPath, 'utf8')
                        .replace(/(from\s+['"]\.\/[^'".]+)(['"])/g, '$1.js$2');
                    res.writeHead(200, {'Content-Type': 'text/javascript; charset=utf-8', 'Cache-Control': 'no-store'});
                    res.end(jsBody);
                    return;
                }
                res.writeHead(404);
                res.end();
                return;
            }
            if (reqPath === '/mobile-fixture') {
                res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
                res.end(fixtureHtml);
                return;
            }
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
            res.end(hostHtml);
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
            `--user-data-dir=${path.join(directory, 'p-360')}`,
            '--window-size=1280,960',
            '--virtual-time-budget=3500',
            '--dump-dom',
            origin + '/',
        ]);
        assert.equal(res.status, 0, `Chromium failed: ${res.stderr}`);
        const match = res.stdout.match(/data-probe="([^"]+)"/);
        assert.ok(match, 'Probe missing from DOM');
        const p = JSON.parse(match[1].replace(/&quot;/g, '"'));

        assert.equal(p.winInnerWidth, 360, 'Mobile iframe must be exact 360px viewport');

        // 1. Checkout stepper states (Step 2 NEVER green on Step 1!)
        assert.equal(p.initialStep1Active, true, 'Step 1 must start active (blue)');
        assert.equal(p.initialStep1Done, false, 'Step 1 must not start done');
        assert.equal(p.initialStep2Done, false, 'Step 2 ("آدرس") must NOT render green before reaching it');
        assert.equal(p.initialStep2Active, false, 'Step 2 must not start active');
        assert.equal(p.afterNextStep1Done, true, 'Step 1 must turn green after advancing to Step 2');
        assert.equal(p.afterNextStep2Active, true, 'Step 2 must turn active (blue) on Step 2');
        assert.equal(p.afterNextStep3Done, false, 'Step 3 must remain gray while on Step 2');

        // 2. Single-row mobile checkout stepper at 360px with zero horizontal overflow
        assert.ok(p.maxTopDelta <= 2, `All 4 checkout steps must stay on 1 horizontal row on 360px mobile, maxTopDelta=${p.maxTopDelta}`);
        assert.ok(p.step4Left >= 0, `4th checkout step must remain within viewport, left=${p.step4Left}`);
        assert.ok(p.docScrollWidth <= 360, `Document must not horizontally overflow 360px viewport, scrollWidth=${p.docScrollWidth}`);

        // 3. Standardized /repair/track action buttons + inline handover panel
        assert.equal(p.btnHeights[0], 46, 'Primary track button must be 46px tall');
        assert.equal(p.btnHeights[1], 46, 'Secondary track button must be 46px tall');
        assert.equal(p.btnHeights[2], 46, 'Outline link track button must be 46px tall');
        assert.equal(p.btnRadii[0], '12px');
        assert.equal(p.btnRadii[1], '12px');
        assert.equal(p.btnRadii[2], '12px');
        assert.equal(p.handoverOpenedInline, true, 'Clicking "تغییر نحوهٔ تحویل" must reveal inline handover panel');

        // 4. Bottom floating bar cart badge outer corner + white ring
        assert.equal(p.badgeBg, 'rgb(180, 35, 45)', 'Cart badge must keep #b4232d red');
        assert.match(p.badgeShadow, /rgb\(255,\s*255,\s*255\)/, 'Bottom cart badge must have white ring box-shadow');
        assert.equal(p.badgeCoversSvgCenter, false, 'Bottom cart badge must perch on outer corner without overlapping cart SVG center');

        // 5. Floating select clean height, no duplicate chevron, floated label
        assert.equal(p.dupChevronDisplay, 'none', 'Duplicate SVG chevron inside .commerce-select.np-float-field must be hidden');
        assert.equal(p.selectHeight, 54, 'Floating select must be 54px tall for uncrowded reading');
        assert.equal(p.selectAppearance, 'none', 'Floating select must suppress native OS arrow');
        assert.ok(p.selectLabelDelta <= 5, `Select floating label must sit on top border, delta=${p.selectLabelDelta}`);

        // 6. Support chat single startup loading indicator
        assert.equal(p.skeletonDisplay, 'flex', 'Support skeleton must display during initializing state');
        assert.equal(p.dotsDisplay, 'none', 'Duplicate support-init-dots must be hidden during initializing state');
        assert.equal(p.textDisplay, 'none', 'Duplicate [data-init-text] must be hidden during initializing state');
    } finally {
        if (server) await new Promise(r => server.close(() => r()));
        try { rmSync(directory, {recursive: true, force: true}); } catch {}
    }
});

test('Phase 10 Admin Chromium UX: anchored 7-column Jalali datepicker popover, intake brand select, tabs, and Persian file picker', async t => {
    const executable = findChrome();
    if (!executable) {
        t.skip('Chromium/Edge executable not available');
        return;
    }

    const stripImports = s => s
        .replace(/@import\s+['"][^'"]+['"];?/g, '')
        .replace(/@source\s+['"][^'"]+['"];?/g, '')
        .replace(/@theme\s+static\s*\{[\s\S]*?\}/g, '')
        .replace(/@apply\s+[^;]+;/g, '');

    const adminCssBundle = [
        stripImports(readFileSync(path.join(rootDir, 'resources/css/customer-account.css'), 'utf8')),
        stripImports(readFileSync(path.join(rootDir, 'resources/css/app.css'), 'utf8')),
        stripImports(readFileSync(path.join(rootDir, 'resources/css/admin.css'), 'utf8')),
    ].join('\n');

    const directory = mkdtempSync(path.join(os.tmpdir(), 'np-admin-ux-'));
    let server;

    const adminHtml = `<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: Tahoma, sans-serif; }
${adminCssBundle}
</style>
</head>
<body class="admin-site">
    <main style="padding: 24px;">
        <div style="max-width: 280px;">
            <label class="label" for="finance-from">از تاریخ</label>
            <input class="input" type="date" id="finance-from" name="from" value="2026-11-27">
        </div>

        <form id="intake-form" style="margin-top: 20px;">
            <select id="intake-brand-select" class="input" data-intake-brand-select>
                <option value="">انتخاب برند دستگاه…</option>
                <option value="PAX" selected>PAX</option>
                <option value="Newland">Newland</option>
            </select>
            <input class="input" name="device_model" value="PAX S910">
        </form>

        <section id="tabs-section" style="margin-top: 20px;">
            <div class="admin-tab-bar" data-admin-tabs>
                <button type="button" class="admin-tab-btn" data-admin-tab="orders" aria-selected="true">سفارش‌ها</button>
                <button type="button" class="admin-tab-btn" data-admin-tab="cases" id="cases-tab-btn" aria-selected="false">تعمیرات</button>
            </div>
            <div data-admin-tab-panel="orders" id="orders-panel">پنل سفارش</div>
            <div data-admin-tab-panel="cases" id="cases-panel" hidden>پنل تعمیرات</div>
        </section>

        <div class="np-file-picker" data-np-file-picker style="margin-top: 20px;">
            <input id="prod-img" name="image" type="file" class="sr-only" data-np-file-input>
            <label for="prod-img" class="np-file-picker__btn">انتخاب تصویر اصلی</label>
            <span class="np-file-picker__name" data-np-file-name data-empty-label="فایلی انتخاب نشده است">فایلی انتخاب نشده است</span>
        </div>
    </main>

    <script type="module">
        import { initAdminCalendar } from '/resources/js/admin-calendar.js';
        initAdminCalendar(document.body);
    </script>
    <script type="module" src="/resources/js/app.js"></script>
    <script>
    window.addEventListener('load', async () => {
        await new Promise(r => setTimeout(r, 200));

        const calDialog = document.querySelector('dialog.admin-calendar');
        const closedDisplay = getComputedStyle(calDialog).display;

        document.querySelector('.admin-date-input button[aria-haspopup="dialog"]').click();
        await new Promise(r => setTimeout(r, 60));

        const openDisplay = getComputedStyle(calDialog).display;
        const calBg = getComputedStyle(calDialog).backgroundColor;
        const calRect = calDialog.getBoundingClientRect();
        const daysGridCols = getComputedStyle(calDialog.querySelector('.admin-calendar-days')).gridTemplateColumns.trim().split(/\\s+/).length;

        const brandSelect = document.getElementById('intake-brand-select');
        brandSelect.value = 'Newland';
        brandSelect.dispatchEvent(new Event('change', { bubbles: true }));
        const updatedModel = document.querySelector('input[name="device_model"]').value;

        document.getElementById('cases-tab-btn').click();
        const ordersHidden = document.getElementById('orders-panel').hidden;
        const casesHidden = document.getElementById('cases-panel').hidden;

        const pickerBtnHeight = document.querySelector('.np-file-picker__btn').getBoundingClientRect().height;

        document.body.setAttribute('data-admin-probe', JSON.stringify({
            closedDisplay,
            openDisplay,
            calBg,
            calLeft: calRect.left,
            calTop: calRect.top,
            calRight: calRect.right,
            calWidth: calRect.width,
            winWidth: window.innerWidth,
            daysGridCols,
            updatedModel,
            ordersHidden,
            casesHidden,
            pickerBtnHeight,
        }));
    });
    </script>
</body></html>`;

    try {
        server = http.createServer((req, res) => {
            const reqPath = (req.url || '/').split('?')[0];
            if (reqPath.startsWith('/resources/js/')) {
                const baseName = path.posix.basename(reqPath);
                const jsFile = baseName.endsWith('.js') ? baseName : `${baseName}.js`;
                const fullPath = path.join(rootDir, 'resources/js', jsFile);
                if (existsSync(fullPath)) {
                    const jsBody = readFileSync(fullPath, 'utf8')
                        .replace(/(from\s+['"]\.\/[^'".]+)(['"])/g, '$1.js$2');
                    res.writeHead(200, {'Content-Type': 'text/javascript; charset=utf-8', 'Cache-Control': 'no-store'});
                    res.end(jsBody);
                    return;
                }
                res.writeHead(404);
                res.end();
                return;
            }
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
            res.end(adminHtml);
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
            `--user-data-dir=${path.join(directory, 'p-admin')}`,
            '--window-size=1280,900',
            '--virtual-time-budget=3000',
            '--dump-dom',
            origin + '/',
        ]);
        assert.equal(res.status, 0, `Chromium failed: ${res.stderr}`);
        const match = res.stdout.match(/data-admin-probe="([^"]+)"/);
        assert.ok(match, 'Admin probe missing from DOM');
        const p = JSON.parse(match[1].replace(/&quot;/g, '"'));

        assert.equal(p.closedDisplay, 'none', 'Closed Jalali calendar dialog must be hidden');
        assert.equal(p.openDisplay, 'grid', 'Open Jalali calendar dialog must display as grid popover');
        assert.equal(p.calBg, 'rgb(255, 255, 255)', 'Calendar popover must have solid white card surface');
        assert.equal(p.daysGridCols, 7, 'Jalali calendar days must render as a 7-column grid');
        assert.ok(p.calLeft >= 12 && p.calRight <= p.winWidth - 12 && p.calTop >= 12, `Calendar popover must stay clamped inside viewport: left=${p.calLeft}, right=${p.calRight}, top=${p.calTop}`);
        assert.ok(p.calWidth >= 280 && p.calWidth <= 320, `Calendar popover width must be compact (~308px), got ${p.calWidth}`);
        assert.equal(p.updatedModel, 'Newland S910', 'Intake brand dropdown must swap brand prefix cleanly on device_model');
        assert.equal(p.ordersHidden, true, 'Inactive tab panel must hide');
        assert.equal(p.casesHidden, false, 'Selected tab panel must show');
        assert.ok(p.pickerBtnHeight >= 34, `Persian file picker button must render cleanly in admin, height=${p.pickerBtnHeight}`);
    } finally {
        if (server) await new Promise(r => server.close(() => r()));
        try { rmSync(directory, {recursive: true, force: true}); } catch {}
    }
});
