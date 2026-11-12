// Playwright function for the local Herd site. All submissions use synthetic fixtures.
async page => {
    const origin = 'https://novinpaypos.test';
    const browser = page.context().browser();
    const context = await browser.newContext({ viewport: { width: 390, height: 840 }, hasTouch: true });
    const base = origin + '/__auth-controls-fixture';
    const verify = origin + '/__auth-controls-verify';
    let requests = 0;
    const assert = (pass, name) => { if (!pass) throw new Error(name); };
    const assets = '<link rel="stylesheet" href="/css/password-visibility.css"><script src="/js/password-visibility.js" defer></script>';
    try {
        const tab = await context.newPage();
        await context.route(base, route => route.fulfill({
            contentType: 'text/html',
            body: `<!doctype html><html lang="fa" dir="rtl" data-support-surface="operator"><head>${assets}
            <script src="/js/customer-otp.js" defer></script></head><body>
            <form id="password-form"><label>گذرواژه<input id="password" name="password" type="password" autocomplete="new-password" required></label>
            <label>تکرار گذرواژه<input id="confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></label><button>ذخیره</button></form>
            <form method="post" action="${verify}" data-account-otp><input type="hidden" name="_token" value="synthetic-csrf">
            <div class="np-otp"><input name="code" autocomplete="one-time-code" minlength="6" maxlength="6" required>
            <div class="np-otp__slots" hidden>${'<span></span>'.repeat(6)}</div></div>
            <button>تأیید</button><p id="account-otp-error" hidden></p><small data-otp-pending hidden>در حال بررسی</small></form></body></html>`,
        }));
        await context.route(verify, async route => {
            requests++;
            const request = route.request();
            const body = request.postData() || '';
            assert(request.method() === 'POST' && request.headers().accept === 'application/json'
                && body.includes('synthetic-csrf'), 'Verification must use a CSRF-bearing JSON request');
            if (body.includes('246810')) return route.fulfill({
                status: 429,
                headers: { 'Retry-After': '2' },
                json: { message: 'درخواست‌های زیادی ثبت شده است.', retry_after: 99 },
            });
            if (body.includes('001234')) return route.fulfill({ status: 200, json: { redirect: '/__auth-controls-next' } });
            return route.fulfill({ status: 422, json: { message: 'کد معتبر نیست یا منقضی شده است.' } });
        });
        await context.route(origin + '/__auth-controls-next', route => route.fulfill({ body: 'Accepted' }));
        await tab.goto(base);
        const password = tab.locator('#password');
        const eye = tab.locator('#password + [role=button]');
        await password.fill('synthetic-password');
        await password.press('Tab');
        assert(await tab.locator('#confirmation').evaluate(input => input === document.activeElement), 'Tab must skip the eye');
        await eye.tap();
        assert(await password.getAttribute('type') === 'text' && await password.inputValue() === 'synthetic-password'
            && await eye.getAttribute('aria-pressed') === 'true', 'Eye must reveal the same value');
        await eye.tap();
        assert(await password.getAttribute('type') === 'password' && await password.getAttribute('autocomplete') === 'new-password', 'Eye must restore masking and preserve autocomplete');
        await tab.evaluate(() => {
            const input = document.createElement('input');
            input.id = 'dynamic-operator-password';
            input.type = 'password';
            document.body.appendChild(input);
        });
        await tab.locator('#dynamic-operator-password + [role=button]').waitFor();

        const code = tab.locator('[name=code]');
        await code.pressSequentially('123456');
        await code.dispatchEvent('change');
        await tab.getByText('کد معتبر نیست یا منقضی شده است.').waitFor();
        await code.dispatchEvent('input');
        await tab.waitForTimeout(160);
        assert(requests === 1 && await code.isEditable(), 'Invalid entry must stay editable without replay');
        await code.fill('246810');
        await code.dispatchEvent('animationstart', { animationName: 'np-otp-autofill' });
        await tab.getByText('درخواست‌های زیادی ثبت شده است.', { exact: false }).waitFor();
        const verifyButton = tab.locator('[data-account-otp] button');
        assert(await verifyButton.isDisabled(), 'Retry-After must disable verification');
        await verifyButton.evaluate(button => new Promise(resolve => {
            const observer = new MutationObserver(() => {
                if (!button.disabled) { observer.disconnect(); resolve(); }
            });
            if (!button.disabled) resolve();
            else observer.observe(button, { attributes: true, attributeFilter: ['disabled'] });
        }));
        assert(requests === 2, 'Autofill and rate-limit handling must send once');
        await code.evaluate(input => {
            const clipboardData = new DataTransfer();
            clipboardData.setData('text/plain', '۰۰۱۲۳۴');
            input.dispatchEvent(new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData }));
        });
        await tab.waitForURL(origin + '/__auth-controls-next');
        assert(requests === 3, 'Pasting a complete code must verify once and advance');

        await tab.goto(origin + '/admin/login');
        await tab.locator('input[type=password] + [role=button]').waitFor();
        return { passwordTabOrder: true, passwordValuesAndAutofillAttributes: true, dynamicOperatorPassword: true,
            otpTypingPasteAndAutofillEvent: true, inlineErrorsAndNavigation: true, verificationRequests: requests, localAdminLogin: true };
    } finally {
        await context.close();
    }
}
