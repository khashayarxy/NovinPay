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

test('Repair device facts and product key rows stay centered without overflow on mobile and desktop', async t => {
    const executable = findChrome();
    if (!executable) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(os.tmpdir(), 'novinpay-repair-facts-'));
    const manifest = JSON.parse(readFileSync(path.join(rootDir, 'public/build/manifest.json'), 'utf8'));
    const css = readFileSync(path.join(rootDir, 'public/build', manifest['resources/css/app.css'].file), 'utf8')
        + readFileSync(path.join(rootDir, 'public/css/storefront-premium.css'), 'utf8');
    const html = [
        '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>',
        css, '</style><body class="public-site"><div class="np-account np-account-detail np-container np-account-repair-tracking"><div class="np-account-order-columns">',
        '<section class="np-account-card np-repair-device-card"><h2>مشخصات دستگاه</h2><dl class="np-account-facts">',
        '<div><dt>برند</dt><dd>Newland</dd></div>',
        '<div><dt>شماره سریال</dt><dd><bdi dir="ltr">LONG-SERIAL-1234567890123456789012345678901234567890</bdi></dd></div>',
        '<div><dt>شرح مشکل</dt><dd class="whitespace-pre-line">شرح چندخطی مشکل دستگاه که باید خوانا و متوازن نمایش داده شود<br>توضیحات بیشتر درباره باتری دستگاه و مشکل شارژ</dd></div>',
        '<div><dt>هزینه تعمیر</dt><dd><span class="np-repair-device-cost">ثبت نشده</span></dd></div>',
        '</dl></section><section class="np-account-card"><h2>گام بعدی و نحوهٔ تحویل دستگاه</h2><div class="repair-handover-panel repair-handover-panel--office"><p class="repair-office-address">نشانی مراجعهٔ حضوری فروشگاه</p><p class="repair-office-hours">تلفن: ۰۹۳۶۲۸۹۶۳۷۳</p></div></section></div></div>',
        '<div class="premium-storefront"><div class="product-overview"><section class="key-mobile"><dl><div style="display:flex"><dt>باتری</dt><dd>ظرفیت باتری<br>۲۶۰۰ میلی‌آمپر ساعت</dd></div></dl></section></div></div>',
        '<script>const rows=[...document.querySelectorAll(".np-repair-device-card .np-account-facts > div")];',
        'const result={viewport:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth,rows:rows.map(row=>{const d=row.querySelector("dt").getBoundingClientRect(),v=row.querySelector("dd").getBoundingClientRect();return Math.abs(d.top+d.height/2-v.top-v.height/2);}),keyAlign:getComputedStyle(document.querySelector(".key-mobile dl > div")).alignItems};',
        'parent.document.body.dataset.probe=JSON.stringify(result);</script></body></html>',
    ].join('');
    const server = http.createServer((req, res) => {
        res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname === '/fixture') {
            res.end(html);
        } else {
            const width = Number(url.searchParams.get('width'));
            res.end('<!doctype html><html><body><iframe src="/fixture" style="width:' + width + 'px;height:900px;border:0"></iframe></body></html>');
        }
    });
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        for (const width of [320, 390, 1024, 1440]) {
            const result = await runChrome(executable, [
                '--headless=new', '--disable-gpu', '--no-sandbox',
                '--user-data-dir=' + path.join(directory, String(width)),
                '--window-size=1600,1000', '--virtual-time-budget=1000', '--dump-dom',
                'http://127.0.0.1:' + server.address().port + '/?width=' + width,
            ]);
            assert.equal(result.status, 0);
            const match = result.stdout.match(/data-probe="([^"]+)"/);
            assert.ok(match, 'Missing responsive probe at ' + width);
            const probe = JSON.parse(match[1].replace(/&quot;/g, '"'));
            assert.equal(probe.viewport, width, 'Requested viewport must be used');
            assert.equal(probe.overflow, false, 'Horizontal overflow at ' + width);
            assert.ok(probe.rows.every(delta => delta <= 1), 'Device labels and values must be centered at ' + width);
            assert.equal(probe.keyAlign, 'center');
        }
    } finally {
        await new Promise(resolve => server.close(resolve));
        rmSync(directory, {recursive: true, force: true});
    }
});

