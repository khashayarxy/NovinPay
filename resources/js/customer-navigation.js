export function initCustomerNavigation() {
    const host = document.querySelector('[data-customer-header]');
    if (!host || host.dataset.bound) return;
    host.dataset.bound = 'true';

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

    const bindMenu = () => {
        menu = host.querySelector('[data-customer-menu]');
        if (!menu || menu.dataset.bound) return;
        menu.dataset.bound = 'true';
        const current = menu;
        const toggle = current.querySelector('summary');
        close();
        current.addEventListener('toggle', () => {
            toggle.setAttribute('aria-expanded', String(current.open));
            if (current.open) {
                const navigation = header?.querySelector('.np-mobile-menu');
                if (navigation) navigation.open = false;
            }
        });
        current.querySelectorAll('a').forEach(link => link.addEventListener('click', () => close()));
        current.querySelector('form')?.addEventListener('submit', (event) => {
            const form = event.currentTarget;
            if (form.dataset.submitting) {
                event.preventDefault();
                return;
            }
            form.dataset.submitting = 'true';
            form.querySelector('button').disabled = true;
        });
    };

    const refresh = async () => {
        if (!mobile.matches || pending) return;
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
            const next = markup.content.querySelector('[data-customer-entry]');
            const current = host.querySelector('[data-customer-entry]');
            if (!next || !current || !['authenticated', 'guest'].includes(next.dataset.customerState)) return;
            if (next.dataset.customerState === current.dataset.customerState) {
                // Another tab may rotate CSRF without changing the signed-in state.
                // Keep the visible UI and update only the token in the existing form.
                const token = current.querySelector('input[name="_token"]');
                const freshToken = next.querySelector('input[name="_token"]');
                if (token && freshToken) token.value = freshToken.value;
                return;
            }
            const restoreFocus = host.contains(document.activeElement);
            close();
            current.replaceWith(next);
            bindMenu();
            if (restoreFocus) host.querySelector('summary, a')?.focus({ preventScroll: true });
        } catch {
            // A transient lookup failure must not replace the server-rendered state.
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
        close();
        if (mobile.matches) refresh();
        else pending?.abort();
    });
    window.addEventListener('pagehide', () => {
        pending?.abort();
        pending = null;
        close();
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) refresh();
    });
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') refresh();
    });
    bindMenu();
}
