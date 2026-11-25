(function () {
    function $(id) { return document.getElementById(id); }

    var card = $('go');
    if (!card) return;
    var redirected = false;
    var inFlight = false;
    var pollTimer = null;
    var retry = $('vpnCheck');

    function stopPolling() {
        redirected = true;
        clearInterval(pollTimer);
        document.removeEventListener('visibilitychange', autoCheck);
    }

    function revealVpnWarning() {
        $('goTitle').textContent = 'انتقال امن به درگاه پرداخت';
        $('goLead').textContent = '';
        $('vpnBox').hidden = false;
        document.title = 'بررسی اتصال به درگاه | نوین‌پی';
    }

    function proceed() {
        if (redirected) return;
        stopPolling();
        $('vpnBox').hidden = true;
        $('goTitle').textContent = 'انتقال امن به درگاه پرداخت';
        $('goLead').textContent = 'لطفاً چند لحظه صبر کنید.';
        document.title = 'انتقال به درگاه پرداخت | نوین‌پی';
        retry.href = card.dataset.gatewayUrl;
        setTimeout(function () { $('fallback').hidden = false; }, 4000);
        window.location.replace(retry.href);
    }

    function indeterminate() {
        if (redirected) return;
        stopPolling();
        window.location.replace(card.dataset.indeterminateUrl);
    }

    function unavailable(status) {
        stopPolling();
        window.location.replace(status === 409 ? card.dataset.resultUrl : card.dataset.errorUrl);
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
            return serverIr === traceIr ? serverIr : null;
        });
    }

    function checkConnection(manual) {
        if (inFlight || redirected || document.hidden) return;
        inFlight = true;
        retry.setAttribute('aria-disabled', 'true');
        geo(manual ? 5000 : 4000).then(function (ir) {
            if (redirected) return;
            if (ir === true) proceed();
            else if (ir === false) revealVpnWarning();
            else indeterminate();
        }, indeterminate).finally(function () {
            inFlight = false;
            retry.removeAttribute('aria-disabled');
        });
    }

    function autoCheck() { checkConnection(false); }

    retry.addEventListener('click', function (event) {
        if (redirected) return;
        event.preventDefault();
        checkConnection(true);
    });
    pollTimer = setInterval(autoCheck, 4000);
    document.addEventListener('visibilitychange', autoCheck);
    if (card.dataset.verifySplit === '1') autoCheck();
})();
