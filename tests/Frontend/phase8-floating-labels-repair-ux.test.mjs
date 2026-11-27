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

const readRepo = rel => readFileSync(new URL('../../' + rel, import.meta.url), 'utf8');

const loadStorefrontBundle = () => {
    const manifestPath = new URL('../../public/build/manifest.json', import.meta.url);
    let compiledCss = '';
    let compiledJs = '';
    if (existsSync(manifestPath)) {
        const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
        const cssFile = manifest['resources/css/app.css']?.file;
        const jsFile = manifest['resources/js/app.js']?.file;
        if (cssFile && existsSync(new URL('../../public/build/' + cssFile, import.meta.url))) {
            compiledCss = readRepo('public/build/' + cssFile);
        }
        if (jsFile && existsSync(new URL('../../public/build/' + jsFile, import.meta.url))) {
            compiledJs = readRepo('public/build/' + jsFile);
        }
    }
    if (!compiledCss) {
        compiledCss = [
            readRepo('resources/css/home.css'),
            readRepo('resources/css/public.css'),
            readRepo('resources/css/customer-account.css'),
            readRepo('resources/css/app.css').replace(/^@(?:import|source)\b[^\r\n]*;/gm, ''),
        ].join('\n');
    }
    const css = [
        compiledCss,
        readRepo('public/css/store-navigation.css'),
        readRepo('public/css/storefront-refinement.css'),
        readRepo('resources/support-chat/support-client/launcher.css'),
    ].join('\n').replace(/@font-face\s*\{[^}]*\}/g, '');

    return {css, compiledJs};
};

