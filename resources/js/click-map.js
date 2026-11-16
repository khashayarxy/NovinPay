// Fetch near the viewport; interaction always requires explicit customer intent.
export function initClickMap(root = document) {
    root.querySelectorAll('[data-map-preview]').forEach((container) => {
        const button = container.querySelector('[data-map-activate]');
        const overlay = container.querySelector('[data-map-placeholder]');
        const viewport = container.querySelector('[data-map-viewport]');
        const status = container.querySelector('[data-map-status]');
        if (!button || !overlay || !viewport || !status || container.dataset.mapBound) return;
        let url;
        try { url = new URL(container.dataset.mapUrl, location.href); } catch { return; }
        if (url.origin !== 'https://neshan.org' || !url.pathname.startsWith('/maps/iframe/places/')) return;
        container.dataset.mapBound = '1';
        button.hidden = false;
        let frame = null, ready = false, activated = false, keyboard = false, observer;
        const interact = () => {
            if (!ready || !activated) return;
            const focusMap = keyboard && document.activeElement === button;
            overlay.hidden = true;
            button.hidden = true;
            frame.inert = false;
            frame.tabIndex = 0;
            frame.removeAttribute('aria-hidden');
            container.dataset.mapActive = 'true';
            status.textContent = '';
            if (focusMap) frame.focus({preventScroll: true});
        };
        const load = () => {
            if (frame) return;
            observer?.disconnect();
            ready = false;
            container.dataset.mapState = 'loading';
            container.setAttribute('aria-busy', 'true');
            status.textContent = 'در حال بارگذاری نقشه…';
            button.disabled = activated;
            const current = document.createElement('iframe');
            frame = current;
            current.className = 'np-location__map np-map-frame';
            current.title = 'موقعیت فروشگاه نوین‌پی در نقشهٔ نشان';
            current.referrerPolicy = 'strict-origin-when-cross-origin';
            current.loading = 'lazy';
            current.tabIndex = -1;
            current.inert = true;
            current.setAttribute('aria-hidden', 'true');
            let pending = true;
            const failed = () => {
                if (!pending || frame !== current) return;
                pending = false;
                window.clearTimeout(timer);
                current.remove();
                frame = null;
                ready = false;
                container.dataset.mapState = 'error';
                container.setAttribute('aria-busy', 'false');
                button.disabled = false;
                button.textContent = 'تلاش دوباره';
                status.textContent = 'نقشه بارگذاری نشد. دوباره تلاش کنید.';
            };
            current.addEventListener('load', () => {
                if (!pending || frame !== current) return;
                pending = false;
                window.clearTimeout(timer);
                ready = true;
                container.dataset.mapState = 'ready';
                container.setAttribute('aria-busy', 'false');
                button.disabled = false;
                button.textContent = 'مشاهده در نقشه';
                status.textContent = '';
                // Cross-origin load cannot attest to the provider's contents.
                interact();
            }, {once: true});
            current.addEventListener('error', failed, {once: true});
            const timer = window.setTimeout(failed, 15000);
            current.src = url.href;
            viewport.insertBefore(current, overlay);
        };
        button.addEventListener('click', (event) => {
            activated = true;
            keyboard = event.detail === 0;
            if (ready) interact();
            else if (frame) button.disabled = true;
            else load();
        });
        if ('IntersectionObserver' in window) {
            observer = new window.IntersectionObserver((entries) => {
                if (entries.some(entry => entry.target === viewport && entry.isIntersecting)) load();
            }, {rootMargin: '200px 0px', threshold: 0});
            observer.observe(viewport);
        }
        // Older browsers retain click-to-load without eager third-party traffic.
    });
}
