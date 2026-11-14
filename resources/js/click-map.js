// Keep the approved provider embed entirely out of the network until explicit intent.
export function initClickMap(root = document) {
    root.querySelectorAll('[data-map-preview]').forEach((container) => {
        const button = container.querySelector('[data-map-activate]');
        const placeholder = container.querySelector('[data-map-placeholder]');
        const status = container.querySelector('[data-map-status]');
        if (!button || !placeholder || !status || container.dataset.mapBound) return;
        let url;
        try { url = new URL(container.dataset.mapUrl, location.href); } catch { return; }
        if (url.origin !== 'https://neshan.org' || !url.pathname.startsWith('/maps/iframe/places/')) return;
        container.dataset.mapBound = '1';
        button.hidden = false;
        button.addEventListener('click', () => {
            if (container.querySelector('iframe')) return;
            button.disabled = true;
            status.textContent = 'در حال بارگذاری نقشه…';
            const frame = document.createElement('iframe');
            frame.className = 'np-location__map';
            frame.title = 'موقعیت نوین‌پی در نقشهٔ نشان';
            frame.referrerPolicy = 'strict-origin-when-cross-origin';
            let timer;
            let active = true;
            const failed = () => {
                if (!active) return;
                active = false;
                window.clearTimeout(timer);
                frame.remove();
                placeholder.hidden = false;
                button.disabled = false;
                status.textContent = 'نقشه بارگذاری نشد. دوباره تلاش کنید یا لینک نشان را باز کنید.';
            };
            // Cross-origin load cannot prove the provider rendered its map. The
            // external link stays available even after a successful frame load.
            frame.addEventListener('load', () => {
                if (!active) return;
                window.clearTimeout(timer);
                status.textContent = 'اگر نقشه نمایش داده نشد، لینک نشان را باز کنید.';
            }, {once: true});
            frame.addEventListener('error', failed, {once: true});
            timer = window.setTimeout(failed, 15000);
            frame.src = url.href;
            placeholder.hidden = true;
            container.insertBefore(frame, status);
        });
    });
}