test('Chromium: Phase 8 RTL floating labels, mobile menu & nav renaming, Stage 4 stacked review, non-focusing checkbox toggle, and recovery/footer geometry', async t => {
    // 1. Static source verification of mobile menu & navigation renaming
    const headerBlade = readRepo('resources/views/components/home/header.blade.php');
    const footerBlade = readRepo('resources/views/components/home/footer.blade.php');
    const recoveryBlade = readRepo('resources/views/pages/repair-recovery.blade.php');

    assert.ok(headerBlade.includes("route('consultation')") && headerBlade.includes('<span>درخواست مشاوره</span>'), 'Mobile hamburger menu must include درخواست مشاوره');
    assert.ok(headerBlade.includes('<span>درخواست تعمیر</span>') && headerBlade.includes('<span>پیگیری تعمیر</span>'), 'Header and mobile menu must use درخواست تعمیر and پیگیری تعمیر');
    assert.ok(footerBlade.includes('>درخواست تعمیر</a>') && footerBlade.includes('>پیگیری تعمیر</a>'), 'Footer must use درخواست تعمیر and پیگیری تعمیر');
    assert.ok(recoveryBlade.includes('شماره موبایل ثبت‌شده را وارد کنید تا کد تأیید پیامک شود.'), 'Recovery page must use shortened helper text inside the card');

    const executable = findChrome();
    if (!executable) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-phase8-ux-'));
    let server = null;
    try {
        const {css, compiledJs} = loadStorefrontBundle();
        assert.ok(compiledJs.length > 0, 'Compiled resources/js/app.js bundle must exist (run npm run build first)');

        const fixtureHtml = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <style>${css}</style>
        </head>
        <body class="public-site flex min-h-screen flex-col">
            <main id="main-content" class="flex-1">
                <div class="np-container public-section repair-track-page">
                    <div class="repair-track-shell">
                        <section class="public-panel repair-recovery-panel" id="recovery-card">
                            <header class="store-page-intro repair-recovery-intro">
                                <h1 id="recovery-title">بازیابی کد پیگیری تعمیر</h1>
                                <p id="recovery-helper">شماره موبایل ثبت‌شده را وارد کنید تا کد تأیید پیامک شود.</p>
                            </header>
                            <form class="public-form">
                                <div class="public-field">
                                    <div class="np-float-field" id="float-empty" data-float-field>
                                        <input class="input" id="empty-name" name="customer_name" placeholder="مثلاً علی رضایی">
                                        <label id="label-empty" for="empty-name">نام و نام خانوادگی</label>
                                    </div>
                                </div>
                                <div class="public-field">
                                    <div class="np-float-field" id="float-phone" data-float-field>
                                        <input class="input" id="prefilled-phone" name="contact_phone" type="tel" dir="ltr" inputmode="numeric" value="۰۹۱۲۳۴۵۶۷۸۹" placeholder="۰۹۱۲۳۴۵۶۷۸۹" aria-invalid="true">
                                        <label id="label-phone" for="prefilled-phone">شماره موبایل</label>
                                    </div>
                                    <p class="public-field-error" id="phone-error" role="alert">شماره موبایل نامعتبر است.</p>
                                </div>
                                <div class="public-field">
                                    <div class="np-float-field" id="float-select" data-float-field>
                                        <select class="input" id="topic-select" name="topic">
                                            <option value="">موضوع را انتخاب کنید</option>
                                            <option value="purchase">مشاوره خرید</option>
                                        </select>
                                        <label id="label-select" for="topic-select">موضوع مشاوره</label>
                                    </div>
                                </div>
                                <div class="public-field">
                                    <div class="np-float-field" id="float-textarea" data-float-field>
                                        <textarea class="input" id="issue-textarea" rows="3" placeholder="شرح مشکل">صفحه نمایش خطوط عمودی دارد</textarea>
                                        <label id="label-textarea" for="issue-textarea">شرح مشکل دستگاه</label>
                                    </div>
                                </div>
                                <div class="repair-entry-actions repair-track-actions repair-recovery-actions">
                                    <button id="recovery-cta" type="button" class="np-button np-button--primary repair-recovery-submit">دریافت کد تأیید</button>
                                </div>
                            </form>
                        </section>
                    </div>
                </div>

                <div class="np-container public-section">
                    <form class="public-form repair-stepper" data-repair-stepper data-initial-step="3" data-is-guest="0">
                        <div class="repair-step" data-step="3">
                            <div class="repair-courier-alt">
                                <label class="repair-choice-card repair-choice-card--accessory repair-courier-alt__toggle" for="other_person_handover">
                                    <input type="checkbox" id="other_person_handover" name="other_person_handover" value="1" data-other-person-toggle>
                                    <span class="repair-choice-card__title">فرد دیگری دستگاه را به پیک تحویل می‌دهد</span>
                                </label>
                                <div class="public-field repair-courier-alt__field" data-other-person-field hidden>
                                    <div class="np-float-field" data-float-field>
                                        <input id="responsive_phone" name="responsive_phone" type="tel" class="input" dir="ltr" inputmode="numeric" placeholder="۰۹۱۲۳۴۵۶۷۸۹">
                                        <label for="responsive_phone">شماره تماس تحویل‌دهنده</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="repair-step" data-step="4">
                            <div class="repair-summary-box" id="stage4-summary" data-repair-summary>
                                <div class="repair-summary-box__item" id="summary-row-device">
                                    <span>دستگاه</span>
                                    <strong class="np-latin-mixed">PAX — S910 — سریال SN998877665544</strong>
                                </div>
                                <div class="repair-summary-box__item" id="summary-row-issue">
                                    <span>شرح مشکل</span>
                                    <strong>دستگاه هنگام چاپ رسیدهای طولانی ریستارت می‌شود و باتری خالی می‌کند</strong>
                                </div>
                            </div>
                        </div>
                        <script type="application/json" id="repair-courier-zones">{}</script>
                    </form>
                </div>
            </main>

            <footer class="np-footer" id="site-footer">
                <div class="np-container">
                    <div class="np-footer__grid">
                        <div class="np-footer__brand">
                            <div class="np-footer__identity"><a href="/" class="np-logo-link">نوین‌پی</a></div>
                            <div class="np-footer__marks">
                                <div class="np-footer__badge">۱</div>
                                <div class="np-footer__badge">۲</div>
                                <div class="np-footer__badge" id="third-trust-badge">۳</div>
                            </div>
                        </div>
                        <div class="np-footer__links-col">
                            <h2>دسترسی سریع</h2>
                            <nav class="np-footer__links">
                                <a href="/products" class="footer-nav-link">فروشگاه</a>
                                <a href="/repair" class="footer-nav-link">درخواست تعمیر</a>
                                <a href="/repair/track" class="footer-nav-link">پیگیری تعمیر</a>
                                <a href="/contact" class="footer-nav-link">تماس با ما</a>
                            </nav>
                        </div>
                    </div>
                    <div class="np-footer__bottom"><p>© نوین‌پی</p></div>
                </div>
            </footer>
            <nav class="global-store-nav" id="bottom-nav" data-mobile-bottom-nav>
                <a href="/"><span>خانه</span></a>
                <a href="/products"><span>فروشگاه</span></a>
                <a href="/cart"><span>سبد خرید</span></a>
                <a href="/repair" aria-current="location"><span>تعمیرات</span></a>
                <a href="/account"><span>حساب کاربری</span></a>
            </nav>
            <button type="button" id="chat-launcher" data-support-launcher aria-label="پشتیبانی"><svg viewBox="0 0 24 24"></svg></button>

            <script type="module">${compiledJs}</script>
            <script>
            window.addEventListener('load', async () => {
                await new Promise(r => setTimeout(r, 220));

                // Measure floating labels
                const emptyInp = document.getElementById('empty-name').getBoundingClientRect();
                const emptyLbl = document.getElementById('label-empty').getBoundingClientRect();
                const phoneInp = document.getElementById('prefilled-phone').getBoundingClientRect();
                const phoneLbl = document.getElementById('label-phone').getBoundingClientRect();
                const phoneErr = document.getElementById('phone-error').getBoundingClientRect();
                const selectEl = document.getElementById('topic-select').getBoundingClientRect();
                const selectLbl = document.getElementById('label-select').getBoundingClientRect();
                const taEl = document.getElementById('issue-textarea').getBoundingClientRect();
                const taLbl = document.getElementById('label-textarea').getBoundingClientRect();

                // Measure recovery card + full-width CTA
                const cardEl = document.getElementById('recovery-card');
                const headingInsideCard = cardEl.contains(document.getElementById('recovery-title'));
                const helperInsideCard = cardEl.contains(document.getElementById('recovery-helper'));
                const ctaRect = document.getElementById('recovery-cta').getBoundingClientRect();

                // Toggle Stage 3 "other person" checkbox on mobile (390px) -> must reveal without focusing input
                const cb = document.getElementById('other_person_handover');
                const altField = document.querySelector('[data-other-person-field]');
                const altInput = document.getElementById('responsive_phone');
                cb.click();
                await new Promise(r => setTimeout(r, 40));
                const altRevealed = !altField.hidden;
                const altFocused = document.activeElement === altInput;

                // Reveal Stage 4 summary box to measure mobile stacked right-aligned layout
                document.querySelector('[data-step="4"]').hidden = false;
                await new Promise(r => setTimeout(r, 30));
                const row = document.getElementById('summary-row-issue');
                const rowStyle = getComputedStyle(row);
                const rowSpan = row.querySelector('span').getBoundingClientRect();
                const rowStrong = row.querySelector('strong').getBoundingClientRect();

                // Scroll to absolute bottom of mobile viewport and verify footer links & 3rd trust badge never collide with bottom nav or chat button
                window.scrollTo(0, document.documentElement.scrollHeight);
                await new Promise(r => setTimeout(r, 60));
                const navRect = document.getElementById('bottom-nav').getBoundingClientRect();
                const chatRect = document.getElementById('chat-launcher').getBoundingClientRect();
                const badge3Rect = document.getElementById('third-trust-badge').getBoundingClientRect();
                const footerLinks = [...document.querySelectorAll('.footer-nav-link')].map(a => a.getBoundingClientRect());

                const intersects = (a, b) => !(a.right <= b.left || a.left >= b.right || a.bottom <= b.top || a.top >= b.bottom);
                const anyFooterLinkClippedByNav = footerLinks.some(r => intersects(r, navRect));
                const chatOverlapsThirdBadge = intersects(chatRect, badge3Rect);

                document.body.setAttribute('data-probe', JSON.stringify({
                    emptyCenteredDelta: Math.abs((emptyLbl.top + emptyLbl.height / 2) - (emptyInp.top + emptyInp.height / 2)),
                    emptyRightOffset: emptyInp.right - emptyLbl.right,
                    phoneFloatedTopDelta: Math.abs((phoneLbl.top + phoneLbl.height / 2) - phoneInp.top),
                    phoneRightOffset: phoneInp.right - phoneLbl.right,
                    errorBelowInput: phoneErr.top >= phoneInp.bottom - 1,
                    selectFloatedTopDelta: Math.abs((selectLbl.top + selectLbl.height / 2) - selectEl.top),
                    textareaFloatedTopDelta: Math.abs((taLbl.top + taLbl.height / 2) - taEl.top),
                    headingInsideCard,
                    helperInsideCard,
                    ctaWidthDiff: Math.abs(ctaRect.width - phoneInp.width),
                    altRevealed,
                    altFocused,
                    stage4FlexDir: rowStyle.flexDirection,
                    stage4StackedOrder: rowSpan.bottom <= rowStrong.top + 2,
                    stage4RightAlignDiff: Math.abs(rowSpan.right - rowStrong.right),
                    anyFooterLinkClippedByNav,
                    chatOverlapsThirdBadge,
                }));
            });
            </script>
        </body></html>`;

        server = http.createServer((req, res) => {
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
            res.end(fixtureHtml);
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
            `--user-data-dir=${path.join(directory, 'p-390')}`,
            '--window-size=390,844',
            '--virtual-time-budget=3000',
            '--dump-dom',
            origin + '/',
        ]);
        assert.equal(res.status, 0, `Chromium failed: ${res.stderr}`);
        const match = res.stdout.match(/data-probe="([^"]+)"/);
        assert.ok(match, 'Probe missing from DOM');
        const p = JSON.parse(match[1].replace(/&quot;/g, '"'));

        assert.ok(p.emptyCenteredDelta <= 5, `Empty floating label must rest centered inside input, delta=${p.emptyCenteredDelta}`);
        assert.ok(p.emptyRightOffset >= 6 && p.emptyRightOffset <= 24, `RTL resting label must align to right, offset=${p.emptyRightOffset}`);
        assert.ok(p.phoneFloatedTopDelta <= 5, `Pre-filled phone floating label must sit on top border, delta=${p.phoneFloatedTopDelta}`);
        assert.ok(p.phoneRightOffset >= 6 && p.phoneRightOffset <= 24, `LTR numeric phone field must keep RTL label right-aligned, offset=${p.phoneRightOffset}`);
        assert.equal(p.errorBelowInput, true, 'Validation error must render cleanly below input without overlapping');
        assert.ok(p.selectFloatedTopDelta <= 5, `Select floating label must sit on top border without overlapping option text, delta=${p.selectFloatedTopDelta}`);
        assert.ok(p.textareaFloatedTopDelta <= 5, `Filled textarea floating label must sit on top border, delta=${p.textareaFloatedTopDelta}`);

        assert.equal(p.headingInsideCard, true, 'Recovery heading must be inside white card');
        assert.equal(p.helperInsideCard, true, 'Recovery helper text must be inside white card');
        assert.ok(p.ctaWidthDiff <= 2, `Recovery CTA button must be full-width matching input, diff=${p.ctaWidthDiff}`);

        assert.equal(p.altRevealed, true, 'Toggling other-person checkbox must reveal alternate phone field');
        assert.equal(p.altFocused, false, 'Toggling other-person checkbox on mobile must NOT auto-focus input');

        assert.equal(p.stage4FlexDir, 'column', 'Stage 4 summary rows must stack vertically on mobile');
        assert.equal(p.stage4StackedOrder, true, 'Stage 4 summary label must sit above value on mobile');
        assert.ok(p.stage4RightAlignDiff <= 2, `Stage 4 label and value must share right-aligned edge, diff=${p.stage4RightAlignDiff}`);

        assert.equal(p.anyFooterLinkClippedByNav, false, 'Bottom navigation bar must not clip footer links at full scroll');
        assert.equal(p.chatOverlapsThirdBadge, false, 'Floating support chat button must never overlap 3rd trust badge');
    } finally {
        if (server) await new Promise(r => server.close(() => r()));
        try { rmSync(directory, {recursive: true, force: true}); } catch {}
    }
});
