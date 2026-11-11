const latin = (value) => value.replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit))).replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)));

export function formatRepairToman(value) {
    const raw = latin(value).replace(/[٬,]/g, '');
    if (!/^\d*$/.test(raw)) return value;
    return raw.replace(/\B(?=(\d{3})+(?!\d))/g, '٬').replace(/\d/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
}

export function initSimpleRepair() {
    document.querySelectorAll('[data-money-input]').forEach((input) => {
        const format = () => {
            const original = input.value;
            const formatted = formatRepairToman(original);
            if (formatted === original) return;
            const position = (offset) => {
                let digits = latin(original.slice(0, offset)).replace(/\D/g, '').length;
                let index = 0;
                while (index < formatted.length && digits > 0) {
                    if (formatted[index] !== '٬') digits--;
                    index++;
                }
                return index;
            };
            const start = position(input.selectionStart);
            const end = position(input.selectionEnd);
            const direction = input.selectionDirection;
            input.value = formatted;
            if (document.activeElement === input) input.setSelectionRange(start, end, direction);
        };
        input.addEventListener('input', format);
        format();
    });
    document.querySelectorAll('[data-simple-repair]').forEach((form) => {
        const method = form.querySelector('[name="delivery_method"]');
        const status = form.querySelector('[name="status"]');
        const refresh = () => {
            const carrier = form.querySelector('[data-carrier-options]');
            const cost = form.querySelector('[data-repair-cost]');
            if (cost) {
                cost.hidden = status.value !== 'ready';
                cost.querySelector('input').disabled = cost.hidden;
            }
            if (carrier) {
                carrier.hidden = method.value !== 'shipping';
                carrier.querySelectorAll('select, input').forEach((input) => { input.disabled = carrier.hidden; });
            }
        };
        status?.addEventListener('change', refresh);
        method?.addEventListener('change', refresh);
        refresh();
    });
}
