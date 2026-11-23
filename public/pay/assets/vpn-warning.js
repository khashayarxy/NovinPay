(function () {
    function $(id) { return document.getElementById(id); }

    var card = $('go');
    if (!card) return;
    var redirected = false;
    var inFlight = false;
    var pollTimer = null;

    function stopPolling() {
        redirected = true;
        clearInterval(pollTimer);
        document.removeEventListener('visibilitychange', autoCheck);
    }

    function revealVpnWarning() {
        $('goTitle').textContent = 'انتقال به درگاه پرداخت';
        $('goLead').textContent = 'درگاه پرداخت فقط از داخل ایران در دسترس است.';
        $('goBar').hidden = true;
        $('goHint').hidden = true;
        $('vpnBox').hidden = false;
        $('vpnCheck').hidden = false;
        document.title = 'وی‌پی‌ان را خاموش کنید | نوین‌پی';
    }

    function proceed() {
        if (redirected) return;
        stopPolling();
        ['vpnBox', 'vpnErr', 'vpnCheck'].forEach(function (id) { $(id).hidden = true; });
        $('goTitle').textContent = 'در حال انتقال به درگاه پرداخت';
        $('goLead').textContent = 'لطفاً چند لحظه صبر کنید.';
        $('goBar').hidden = false;
        $('goHint').hidden = false;
        document.title = 'انتقال به درگاه پرداخت | نوین‌پی';
        setTimeout(function () { $('fallback').hidden = false; }, 4000);
        window.location.replace($('fallbackLink').href);
    }

    function unavailable(status) {
        stopPolling();
        if (status === 409) {
            window.location.replace(card.dataset.resultUrl);
            return;
        }
        document.body.className = 't-rose';
        card.hidden = true;
        $('bad').hidden = false;
        document.title = 'لینک پرداخت نامعتبر | نوین‌پی';
    }

    function probeSplitTunnelVpn(timeoutMs) {
        var ctl = new AbortController();
        var timer = setTimeout(function () { ctl.abort(); }, Math.min(timeoutMs, 2400));
        return fetch('https://www.cloudflare.com/cdn-cgi/trace', {
            cache: 'no-store',
            mode: 'cors',
            credentials: 'omit',
            signal: ctl.signal
        }).then(function (r) {
            if (!r.ok) return null;
            return r.text();
        }).then(function (txt) {
            if (typeof txt !== 'string') return null;
            var m = txt.match(/(?:^|\n)loc=([A-Z]{2})(?:\r|\n|$)/);
            if (!m) return null;
            return m[1] === 'IR';
        }).catch(function () {
            return null;
        }).finally(function () {
            clearTimeout(timer);
        });
    }

    function geo(timeoutMs) {
        var ctl = new AbortController();
        var timer = setTimeout(function () { ctl.abort(); }, timeoutMs);
        var serverCheck = fetch(card.dataset.checkUrl, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            redirect: 'error',
            signal: ctl.signal
        }).then(function (response) {
            if (response.status === 404 || response.status === 409) {
                unavailable(response.status);
                return 'UNAVAILABLE';
            }
            if (!response.ok) throw new Error('Connectivity check failed');
            return response.json().then(function (data) {
                if (!data || (data.ir !== null && typeof data.ir !== 'boolean')) throw new Error('Invalid connectivity result');
                return data.ir;
            });
        }).finally(function () { clearTimeout(timer); });

        return Promise.all([serverCheck, probeSplitTunnelVpn(timeoutMs)]).then(function (results) {
            var serverIr = results[0];
            var traceIr = results[1];
            if (serverIr === 'UNAVAILABLE') return null;
            if (serverIr === false || traceIr === false) return false;
            if (serverIr === true || traceIr === true) return true;
            return null;
        });
    }

    function autoCheck() {
        if (inFlight || redirected || document.hidden) return;
        inFlight = true;
        geo(4000).then(function (ir) {
            if (ir === true) proceed();
            else if (ir === false && card.dataset.verifySplit === '1') {
                card.dataset.verifySplit = '0';
                revealVpnWarning();
            }
        }, function () {}).then(function () { inFlight = false; });
    }

    function showError(text) {
        revealVpnWarning();
        $('vpnErrText').textContent = text;
        $('vpnCheck').textContent = 'تلاش مجدد';
        function reveal() {
            $('vpnBox').hidden = true;
            $('vpnErr').hidden = true;
            void $('vpnErr').offsetWidth;
            $('vpnErr').hidden = false;
        }
        var box = $('vpnBox');
        if (!box.hidden) {
            box.style.transition = 'opacity .25s ease';
            box.style.opacity = '0';
            setTimeout(reveal, 250);
        } else {
            reveal();
        }
    }

    $('vpnCheck').addEventListener('click', function () {
        if (inFlight || redirected) return;
        inFlight = true;
        var btn = this;
        btn.disabled = true;
        geo(5000).then(function (ir) {
            if (redirected) return;
            if (ir !== false) { proceed(); return; }
            showError('همچنان وی‌پی‌ان روشن است. مطمئن شوید که وی‌پی‌ان خاموش است و روی دکمه‌ی «تلاش مجدد» بزنید.');
        }, function () {
            showError('ارتباط برقرار نشد. اینترنت خود را بررسی کنید و روی دکمه‌ی «تلاش مجدد» بزنید.');
        }).then(function () {
            inFlight = false;
            setTimeout(function () { btn.disabled = false; }, 1200);
        });
    });

    if (card.dataset.verifySplit === '1') {
        inFlight = true;
        geo(2600).then(function (ir) {
            if (redirected) return;
            if (ir === false) {
                card.dataset.verifySplit = '0';
                revealVpnWarning();
            } else {
                proceed();
            }
        }, function () {
            proceed();
        }).then(function () {
            inFlight = false;
        });
    }

    pollTimer = setInterval(autoCheck, 4000);
    document.addEventListener('visibilitychange', autoCheck);
})();
