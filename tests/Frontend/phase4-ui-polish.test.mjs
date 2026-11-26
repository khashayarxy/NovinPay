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
        read('resources/support-chat/support-client/launcher.css'),
        read('public/css/storefront-premium.css'),
        read('public/css/store-navigation.css'),
        read('public/css/storefront-refinement.css'),
    ].join('\n').replace(/@font-face\s*\{[^}]*\}/g, '');
};

const loadCompiledAppJs = () => {
    const manifestPath = new URL('../../public/build/manifest.json', import.meta.url);
    if (existsSync(manifestPath)) {
        const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
        const appJsFile = manifest['resources/js/app.js']?.file;
        if (appJsFile && existsSync(new URL('../../public/build/' + appJsFile, import.meta.url))) {
            return readFileSync(new URL('../../public/build/' + appJsFile, import.meta.url), 'utf8');
        }
    }
    return '';
};

const startFixtureServer = async routes => {
    const server = http.createServer((req, res) => {
        const pathname = new URL(req.url || '/', 'http://127.0.0.1').pathname;
        const entry = routes[pathname];
        if (!entry) {
            res.writeHead(404, {'Content-Type': 'text/plain; charset=utf-8'});
            res.end('Not found');
            return;
        }
        res.writeHead(200, {'Content-Type': entry.type || 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
        res.end(entry.body);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const {port} = server.address();
    return {
        origin: `http://127.0.0.1:${port}`,
        close: () => new Promise(resolve => server.close(resolve)),
    };
};

test('Chromium: Phase 4 consultation split-card, unified red cart badge, 48x48 mobile chat launcher, POS serial uppercase, and submit spinner', async t => {
    const executable = findChrome();
    if (!executable) {
        t.skip('Chromium/Edge executable not available');
        return;
    }

    const appJs = loadCompiledAppJs();
    assert.ok(appJs.length > 0, 'Expected compiled public/build app.js from npm run build');
    const css = loadCssBundle();

    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-phase4-smoke-'));
    let server = null;

    try {
        const consultationHtml = `<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head>
        <body class="public-site flex min-h-screen flex-col">
            <header class="np-header" data-mobile-header><div class="np-container np-header__inner"><a href="/" class="np-logo-link">نوین‌پی</a></div></header>
            <dialog id="mobile-drawer" class="np-mobile-drawer" open><div class="np-mobile-drawer__panel"><div class="np-mobile-drawer__footer">
                <a class="np-mobile-drawer__account np-mobile-drawer__cart" href="/cart"><span>سبد خرید</span><span class="store-cart-count" data-store-cart-count>۲</span></a>
            </div></div></dialog>
            <main id="main-content" class="flex-1">
                <div class="repair-entry-bg">
                    <div class="np-container public-section repair-entry consultation-entry">
                        <div class="repair-entry-shell">
                            <div class="repair-split-card consultation-split-card">
                                <section class="repair-split-form consultation-split-form">
                                    <header class="consultation-card-head">
                                        <span class="consultation-card-head__badge">مشاورهٔ تخصصی نوین‌پی</span>
                                        <h1 id="consultation-form-title">درخواست مشاوره خرید و خدمات</h1>
                                        <p>پرسش خود را دربارهٔ انتخاب کارتخوان بنویسید.</p>
                                    </header>
                                    <form id="consult-form" class="public-form" method="post" action="/consultation-sink" data-public-submit-loading>
                                        <div class="public-form__grid">
                                            <div><label class="label" for="cname">نام و نام خانوادگی</label><input class="input" id="cname" name="customer_name" value="علی محمدی" required></div>
                                            <div><label class="label" for="cphone">شماره موبایل</label><input class="input" id="cphone" name="contact_phone" type="tel" inputmode="numeric" autocomplete="tel" value="09123456789" required></div>
                                        </div>
                                        <div><label class="label" for="dserial">شماره سریال</label><input class="input pos-serial-input" id="dserial" name="device_serial" dir="ltr" autocapitalize="characters" spellcheck="false" data-pos-serial placeholder="کد S/N"></div>
                                        <div class="consultation-form-actions">
                                            <button id="consult-submit" class="np-button np-button--primary" type="submit" data-loading-label="در حال ارسال...">ثبت درخواست مشاوره</button>
                                        </div>
                                    </form>
                                </section>
                                <aside class="repair-split-trust consultation-split-trust">
                                    <div class="repair-split-trust__top">
                                        <span class="repair-trust-card__eyebrow">همراهی پیش از تصمیم</span>
                                        <h2>راهنمایی دقیق متناسب با کسب‌وکار شما</h2>
                                    </div>
                                    <div class="repair-split-trust__footer">
                                        <span>تماس مستقیم با مشاوران نوین‌پی:</span>
                                        <a href="tel:09362896373" dir="ltr">۰۹۳۶۲۸۹۶۳۷۳</a>
                                    </div>
                                </aside>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <nav class="global-store-nav" data-mobile-bottom-nav><a href="/cart"><span>سبد خرید</span><span class="store-cart-count" data-store-cart-count>۲</span></a></nav>
            <button type="button" data-support-launcher aria-label="پشتیبانی"><svg viewBox="0 0 24 24"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5H3l2-6a8.5 8.5 0 1 1 16-2.5z"/></svg></button>
            <script type="module" src="/app.js"></script>
        </body></html>`;

        const routes = {
            '/app.js': {type: 'application/javascript; charset=utf-8', body: appJs},
            '/consultation': {body: consultationHtml},
            '/runner': {
                body: `<!DOCTYPE html><meta charset="utf-8"><body><iframe id="vp" style="border:0"></iframe><script>
                (async () => {
                    const frame = document.getElementById('vp');
                    const loadAt = (w, h) => new Promise((resolve, reject) => {
                        frame.style.width = w + 'px';
                        frame.style.height = h + 'px';
                        frame.onload = () => setTimeout(resolve, 180);
                        frame.onerror = reject;
                        frame.src = '/consultation?t=' + Date.now() + '_' + w;
                    });

                    // 1. Mobile 390x844 checks
                    await loadAt(390, 844);
                    {
                        const doc = frame.contentDocument;
                        const win = frame.contentWindow;
                        if (doc.documentElement.scrollWidth > doc.documentElement.clientWidth + 1) {
                            throw new Error('Horizontal overflow on /consultation mobile: ' + doc.documentElement.scrollWidth);
                        }

                        // Cart badge color parity between hamburger drawer and bottom nav
                        const drawerBadge = doc.querySelector('.np-mobile-drawer__cart .store-cart-count');
                        const bottomBadge = doc.querySelector('.global-store-nav .store-cart-count');
                        const drawerBg = win.getComputedStyle(drawerBadge).backgroundColor;
                        const bottomBg = win.getComputedStyle(bottomBadge).backgroundColor;
                        if (drawerBg !== bottomBg || drawerBg !== 'rgb(180, 35, 45)') {
                            throw new Error('Cart badge color mismatch: drawer=' + drawerBg + ' bottom=' + bottomBg);
                        }
                        doc.getElementById('mobile-drawer').removeAttribute('open');

                        // Consultation form visible above the fold on mobile
                        const formRect = doc.getElementById('consult-form').getBoundingClientRect();
                        if (formRect.top > 260) {
                            throw new Error('Consultation form should be above the fold on mobile, got top=' + formRect.top);
                        }

                        // Mobile floating support launcher is 48x48 and sits cleanly above bottom nav
                        const launcher = doc.querySelector('button[data-support-launcher]');
                        const navRect = doc.querySelector('.global-store-nav').getBoundingClientRect();
                        const lRect = launcher.getBoundingClientRect();
                        if (Math.round(lRect.width) !== 48 || Math.round(lRect.height) !== 48) {
                            throw new Error('Expected 48x48 mobile chat launcher, got ' + lRect.width + 'x' + lRect.height);
                        }
                        if (lRect.bottom > navRect.top) {
                            throw new Error('Launcher overlaps bottom nav: launcherBottom=' + lRect.bottom + ', navTop=' + navRect.top);
                        }

                        // Focusing an input hides launcher on mobile so it never blocks form typing
                        const serial = doc.getElementById('dserial');
                        serial.focus();
                        if (win.getComputedStyle(launcher).visibility !== 'hidden') {
                            throw new Error('Expected support launcher hidden while typing in mobile input');
                        }

                        // POS serial auto-uppercase + Persian/Arabic digit normalization
                        serial.value = 'pax-s910-۹۸۱۲۳ab';
                        serial.dispatchEvent(new win.Event('input', {bubbles: true}));
                        if (serial.value !== 'PAX-S910-98123AB') {
                            throw new Error('Expected POS serial auto-uppercase PAX-S910-98123AB, got ' + serial.value);
                        }
                        serial.blur();

                        // Submit loading state + pageshow (bfcache) reset
                        const form = doc.getElementById('consult-form');
                        const btn = doc.getElementById('consult-submit');
                        form.addEventListener('submit', e => {
                            // Keep page in-place after bubble submit handler runs by preventing navigation on window unload?
                            // Instead, intercept right after bubble listener by setting action to javascript:void(0)
                        });
                        form.action = 'javascript:void(0)';
                        btn.click();
                        await new Promise(r => setTimeout(r, 30));
                        if (!btn.disabled || btn.dataset.submitting !== '1' || !btn.querySelector('.np-btn-spinner') || !btn.textContent.includes('در حال ارسال...')) {
                            throw new Error('Expected submit button disabled with spinner & در حال ارسال..., got html=' + btn.innerHTML);
                        }
                        win.dispatchEvent(new win.PageTransitionEvent('pageshow', {persisted: true}));
                        if (btn.disabled || btn.dataset.submitting || !btn.textContent.includes('ثبت درخواست مشاوره')) {
                            throw new Error('Expected submit button reset on pageshow bfcache restore');
                        }
                    }

                    // 2. Desktop 1440x900 split-card 2-column check
                    await loadAt(1440, 900);
                    {
                        const doc = frame.contentDocument;
                        doc.getElementById('mobile-drawer').removeAttribute('open');
                        const formPane = doc.querySelector('.consultation-split-form').getBoundingClientRect();
                        const trustPane = doc.querySelector('.consultation-split-trust').getBoundingClientRect();
                        if (Math.abs(formPane.top - trustPane.top) > 4 || trustPane.left >= formPane.left) {
                            throw new Error('Expected RTL 2-column split card on desktop (form right, trust left)');
                        }
                    }

                    document.body.textContent = 'PHASE4_UI_POLISH_PASS';
                })().catch(err => {
                    document.body.textContent = 'PHASE4_UI_POLISH_FAIL: ' + (err?.stack || err?.message || err);
                });
                </script></body>`,
            },
        };

        server = await startFixtureServer(routes);
        const result = await runChrome(executable, [
            '--headless=new', '--disable-gpu', '--disable-background-networking',
            '--window-size=1600,1200', '--no-first-run', '--user-data-dir=' + path.join(directory, 'profile'),
            '--virtual-time-budget=6000', '--dump-dom', server.origin + '/runner',
        ]);

        assert.equal(result.status, 0, result.stderr.slice(-500));
        assert.ok(/PHASE4_UI_POLISH_PASS/.test(result.stdout), result.stdout.slice(-900));
    } finally {
        await server?.close();
        if (realpathSync(path.dirname(directory)) !== realpathSync(tmpdir()) || !path.basename(directory).startsWith('novinpay-phase4-smoke-')) {
            throw new Error('Unexpected cleanup path');
        }
        rmSync(directory, {recursive: true, force: true, maxRetries: 3, retryDelay: 100});
    }
});
