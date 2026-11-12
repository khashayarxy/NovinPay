import { asciiDigits, persianDigits } from './admin-digits.js';

const DAY = 86400000;
const calendar = new Intl.DateTimeFormat('en-US-u-ca-persian-nu-latn', {
    year: 'numeric', month: 'numeric', day: 'numeric', timeZone: 'UTC',
});
const months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
const pad = value => String(value).padStart(2, '0');

export function jalaliParts(timestamp) {
    const parts = calendar.formatToParts(timestamp);
    return ['year', 'month', 'day'].map(name => Number(parts.find(part => part.type === name).value));
}

export function gregorianDay(year, month, day) {
    if (year < 1 || year > 9377 || month < 1 || month > 12 || day < 1 || day > 31) return null;
    const target = year * 10000 + month * 100 + day;
    let first = Date.UTC(year + 621, 0, 1) / DAY;
    let last = Date.UTC(year + 622, 3, 1) / DAY;
    while (first <= last) {
        const middle = Math.floor((first + last) / 2);
        const [y, m, d] = jalaliParts(middle * DAY);
        const current = y * 10000 + m * 100 + d;
        if (current === target) return middle * DAY;
        if (current < target) first = middle + 1;
        else last = middle - 1;
    }
    return null;
}

export function parseAdminDate(value, timed) {
    const match = asciiDigits(value.trim()).match(/^(\d{1,4})[/\-](\d{1,2})[/\-](\d{1,2})(?:[ T](\d{1,2}):(\d{2}))?$/);
    if (!match || timed !== Boolean(match[4]) || Number(match[4]) > 23 || Number(match[5]) > 59) return '';
    const date = gregorianDay(...match.slice(1, 4).map(Number));
    if (date === null) return '';
    return new Date(date).toISOString().slice(0, 10) + (timed ? `T${pad(match[4])}:${match[5]}` : '');
}

function displayDate(value) {
    if (!value) return '';
    const [date, time] = value.split('T');
    const parts = jalaliParts(Date.parse(date + 'T00:00:00Z'));
    return persianDigits(parts.map(pad).join('/') + (time ? ' ' + time : ''));
}

export function initAdminCalendar(root) {
    const dates = root.querySelectorAll('input:is([type=date],[type=datetime-local])');
    if (!dates.length) return;

    const dialog = document.createElement('dialog');
    dialog.className = 'admin-calendar';
    dialog.setAttribute('aria-labelledby', 'admin-calendar-month');
    dialog.innerHTML = `
        <header><button type="button" data-prev aria-label="ماه قبل">›</button>
        <strong id="admin-calendar-month" aria-live="polite"></strong>
        <button type="button" data-next aria-label="ماه بعد">‹</button></header>
        <div class="admin-calendar-week" aria-hidden="true"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div>
        <div class="admin-calendar-days" aria-label="روزهای ماه"></div>
        <footer><button type="button" data-clear>پاک کردن</button><button type="button" data-close>بستن</button></footer>`;
    root.append(dialog);
    const grid = dialog.querySelector('.admin-calendar-days');
    let year, month, chosen, write;
    const title = dialog.querySelector('strong');
    function render() {
        title.textContent = `${months[month - 1]} ${persianDigits(year)}`;
        grid.replaceChildren();
        const first = gregorianDay(year, month, 1);
        const offset = (new Date(first).getUTCDay() + 1) % 7;
        for (let index = 0; index < offset; index++) grid.append(document.createElement('span'));
        for (let day = 1; day <= 31; day++) {
            const timestamp = first + (day - 1) * DAY;
            if (jalaliParts(timestamp)[1] !== month) break;
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = persianDigits(day);
            button.setAttribute('aria-label', `${persianDigits(day)} ${title.textContent}`);
            button.setAttribute('aria-pressed', String(timestamp === chosen));
            button.addEventListener('click', () => { write(timestamp); dialog.close(); });
            grid.append(button);
        }
    }
    function shift(delta) {
        const index = year * 12 + month - 1 + delta;
        const nextYear = Math.floor(index / 12);
        if (nextYear < 1 || nextYear > 9377) return;
        year = nextYear;
        month = index % 12 + 1;
        render();
    }
    dialog.querySelector('[data-prev]').addEventListener('click', () => shift(-1));
    dialog.querySelector('[data-next]').addEventListener('click', () => shift(1));
    dialog.querySelector('[data-clear]').addEventListener('click', () => { write(null); dialog.close(); });
    dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());

    for (const field of dates) {
        const timed = field.type === 'datetime-local';
        const original = field.value;
        const input = document.createElement('input');
        input.type = 'text';
        input.id = field.id;
        input.className = field.className;
        input.dir = 'ltr';
        input.required = field.required;
        input.disabled = field.disabled;
        input.readOnly = field.readOnly;
        input.autocomplete = 'off';
        input.placeholder = timed ? '۱۴۰۵/۰۱/۰۱ ۱۴:۳۰' : '۱۴۰۵/۰۱/۰۱';
        input.value = displayDate(original);
        for (const attribute of ['aria-invalid', 'aria-describedby']) {
            if (field.hasAttribute(attribute)) input.setAttribute(attribute, field.getAttribute(attribute));
        }
        const wrapper = document.createElement('div');
        wrapper.className = 'admin-date-input';
        const picker = document.createElement('button');
        picker.type = 'button';
        picker.className = 'btn btn-secondary';
        picker.textContent = 'تقویم';
        picker.setAttribute('aria-haspopup', 'dialog');
        if (field.labels?.length) picker.setAttribute('aria-label', `تقویم ${field.labels[0].textContent.trim()}`);
        picker.disabled = field.disabled || field.readOnly;
        field.replaceWith(wrapper);
        field.removeAttribute('id');
        field.type = 'hidden';
        field.value = original;
        wrapper.append(input, picker, field);
        function update() {
            const start = input.selectionStart;
            const end = input.selectionEnd;
            input.value = persianDigits(asciiDigits(input.value));
            input.setSelectionRange(start, end);
            field.value = parseAdminDate(input.value, timed);
            input.setCustomValidity(input.value.trim() && !field.value ? 'تاریخ شمسی معتبر وارد کنید.' : '');
        }
        input.addEventListener('input', update);
        field.form?.addEventListener('reset', () => {
            queueMicrotask(() => { input.value = displayDate(original); update(); });
        });
        picker.addEventListener('click', () => {
            chosen = field.value ? Date.parse(field.value.slice(0, 10) + 'T00:00:00Z') : null;
            const today = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tehran' }).format(new Date());
            [year, month] = jalaliParts(chosen ?? Date.parse(today + 'T00:00:00Z'));
            write = day => {
                const time = field.value.split('T')[1] ?? original.split('T')[1] ?? '00:00';
                input.value = day === null ? '' : displayDate(new Date(day).toISOString().slice(0, 10) + (timed ? 'T' + time : ''));
                update();
            };
            render();
            dialog.showModal();
            (grid.querySelector('[aria-pressed=true]') ?? grid.querySelector('button'))?.focus();
        });
    }
}
