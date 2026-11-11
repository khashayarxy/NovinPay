(function () {
    var resend = document.querySelector('[data-account-resend]');
    var seconds = resend && Number(resend.getAttribute('data-account-resend'));
    if (seconds > 0) {
        var until = Date.now() + seconds * 1000;
        var label = resend.textContent;
        var format = window.Intl && new Intl.NumberFormat('fa-IR');
        var updateResend = function () {
            var remaining = Math.max(0, Math.ceil((until - Date.now()) / 1000));
            resend.disabled = remaining > 0;
            resend.textContent = remaining
                ? 'ارسال دوباره پس از ' + (format ? format.format(remaining) : remaining) + ' ثانیه'
                : label;
            if (remaining) window.setTimeout(updateResend, 1000);
        };
        updateResend();
    }

    var form = document.querySelector('[data-account-otp]');
    if (!form) return;

    var input = form.elements.code;
    var field = form.querySelector('.np-otp');
    var visual = field.querySelector('.np-otp__slots');
    var slots = visual.children;
    var button = form.querySelector('button');
    var pending = form.querySelector('[data-otp-pending]');
    var credential = null;

    function digits(text) {
        return text.replace(/[\u06f0-\u06f9\u0660-\u0669]/g, function (digit) {
            return String(digit.charCodeAt(0) & 15);
        }).replace(/[^0-9]/g, '');
    }

    function stopAutofill() {
        if (credential) credential.abort();
        credential = null;
    }

    function render() {
        var cursor = Math.min(input.selectionStart, 5);
        for (var i = 0; i < slots.length; i++) {
            slots[i].textContent = input.value.charAt(i);
            if (document.activeElement === input && i === cursor) slots[i].setAttribute('data-otp-current', '');
            else slots[i].removeAttribute('data-otp-current');
        }
    }

    if (window.CSS && CSS.supports('display', 'grid') && CSS.supports('aspect-ratio', '1')) {
        visual.hidden = false;
        field.classList.add('is-enhanced');
    }

    input.addEventListener('input', function () {
        var value = digits(input.value).slice(0, 6);
        if (value !== input.value) {
            var cursor = digits(input.value.slice(0, input.selectionStart)).length;
            input.value = value;
            input.setSelectionRange(cursor, cursor);
        }
        field.removeAttribute('data-otp-error');
        input.removeAttribute('aria-invalid');
        if (value.length === 6) stopAutofill();
        render();
    });
    input.addEventListener('paste', function (event) {
        if (!event.clipboardData) return;
        var value = digits(event.clipboardData.getData('text'));
        if (value.length < 6) return;
        event.preventDefault();
        input.value = value.slice(0, 6);
        input.dispatchEvent(new Event('input'));
    });
    ['focus', 'blur', 'keyup', 'click', 'select'].forEach(function (name) {
        input.addEventListener(name, render);
    });
    form.addEventListener('submit', function () {
        stopAutofill();
        button.disabled = true;
        pending.hidden = false;
    });
    window.addEventListener('pagehide', stopAutofill);
    window.addEventListener('pageshow', function () {
        button.disabled = false;
        pending.hidden = true;
        render();
    });

    if (!document.getElementById('error-summary')) input.focus();
    render();

    if (window.isSecureContext && window.OTPCredential && window.AbortController && navigator.credentials) {
        credential = new AbortController();
        navigator.credentials.get({otp: {transport: ['sms']}, signal: credential.signal}).then(function (result) {
            if (result && typeof result.code === 'string' && !input.value) {
                input.value = digits(result.code).slice(0, 6);
                input.dispatchEvent(new Event('input'));
            }
        }).catch(function () { /* Manual entry remains available. */ });
    }
}());
