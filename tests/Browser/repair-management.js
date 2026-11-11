// Playwright function for local Herd using synthetic HTML and the real Vite assets.
async page => {
    const origin = 'https://novinpaypos.test';
    const results = [];
    for (const width of [390, 1440]) {
        const context = await page.context().browser().newContext({ viewport: { width, height: 900 } });
        try {
            const tab = await context.newPage();
            await tab.goto(origin + '/admin/login');
            const assets = await tab.evaluate(() => ({
                css: [...document.querySelectorAll('link[rel=stylesheet]')].find(node => node.href.includes('/build/assets/app-'))?.href,
                js: [...document.querySelectorAll('script[type=module]')].find(node => node.src.includes('/build/assets/app-'))?.src,
            }));
            if (!assets.css || !assets.js) throw new Error('Local Vite build is required');
            const fixture = origin + '/__repair-management-fixture';
            await context.route(fixture, route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html>
                <html lang="fa" dir="rtl"><link rel="stylesheet" href="${assets.css}"><script type="module" src="${assets.js}"></script>
                <body class="admin-site"><section class="simple-repair-details"><div>مشتری</div><div>دستگاه</div></section>
                <form data-simple-repair>
                  <select name="status"><option value="repairing">در حال تعمیر</option><option value="ready">آماده تحویل</option></select>
                  <div data-repair-cost hidden><input name="repair_cost" data-money-input inputmode="numeric" disabled></div>
                  <select name="delivery_method"><option value="in_person">حضوری</option><option value="shipping">ارسال</option></select>
                  <div data-carrier-options hidden><select name="delivery_carrier" disabled><option value="">انتخاب نشده</option><option value="tipax">تیپاکس</option></select></div>
                </form></body></html>` }));
            await tab.goto(fixture);
            const cost = tab.locator('[name=repair_cost]');
            const carrier = tab.locator('[name=delivery_carrier]');
            const expect = (ok, reason) => { if (!ok) throw new Error(reason); };
            expect(await cost.isHidden() && await cost.isDisabled(), 'Earlier cost is hidden and omitted');
            await tab.selectOption('[name=status]', 'ready');
            expect(await cost.isVisible() && await cost.isEnabled(), 'Ready status enables cost');
            await cost.fill('1250000');
            expect(await cost.inputValue() === '۱٬۲۵۰٬۰۰۰', 'Display exact toman');
            await cost.evaluate(input => input.setSelectionRange(3, 3));
            await cost.press('9');
            expect(await cost.inputValue() === '۱۲٬۹۵۰٬۰۰۰' && await cost.evaluate(input => input.selectionStart === 4), 'Keep caret near the edited digit');
            await tab.selectOption('[name=delivery_method]', 'shipping');
            await carrier.selectOption('tipax');
            await tab.selectOption('[name=delivery_method]', 'in_person');
            expect(await carrier.isDisabled() && await carrier.isHidden(), 'Pickup omits carrier');
            await tab.selectOption('[name=delivery_method]', 'shipping');
            expect(await carrier.inputValue() === 'tipax', 'Restores draft carrier');
            const layout = await tab.locator('.simple-repair-details').evaluate(element => ({
                columns: getComputedStyle(element).gridTemplateColumns.split(' ').length,
                overflow: document.documentElement.scrollWidth > innerWidth,
            }));
            expect(layout.columns === (width > 800 ? 2 : 1) && !layout.overflow, 'Responsive RTL layout');
            results.push({ width, ...layout, caret: true, costVisibility: true, carrierPreserved: true });
        } finally {
            await context.close();
        }
    }
    return results;
}
