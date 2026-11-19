import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {pathToFileURL} from 'node:url';

test('Chromium: modal drawer locks scroll, contains focus and reconciles account navigation', t => {
    const executable = (process.env.CHROME_BIN ? [process.env.CHROME_BIN] : process.platform === 'win32'
        ? ['C:/Program Files/Google/Chrome/Application/chrome.exe'] : ['/usr/bin/google-chrome', '/usr/bin/chromium']).find(existsSync);
    if (!executable) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-drawer-'));
    try {
        const source = readFileSync(new URL('../../resources/js/mobile-navigation.js', import.meta.url)).toString('base64');
        const fixture = path.join(directory, 'fixture.html');
        writeFileSync(fixture, `<!doctype html><html dir="rtl"><meta charset="utf-8"><body style="min-height:3000px">
        <header data-mobile-header><button data-navigation-toggle aria-expanded="false">Open</button>
        <dialog class="np-mobile-drawer"><div><button data-navigation-close autofocus>Close</button><a href="#target">Account</a></div></dialog></header>
        <button id="background">Background</button><script type="module">
        try {
            const {initMobileNavigation} = await import('data:text/javascript;base64,${source}');
            const check = (value, message) => { if (!value) throw Error(message); };
            const wait = () => new Promise(resolve => setTimeout(resolve, 220));
            const media = {matches: true, addEventListener(type, callback) { this.change = callback; }};
            const reduced = {matches: false};
            window.matchMedia = query => query.includes('reduced-motion') ? reduced : media;
            const header = document.querySelector('header'), dialog = document.querySelector('dialog'), toggle = document.querySelector('[data-navigation-toggle]');
            initMobileNavigation(); initMobileNavigation();
            window.scrollTo(0,300); const before = window.scrollY;
            let opened = 0; header.addEventListener('mobile-navigation:open', () => opened++);
            toggle.click();
            check(dialog.open && opened === 1 && toggle.getAttribute('aria-expanded') === 'true', 'one modal open');
            check(document.body.style.position === 'fixed' && document.documentElement.style.overflow === 'hidden', 'background locked');
            check(document.activeElement.hasAttribute('data-navigation-close'), 'initial close focus');
            dialog.querySelector('[data-navigation-close]').dispatchEvent(new KeyboardEvent('keydown',{key:'Tab',shiftKey:true,bubbles:true,cancelable:true}));
            check(document.activeElement === dialog.querySelector('a'), 'reverse Tab wraps inside modal');
            document.activeElement.dispatchEvent(new KeyboardEvent('keydown',{key:'Tab',bubbles:true,cancelable:true}));
            check(document.activeElement.hasAttribute('data-navigation-close'), 'Tab wraps to close');
            document.querySelector('#background').focus();
            check(dialog.contains(document.activeElement), 'modal background inert');
            dialog.dispatchEvent(new Event('cancel', {cancelable:true})); await wait();
            check(!dialog.open && document.activeElement === toggle, 'Escape closes and restores focus');
            check(!document.body.style.position && !document.documentElement.style.overflow && window.scrollY === before, 'styles and scroll restored');
            toggle.click(); dialog.dispatchEvent(new PointerEvent('pointerdown', {bubbles:true})); dialog.click(); await wait();
            check(!dialog.open, 'backdrop closes');
            toggle.click(); dialog.querySelector('[data-navigation-close]').click(); await wait();
            check(!dialog.open, 'close button');
            toggle.click(); header.dispatchEvent(new Event('customer-navigation:open'));
            check(!dialog.open && !document.body.style.position, 'account state cannot conflict');
            reduced.matches = true; toggle.click(); dialog.querySelector('[data-navigation-close]').click();
            check(!dialog.open && !dialog.classList.contains('is-closing'), 'reduced motion closes immediately');
            reduced.matches = false; toggle.click(); dialog.querySelector('[data-navigation-close]').click(); window.dispatchEvent(new Event('pagehide'));
            check(!dialog.open && !document.body.style.position, 'pagehide clears in-flight animation lock');
            toggle.click(); media.matches = false; media.change();
            check(!dialog.open && !document.body.style.position, 'desktop resize releases modal');
            document.body.textContent = 'MOBILE_DRAWER_BROWSER_PASS';
        } catch (error) {document.body.textContent = 'MOBILE_DRAWER_BROWSER_FAIL: '+error.stack;}
        </script></html>`);
        const result = spawnSync(executable, ['--headless=new', '--disable-gpu', '--disable-background-networking', '--no-first-run',
            '--user-data-dir='+path.join(directory,'profile'), '--virtual-time-budget=4000', '--dump-dom', pathToFileURL(fixture).href],
        {encoding:'utf8',timeout:20000,maxBuffer:1024*1024,windowsHide:true});
        assert.ifError(result.error); assert.equal(result.status,0);
        assert.match(result.stdout,/MOBILE_DRAWER_BROWSER_PASS/,result.stdout.slice(-2500));
    } finally {
        if (realpathSync(path.dirname(directory)) !== realpathSync(tmpdir()) || !path.basename(directory).startsWith('novinpay-drawer-')) throw Error('Unexpected cleanup target');
        rmSync(directory,{recursive:true,force:true,maxRetries:3,retryDelay:100});
    }
});

