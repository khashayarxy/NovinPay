// Shared storefront enhancement. Routes, authentication and cart validation stay in Laravel.
(() => {
    if (typeof HTMLDialogElement === 'undefined' || typeof HTMLDialogElement.prototype.showModal !== 'function') return;
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
    let cartPending = false;
    const cartDialog = document.getElementById('cart-dialog');
    const cartNumber = new Intl.NumberFormat('fa-IR', {useGrouping: false});

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
        const summary = dialog.querySelector('[data-cart-summary]');
        summary.replaceChildren();
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
                const total = content.querySelector('[data-store-cart-total]');
                if (total) summary.replaceChildren(total);
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

    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!cartDialog || !form.matches('form[data-cart-mutation]')) return;
        if (form.closest('[data-storefront-cart]') && !cartDialog.contains(form)) return;
        const endpoint = new URL(form.action, location.href);
        if (endpoint.origin !== location.origin) return;
        event.preventDefault();
        if (cartPending) return;
        const fromDrawer = cartDialog.contains(form);
        const submitter = event.submitter || form.querySelector('button[type="submit"]');
        const error = cartDialog.querySelector('[data-cart-error]');
        const data = new FormData(form);
        error.hidden = true;
        cartPending = true;
        form.setAttribute('aria-busy', 'true');
        const previouslyDisabled = submitter?.disabled;
        if (submitter) submitter.disabled = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(endpoint, {method: 'POST', body: data,
                credentials: 'same-origin', redirect: 'error', signal: controller.signal,
                headers: {Accept: 'application/json'}});
            if (!response.ok) {
                const message = response.status === 409 ? 'موجودی برای این درخواست کافی نیست.'
                    : response.status === 422 ? 'تعداد را بین ۱ تا ۱۰ انتخاب کنید.'
                    : 'تغییر سبد انجام نشد. وضعیت سبد را بررسی کنید.';
                throw new Error(message);
            }
            const {count} = await response.json();
            if (!Number.isInteger(count) || count < 0) throw new Error();
            document.querySelectorAll('[data-store-cart-count]').forEach(badge => {
                badge.hidden = count === 0;
                badge.querySelector('bdi').textContent = cartNumber.format(count);
            });
            if (active === cartDialog) {
                const focusInCart = cartDialog.querySelector('[data-live-cart]').contains(document.activeElement);
                if (focusInCart || fromDrawer) cartDialog.querySelector('[data-storefront-close]')?.focus();
                await refreshCart(cartDialog);
            } else if (!active && !fromDrawer) {
                open(cartDialog, submitter);
            }
        } catch (failure) {
            error.textContent = failure.message && /[\u0600-\u06ff]/.test(failure.message) ? failure.message
                : 'نتیجهٔ درخواست مشخص نیست. پیش از تکرار، وضعیت سبد را بررسی کنید.';
            error.hidden = false;
            if (!active && !fromDrawer) open(cartDialog, submitter);
            else if (active === cartDialog && controller.signal.aborted) await refreshCart(cartDialog);
        } finally {
            clearTimeout(timeout);
            cartPending = false;
            form.removeAttribute('aria-busy');
            if (submitter) submitter.disabled = previouslyDisabled;
        }
    });

    function activate(dialog, focus) {
        if (!locked) lock();
        active = dialog;
        triggers.forEach(element => element.setAttribute('aria-expanded', String(element.dataset.storefrontOpen === dialog.id)));
        dialog.showModal();
        layers.get(dialog.id)?.show?.();
        const target = focus || (!dialog.dataset.cartUrl && dialog.querySelector('input:not([type="hidden"]), iframe')) || dialog.querySelector('[data-storefront-close]');
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

    document.querySelector('.premium-storefront [data-customer-header]')?.addEventListener('click', event => {
        const trigger = event.target.closest('[data-customer-entry] [data-customer-guest], [data-customer-entry] summary');
        const dialog = document.getElementById('account-dialog');
        if (!trigger || !dialog || !matchMedia('(max-width: 700px)').matches
            || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        open(dialog, trigger);
    });
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
    const filters = document.querySelector('[data-catalog-filters]');
    if (filters) {
        const dialog = document.getElementById('catalog-filter-dialog');
        const sidebar = filters.parentElement;
        const mobileFilters = matchMedia('(max-width: 850px)');
        function placeFilters() {
            if (!mobileFilters.matches && active === dialog) close();
            (mobileFilters.matches ? dialog.querySelector('[data-filter-slot]') : sidebar).append(filters);
            sidebar.hidden = mobileFilters.matches;
        }
        mobileFilters.addEventListener('change', placeFilters);
        placeFilters();
        document.body.classList.add('catalog-filter-ready');
        const sort = document.querySelector('[data-catalog-sort]');
        sort?.addEventListener('change', () => sort.form.requestSubmit());
    }
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

    const contact = document.querySelector('[data-product-dock]');
    const action = document.querySelector('[data-product-primary-action]');
    const productContent = contact?.closest('main')?.querySelector('.storefront-content');
    const productHeader = contact && document.querySelector('.np-header');
    const navigation = contact && document.querySelector('[data-mobile-bottom-nav]');
    const clearTargets = [...document.querySelectorAll([
        '.purchase :is(.price bdi, .price small, .stock strong, time, .product-cart-form, a.primary)',
        '.gallery-arrow, .gallery-zoom-mark, .image-count, .thumbs button',
        '.section-nav a, .key-mobile .text-link, .product-faq summary, .product-feedback :is(.btn, form)',
        '.catalog-toolbar :is(button, select), .public-product-card a:not([tabindex="-1"]), .repair-entry :is(a, button)',
    ].join(', '))];
    const mobile = matchMedia('(max-width: 700px)');
    let frame = 0;
    function render() {
        frame = 0;
        if (active) return;
        if (contact && action && productContent) {
            const headerEdge = productHeader?.getBoundingClientRect().bottom ?? 0;
            const navEdge = navigation?.getBoundingClientRect().top ?? innerHeight;
            const show = mobile.matches && action.getClientRects().length > 0
                && action.getBoundingClientRect().bottom <= headerEdge
                && productContent.getBoundingClientRect().bottom > navEdge;
            contact.hidden = !show;
        }
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
    if (contact && 'ResizeObserver' in window) {
        new ResizeObserver(([entry]) => {
            const height = entry.borderBoxSize?.[0]?.blockSize ?? contact.offsetHeight;
            if (height) root.style.setProperty('--store-purchase-height', `${height}px`);
            schedule();
        }).observe(contact, {box: 'border-box'});
    }
    render();
})();