test('Phase 10 static stylesheet contracts: admin enterprise design system and single support-chat startup state', () => {
    const adminCss = readFileSync(path.join(rootDir, 'resources/css/admin.css'), 'utf8');
    const customerV2Css = readFileSync(path.join(rootDir, 'resources/support-chat/support-client/customer-v2.css'), 'utf8');

    assert.match(adminCss, /\.admin-stat-grid\b/);
    assert.match(adminCss, /\.admin-id-chip\b/);
    assert.match(adminCss, /\.admin-step-strip\b/);
    assert.match(adminCss, /\.admin-site\s+select\.input\b/);
    assert.match(adminCss, /\.admin-step-strip--4\b/);
    assert.match(adminCss, /\.repair-receipt-80mm\b/);
    assert.match(adminCss, /\.repair-formal-invoice\b/);
    assert.match(adminCss, /\.finance-doc-metrics\b/);
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
            <div style="position:fixed;top:0;left:0;visibility:hidden;pointer-events:none">
            <div class="np-account-repair-tracking">
                <section class="np-account-card np-account-order-stepper"><ol class="np-timeline"><li><strong>ثبت درخواست</strong><span id="repair-step-note" class="np-timeline__status">در انتظار تحویل دستگاه</span></li></ol></section>
                <section class="np-account-card"><div class="repair-handover-panel repair-handover-panel--office">
                    <p id="repair-store-address" class="repair-office-address">نشانی فروشگاه</p>
                    <p class="repair-office-hours">ساعات کاری فروشگاه</p>
                </div></section>
            </div>
            <span id="other-step-note" class="np-timeline__status">مرحلهٔ فعلی</span>
            </div>
            <div class="repair-track-btn-row">
                <button type="button" id="btn-handover" class="np-button np-button--primary repair-track-action-btn" data-toggle-handover-editor aria-expanded="false" aria-controls="repair-track-handover-panel">تغییر نحوهٔ تحویل</button>
                <button type="button" id="btn-another" class="np-button np-button--outline repair-track-action-btn">پیگیری کد دیگر</button>
                <a href="/repair" id="btn-new-repair" class="np-button np-button--outline repair-track-action-btn">ثبت درخواست تعمیر</a>
            </div>
            <div id="repair-track-handover-panel" class="repair-track-inline-handover" hidden>فرم تغییر نحوهٔ تحویل</div>
        </section>

        <section style="margin-top: 20px;">
            <div class="np-float-field" id="consultation-float-wrap" data-float-field>
                <select class="input" id="consultation-topic" name="topic" required>
                    <option value="" disabled selected hidden></option>
                    <option value="buy">مشاوره خرید کارتخوان</option>
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

        // Consultation true floating select (centered when empty, floats on focus/selection, blank option hidden)
        const topicWrap = document.getElementById('consultation-float-wrap');
        const topicEl = document.getElementById('consultation-topic');
        const topicLbl = document.getElementById('consultation-label');
        const blankOpt = topicEl.querySelector('option[value=""]');
        const topicRect = topicEl.getBoundingClientRect();
        const topicStyle = getComputedStyle(topicEl);
        const initialLblRect = topicLbl.getBoundingClientRect();
        const selectInitialFloated = topicWrap.classList.contains('is-floated');
        const selectCenteredDelta = Math.abs((initialLblRect.top + initialLblRect.height / 2) - (topicRect.top + topicRect.height / 2));
        const blankOptHiddenAndDisabled = Boolean(blankOpt?.disabled && blankOpt?.hidden && blankOpt.textContent === '');

        topicEl.focus();
        await new Promise(r => setTimeout(r, 30));
        const selectFocusFloated = topicWrap.classList.contains('is-floated');
        topicEl.blur();
        await new Promise(r => setTimeout(r, 30));
        const selectBlurReturned = !topicWrap.classList.contains('is-floated');

        topicEl.value = 'buy';
        topicLbl.style.transition = 'none';
        topicEl.dispatchEvent(new Event('change', { bubbles: true }));
        await new Promise(r => setTimeout(r, 30));
        const floatedLblRect = topicLbl.getBoundingClientRect();
        const selectSelectedFloated = topicWrap.classList.contains('is-floated');
        const selectLabelDelta = Math.abs((floatedLblRect.top + floatedLblRect.height / 2) - topicRect.top);

        window.__mobileProbe = {
            repairStepNoteDisplay: getComputedStyle(document.getElementById('repair-step-note')).display,
            otherStepNoteDisplay: getComputedStyle(document.getElementById('other-step-note')).display,
            repairAddressMargin: getComputedStyle(document.getElementById('repair-store-address')).margin,
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
            selectInitialFloated,
            selectCenteredDelta,
            blankOptHiddenAndDisabled,
            selectFocusFloated,
            selectBlurReturned,
            selectSelectedFloated,
            selectLabelDelta,
        };
    });
    </script>
