const latin = (value) => value.replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit))).replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)));

export function initSimpleRepair() {
    document.querySelectorAll('[data-money-input]').forEach((input) => {
        const format = () => {
            const raw = latin(input.value).replace(/[٬,]/g, '').replace(/^0+(?=\d)/, '');
            if (!/^\d*$/.test(raw)) return;
            const grouped = raw.replace(/\B(?=(\d{3})+(?!\d))/g, '٬');
            input.value = grouped.replace(/\d/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
            input.style.width = `${Math.max(2, input.value.length + 0.5)}ch`;
        };
        input.addEventListener('input', format);
        format();
    });
    document.querySelectorAll('[data-simple-repair]').forEach((form) => {
        const settlement = form.querySelector('[name="settled"]');
        const method = form.querySelector('[name="delivery_method"]');
        const refresh = () => {
            const payment = form.querySelector('[data-settlement-options]');
            const carrier = form.querySelector('[data-carrier-options]');
            if (payment) {
                payment.hidden = !settlement.checked;
                payment.querySelectorAll('input').forEach((input) => { input.disabled = !settlement.checked; });
            }
            if (carrier) {
                carrier.hidden = method.value !== 'shipping';
                carrier.querySelectorAll('select').forEach((input) => { input.disabled = method.value !== 'shipping'; });
            }
        };
        settlement?.addEventListener('change', refresh);
        method?.addEventListener('change', refresh);
        refresh();
    });
}
