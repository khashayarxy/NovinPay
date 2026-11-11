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
                ? 'درخواست کد جدید پس از ' + (format ? format.format(remaining) : remaining) + ' ثانیه'
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
    var error = document.getElementById('account-otp-error');
    var credential = null;
    var canVerifyInline = window.fetch && window.FormData && window.AbortController && window.URL;
    var request = null;
    var scheduled = null;
    var lastAttempt = null;
    var active = true;
    var navigating = false;

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

    function busy(value) {
        button.disabled = value;
        input.readOnly = value;
        pending.hidden = !value;
        form.setAttribute('aria-busy', String(value));
    }

    function clearError() {
        error.hidden = true;
        error.textContent = '';
        field.removeAttribute('data-otp-error');
        input.removeAttribute('aria-invalid');
    }

    function showError(message) {
        error.textContent = message;
        error.hidden = false;
        field.setAttribute('data-otp-error', '');
        input.setAttribute('aria-invalid', 'true');
    }

    function verify(manual) {
        window.clearTimeout(scheduled);
        var code = input.value;
        if (!active || request || navigating || !/^[0-9]{6}$/.test(code) || (!manual && code === lastAttempt)) return;
        lastAttempt = code;
        stopAutofill();
        clearError();
        var controller = new AbortController();
        request = controller;
        var payload = new FormData(form);
        busy(true);

        window.fetch(form.action, {
            method: 'POST',
            body: payload,
            credentials: 'same-origin',
            headers: {Accept: 'application/json'},
            redirect: 'error',
            signal: controller.signal
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                if (!active || request !== controller) return;
                if (response.ok && typeof body.redirect === 'string') {
                    var destination = new URL(body.redirect, window.location.href);
                    if (destination.origin !== window.location.origin) throw new Error();
                    window.location.assign(destination.href);
                    navigating = true;
                    return;
                }
                if (response.status === 422 && typeof body.message === 'string') {
                    showError(body.message);
                } else if (response.status === 429) {
                    showError('تعداد تلاش‌ها زیاد است. کمی صبر کنید و دوباره تلاش کنید.');
                } else if (response.status === 419) {
                    showError('مهلت نشست تمام شده است. صفحه را تازه کنید و دوباره کد بگیرید.');
                } else {
                    throw new Error();
                }
            });
        }).catch(function () {
            if (active && request === controller) showError('بررسی کد انجام نشد. دوباره تلاش کنید.');
        }).then(function () {
            if (request !== controller) return;
            request = null;
            if (!navigating) busy(false);
        });
    }

    function onCodeChange() {
        if (!active || request || navigating) return;
        window.clearTimeout(scheduled);
        var value = digits(input.value).slice(0, 6);
        if (value !== input.value) {
            var cursor = digits(input.value.slice(0, input.selectionStart)).length;
            input.value = value;
            input.setSelectionRange(cursor, cursor);
        }
        if (value !== lastAttempt) clearError();
        render();
        if (value.length === 6) {
            stopAutofill();
            if (canVerifyInline && value !== lastAttempt) scheduled = window.setTimeout(function () { verify(false); }, 120);
        }
    }

    if (window.CSS && CSS.supports('display', 'grid') && CSS.supports('aspect-ratio', '1')) {
        visual.hidden = false;
        field.classList.add('is-enhanced');
    }

    input.addEventListener('input', onCodeChange);
    input.addEventListener('change', onCodeChange);
    input.addEventListener('animationstart', function (event) {
        if (event.animationName === 'np-otp-autofill') onCodeChange();
    });
    input.addEventListener('paste', function (event) {
        if (!event.clipboardData) return;
        var value = digits(event.clipboardData.getData('text'));
        if (value.length < 6) return;
        event.preventDefault();
        if (value.length !== 6 || request || navigating) return;
        input.value = value;
        onCodeChange();
    });
    ['focus', 'blur', 'keyup', 'click', 'select'].forEach(function (name) {
        input.addEventListener(name, render);
    });
    form.addEventListener('submit', function (event) {
        if (canVerifyInline) {
            event.preventDefault();
            verify(true);
            return;
        }
        stopAutofill();
        busy(true);
    });
    window.addEventListener('pagehide', function () {
        active = false;
        window.clearTimeout(scheduled);
        stopAutofill();
        if (request) request.abort();
        request = null;
    });
    window.addEventListener('pageshow', function () {
        active = true;
        navigating = false;
        if (!request) busy(false);
        onCodeChange();
    });

    if (!document.getElementById('error-summary')) input.focus();
    onCodeChange();

    if (window.isSecureContext && window.OTPCredential && window.AbortController && navigator.credentials) {
        credential = new AbortController();
        navigator.credentials.get({otp: {transport: ['sms']}, signal: credential.signal}).then(function (result) {
            if (active && !request && !navigating && result && typeof result.code === 'string' && !input.value
                && /^[0-9]{6}$/.test(digits(result.code))) {
                input.value = digits(result.code);
                onCodeChange();
            }
        }).catch(function () { /* Manual entry remains available. */ });
    }
}());
