(function () {
    function $(id) { return document.getElementById(id); }

    var card = $('go');
    var redirected = false;
    var inFlight = false;
    var pollTimer = null;

    function stopPolling() {
        redirected = true;
        clearInterval(pollTimer);
        document.removeEventListener('visibilitychange', autoCheck);
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

    function geo(timeoutMs) {
        var ctl = new AbortController();
        var timer = setTimeout(function () { ctl.abort(); }, timeoutMs);
        return fetch(card.dataset.checkUrl, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            redirect: 'error',
            signal: ctl.signal
        }).then(function (response) {
            if (response.status === 404 || response.status === 409) {
                unavailable(response.status);
                return null;
            }
            if (!response.ok) throw new Error('Connectivity check failed');
            return response.json().then(function (data) {
                if (!data || (data.ir !== null && typeof data.ir !== 'boolean')) throw new Error('Invalid connectivity result');
                return data.ir;
            });
        }).finally(function () { clearTimeout(timer); });
    }

    function autoCheck() {
        if (inFlight || redirected || document.hidden) return;
        inFlight = true;
        geo(4000).then(function (ir) {
            if (ir === true) proceed();
        }, function () {}).then(function () { inFlight = false; });
    }

    function showError(text) {
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

    pollTimer = setInterval(autoCheck, 4000);
    document.addEventListener('visibilitychange', autoCheck);
})();
