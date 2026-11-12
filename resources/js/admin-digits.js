export const asciiDigits = value => String(value).replace(/[۰-۹٠-٩]/g, digit => String(digit.charCodeAt(0) & 15));
export const persianDigits = value => String(value).replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[digit]);

export function initAdminDigits(root) {
    for (const input of root.querySelectorAll('input:is([type=number],[type=tel],[inputmode=numeric],[inputmode=decimal],[data-admin-digits]):not([data-money-input]):not([data-digits-ready])')) {
        if (!input.name || input.type === 'hidden' || input.type === 'password') continue;
        input.dataset.digitsReady = '';
        const validator = input.cloneNode();
        const numeric = input.type === 'number';
        const decimal = input.inputMode === 'decimal' || (numeric && validator.step !== '' && validator.step !== '1');
        if (numeric) {
            input.type = 'text';
            input.inputMode = decimal ? 'decimal' : 'numeric';
        }
        input.removeAttribute('pattern');
        input.dir = 'ltr';
        const canonical = () => {
            const value = asciiDigits(input.value);
            return decimal ? value.replaceAll('٫', '.') : value;
        };
        function update() {
            const start = input.selectionStart;
            const end = input.selectionEnd;
            const direction = input.selectionDirection;
            const visual = persianDigits(asciiDigits(input.value));
            if (input.value !== visual) {
                input.value = visual;
                if (start !== null) input.setSelectionRange(start, end, direction);
            }
            validator.value = canonical();
            const invalid = !validator.validity.valid || (numeric && canonical() && validator.value === '');
            input.setCustomValidity(invalid ? 'مقدار عددی این فیلد معتبر نیست.' : '');
        }
        update();
        input.addEventListener('input', update);
        input.form?.addEventListener('reset', () => queueMicrotask(update));
        input.form?.addEventListener('formdata', event => {
            if (!input.matches(':disabled') && event.formData.has(input.name)) event.formData.set(input.name, canonical());
        });
    }
}