</body>
</html>`;

    const desktopFooterHtml = `<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><style>*,*::before,*::after{box-sizing:border-box}body{margin:0;font-family:Tahoma,sans-serif}${cssBundle}</style></head>
<body class="public-site">
    <footer class="np-footer">
        <div class="np-container" style="max-width:1200px;margin:0 auto;padding:0 24px;">
            <div class="np-footer__grid">
                <div class="np-footer__brand">
                    <div class="np-footer__identity"><strong>نوین‌پی</strong><p class="np-footer__tagline">فروش و تعمیر کارتخوان در شهریار</p></div>
                </div>
                <div class="np-footer__links-col">
                    <h2>دسترسی سریع</h2>
                    <nav class="np-footer__links" aria-label="دسترسی سریع">
                        <a href="/products">فروشگاه</a>
                        <a href="/repair">درخواست تعمیر</a>
                        <a href="/repair/track">پیگیری تعمیر</a>
                        <a href="/contact">تماس با ما</a>
                    </nav>
                </div>
                <div class="np-footer__information">
                    <h2>تماس با نوین‌پی</h2>
                </div>
            </div>
        </div>
    </footer>
</body></html>`;

    const hostHtml = `<!doctype html>
<html lang="fa"><head><meta charset="utf-8"></head><body>
    <iframe id="mobile-360" src="/mobile-fixture" style="width:360px;height:800px;border:0;"></iframe>
    <iframe id="desktop-footer" src="/desktop-footer" style="width:1280px;height:360px;border:0;"></iframe>
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

        const dfDoc = document.getElementById('desktop-footer').contentDocument;
        const fLinks = [...dfDoc.querySelectorAll('.np-footer__links a')].map(el => el.getBoundingClientRect());
        const footerGrid2x2 = fLinks.length === 4
            && Math.abs(fLinks[0].top - fLinks[1].top) <= 1
            && Math.abs(fLinks[2].top - fLinks[3].top) <= 1
            && fLinks[2].top >= fLinks[0].bottom + 6
            && Math.abs(fLinks[0].width - fLinks[1].width) <= 1
            && Math.abs(fLinks[2].width - fLinks[3].width) <= 1
            && Math.abs(fLinks[0].width - fLinks[2].width) <= 1;

        document.body.setAttribute('data-probe', JSON.stringify({
            ...mf.contentWindow.__mobileProbe,
            skeletonDisplay,
            dotsDisplay,
            textDisplay,
            footerGrid2x2,
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
            if (reqPath === '/desktop-footer') {
                res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
                res.end(desktopFooterHtml);
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
        assert.equal(p.repairStepNoteDisplay, 'none', 'Account repair stepper must show titles without secondary notes');
        assert.notEqual(p.otherStepNoteDisplay, 'none', 'Other timeline status labels must remain visible');
        assert.equal(p.repairAddressMargin, '0px', 'Approved store address spacing must survive account card styles');

        // 4. Bottom floating bar cart badge outer corner + white ring
        assert.equal(p.badgeBg, 'rgb(180, 35, 45)', 'Cart badge must keep #b4232d red');
        assert.match(p.badgeShadow, /rgb\(255,\s*255,\s*255\)/, 'Bottom cart badge must have white ring box-shadow');
        assert.equal(p.badgeCoversSvgCenter, false, 'Bottom cart badge must perch on outer corner without overlapping cart SVG center');

        // 5. True floating-label select (empty rests centered, blank option hidden, floats on focus/selection) + 2x2 desktop footer symmetry
        assert.equal(p.dupChevronDisplay, 'none', 'Duplicate SVG chevron inside .commerce-select.np-float-field must be hidden');
        assert.equal(p.selectHeight, 54, 'Floating select must be 54px tall for uncrowded reading');
        assert.equal(p.selectAppearance, 'none', 'Floating select must suppress native OS arrow');
        assert.equal(p.selectInitialFloated, false, 'Empty unselected <select> must NOT float label prematurely');
        assert.ok(p.selectCenteredDelta <= 5, `Empty unselected <select> label must rest centered inside field, delta=${p.selectCenteredDelta}`);
        assert.equal(p.blankOptHiddenAndDisabled, true, 'Empty <select> placeholder option must be empty, disabled, and hidden');
        assert.equal(p.selectFocusFloated, true, 'Focusing empty <select> must float label to top border');
        assert.equal(p.selectBlurReturned, true, 'Blurring unselected <select> must return label to resting center');
        assert.equal(p.selectSelectedFloated, true, 'Selecting a non-empty option must keep label floated');
        assert.ok(p.selectLabelDelta <= 5, `Selected <select> floating label must sit on top border, delta=${p.selectLabelDelta}`);
        assert.equal(p.footerGrid2x2, true, 'Desktop footer quick-access buttons must form a symmetric 2x2 equal-width grid without 3+1 orphan wrap');

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
        stripImports(readFileSync(path.join(rootDir, 'resources/css/shared.css'), 'utf8')),
        stripImports(readFileSync(path.join(rootDir, 'resources/css/simple-repair.css'), 'utf8')),
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
    <div class="admin-shell">
    <aside id="admin-sidebar" class="admin-sidebar" aria-label="منوی مدیریت">
        <button type="button" class="admin-mobile-menu-btn admin-mobile-nav-toggle" data-admin-mobile-toggle aria-controls="admin-navigation-panel" aria-expanded="false">فهرست مدیریت</button>
        <div id="admin-navigation-panel" class="admin-navigation-panel" data-admin-navigation>
            <nav aria-label="ناوبری مدیریت" class="admin-nav">
                <a class="admin-nav-direct" href="/admin" aria-current="page"><span>داشبورد مدیریت</span></a>
                <div class="admin-nav-tier">
                    <p class="admin-nav-tier-label">عملیات روزانه</p>
                    <div class="admin-nav-section">
                        <div class="admin-nav-group" id="nav-group-repairs">
                            <button type="button" class="admin-nav-group-trigger" id="nav-btn-repairs" aria-expanded="false" aria-controls="nav-sub-repairs">
                                <span class="admin-nav-group-label"><span>تعمیرات</span></span>
                                <svg class="admin-nav-chevron-svg" id="nav-chev-repairs" style="transition:none" width="16" height="16" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <div id="nav-sub-repairs" class="admin-nav-children">
                                <a href="/admin/repairs">پرونده‌های تعمیر</a>
                                <a href="/admin/repair-requests">درخواست‌های آنلاین</a>
                            </div>
                        </div>
                    </div>
                    <div class="admin-nav-section">
                        <div class="admin-nav-group" id="nav-group-orders">
                            <button type="button" class="admin-nav-group-trigger" id="nav-btn-orders" aria-expanded="false" aria-controls="nav-sub-orders">
                                <span class="admin-nav-group-label"><span>سفارش‌ها و کالاها</span></span>
                                <svg class="admin-nav-chevron-svg" id="nav-chev-orders" style="transition:none" width="16" height="16" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <div id="nav-sub-orders" class="admin-nav-children">
                                <a href="/admin/orders">لیست سفارش‌ها</a>
                                <a href="/admin/products">مدیریت محصولات</a>
                            </div>
                        </div>
                    </div>
                    <div class="admin-nav-section">
                        <div class="admin-nav-group" id="nav-group-customers">
                            <button type="button" class="admin-nav-group-trigger" id="nav-btn-customers" aria-expanded="false" aria-controls="nav-sub-customers">
                                <span class="admin-nav-group-label"><span>مشتریان</span></span>
                                <svg class="admin-nav-chevron-svg" style="transition:none" width="16" height="16" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <div id="nav-sub-customers" class="admin-nav-children">
                                <a href="/admin/customers">لیست مشتریان</a>
                            </div>
                        </div>
                    </div>
                </div>
            </nav>
        </div>
    </aside>
    <main style="padding: 24px;">
        <div style="max-width: 280px;">
            <label class="label" for="finance-from">از تاریخ</label>
            <input class="input" type="date" id="finance-from" name="from" value="2026-11-27">
        </div>

        <form id="intake-form" style="margin-top: 20px;">
            <select id="intake-brand-select" name="device_brand" class="input" data-intake-brand-select>
                <option value="">انتخاب برند دستگاه…</option>
                <option value="PAX" selected>PAX</option>
                <option value="Newland">Newland</option>
            </select>
            <input class="input" name="device_model" value="9220">
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
    </div>

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

        // Exclusive Sidebar Accordion Checks
        const subRepairs = document.getElementById('nav-sub-repairs');
        const subOrders = document.getElementById('nav-sub-orders');
        const subCustomers = document.getElementById('nav-sub-customers');
        const btnRepairs = document.getElementById('nav-btn-repairs');
        const btnOrders = document.getElementById('nav-btn-orders');
        const chevRepairs = document.getElementById('nav-chev-repairs');

        const navInitialCollapsed = [subRepairs, subOrders, subCustomers].every(el => getComputedStyle(el).display === 'none')
            && btnRepairs.getAttribute('aria-expanded') === 'false'
            && btnOrders.getAttribute('aria-expanded') === 'false';
        const chevClosedTransform = getComputedStyle(chevRepairs).transform;

        // 1. Click Repairs -> opens Repairs only
        btnRepairs.click();
        const afterOpenRepairs = getComputedStyle(subRepairs).display === 'grid'
            && btnRepairs.getAttribute('aria-expanded') === 'true'
            && getComputedStyle(subOrders).display === 'none';
        const chevOpenTransform = getComputedStyle(chevRepairs).transform;

        // 2. Click Orders -> opens Orders and auto-collapses Repairs (exclusive accordion)
        btnOrders.click();
        const afterSwitchToOrders = getComputedStyle(subOrders).display === 'grid'
            && btnOrders.getAttribute('aria-expanded') === 'true'
            && getComputedStyle(subRepairs).display === 'none'
            && btnRepairs.getAttribute('aria-expanded') === 'false';

        // 3. Click Orders again -> collapses Orders
        btnOrders.click();
        const afterCollapseOrders = getComputedStyle(subOrders).display === 'none'
            && btnOrders.getAttribute('aria-expanded') === 'false';

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
            navInitialCollapsed,
            afterOpenRepairs,
            chevRotated: chevClosedTransform !== chevOpenTransform,
            afterSwitchToOrders,
            afterCollapseOrders,
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
        assert.equal(p.updatedModel, '9220', 'Changing intake brand dropdown must NOT auto-insert brand prefix into device_model');
        assert.equal(p.ordersHidden, true, 'Inactive tab panel must hide');
        assert.equal(p.casesHidden, false, 'Selected tab panel must show');
        assert.ok(p.pickerBtnHeight >= 34, `Persian file picker button must render cleanly in admin, height=${p.pickerBtnHeight}`);
        assert.equal(p.navInitialCollapsed, true, 'All sidebar groups must start collapsed by default on dashboard');
        assert.equal(p.afterOpenRepairs, true, 'Clicking a closed sidebar group must expand its submenu');
        assert.equal(p.chevRotated, true, 'Expanding a sidebar group must rotate its chevron SVG');
        assert.equal(p.afterSwitchToOrders, true, 'Expanding another sidebar group must auto-collapse the previously open group');
        assert.equal(p.afterCollapseOrders, true, 'Clicking an already open sidebar group must collapse it');
    } finally {
        if (server) await new Promise(r => server.close(() => r()));
        try { rmSync(directory, {recursive: true, force: true}); } catch {}
    }
});
