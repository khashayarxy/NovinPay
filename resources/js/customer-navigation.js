export function initCustomerNavigation() {
    const host = document.querySelector('[data-customer-header]');
    if (!host || host.dataset.bound) return;
    host.dataset.bound = 'true';

    const guest = host.querySelector('[data-customer-guest]');
    const mobile = window.matchMedia('(max-width: 850px)');
    const header = host.closest('[data-mobile-header]');
    let pending = null;
    let menu = null;

    const close = (restoreFocus = false) => {
        if (!menu) return;
        menu.open = false;
        menu.querySelector('summary').setAttribute('aria-expanded', 'false');
        if (restoreFocus) menu.querySelector('summary').focus({ preventScroll: true });
    };

    const reset = () => {
        close();
        menu?.remove();
        menu = null;
        guest.hidden = false;
    };

    const refresh = async () => {
        if (!mobile.matches || pending) return;
        reset();
        const controller = new AbortController();
        pending = controller;
        const timeout = window.setTimeout(() => controller.abort(), 8000);
        try {
            const url = new URL(host.dataset.sessionUrl, location.href);
            if (url.origin !== location.origin) return;
            const response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'manual',
                signal: controller.signal,
                headers: { Accept: 'text/html' },
            });
            if (!response.ok || !mobile.matches || controller.signal.aborted
                || !response.headers.get('Content-Type')?.includes('text/html')) return;
            const markup = document.createElement('template');
            markup.innerHTML = await response.text();
            if (controller.signal.aborted) return;
            const nextMenu = markup.content.querySelector('details[data-customer-menu]');
            if (!nextMenu) return;
            menu = nextMenu;
            menu.open = false;
            const toggle = menu.querySelector('summary');
            toggle.setAttribute('aria-expanded', 'false');
            menu.addEventListener('toggle', () => {
                toggle.setAttribute('aria-expanded', String(nextMenu.open));
                if (nextMenu.open) {
                    const navigation = header?.querySelector('.np-mobile-menu');
                    if (navigation) navigation.open = false;
                }
            });
            menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => close()));
            menu.querySelector('form')?.addEventListener('submit', (event) => {
                const form = event.currentTarget;
                if (form.dataset.submitting) {
                    event.preventDefault();
                    return;
                }
                form.dataset.submitting = 'true';
                form.querySelector('button').disabled = true;
            });
            guest.hidden = true;
            host.append(menu);
        } catch {
            // Keep the ordinary login link usable if the private session lookup fails.
        } finally {
            window.clearTimeout(timeout);
            if (pending === controller) pending = null;
        }
    };

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu?.open) {
            event.preventDefault();
            close(true);
        }
    });
    document.addEventListener('pointerdown', event => {
        if (!host.contains(event.target)) close();
    });
    document.addEventListener('focusin', event => {
        if (!host.contains(event.target)) close();
    });
    header?.querySelector('.np-mobile-menu')?.addEventListener('toggle', (event) => {
        if (event.currentTarget.open) close();
    });
    mobile.addEventListener('change', () => {
        if (mobile.matches) refresh();
        else {
            pending?.abort();
            reset();
        }
    });
    window.addEventListener('pagehide', () => {
        pending?.abort();
        pending = null;
        reset();
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) refresh();
    });
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') refresh();
    });
    refresh();
}
