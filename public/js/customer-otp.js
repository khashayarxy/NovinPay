(function () {
    // A restored form can belong to a completed, expired or replaced flow.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) window.location.reload();
    });
    var canRequest = window.fetch && window.FormData && window.AbortController && window.URL;
    function diagnostic(operation, outcome, started) {
        var log = window.CustomerLoginDiagnostics || {events: []};
        log.events.push({operation: operation, outcome: outcome, ms: Math.max(0, Date.now() - started)});
        log.events = log.events.slice(-30);
        window.CustomerLoginDiagnostics = log;
    }
    // Bound both headers and JSON-body reads; an aborted response may still arrive.
    function boundedJSON(url, options, operation, milliseconds) {
        return new Promise(function (resolve, reject) {
            var controller = new AbortController(), signal = options.signal, settled = false, started = Date.now();
            function finish(error, value) {
                if (settled) return;
                settled = true;
                window.clearTimeout(timer);
                if (signal) signal.removeEventListener('abort', abort);
                diagnostic(operation, error ? error.message : 'http_' + value.response.status, started);
                if (error) reject(error); else resolve(value);
            }
            function abort() { controller.abort(); finish(new Error('cancelled')); }
            var timer = window.setTimeout(function () { controller.abort(); finish(new Error('deadline')); }, milliseconds || 12000);
            if (signal) {
                if (signal.aborted) { abort(); return; }
                signal.addEventListener('abort', abort, {once: true});
            }
            options.signal = controller.signal;
            window.fetch(url, options).then(function (response) {
                return response.json().then(function (body) { finish(null, {response: response, body: body}); });
            }).catch(function () { finish(new Error('transport')); });
        });
    }
    function destination(value) {
        var url = new URL(value, window.location.href);
        if (url.origin !== window.location.origin) throw new Error('foreign_destination');
        return url.href;
    }
    function reconcile(form) {
        if (!form.getAttribute('data-login-state')) return Promise.reject(new Error('no_state_endpoint'));
        var url = new URL(form.getAttribute('data-login-state'), window.location.href);
        if (url.origin !== window.location.origin) return Promise.reject(new Error('foreign_state_endpoint'));
        url.searchParams.set('flow', form.elements.flow ? form.elements.flow.value : '');
        if (form.elements.request_id) url.searchParams.set('request_id', form.elements.request_id.value);
        if (form.elements.recover) url.searchParams.set('recover', form.elements.recover.value);
        function read(attempt) {
            if (window.navigator && window.navigator.onLine === false) return Promise.reject(new Error('offline'));
            return boundedJSON(url.href, {credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: {Accept: 'application/json'}}, 'state', 8000)
                .then(function (result) {
                    if ([401, 403, 409, 419].indexOf(result.response.status) >= 0) return {state: 'expired'};
                    if (result.response.status === 429) throw new Error('state_limited');
                    if (!result.response.ok) throw new Error('state_unavailable');
                    if (['authenticated', 'challenge', 'expired', 'idle'].indexOf(result.body.state) < 0) throw new Error('state_contract');
                    return result.body;
                }).catch(function (error) {
                    if (attempt >= 2 || error.message === 'offline' || error.message === 'state_limited') throw error;
                    return new Promise(function (resolve) { window.setTimeout(resolve, 500 * Math.pow(2, attempt) + Math.floor(Math.random() * 250)); })
                        .then(function () { return read(attempt + 1); });
                });
        }
        return read(0);
    }
    var dotsMarkup = '<span class="np-btn-dots" aria-hidden="true"><span></span><span></span><span></span></span><span class="sr-only">در حال بررسی…</span>';
    document.querySelectorAll('[data-account-send]').forEach(function (sendForm) {
        if (!canRequest) return;
        var submit = sendForm.querySelector('button[type="submit"]'), status = sendForm.querySelector('[data-send-status]');
        var alive = true, job = null, uncertain = false, restart = false, locked = false, original = submit.textContent;
        var isPrimaryBtn = submit.classList.contains('np-account-primary');
        function notify(text) {
            if (!status) return;
            if (!text) {
                status.hidden = true;
                status.textContent = '';
                return;
            }
            status.hidden = false;
            status.textContent = text;
        }
        function setLoadingState(loading) {
            if (loading) {
                notify('');
                if (isPrimaryBtn) submit.innerHTML = dotsMarkup;
            }
        }
        function navigate(state) {
            if (state.state === 'authenticated' || state.state === 'challenge') {
                window.location.assign(destination(state.redirect));
                return true;
            }
            return false;
        }
        function recover() {
            setLoadingState(true);
            return reconcile(sendForm).then(function (state) {
                if (!alive || navigate(state)) return;
                uncertain = state.state !== 'idle';
                restart = state.state === 'expired';
                notify(state.state === 'idle' ? 'درخواستی ثبت نشده است. می‌توانید دوباره ادامه دهید.' : 'مهلت این مرحله تمام شده است. صفحهٔ ورود را دوباره باز کنید.');
            });
        }
        function submitOnce(recoverOnly) {
            if (!alive || job || submit.disabled) return;
            if (restart) { window.location.assign(destination('/login')); return; }
            var controller = new AbortController(); job = controller;
            sendForm.setAttribute('aria-busy', 'true'); submit.disabled = true;
            setLoadingState(true);
            var task = recoverOnly || uncertain ? recover() : boundedJSON(sendForm.action, {
                method: 'POST', body: new FormData(sendForm), credentials: 'same-origin', headers: {Accept: 'application/json'}, redirect: 'error', signal: controller.signal
            }, 'send').then(function (result) {
                if (!alive) return;
                if (typeof result.body.redirect === 'string' && (result.response.ok || result.body.state === 'challenge')) {
                    navigate({state: 'challenge', redirect: result.body.redirect}); return;
                }
                if (result.response.status === 422) { notify('شماره موبایل یا ایمیل را بررسی کنید.'); return; }
                if (result.response.status === 429) {
                    countdown(submit, function (waiting) { locked = waiting; submit.disabled = locked || !!job; })(Number(result.response.headers.get('Retry-After')) || Number(result.body.retry_after));
                    notify(typeof result.body.message === 'string' ? result.body.message : 'برای تلاش دوباره کمی صبر کنید.'); return;
                }
                if ([401, 403, 419].indexOf(result.response.status) >= 0) { restart = true; notify('مهلت نشست تمام شده است. صفحهٔ ورود را دوباره باز کنید.'); return; }
                uncertain = true; throw new Error('send_unknown');
            }).catch(function () { if (alive) { uncertain = true; return recover(); } });
            task.catch(function () { if (alive) { uncertain = true; notify('ارتباط برقرار نشد. پس از اتصال، وضعیت درخواست را بررسی کنید.'); } }).then(function () {
                if (job !== controller) return;
                job = null;
                if (!alive) return;
                sendForm.setAttribute('aria-busy', 'false'); submit.disabled = locked;
                if (!locked) submit.textContent = restart ? 'شروع دوباره' : uncertain ? 'بررسی وضعیت' : original;
            });
        }
        sendForm.addEventListener('submit', function (event) { event.preventDefault(); submitOnce(false); });
        window.addEventListener('online', function () { if (uncertain && !job) submitOnce(true); });
        window.addEventListener('pagehide', function () { alive = false; if (job) job.abort(); });
    });
    function countdown(button, onChange) {
        var until = 0;
        var timer;
        var label = button.textContent;
        var format = window.Intl && new Intl.NumberFormat('fa-IR');
        function update() {
            var remaining = Math.max(0, Math.ceil((until - Date.now()) / 1000));
            var amount = remaining >= 3600 ? Math.ceil(remaining / 3600) : remaining > 60 ? Math.ceil(remaining / 60) : remaining;
            var unit = remaining >= 3600 ? 'ساعت' : remaining > 60 ? 'دقیقه' : 'ثانیه';
            button.textContent = remaining ? (format ? format.format(amount) : amount) + ' ' + unit + ' تا تلاش دوباره' : label;
            onChange(remaining > 0);
            if (remaining) timer = window.setTimeout(update, remaining > 60 ? 10000 : 1000);
        }
        return function (seconds) {
            if (!(seconds > 0)) return;
            window.clearTimeout(timer);
            until = Date.now() + seconds * 1000;
            update();
        };
    }
    document.querySelectorAll('[data-account-wait]').forEach(function (button) {
        countdown(button, function (locked) {
            button.disabled = locked || button.form.getAttribute('aria-busy') === 'true';
        })(Number(button.getAttribute('data-account-wait')));
    });
    document.querySelectorAll('[data-account-submit]').forEach(function (form) {
        var button = form.querySelector('button[type="submit"]');
        form.addEventListener('submit', function (event) {
            if (canRequest && form.hasAttribute('data-account-send')) return;
            if (button.disabled || form.getAttribute('aria-busy') === 'true') {
                event.preventDefault();
                return;
            }
            if (event.defaultPrevented) return;
            form.setAttribute('aria-busy', 'true');
            button.disabled = true;
        });
    });

    var form = document.querySelector('[data-account-otp]');
    if (!form) return;

    var input = form.elements.code;
    var field = form.querySelector('.np-otp, .np-otp-boxes');
    var visual = field ? field.querySelector('.np-otp__slots, .np-otp-boxes__grid') : null;
    var slots = visual ? visual.children : [];
    var button = form.querySelector('button[type="submit"]');
    var originalVerifyText = button ? button.textContent : '';
    var pending = form.querySelector('[data-otp-pending]');
    var error = document.getElementById('account-otp-error');
    var credential = null;
    var canVerifyInline = window.fetch && window.FormData && window.AbortController && window.URL;
    var request = null;
    var scheduled = null;
    var lastAttempt = null;
    var retryable = false;
    var active = true;
    var navigating = false;
    var verifyLocked = false;
    var otpExpired = false;
    var lockVerify = countdown(button, function (locked) {
        verifyLocked = locked;
        button.disabled = locked || otpExpired || !!request;
    });
    lockVerify(Number(button.getAttribute('data-verify-wait')));

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
        var cursor = Math.min(input.selectionStart || 0, 5);
        for (var i = 0; i < slots.length; i++) {
            var ch = input.value.charAt(i);
            if ('value' in slots[i]) {
                if (slots[i].value !== ch) slots[i].value = ch;
            } else {
                slots[i].textContent = ch;
            }
            if (document.activeElement === input && i === cursor) slots[i].setAttribute('data-otp-current', '');
            else slots[i].removeAttribute('data-otp-current');
        }
    }

    function busy(value) {
        button.disabled = value || verifyLocked || otpExpired;
        input.readOnly = value;
        for (var i = 0; i < slots.length; i++) {
            if ('readOnly' in slots[i]) slots[i].readOnly = value;
        }
        if (pending) pending.hidden = !value;
        if (value) {
            button.innerHTML = dotsMarkup;
        } else if (!verifyLocked) {
            button.textContent = originalVerifyText;
        }
        form.setAttribute('aria-busy', String(value));
    }

    function clearError() {
        if (error) {
            error.hidden = true;
            error.textContent = '';
        }
        if (field) field.removeAttribute('data-otp-error');
        input.removeAttribute('aria-invalid');
    }

    function showError(message) {
        if (error) {
            error.textContent = message;
            error.hidden = false;
        }
        if (field) field.setAttribute('data-otp-error', '');
        input.setAttribute('aria-invalid', 'true');
    }

    var expiresAt = Number(form.getAttribute('data-otp-expires'));
    var serverNow = Number(form.getAttribute('data-server-now'));
    var expiryTimer = null;
    if (expiresAt > 0 && serverNow > 0) {
        var deadline = Date.now() + Math.max(0, expiresAt - serverNow) * 1000;
        var expiryText = document.getElementById('code-expiry');
        var tickExpiry = function () {
            otpExpired = Date.now() >= deadline;
            if (otpExpired && expiryText) expiryText.textContent = 'مهلت این کد تمام شد. کد تازه درخواست کنید.';
            button.disabled = otpExpired || verifyLocked || !!request;
            if (!otpExpired) expiryTimer = window.setTimeout(tickExpiry, 1000);
        };
        tickExpiry();
    }

    function reconcileVerification(controller) {
        return reconcile(form).then(function (state) {
            if (!active || request !== controller) return;
            if (state.state === 'authenticated' || state.state === 'challenge' && form.elements.challenge && state.challenge !== form.elements.challenge.value) {
                window.location.assign(destination(state.redirect)); navigating = true;
            } else if (state.state === 'challenge') {
                showError('نتیجهٔ درخواست قبلی تأیید نشد. همان کد را دوباره بررسی کنید.');
            } else {
                retryable = false;
                showError('مهلت این مرحله تمام شده است. ورود را دوباره شروع کنید.');
            }
        }).catch(function () { if (active && request === controller) showError('ارتباط برقرار نشد. کد حفظ شده است؛ دوباره بررسی کنید.'); });
    }

    function verify(manual) {
        window.clearTimeout(scheduled);
        var code = input.value;
        if (!active || request || navigating || verifyLocked || otpExpired || !/^[0-9]{6}$/.test(code)
            || (code === lastAttempt && (!manual || !retryable))) return;
        lastAttempt = code;
        retryable = false;
        stopAutofill();
        clearError();
        var controller = new AbortController();
        request = controller;
        var payload = new FormData(form);
        busy(true);

        boundedJSON(form.action, {
            method: 'POST',
            body: payload,
            credentials: 'same-origin',
            headers: {Accept: 'application/json'},
            redirect: 'error',
            signal: controller.signal
        }, 'verify').then(function (result) {
            var response = result.response, body = result.body;
                if (!active || request !== controller) return;
                if (response.ok && typeof body.redirect === 'string') {
                    window.location.assign(destination(body.redirect));
                    navigating = true;
                    return;
                }
                if (response.status === 422 && typeof body.message === 'string') {
                    showError(body.message);
                } else if (response.status === 429) {
                    retryable = true;
                    lockVerify(Number(response.headers.get('Retry-After')) || Number(body.retry_after));
                    showError(typeof body.message === 'string' ? body.message : 'تعداد تلاش‌ها زیاد است. دوباره تلاش کنید.');
                } else if ([409, 419].indexOf(response.status) >= 0) {
                    throw new Error('reconcile_session');
                } else {
                    throw new Error();
                }
        }).catch(function () {
            if (active && request === controller) {
                retryable = true;
                return reconcileVerification(controller);
            }
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
        if (value !== lastAttempt) {
            lastAttempt = null;
            clearError();
        }
        render();
        if (value.length === 6) {
            stopAutofill();
            if (canVerifyInline && value !== lastAttempt) scheduled = window.setTimeout(function () { verify(false); }, 120);
        }
    }

    if (visual && field && window.CSS && CSS.supports('display', 'grid') && CSS.supports('aspect-ratio', '1')) {
        visual.hidden = false;
        field.classList.add('is-enhanced');
    }

    input.addEventListener('input', onCodeChange);
    input.addEventListener('change', onCodeChange);
    input.addEventListener('animationstart', function (event) {
        if (event.animationName === 'np-otp-autofill') onCodeChange();
    });
    window.addEventListener('online', function () {
        if (!active || request || navigating || !retryable) return;
        var controller = new AbortController(); request = controller; busy(true);
        reconcileVerification(controller).then(function () {
            if (request !== controller) return;
            request = null;
            if (!navigating) busy(false);
        });
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
        if (verifyLocked || form.getAttribute('aria-busy') === 'true') {
            event.preventDefault();
            return;
        }
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
        window.clearTimeout(expiryTimer);
        stopAutofill();
        if (request) request.abort();
        request = null;
    });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) return;
        active = true;
        navigating = false;
        if (!request) busy(false);
        onCodeChange();
    });

    if (!document.getElementById('error-summary')) {
        if (slots.length > 0 && 'focus' in slots[0] && field && field.classList.contains('np-otp-boxes')) {
            slots[0].focus();
        } else {
            input.focus();
        }
    }
    onCodeChange();

    if (window.isSecureContext && window.OTPCredential && window.AbortController && navigator.credentials) {
        credential = new AbortController();
        navigator.credentials.get({otp: {transport: ['sms']}, signal: credential.signal}).then(function (result) {
            if (active && !request && !navigating && result && typeof result.code === 'string'
                && (!input.value || input.value.length < 6)
                && /^[0-9]{6}$/.test(digits(result.code))) {
                input.value = digits(result.code);
                onCodeChange();
            }
        }).catch(function () { /* Manual entry remains available. */ });
    }
}());
