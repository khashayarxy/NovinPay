export function initMobileNavigation() {
    const header = document.querySelector('[data-mobile-header]');
    const dialog = header?.querySelector('.np-mobile-drawer');
    const toggle = header?.querySelector('[data-navigation-toggle]');
    if (!dialog || !toggle || dialog.dataset.bound) return;
    dialog.dataset.bound = 'true';
    const mobile = window.matchMedia('(max-width: 850px)');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    const scrollOwner = Symbol('mobile navigation');
    const shared = window.StorefrontOverlays;
    if (shared) {
        const closeButton = dialog.querySelector('[data-navigation-close]');
        closeButton.setAttribute('data-storefront-close', '');
        shared.register(dialog, {
            surface: dialog.querySelector('.np-mobile-drawer__panel'),
            show: () => toggle.setAttribute('aria-expanded', 'true'),
            hide: () => {
                dialog.classList.remove('is-closing');
                toggle.setAttribute('aria-expanded', 'false');
            },
            beforeClose: () => {
                if (reduced.matches) return;
                dialog.classList.add('is-closing');
                return new Promise(resolve => setTimeout(resolve, 180));
            },
        });
        toggle.addEventListener('click', () => {
            if (!mobile.matches) return;
            header.dispatchEvent(new Event('mobile-navigation:open'));
            shared.open(dialog, toggle);
        });
        mobile.addEventListener('change', () => { if (!mobile.matches && dialog.open) shared.close(); });
        return;
    }
    let locked = null;
    let closing = null;
    const unlock = () => {
        window.StorefrontScrollLock?.release(scrollOwner);
        if (!locked) return;
        const {x, y, body, root} = locked;
        for (const [key, value] of Object.entries(body)) document.body.style[key] = value;
        document.documentElement.style.overflow = root;
        locked = null;
        window.scrollTo({left: x, top: y, behavior: 'instant'});
    };
    const finishClose = (restoreFocus) => {
        window.clearTimeout(closing);
        closing = null;
        dialog.classList.remove('is-closing');
        if (dialog.open) dialog.close();
        toggle.setAttribute('aria-expanded', 'false');
        unlock();
        if (restoreFocus && mobile.matches) toggle.focus({preventScroll: true});
    };
    const close = (restoreFocus = true, immediately = false) => {
        if (!dialog.open || closing !== null) return;
        if (immediately || reduced.matches) return finishClose(restoreFocus);
        dialog.classList.add('is-closing');
        closing = window.setTimeout(() => finishClose(restoreFocus), 180);
    };
    toggle.addEventListener('click', () => {
        if (!mobile.matches || dialog.open) return;
        header.dispatchEvent(new Event('mobile-navigation:open'));
        if (window.StorefrontScrollLock) {
            window.StorefrontScrollLock.acquire(scrollOwner);
        } else {
            const root = document.documentElement;
            locked = {x: scrollX, y: scrollY, root: root.style.overflow, body: {}};
            root.style.overflow = 'hidden';
        }
        dialog.showModal();
        toggle.setAttribute('aria-expanded', 'true');
    });
    dialog.querySelector('[data-navigation-close]').addEventListener('click', () => close());
    // Dismiss only a complete backdrop gesture, not a drag from inside the panel.
    let outside = false;
    dialog.addEventListener('pointerdown', event => { outside = event.target === dialog; });
    dialog.addEventListener('click', event => {
        if (outside && event.target === dialog) close();
        outside = false;
    });
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    dialog.addEventListener('keydown', event => {
        if (event.key !== 'Tab') return;
        const items = [...dialog.querySelectorAll('button:not(:disabled), a[href]')].filter(item => item.getClientRects().length);
        const first = items[0], last = items.at(-1);
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first?.focus();
        }
    });
    dialog.addEventListener('close', () => {
        // A queued close event may belong to a modal already reopened by a new action.
        if (dialog.open) return;
        window.clearTimeout(closing);
        closing = null;
        dialog.classList.remove('is-closing');
        toggle.setAttribute('aria-expanded', 'false');
        unlock();
    });
    dialog.querySelectorAll('a').forEach(link => link.addEventListener('click', () => finishClose(false)));
    header.addEventListener('customer-navigation:open', () => finishClose(false));
    mobile.addEventListener('change', () => { if (!mobile.matches) finishClose(false); });
    window.addEventListener('pagehide', () => finishClose(false));
    window.addEventListener('pageshow', event => { if (event.persisted) finishClose(false); });
}
