const latin = (value) => value.replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit))).replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)));

export function formatRepairToman(value) {
    const raw = latin(value).replace(/[٬,]/g, '');
    if (!/^\d*$/.test(raw)) return value;
    return raw.replace(/\B(?=(\d{3})+(?!\d))/g, '٬').replace(/\d/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
}

export function initSimpleRepair() {
    document.querySelectorAll('[data-money-input]').forEach((input) => {
        const format = (event) => {
            const original = input.value;
            if (original === '') return;
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
            let start = position(input.selectionStart);
            let end = position(input.selectionEnd);
            if (event?.inputType === 'deleteContentForward' && start === end && formatted[start] === '٬') {
                start++;
                end++;
            }
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
