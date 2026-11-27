import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path, { join, extname } from 'node:path';
import { spawn } from 'node:child_process';

const rootDir = process.cwd();

const findChrome = () => (process.env.CHROME_BIN ? [process.env.CHROME_BIN] : process.platform === 'win32'
    ? ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']
    : ['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser']).find(existsSync);

const runChrome = (executable, args, timeoutMs = 25000) => new Promise((resolve, reject) => {
    const child = spawn(executable, args, { windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
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
    child.once('close', status => { clearTimeout(timer); resolve({ status, stdout, stderr }); });
});

function loadCustomerCssBundle() {
    const stripDirectives = (css) =>
        css
            .replace(/^@(?:import|source)\b[^\r\n]*;/gm, '')
            .replace(/@theme\s*\{[\s\S]*?\}/g, '')
            .replace(/@font-face\s*\{[^}]*\}/g, '');

    return [
        '* { box-sizing: border-box; } html, body { margin: 0; padding: 0; } [hidden] { display: none !important; }',
        stripDirectives(readFileSync(join(rootDir, 'resources/css/app.css'), 'utf8')),
        stripDirectives(readFileSync(join(rootDir, 'resources/css/home.css'), 'utf8')),
        stripDirectives(readFileSync(join(rootDir, 'resources/css/public.css'), 'utf8')),
        stripDirectives(readFileSync(join(rootDir, 'resources/css/customer-account.css'), 'utf8')),
        readFileSync(join(rootDir, 'public/css/storefront-refinement.css'), 'utf8'),
        readFileSync(join(rootDir, 'public/css/store-navigation.css'), 'utf8'),
        readFileSync(join(rootDir, 'public/css/product-experience.css'), 'utf8'),
    ].join('\n');
}

test('Phase 9 support chat CSS/JS enforces calm blue palette, uniform circular header buttons, skeleton loader, and 3-choice CSAT layout', () => {
    const interactionCss = readFileSync(join(rootDir, 'resources/support-chat/support-client/interaction.css'), 'utf8');
    const customerV2Css = readFileSync(join(rootDir, 'resources/support-chat/support-client/customer-v2.css'), 'utf8');
    const bplusCss = readFileSync(join(rootDir, 'resources/support-chat/support-client/bplus.css'), 'utf8');
    const launcherCss = readFileSync(join(rootDir, 'resources/support-chat/support-client/launcher.css'), 'utf8');
    const widgetHtml = readFileSync(join(rootDir, 'resources/support-chat/support-client/widget-v2.html'), 'utf8');
    const satisfactionJs = readFileSync(join(rootDir, 'resources/support-chat/support-client/satisfaction.js'), 'utf8');
    const operatorJs = readFileSync(join(rootDir, 'resources/support-chat/operator/app.js'), 'utf8');
    const workspaceCss = readFileSync(join(rootDir, 'resources/support-chat/operator/workspace.css'), 'utf8');

    // 1. Calm SaaS blue (#1d5bd6) replaces neon #0866ff in customer widget & launcher
    assert.match(customerV2Css, /--blue:#1d5bd6/);
    assert.match(launcherCss, /background:#1d5bd6/);
    assert.doesNotMatch(bplusCss, /#0866ff/);

    // 2. Moon/dark-mode button (.support-quick-theme) is excluded from .icon-button borderless 9px reset and forced uniform 36x36 pill
    assert.match(interactionCss, /:not\(\.support-quick-theme\)/);
    assert.match(bplusCss, /\.chat-header-actions>button\.icon-button\{[^}]*width:36px!important;height:36px!important[^}]*border-radius:999px!important/);

    // 3. Skeleton loader exists in widget-v2.html & customer-v2.css
    assert.match(widgetHtml, /class="support-skeleton"/);
    assert.match(customerV2Css, /\.support-skeleton__bubble\{/);

    // 4. Post-chat CSAT places all 3 choices ("بله", "خیر", "فعلاً نه") inside .support-csat-choices 3-column grid
    assert.match(satisfactionJs, /button\('بله',[^\r\n]+,choices,/);
    assert.match(satisfactionJs, /button\('خیر',[^\r\n]+,choices,/);
    assert.match(satisfactionJs, /button\('فعلاً نه',[^\r\n]+,choices,/);
    assert.match(customerV2Css, /\.support-csat-choices\{display:grid;grid-template-columns:repeat\(3,minmax\(0,1fr\)\);gap:8px/);

    // 5. Operator /support panel renders status pills, CSAT badges, context chips & quick-reply bar
    assert.match(operatorJs, /renderQuickReplyBar\(\)/);
    assert.match(operatorJs, /op-status-pill/);
    assert.match(operatorJs, /op-csat-pill/);
    assert.match(workspaceCss, /\.op-quick-reply-bar\{/);
});

test('Phase 9 Chromium verification: mutually-exclusive addresses, horizontal mobile stepper, cart badge outer ring, review edit/helpful, and device prefill', async t => {
    const executable = findChrome();
    if (!executable) {
        t.skip('Chromium/Edge executable not available');
        return;
    }

    const cssBundle = loadCustomerCssBundle();
    let helpfulVoteState = false;
    let helpfulCount = 2;

    const htmlFixture = `<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>${cssBundle}</style>
</head>
<body class="public-site product-detail-page">
    <header class="np-header">
        <a class="np-customer-trigger store-mobile-cart" id="test-mobile-cart" href="/cart" aria-label="سبد خرید">
            <svg id="test-cart-svg" width="20" height="20" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/></svg>
            <span data-store-cart-count class="store-cart-count ring-2 ring-white" id="test-cart-badge"><bdi>۳</bdi></span>
        </a>
    </header>

    <main class="np-account">
        <section class="np-account-card">
            <div class="np-stepper-wrap">
                <ol class="np-timeline np-timeline--horizontal" id="test-horizontal-stepper">
                    <li data-stage="complete"><span class="np-timeline__mark">✓</span><div><strong>ثبت درخواست</strong></div></li>
                    <li data-stage="current" aria-current="step"><span class="np-timeline__mark"><span class="np-timeline__num">۲</span></span><div><strong>عیب‌یابی فنی</strong></div></li>
                    <li data-stage="upcoming"><span class="np-timeline__mark"><span class="np-timeline__num">۳</span></span><div><strong>تحویل نهایی</strong></div></li>
                </ol>
            </div>
        </section>

        <section class="np-account-card" data-addresses-accordion>
            <article class="np-address-card">
                <button type="button" id="addr-btn-1" data-address-accordion-toggle="addr-panel-1" aria-expanded="false">ویرایش ۱</button>
                <div id="addr-panel-1" data-address-accordion-panel hidden><input value="تهران"></div>
            </article>
            <article class="np-address-card">
                <button type="button" id="addr-btn-2" data-address-accordion-toggle="addr-panel-2" aria-expanded="false">ویرایش ۲</button>
                <div id="addr-panel-2" data-address-accordion-panel hidden><input value="شیراز"></div>
            </article>
            <button type="button" id="addr-btn-new" data-address-accordion-toggle="addr-panel-new" aria-expanded="false">افزودن آدرس جدید</button>
            <div id="addr-panel-new" data-address-accordion-panel hidden><input value=""></div>
        </section>

        <section id="repair-quick-prefill">
            <button type="button" id="chip-me31" class="repair-saved-device-chip" data-prefill-device data-device-brand="Newland" data-device-model="ME31" data-device-serial="NL887766">Newland ME31</button>
            <input type="radio" name="device_brand" value="PAX">
            <input type="radio" name="device_brand" value="Newland">
            <div class="np-float-field" data-float-field>
                <input id="device_model" name="device_model" placeholder=" " value="">
                <label for="device_model">مدل دستگاه</label>
            </div>
            <div class="np-float-field" data-float-field>
                <input id="device_serial" name="device_serial" placeholder=" " value="">
                <label for="device_serial">سریال دستگاه</label>
            </div>
        </section>

        <section class="product-feedback">
            <button type="button" id="open-review-edit" data-toggle-review-edit="submit-review" aria-expanded="false">ویرایش دیدگاه</button>
            <form id="submit-review" class="product-feedback-form" hidden>
                <div class="np-float-field" data-float-field>
                    <textarea id="body-review" name="body">متن قبلی دیدگاه</textarea>
                    <label for="body-review">ویرایش دیدگاه شما</label>
                </div>
                <button type="button" id="cancel-review-edit" data-cancel-review-edit="submit-review">انصراف</button>
            </form>

            <form method="post" action="/helpful-vote" class="product-helpful-form" data-helpful-form id="helpful-form">
                <button type="submit" class="product-helpful-btn" data-helpful-btn id="helpful-btn" aria-pressed="false">
                    <span>مفید بود</span>
                    <span class="product-helpful-count" data-helpful-count id="helpful-count">۲</span>
                </button>
            </form>
        </section>
    </main>
    <script type="module" src="/resources/js/app.js"></script>
</body>
</html>`;

    const runnerHtml = `<!DOCTYPE html><meta charset="utf-8"><body><iframe id="vp" style="border:0;width:375px;height:812px"></iframe><script>
    (async () => {
        const frame = document.getElementById('vp');
        await new Promise((resolve, reject) => {
            frame.onload = () => setTimeout(resolve, 250);
            frame.onerror = reject;
            frame.src = '/fixture?t=' + Date.now();
        });
        const doc = frame.contentDocument;
        const win = frame.contentWindow;

        // 1. Mobile horizontal stepper stays horizontal (flex row) at 375px width
        const stepper = doc.getElementById('test-horizontal-stepper');
        if (win.getComputedStyle(stepper).display !== 'flex') {
            throw new Error('Expected horizontal stepper display=flex, got ' + win.getComputedStyle(stepper).display);
        }
        const items = [...stepper.querySelectorAll(':scope > li')];
        if (Math.abs(items[0].getBoundingClientRect().top - items[1].getBoundingClientRect().top) > 2) {
            throw new Error('Expected stepper items on same horizontal row on mobile');
        }

        // 2. Product page mobile header cart badge sits on outer corner with white ring, not covering icon center
        const btnRect = doc.getElementById('test-mobile-cart').getBoundingClientRect();
        const svgRect = doc.getElementById('test-cart-svg').getBoundingClientRect();
        const badge = doc.getElementById('test-cart-badge');
        const badgeRect = badge.getBoundingClientRect();
        const svgCenterX = svgRect.left + svgRect.width / 2;
        const svgCenterY = svgRect.top + svgRect.height / 2;
        const coversCenter = svgCenterX >= badgeRect.left && svgCenterX <= badgeRect.right && svgCenterY >= badgeRect.top && svgCenterY <= badgeRect.bottom;
        if (coversCenter) {
            throw new Error('Cart badge must not cover center of cart SVG');
        }
        if (!(badgeRect.top < btnRect.top)) {
            throw new Error('Cart badge should extend cleanly onto outer top corner, badgeTop=' + badgeRect.top + ' btnTop=' + btnRect.top);
        }
        if (!win.getComputedStyle(badge).boxShadow.includes('rgb(255, 255, 255)')) {
            throw new Error('Expected white outer ring box-shadow on cart badge, got ' + win.getComputedStyle(badge).boxShadow);
        }

        // 3. Addresses accordion never allows two forms open simultaneously
        doc.getElementById('addr-btn-1').click();
        if (doc.getElementById('addr-panel-1').hidden || !doc.getElementById('addr-panel-2').hidden) {
            throw new Error('Address 1 failed to open exclusively');
        }
        doc.getElementById('addr-btn-2').click();
        if (!doc.getElementById('addr-panel-1').hidden || doc.getElementById('addr-panel-2').hidden) {
            throw new Error('Opening address 2 must close address 1');
        }
        doc.getElementById('addr-btn-new').click();
        if (!doc.getElementById('addr-panel-2').hidden || doc.getElementById('addr-panel-new').hidden) {
            throw new Error('Opening new address form must close address 2');
        }

        // 4. Quick device prefill chip sets brand radio, model, serial, and floats labels
        doc.getElementById('chip-me31').click();
        const brandChecked = doc.querySelector('input[name="device_brand"][value="Newland"]').checked;
        const modelInput = doc.getElementById('device_model');
        const serialInput = doc.getElementById('device_serial');
        if (!brandChecked || modelInput.value !== 'ME31' || serialInput.value !== 'NL887766' || !modelInput.closest('[data-float-field]').classList.contains('is-floated')) {
            throw new Error('Quick device prefill failed: ' + JSON.stringify({ brandChecked, model: modelInput.value, serial: serialInput.value }));
        }

        // 5. Review edit toggle & AJAX helpful vote toggle
        if (!doc.getElementById('submit-review').hidden) {
            throw new Error('Expected #submit-review hidden initially');
        }
        doc.getElementById('open-review-edit').click();
        if (doc.getElementById('submit-review').hidden) {
            throw new Error('Expected #submit-review visible after clicking edit');
        }
        doc.getElementById('cancel-review-edit').click();
        if (!doc.getElementById('submit-review').hidden) {
            throw new Error('Expected #submit-review hidden after cancel');
        }

        doc.getElementById('helpful-btn').click();
        const start = Date.now();
        while (doc.getElementById('helpful-btn').getAttribute('aria-pressed') !== 'true' && Date.now() - start < 2000) {
            await new Promise(r => setTimeout(r, 30));
        }
        if (doc.getElementById('helpful-btn').getAttribute('aria-pressed') !== 'true' || doc.getElementById('helpful-count').textContent !== '۳') {
            throw new Error('Helpful vote toggle failed: count=' + doc.getElementById('helpful-count').textContent);
        }

        document.body.textContent = 'PHASE9_UX_PASS';
    })().catch(err => {
        document.body.textContent = 'PHASE9_UX_FAIL: ' + (err?.stack || err?.message || err);
    });
    </script></body>`;

    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-phase9-ux-'));
    const mimeTypes = { '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8' };
    const server = http.createServer((req, res) => {
        const url = new URL(req.url || '/', 'http://127.0.0.1');
        if (req.method === 'POST' && url.pathname === '/helpful-vote') {
            helpfulVoteState = !helpfulVoteState;
            helpfulCount += helpfulVoteState ? 1 : -1;
            const fa = String(helpfulCount).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
            res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
            res.end(JSON.stringify({ ok: true, voted: helpfulVoteState, helpful_count: helpfulCount, helpful_count_fa: fa }));
            return;
        }
        if (url.pathname === '/runner') {
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            res.end(runnerHtml);
            return;
        }
        if (url.pathname.startsWith('/resources/js/')) {
            let safeRel = url.pathname.replace(/^\/+/, '');
            if (!extname(safeRel)) {
                safeRel += '.js';
            }
            const safePath = join(rootDir, safeRel);
            const content = readFileSync(safePath);
            res.writeHead(200, { 'Content-Type': mimeTypes[extname(safePath)] || 'text/javascript; charset=utf-8' });
            res.end(content);
            return;
        }
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(htmlFixture);
    });

    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const { port } = server.address();

    try {
        const result = await runChrome(executable, [
            '--headless=new', '--disable-gpu', '--disable-background-networking',
            '--window-size=1280,960', '--no-first-run', '--user-data-dir=' + path.join(directory, 'profile'),
            '--virtual-time-budget=6000', '--dump-dom', `http://127.0.0.1:${port}/runner`,
        ]);

        assert.equal(result.status, 0, result.stderr.slice(-500));
        assert.ok(/PHASE9_UX_PASS/.test(result.stdout), result.stdout.slice(-900));
    } finally {
        await new Promise((resolve) => server.close(resolve));
        rmSync(directory, { recursive: true, force: true, maxRetries: 3, retryDelay: 100 });
    }
});
