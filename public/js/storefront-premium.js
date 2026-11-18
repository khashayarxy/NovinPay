// Page-scoped enhancement. Routes, authentication and cart validation stay in Laravel.
(() => {
    if (!document.body.classList.contains('premium-storefront')) return;
    const body = document.body;
    const root = document.documentElement;
    const triggers = [...document.querySelectorAll('[data-storefront-open]')];
    const layers = new Map();
    const openers = new WeakMap();
    let active = null;
    let locked = null;
    let closing = false;
    const pending = new Map();
    let cartVersion = 0;
    let accountVersion = 0;

    function lock() {
        const style = body.style;
        locked = {x: scrollX, y: scrollY, rootOverflow: root.style.overflow,
            body: {position: style.position, top: style.top, left: style.left, width: style.width, overflow: style.overflow}};
        root.style.overflow = 'hidden';
        Object.assign(style, {position: 'fixed', top: `${-locked.y}px`, left: `${-locked.x}px`, width: '100%', overflow: 'hidden'});
        root.classList.add('storefront-overlay-open');
        body.classList.add('storefront-overlay-open');
    }

    function deactivate() {
        const previous = active;
        active = null;
        if (previous) layers.get(previous.id)?.hide?.();
        previous?.close();
        pending.forEach(controller => controller.abort());
        pending.clear();
        return previous;
    }

    function finish(restoreFocus = true) {
        const previous = deactivate();
        closing = false;
        root.classList.remove('storefront-overlay-open');
        body.classList.remove('storefront-overlay-open', 'keyboard-open');
        triggers.forEach(trigger => trigger.setAttribute('aria-expanded', 'false'));
        if (locked) {
            const saved = locked;
            locked = null;
            Object.assign(body.style, saved.body);
            root.style.overflow = saved.rootOverflow;
            scrollTo({left: saved.x, top: saved.y, behavior: 'instant'});
        }
        document.dispatchEvent(new Event('storefront-overlay:closed'));
        if (restoreFocus && previous) openers.get(previous)?.focus({preventScroll: true});
    }

    function close() {
        if (!active || closing) return;
        if (history.state?.storefrontOverlay) {
            closing = true;
            history.back();
        } else finish();
    }

    async function readPage(url, key) {
        pending.get(key)?.abort();
        const target = new URL(url, location.href);
        if (target.origin !== location.origin) throw new Error('Cross-origin panel');
        const controller = new AbortController();
        pending.set(key, controller);
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch(target, {credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                signal: controller.signal, headers: {Accept: 'text/html'}});
            if (!response.ok || !response.headers.get('Content-Type')?.includes('text/html')) throw new Error('Panel unavailable');
            const document = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (controller.signal.aborted) throw new Error('Panel cancelled');
            return document;
        } finally {
            clearTimeout(timeout);
            if (pending.get(key) === controller) pending.delete(key);
        }
    }

    function prepareAccount(container) {
        const entry = container.querySelector('[data-customer-entry]');
        if (!entry) return;
        const authenticated = entry.dataset.customerState === 'authenticated';
        const menu = entry.querySelector('details');
        if (menu) menu.open = true;
        entry.classList.add('is-panel-ready');
        container.closest('dialog').querySelectorAll('[data-account-guest-copy]').forEach(element => { element.hidden = authenticated; });
    }

    async function refreshAccount(dialog) {
        const version = ++accountVersion;
        const container = dialog.querySelector('[data-storefront-account-content]');
        prepareAccount(container);
        try {
            const document = await readPage(dialog.dataset.accountUrl, 'account');
            const next = document.querySelector('[data-customer-entry]');
            if (active !== dialog || version !== accountVersion || !next || !['guest', 'authenticated'].includes(next.dataset.customerState)) return;
            const restoreFocus = container.contains(window.document.activeElement);
            const focusedHref = window.document.activeElement?.getAttribute('href');
            container.replaceChildren(next);
            prepareAccount(container);
            if (restoreFocus) {
                const matching = [...container.querySelectorAll('a[href]')].find(link => link.getAttribute('href') === focusedHref);
                (matching || dialog.querySelector('[data-storefront-close]')).focus({preventScroll: true});
            }
        } catch {
            // Preserve the server-rendered session view on a transient failure.
        }
    }

    async function refreshCart(dialog) {
        const version = ++cartVersion;
        const container = dialog.querySelector('[data-live-cart]');
        const footer = dialog.querySelector('[data-cart-footer]');
        const retry = dialog.querySelector('[data-cart-retry]');
        const hadRetryFocus = document.activeElement === retry;
        if (hadRetryFocus) dialog.querySelector('[data-storefront-close]')?.focus();
        footer.hidden = true;
        retry.hidden = true;
        container.replaceChildren(Object.assign(document.createElement('p'), {textContent: 'در حال دریافت سبد خرید…'}));
        container.setAttribute('aria-busy', 'true');
        try {
            const document = await readPage(dialog.dataset.cartUrl, 'cart');
            const content = document.querySelector('[data-storefront-cart]');
            if (!content) throw new Error('Cart content unavailable');
            if (active !== dialog || version !== cartVersion) return;
            if (content.dataset.cartEmpty === 'true') {
                container.replaceChildren(dialog.querySelector('[data-empty-cart]').content.cloneNode(true));
            } else {
                content.querySelector('h1')?.remove();
                content.classList.add('cart-sheet-content');
                container.replaceChildren(content);
                footer.hidden = false;
            }
        } catch {
            if (active === dialog && version === cartVersion) {
                container.replaceChildren(Object.assign(document.createElement('p'), {
                textContent: 'دریافت سبد خرید انجام نشد. صفحهٔ سبد خرید را باز کنید یا دوباره تلاش کنید.',
                }));
            footer.hidden = false;
            retry.hidden = false;
            }
        } finally {
            if (active === dialog && version === cartVersion) container.removeAttribute('aria-busy');
        }
    }

    function activate(dialog, focus) {
        if (!locked) lock();
        active = dialog;
        triggers.forEach(element => element.setAttribute('aria-expanded', String(element.dataset.storefrontOpen === dialog.id)));
        dialog.showModal();
        layers.get(dialog.id)?.show?.();
        const target = focus || dialog.querySelector('input:not([type="hidden"]), iframe') || dialog.querySelector('[data-storefront-close]');
        target?.focus({preventScroll: true});
        if (dialog.dataset.cartUrl) refreshCart(dialog);
        if (dialog.dataset.accountUrl) refreshAccount(dialog);
    }

    function open(dialog, trigger) {
        if (!dialog || dialog === active || closing || !layers.has(dialog.id)) return;
        if (active && !active.contains(trigger)) return;
        openers.set(dialog, trigger);
        history.pushState({...history.state, storefrontOverlay: dialog.id}, '', '#overlay-' + dialog.id);
        deactivate();
        activate(dialog);
    }

    function restoreLayer() {
        closing = false;
        const id = history.state?.storefrontOverlay;
        const next = location.hash === '#overlay-' + id ? layers.get(id)?.dialog ?? null : null;
        if (next === active) return;
        if (!next) {
            finish();
            return;
        }
        const previous = deactivate();
        const trigger = previous && openers.get(previous);
        activate(next, trigger && next.contains(trigger) ? trigger : null);
    }

    window.StorefrontOverlays = {open, close, register};
    triggers.forEach(trigger => {
        trigger.hidden = false;
        trigger.setAttribute('aria-controls', trigger.dataset.storefrontOpen);
        trigger.setAttribute('aria-expanded', 'false');
        trigger.addEventListener('click', event => {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const dialog = document.getElementById(trigger.dataset.storefrontOpen);
            if (!dialog || typeof dialog.showModal !== 'function') return;
            event.preventDefault();
            open(dialog, trigger);
        });
    });
    function register(dialog, callbacks = {}) {
        const registered = layers.get(dialog.id);
        if (registered?.dialog === dialog) {
            Object.assign(registered, callbacks);
            return;
        }
        layers.set(dialog.id, {dialog, ...callbacks});
        dialog.querySelector('[data-cart-retry]')?.addEventListener('click', () => refreshCart(dialog));
        dialog.querySelectorAll('[data-storefront-close]').forEach(button => button.addEventListener('click', close));
        dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
        dialog.addEventListener('close', () => { if (active === dialog && !dialog.open) close(); });
        let outside = false;
        const isOutside = event => {
            if (event.target !== dialog) return false;
            const surface = layers.get(dialog.id).surface ?? dialog;
            const bounds = surface.getBoundingClientRect();
            return event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom;
        };
        dialog.addEventListener('pointerdown', event => { outside = isOutside(event); });
        dialog.addEventListener('click', event => { if (event.detail && outside && isOutside(event)) close(); outside = false; });
        dialog.addEventListener('keydown', event => {
            if (event.defaultPrevented || event.isComposing) return;
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); return; }
            if (event.key !== 'Tab') return;
            const controls = [...dialog.querySelectorAll('a[href], button, input, select, textarea, summary, iframe, [tabindex]')]
                .filter(element => element.tabIndex >= 0 && !element.matches(':disabled') && !element.closest('[inert]')
                    && element.getClientRects().length && getComputedStyle(element).visibility === 'visible');
            const first = controls[0], last = controls.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        });
    }
    document.querySelectorAll('[data-storefront-dialog]').forEach(dialog => register(dialog));
    window.addEventListener('popstate', restoreLayer);
    window.addEventListener('pagehide', () => finish(false));
    window.addEventListener('pageshow', restoreLayer);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && active?.dataset.accountUrl) refreshAccount(active);
    });
    const purchaseColumn = document.querySelector('[data-purchase-column]');
    function fitPurchaseColumn() {
        purchaseColumn?.toggleAttribute('data-too-tall', purchaseColumn.offsetHeight > innerHeight - 32);
    }
    if (purchaseColumn && 'ResizeObserver' in window) {
        new ResizeObserver(fitPurchaseColumn).observe(purchaseColumn);
    }
    function resize() {
        fitPurchaseColumn();
        const viewport = window.visualViewport;
        const settledScale = viewport && Math.abs(viewport.scale - 1) < .01;
        const height = settledScale ? viewport.height : innerHeight;
        const top = settledScale ? Math.max(0, viewport.offsetTop) : 0;
        const bottom = Math.max(0, innerHeight - top - height);
        root.style.setProperty('--storefront-overlay-height', `${height}px`);
        root.style.setProperty('--storefront-overlay-top', `${top}px`);
        root.style.setProperty('--storefront-overlay-bottom', `${bottom}px`);
        const editing = document.activeElement?.matches('input,textarea,select,[contenteditable="true"]');
        body.classList.toggle('keyboard-open', !!editing && innerHeight - height > 140);
    }
    window.visualViewport?.addEventListener('resize', resize, {passive: true});
    window.visualViewport?.addEventListener('scroll', resize, {passive: true});
    window.addEventListener('resize', resize, {passive: true});
    document.addEventListener('focusin', resize);
    document.addEventListener('focusout', () => requestAnimationFrame(resize));
    resize();

    const contact = document.querySelector('.sticky-contact');
    const action = document.querySelector('[data-product-primary-action]');
    const clearTargets = [...document.querySelectorAll([
        '.purchase :is(.price bdi, .price small, .stock strong, time, .product-cart-form, a.primary)',
        '.gallery-arrow, .gallery-zoom-mark, .image-count, .thumbs button',
        '.section-nav a, .key-mobile .text-link, .product-faq summary, .product-feedback :is(.btn, form)',
    ].join(', '))];
    const mobile = matchMedia('(max-width: 700px)');
    let frame = 0;
    function render() {
        frame = 0;
        if (active) return;
        if (contact && action) contact.hidden = action.getBoundingClientRect().bottom > 0;
        body.classList.toggle('floating-has-contact', !!contact && !contact.hidden);
        const launcher = mobile.matches && document.querySelector('[data-support-launcher]');
        const bounds = launcher && launcher.getBoundingClientRect();
        body.classList.toggle('floating-support-obstructs', !!bounds?.width && clearTargets.some(target => {
            const rect = target.getBoundingClientRect();
            return rect.width && rect.height && rect.left < bounds.right + 4 && rect.right > bounds.left - 4
                && rect.top < bounds.bottom + 4 && rect.bottom > bounds.top - 4;
        }));
    }
    function schedule() { if (!frame) frame = requestAnimationFrame(render); }
    window.addEventListener('scroll', schedule, {passive: true});
    window.addEventListener('resize', schedule, {passive: true});
    window.addEventListener('load', schedule, {once: true});
    document.addEventListener('storefront-overlay:closed', schedule);
    render();
})();