test('Chromium: compact product section links stay below the header while scrolling', t => {
    const executable = (process.env.CHROME_BIN ? [process.env.CHROME_BIN] : process.platform === 'win32'
        ? ['C:/Program Files/Google/Chrome/Application/chrome.exe'] : ['/usr/bin/google-chrome', '/usr/bin/chromium']).find(existsSync);
    if (!executable) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-product-tabs-'));
    try {
        const css = readFileSync(new URL('../../public/css/storefront-premium.css', import.meta.url), 'utf8');
        const fixture = path.join(directory, 'fixture.html');
        writeFileSync(fixture, `<!doctype html><html dir="rtl"><meta charset="utf-8"><style>${css}</style>
        <body class="premium-storefront"><header class="np-header" style="height:65px">Header</header>
        <div style="height:900px"></div><main class="product-layout"><div class="reading">
        <nav class="section-nav card" data-product-sections><a href="#intro">Overview</a><a href="#specs">Specifications</a></nav>
        <section id="intro" class="article" style="height:580px">Overview</section>
        <section id="specs" class="technical" style="height:580px">Specifications</section>
        </div></main><div style="height:700px"></div>
        <script>
        const tabs = document.querySelector('[data-product-sections]');
        const header = document.querySelector('.np-header');
        document.documentElement.style.setProperty('--product-header-height', header.offsetHeight + 'px');
        document.documentElement.style.setProperty('--product-tabs-height', tabs.offsetHeight + 'px');
        for (const top of [1020, 1540, 1100]) {
            scrollTo({top, behavior:'instant'});
            if (Math.abs(tabs.getBoundingClientRect().top - header.getBoundingClientRect().bottom - 8) > 1) {
                throw new Error('Section navigation did not follow scrolling below the header');
            }
        }
        document.body.textContent = 'MOBILE_PRODUCT_TABS_BROWSER_PASS';
        </script></html>`);
        const result = spawnSync(executable, ['--headless=new', '--disable-gpu', '--disable-background-networking', '--no-first-run',
            '--window-size=500,700', '--user-data-dir='+path.join(directory,'profile'), '--dump-dom', pathToFileURL(fixture).href],
        {encoding:'utf8',timeout:20000,maxBuffer:1024*1024,windowsHide:true});
        assert.ifError(result.error); assert.equal(result.status, 0);
        assert.match(result.stdout, /<body[^>]*>MOBILE_PRODUCT_TABS_BROWSER_PASS<\/body>/);
    } finally {
        if (realpathSync(path.dirname(directory)) !== realpathSync(tmpdir()) || !path.basename(directory).startsWith('novinpay-product-tabs-')) throw Error('Unexpected cleanup target');
        rmSync(directory,{recursive:true,force:true,maxRetries:3,retryDelay:100});
    }
});
