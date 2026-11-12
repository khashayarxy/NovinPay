// Playwright function for local Herd. The browser posts only to intercepted fixture routes.
async page => {
    const origin = 'https://novinpaypos.test';
    const context = await page.context().browser().newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
    const start = origin + '/__mobile-digits-fixture';
    const end = origin + '/__mobile-digits-submission';
    try {
        const tab = await context.newPage();
        await context.route(start, route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html>
            <html dir="rtl" lang="fa"><script src="/js/mobile-digits.js" defer></script>
            <form method="post" action="${end}"><input type="tel" name="phone" placeholder="09123456789" autocomplete="tel">
            <input type="tel" name="saved" value="09120000000" readonly><button>ثبت</button></form>` }));
        let posted = null;
        await context.route(end, route => {
            posted = new URLSearchParams(route.request().postData());
            return route.fulfill({ body: 'accepted' });
        });
        await tab.goto(start);
        const input = tab.locator('[name=phone]');
        const expect = (ok, label) => { if (!ok) throw new Error(label); };
        expect(await input.getAttribute('placeholder') === '۰۹۱۲۳۴۵۶۷۸۹', 'Persian placeholder');
        expect(await tab.locator('[name=saved]').inputValue() === '۰۹۱۲۰۰۰۰۰۰۰', 'Persian read-only prefill');
        await input.pressSequentially('09123456789');
        expect(await input.inputValue() === '۰۹۱۲۳۴۵۶۷۸۹', 'English typing');
        await input.fill('٠٩١٢٣٤٥٦٧٨٩');
        expect(await input.inputValue() === '۰۹۱۲۳۴۵۶۷۸۹', 'Arabic paste-style input');
        await input.fill('۰۹۱۲۳۴۵۶۷۸۹');
        expect(await input.inputValue() === '۰۹۱۲۳۴۵۶۷۸۹', 'Persian input');
        await input.evaluate(field => {
            field.value = '09123456789';
            field.dispatchEvent(new Event('change', { bubbles: true }));
            const dynamic = document.createElement('input');
            dynamic.type = 'tel';
            dynamic.name = 'dynamic';
            dynamic.value = '09120000001';
            field.form.appendChild(dynamic);
        });
        expect(await input.inputValue() === '۰۹۱۲۳۴۵۶۷۸۹', 'Autofill change event');
        expect(await tab.locator('[name=dynamic]').inputValue() === '۰۹۱۲۰۰۰۰۰۰۱', 'Dynamic prefill');
        await tab.getByRole('button', { name: 'ثبت' }).click();
        await tab.waitForURL(end);
        expect(posted?.get('phone') === '09123456789' && posted.get('saved') === '09120000000'
            && posted.get('dynamic') === '09120000001', 'ASCII form data with leading zero');
        return { persianPresentation: true, threeDigitSets: true, autofillChange: true, dynamicPrefill: true, asciiSubmission: true };
    } finally {
        await context.close();
    }
}
