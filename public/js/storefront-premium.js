// Shared storefront enhancement. Routes, authentication and cart validation stay in Laravel.
(() => {
    if (typeof HTMLDialogElement === 'undefined' || typeof HTMLDialogElement.prototype.showModal !== 'function') return;
    const body = document.body;
    const root = document.documentElement;
    const triggers = [...document.querySelectorAll('[data-storefront-open]')];
    const layers = new Map();
    const openers = new WeakMap();
    let active = null;
    const scrollLockOwner = Symbol('storefront overlays');
    let closing = false;
    const pending = new Map();
    let cartVersion = 0;
    let cartRead = null;
    let cartEpoch = 0;
    let cartSnapshot = null;
    let cartPreloadPromise = null;
    const commerce = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel('novinpay-commerce') : null;
    let accountVersion = 0;
    let cartPending = false;
    const cartDialog = document.getElementById('cart-dialog');
    const cartNumber = new Intl.NumberFormat('fa-IR', {useGrouping: false});
    function renderCartCount(count) {
        if (!Number.isInteger(count) || count < 0) return;
        document.querySelectorAll('[data-store-cart-count]').forEach(badge => {
            badge.hidden = count === 0;
            badge.querySelector('bdi').textContent = cartNumber.format(count);
        });
    }

    function deactivate() {
        const previous = active;
        active = null;
        if (previous && previous === cartDialog) clearCart(cartDialog);
        if (previous) layers.get(previous.id)?.hide?.();
        previous?.close();
        pending.forEach((controller, key) => {
            if (key !== 'cart-preload') {
                controller.abort();
                pending.delete(key);
            }
        });
        return previous;
    }

    function finish(restoreFocus = true) {
        const previous = deactivate();
        closing = false;
        triggers.forEach(trigger => trigger.setAttribute('aria-expanded', 'false'));
        window.StorefrontScrollLock?.release(scrollLockOwner);
        document.dispatchEvent(new Event('storefront-overlay:closed'));
        if (restoreFocus && previous) openers.get(previous)?.focus({preventScroll: true});
    }

    async function close() {
        if (!active || closing) return;
        const current = active;
        closing = true;
        await layers.get(current.id)?.beforeClose?.();
        if (active !== current) return;
        if (history.state?.storefrontOverlay) {
            history.back();
            setTimeout(() => {
                if (closing && active === current) finish();
            }, 60);
        } else {
            finish();
        }
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

    function applyCartDocument(dialog, doc, epochAtFetch, html = null) {
        const content = doc.querySelector('[data-storefront-cart]');
        if (!content) throw new Error('Cart content unavailable');
        const container = dialog.querySelector('[data-live-cart]');
        const footer = dialog.querySelector('[data-cart-footer]');
        const summary = dialog.querySelector('[data-cart-summary]');
        const viewLink = dialog.querySelector('[data-cart-view-link]');
        const retry = dialog.querySelector('[data-cart-retry]');
        const status = dialog.querySelector('[data-cart-status]');
        if (content.dataset.cartCount !== undefined) renderCartCount(Number(content.dataset.cartCount));
        const isEmpty = content.dataset.cartEmpty === 'true';
        const contentWasEmpty = container.querySelector('.empty-cart');
        if (isEmpty) {
            if (!contentWasEmpty) container.replaceChildren(dialog.querySelector('[data-empty-cart]').content.cloneNode(true));
            summary.replaceChildren();
            if (viewLink) viewLink.hidden = true;
            footer.hidden = true;
        } else {
            content.querySelector('h1')?.remove();
            content.classList.add('cart-sheet-content');
            const total = content.querySelector('[data-store-cart-total]');
            summary.replaceChildren(...(total ? [total] : []));
            container.replaceChildren(content);
            if (viewLink) viewLink.hidden = false;
            footer.hidden = false;
        }
        retry.hidden = true;
        container.inert = false;
        summary.inert = false;
        status.textContent = '';
        status.hidden = false;
        if (html !== null && dialog.hasAttribute('data-cart-preload')) {
            cartSnapshot = {html, epoch: epochAtFetch};
        }
    }

    function hydrateCartFromSsr(dialog) {
        const tpl = dialog.querySelector('template[data-ssr-cart]');
        if (!tpl) return false;
        const fragment = tpl.content.cloneNode(true);
        if (!fragment.querySelector('[data-storefront-cart]')) return false;
        applyCartDocument(dialog, fragment, cartEpoch, null);
        return true;
    }

    function preloadCart(dialog) {
        if (!dialog?.dataset.cartUrl || !dialog.hasAttribute('data-cart-preload') || cartPreloadPromise || cartRead || active === dialog) return;
        const epochAtStart = cartEpoch;
        cartPreloadPromise = (async () => {
            try {
                const doc = await readPage(dialog.dataset.cartUrl, 'cart-preload');
                if (epochAtStart !== cartEpoch) return;
                const content = doc.querySelector('[data-storefront-cart]');
                if (!content) return;
                if (content.dataset.cartCount !== undefined) renderCartCount(Number(content.dataset.cartCount));
                cartSnapshot = {html: doc.documentElement.outerHTML, epoch: epochAtStart};
            } catch {
                // Silent background preload; clicking Cart falls back to live refreshCart().
            } finally {
                cartPreloadPromise = null;
            }
        })();
    }

    async function refreshCart(dialog, {discard = false, supersede = false, focusKey = null} = {}) {
        if (cartRead && !discard && !supersede) return cartRead;
        if (discard) clearCart(dialog);
        const version = ++cartVersion;
        const epochAtStart = cartEpoch;
        const container = dialog.querySelector('[data-live-cart]');
        const footer = dialog.querySelector('[data-cart-footer]');
        const retry = dialog.querySelector('[data-cart-retry]');
        const summary = dialog.querySelector('[data-cart-summary]');
        const status = dialog.querySelector('[data-cart-status]');
        const closeButton = dialog.querySelector('[data-storefront-close]');
        if (container.contains(document.activeElement) || document.activeElement === retry) {
            closeButton?.focus({preventScroll: true});
        }
        retry.hidden = true;
        footer.hidden = false;
        container.inert = true;
        summary.inert = true;
        container.setAttribute('aria-busy', 'true');
        status.textContent = '';
        status.hidden = false;
        const current = () => active === dialog && version === cartVersion;
        const delay = setTimeout(() => {
            if (current()) status.textContent = container.childElementCount
                ? 'در حال بررسی آخرین وضعیت سبد…' : 'در حال دریافت سبد خرید…';
        }, 180);

        cartRead = (async () => {
            try {
                const doc = await readPage(dialog.dataset.cartUrl, 'cart');
                if (!current()) return;
                applyCartDocument(dialog, doc, epochAtStart, doc.documentElement.outerHTML);
                if (focusKey && window.document.activeElement === closeButton) {
                    const control = container.querySelector('[data-cart-focus="' + CSS.escape(focusKey) + '"]');
                    const focus = control?.disabled
                        ? control.closest('[data-cart-item]')?.querySelector('button[data-cart-focus]:enabled')
                        : control;
                    focus?.focus({preventScroll: true});
                }
            } catch {
                if (!current()) return;
                status.textContent = container.childElementCount
                    ? 'وضعیت فعلی سبد تأیید نشد.'
                    : 'دریافت سبد خرید انجام نشد. دوباره تلاش کنید.';
                retry.hidden = false;
            } finally {
                clearTimeout(delay);
                if (current()) {
                    container.removeAttribute('aria-busy');
                    cartRead = null;
                }
            }
        })();
        return cartRead;
    }

    function clearCart(dialog) {
        ++cartVersion;
        pending.get('cart')?.abort();
        cartRead = null;
        dialog.querySelector('[data-live-cart]').replaceChildren();
        dialog.querySelector('[data-cart-summary]').replaceChildren();
        dialog.querySelector('[data-cart-status]').textContent = '';
        dialog.querySelector('[data-cart-error]').hidden = true;
    }
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('form[data-cart-mutation]')) return;
        const fromDrawer = !!(cartDialog && cartDialog.contains(form));
        const inPageCart = !fromDrawer && !!form.closest('[data-storefront-cart]');
        if (!cartDialog && !inPageCart) return;
        const endpoint = new URL(form.action, location.href);
        if (endpoint.origin !== location.origin) return;

        if (form.matches('form[data-cart-qty-form]')) {
            event.preventDefault();
            const submitter = event.submitter;
            const slug = form.dataset.cartSlug || '';
            const currentQty = Math.max(1, Number(form.dataset.cartQty || 1));
            const limit = Math.max(1, Number(form.dataset.cartLimit || 1000));
            const step = submitter?.dataset.cartStep;
            const requested = step === 'inc' ? currentQty + 1 : step === 'dec' ? currentQty - 1 : Number(submitter?.value || currentQty);
            const nextQty = Math.max(1, Math.min(limit, requested));
            if (!slug || nextQty === currentQty) return;
            clearCartErrors();
            updateCartDomOptimistic(slug, nextQty);
            scheduleQtySync(slug, endpoint.href, nextQty, false);
            return;
        }

        event.preventDefault();
        if (cartPending) return;
        const epoch = cartEpoch;
        const submitter = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
        const error = getCartErrorElement(fromDrawer);
        const focusKey = (fromDrawer || inPageCart) ? submitter?.dataset.cartFocus : null;
        const removeSlug = form.closest('[data-cart-item]')?.dataset.cartSlug || null;
        if (error) error.hidden = true;
        cartPending = true;
        form.setAttribute('aria-busy', 'true');
        const previouslyDisabled = submitter?.disabled;
        const originalLabel = submitter && !fromDrawer && !inPageCart ? submitter.textContent : null;
        if (submitter) {
            submitter.disabled = true;
            submitter.dataset.loading = 'true';
            if (originalLabel !== null) submitter.textContent = 'در حال افزودن…';
        }
        const playFlyToCart = (sourceEl) => {
            if (!sourceEl || typeof sourceEl.getBoundingClientRect !== 'function') return;
            if (typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            const cartTriggers = [...document.querySelectorAll('[data-storefront-open="cart-dialog"]')];
            const targetEl = cartTriggers.find(el => {
                const r = el.getBoundingClientRect();
                return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden';
            });
            if (!targetEl) return;
            const productImg = document.querySelector('.gallery-stage img, .product-gallery img, .store-cart-image img');
            const startRect = (productImg && productImg.getBoundingClientRect().width > 0 ? productImg : sourceEl).getBoundingClientRect();
            const endRect = targetEl.getBoundingClientRect();
            if (!startRect.width || !endRect.width) return;

            const flyer = document.createElement('div');
            flyer.className = 'np-cart-flyer';
            flyer.setAttribute('aria-hidden', 'true');
            if (productImg && productImg.src) {
                const img = document.createElement('img');
                img.src = productImg.src;
                img.alt = '';
                flyer.append(img);
            } else {
                flyer.textContent = '+۱';
            }
            const size = 44;
            const startX = startRect.left + startRect.width / 2 - size / 2;
            const startY = startRect.top + startRect.height / 2 - size / 2;
            const endX = endRect.left + endRect.width / 2 - size / 2;
            const endY = endRect.top + endRect.height / 2 - size / 2;
            flyer.style.left = `${startX}px`;
            flyer.style.top = `${startY}px`;
            document.body.append(flyer);

            const dx = endX - startX;
            const dy = endY - startY;
            const finishFly = () => {
                flyer.remove();
                targetEl.classList.remove('is-bouncing');
                void targetEl.offsetWidth;
                targetEl.classList.add('is-bouncing');
                setTimeout(() => targetEl.classList.remove('is-bouncing'), 500);
            };
            if (typeof flyer.animate === 'function') {
                const anim = flyer.animate([
                    {transform: 'translate3d(0, 0, 0) scale(1)', opacity: 0.96},
                    {transform: `translate3d(${dx * 0.55}px, ${Math.min(dy * 0.35, -48)}px, 0) scale(0.78)`, opacity: 0.92, offset: 0.45},
                    {transform: `translate3d(${dx}px, ${dy}px, 0) scale(0.28)`, opacity: 0.2},
                ], {duration: 540, easing: 'cubic-bezier(0.22, 1, 0.36, 1)'});
                anim.onfinish = finishFly;
                anim.oncancel = () => flyer.remove();
            } else {
                setTimeout(finishFly, 300);
            }
        };
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            await flushPendingCartSync({cancelSlug: removeSlug});
            const data = new FormData(form);
            if (submitter?.name) data.set(submitter.name, submitter.value);
            const currentProof = document.querySelector('input[name="cart_context"]')?.value;
            const currentRevision = document.querySelector('input[name="cart_revision"]')?.value;
            if (currentProof && data.has('cart_context')) data.set('cart_context', currentProof);
            if (currentRevision && data.has('cart_revision')) data.set('cart_revision', currentRevision);

            const headers = {Accept: 'application/json', 'X-Storefront-Cart-Html': '1'};
            if (inPageCart) headers['X-Storefront-Cart-Page'] = '1';
            const sendRequest = () => fetch(endpoint, {method: 'POST', body: data,
                credentials: 'same-origin', redirect: 'error', signal: controller.signal, headers});
            let response = await sendRequest();
            if (response.status === 409 && !fromDrawer) {
                const payload409 = await response.clone().json().catch(() => null);
                const serverMessage = typeof payload409?.message === 'string' ? payload409.message : '';
                if (!serverMessage.includes('موجودی')) {
                    const targetCartUrl = cartDialog?.dataset.cartUrl || '/cart';
                    const refreshed = await fetch(targetCartUrl, {credentials: 'same-origin', headers: {Accept: 'text/html'}})
                        .then(r => (r.ok ? r.text() : ''))
                        .catch(() => '');
                    if (refreshed) {
                        const freshDoc = new DOMParser().parseFromString(refreshed, 'text/html');
                        const freshProof = freshDoc.querySelector('input[name="cart_context"]')?.value;
                        const freshRevision = freshDoc.querySelector('input[name="cart_revision"]')?.value;
                        if (freshProof) {
                            data.set('cart_context', freshProof);
                            if (freshRevision && data.has('cart_revision')) data.set('cart_revision', freshRevision);
                            document.querySelectorAll('input[name="cart_context"]').forEach(input => { input.value = freshProof; });
                            if (freshRevision) {
                                document.querySelectorAll('input[name="cart_revision"]').forEach(input => { input.value = freshRevision; });
                            }
                            response = await sendRequest();
                        }
                    }
                }
            }
            if (!response.ok) {
                const errPayload = await response.json().catch(() => null);
                const message = typeof errPayload?.message === 'string' && errPayload.message.length > 0
                    ? errPayload.message
                    : response.status === 409 ? 'سبد یا موجودی تغییر کرده است. پیش از تلاش دوباره، سبد را بررسی کنید.'
                    : response.status === 422 ? 'تعداد انتخاب‌شده معتبر نیست.'
                    : 'تغییر سبد انجام نشد. وضعیت سبد را بررسی کنید.';
                throw new Error(message);
            }
            const payload = await response.json();
            const {count, proof, revision, quantities, separate, available, html} = payload;
            if (!Number.isInteger(count) || count < 0) throw new Error();
            if (typeof proof === 'string' && proof.length > 0) {
                document.querySelectorAll('input[name="cart_context"]').forEach(input => { input.value = proof; });
            }
            if (typeof revision === 'string' && revision.length > 0) {
                document.querySelectorAll('input[name="cart_revision"]').forEach(input => { input.value = revision; });
            }
            syncProductControls(quantities, separate, available);
            commerce?.postMessage('cart-change');
            if (epoch !== cartEpoch) return;
            cartSnapshot = null;
            cartDialog?.querySelector('template[data-ssr-cart]')?.remove();
            renderCartCount(count);
            if (!fromDrawer && !inPageCart) {
                playFlyToCart(submitter || form);
                if (form.id === 'product-cart-form') showAddedBar();
            }
            if (inPageCart && typeof html === 'string' && html.length > 0) {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                applyPageCartDocument(doc, focusKey);
            } else if (cartDialog && active === cartDialog) {
                if (typeof html === 'string' && html.length > 0) {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    applyCartDocument(cartDialog, doc, cartEpoch, html);
                    if (focusKey) restoreCartFocus(cartDialog, focusKey);
                } else {
                    const focusInCart = cartDialog.querySelector('[data-live-cart]').contains(document.activeElement);
                    if (focusInCart || fromDrawer) cartDialog.querySelector('[data-storefront-close]')?.focus({preventScroll: true});
                    await refreshCart(cartDialog, {supersede: true, focusKey});
                }
            } else if (!active && !fromDrawer && !inPageCart && cartDialog) {
                if (typeof html === 'string' && html.length > 0) {
                    try {
                        const doc = new DOMParser().parseFromString(html, 'text/html');
                        applyCartDocument(cartDialog, doc, cartEpoch, html);
                    } catch {
                        preloadCart(cartDialog);
                    }
                } else {
                    preloadCart(cartDialog);
                }
            }
        } catch (failure) {
            if (epoch !== cartEpoch) return;
            if (error) {
                error.textContent = failure.message && /[\u0600-\u06ff]/.test(failure.message) ? failure.message
                    : 'نتیجهٔ درخواست مشخص نیست. پیش از تکرار، وضعیت سبد را بررسی کنید.';
                error.hidden = false;
            }
            if (!active && !fromDrawer && !inPageCart && cartDialog) {
                open(cartDialog, submitter);
                if (error) error.hidden = false;
            } else if (cartDialog && active === cartDialog) {
                await refreshCart(cartDialog, {supersede: true, focusKey});
            }
        } finally {
            clearTimeout(timeout);
            cartPending = false;
            form.removeAttribute('aria-busy');
            if (submitter) {
                submitter.disabled = previouslyDisabled;
                delete submitter.dataset.loading;
                if (originalLabel !== null) submitter.textContent = originalLabel;
            }
        }
    });

    const addedBar = document.querySelector('[data-added-bar]');
    let addedBarTimer = 0;
    function hideAddedBar() {
        clearTimeout(addedBarTimer);
        addedBarTimer = 0;
        if (!addedBar) return;
        addedBar.hidden = true;
        addedBar.removeAttribute('data-bar-visible');
        root.style.setProperty('--store-added-bar-space', '0px');
    }
    function showAddedBar() {
        if (!addedBar) return;
        clearTimeout(addedBarTimer);
        addedBar.hidden = false;
        requestAnimationFrame(() => {
            addedBar.setAttribute('data-bar-visible', '');
            const h = addedBar.offsetHeight || 48;
            root.style.setProperty('--store-added-bar-space', `${h + 8}px`);
        });
        addedBarTimer = setTimeout(hideAddedBar, 5200);
    }
    addedBar?.querySelector('[data-added-bar-close]')?.addEventListener('click', hideAddedBar);

    const toFaDigits = value => String(value).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
    const faMoneyFormat = new Intl.NumberFormat('fa-IR');
    const pendingQtySync = new Map();
    let qtySyncChain = Promise.resolve();

    function getCartErrorElement(fromDrawer = false) {
        if (fromDrawer && cartDialog) return cartDialog.querySelector('[data-cart-error]');
        return document.querySelector('[data-cart-page-error]') || cartDialog?.querySelector('[data-cart-error]') || null;
    }

    function clearCartErrors() {
        document.querySelectorAll('[data-cart-error], [data-cart-page-error]').forEach(el => { el.hidden = true; });
    }

    function restoreCartFocus(rootEl, focusKey) {
        if (!rootEl || !focusKey) return;
        const control = rootEl.querySelector('[data-cart-focus="' + CSS.escape(focusKey) + '"]');
        const focus = control?.disabled
            ? control.closest('[data-cart-item]')?.querySelector('button[data-cart-focus]:enabled')
            : control;
        focus?.focus({preventScroll: true});
    }

    function applyPageCartDocument(doc, focusKey = null) {
        const nextSection = doc.querySelector('[data-storefront-cart]');
        const currentSection = [...document.querySelectorAll('[data-storefront-cart]')].find(el => !cartDialog?.contains(el));
        if (!nextSection || !currentSection) return;
        currentSection.replaceWith(nextSection);
        if (focusKey) restoreCartFocus(nextSection, focusKey);
    }

    function formatStockNote(qty, limit, stockAvail) {
        if (stockAvail === 1) return 'تنها ۱ عدد در انبار باقی مانده (موجودی: ۱ عدد)';
        if (qty >= limit) {
            return stockAvail > limit
                ? `حداکثر تعداد مجاز در هر سفارش انتخاب شده است (${toFaDigits(limit)} عدد)`
                : `موجودی: ${toFaDigits(limit)} عدد در انبار (حداکثر موجودی انتخاب شده است)`;
        }
        if (stockAvail <= 5) return `تنها ${toFaDigits(stockAvail)} عدد در انبار باقی مانده`;
        return '';
    }

    function updateCartDomOptimistic(slug, nextQty) {
        const escapedSlug = CSS.escape(slug);
        document.querySelectorAll(`[data-cart-item][data-cart-slug="${escapedSlug}"]`).forEach(item => {
            const limit = Math.max(1, Number(item.dataset.cartLimit || 1000));
            const stockAvail = Math.max(limit, Number(item.dataset.stockAvailable || limit));
            const unitPrice = Number(item.dataset.unitPrice || 0);
            item.dataset.cartQty = String(nextQty);

            const form = item.querySelector('form[data-cart-qty-form]');
            if (form) {
                form.dataset.cartQty = String(nextQty);
                const valEl = form.querySelector('[data-cart-qty-value], .commerce-stepper__value bdi');
                if (valEl) valEl.textContent = toFaDigits(nextQty);
                const decBtn = form.querySelector('[data-cart-step="dec"]');
                const incBtn = form.querySelector('[data-cart-step="inc"]');
                if (decBtn) {
                    decBtn.value = String(Math.max(1, Math.min(nextQty - 1, limit)));
                    decBtn.disabled = nextQty <= 1;
                }
                if (incBtn) {
                    incBtn.value = String(nextQty + 1);
                    incBtn.disabled = nextQty >= limit;
                }
            }

            const lineTotalBdi = item.querySelector('[data-cart-line-total] bdi');
            if (lineTotalBdi && unitPrice > 0) {
                lineTotalBdi.textContent = faMoneyFormat.format(unitPrice * nextQty);
            }

            const noteEl = item.querySelector('[data-cart-stock-note]');
            if (noteEl) {
                const note = formatStockNote(nextQty, limit, stockAvail);
                noteEl.textContent = note;
                noteEl.hidden = note === '';
            }
        });

        let latestCount = null;
        document.querySelectorAll('[data-storefront-cart]').forEach(cartEl => {
            let sumQty = 0;
            let sumMoney = 0;
            cartEl.querySelectorAll('[data-cart-item]').forEach(item => {
                const q = Math.max(0, Number(item.dataset.cartQty || 0));
                const p = Number(item.dataset.unitPrice || 0);
                sumQty += q;
                sumMoney += q * p;
            });
            cartEl.dataset.cartCount = String(sumQty);
            latestCount = sumQty;
            const grandBdi = cartEl.querySelector('[data-cart-grand-total] bdi')
                || (cartDialog?.contains(cartEl) ? cartDialog.querySelector('[data-cart-summary] [data-cart-grand-total] bdi') : null);
            if (grandBdi) grandBdi.textContent = faMoneyFormat.format(sumMoney);
        });
        if (latestCount !== null) renderCartCount(latestCount);
        syncProductControls({[slug]: nextQty});
    }

    function reconcileCartStateFromServer(payload) {
        if (!payload || typeof payload !== 'object') return;
        const {quantities, available, stockAvailable, prices, total} = payload;
        document.querySelectorAll('[data-cart-item]').forEach(item => {
            const slug = item.dataset.cartSlug || '';
            if (!slug || !quantities || !(slug in quantities)) return;
            const qty = Math.max(1, Number(quantities[slug] || 1));
            const limit = Math.max(1, Number(available?.[slug] ?? item.dataset.cartLimit ?? 1000));
            const stockAvail = Math.max(limit, Number(stockAvailable?.[slug] ?? item.dataset.stockAvailable ?? limit));
            const unitPrice = Number(prices?.[slug] ?? item.dataset.unitPrice ?? 0);
            item.dataset.cartLimit = String(limit);
            item.dataset.stockAvailable = String(stockAvail);
            item.dataset.unitPrice = String(unitPrice);
            const form = item.querySelector('form[data-cart-qty-form]');
            if (form) form.dataset.cartLimit = String(limit);
            updateCartDomOptimistic(slug, qty);
        });
        if (typeof total === 'number') {
            document.querySelectorAll('[data-cart-grand-total] bdi').forEach(bdi => {
                bdi.textContent = faMoneyFormat.format(total);
            });
        }
    }

    async function sendQtySyncRequest(entry) {
        const csrf = document.querySelector('input[name="_token"]')?.value || '';
        const proof = document.querySelector('input[name="cart_context"]')?.value || '';
        const revision = document.querySelector('input[name="cart_revision"]')?.value || '';
        if (!entry.url || !csrf) return;

        const data = new FormData();
        data.append('_token', csrf);
        if (proof) data.append('cart_context', proof);
        if (revision) data.append('cart_revision', revision);
        if (entry.qty === 0) {
            data.append('scope', entry.removeScope || 'device');
        } else {
            data.append('quantity', String(entry.qty));
        }

        const response = await fetch(entry.url, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            redirect: 'error',
            headers: {Accept: 'application/json', 'X-Storefront-Cart-State': '1'},
        });
        if (!response.ok) {
            const errPayload = await response.json().catch(() => null);
            const message = typeof errPayload?.message === 'string' && errPayload.message.length > 0
                ? errPayload.message
                : response.status === 409 ? 'سبد یا موجودی تغییر کرده است. وضعیت سبد بررسی می‌شود.'
                : 'تغییر تعداد انجام نشد.';
            const error = new Error(message);
            error.status = response.status;
            throw error;
        }
        const payload = await response.json();
        if (typeof payload.proof === 'string' && payload.proof) {
            document.querySelectorAll('input[name="cart_context"]').forEach(input => { input.value = payload.proof; });
        }
        if (typeof payload.revision === 'string' && payload.revision) {
            document.querySelectorAll('input[name="cart_revision"]').forEach(input => { input.value = payload.revision; });
        }
        if (Number.isInteger(payload.count)) {
            cartSnapshot = null;
            cartDialog?.querySelector('template[data-ssr-cart]')?.remove();
            if (!pendingQtySync.has(entry.slug)) {
                renderCartCount(payload.count);
                syncProductControls(payload.quantities, payload.separate, payload.available);
                reconcileCartStateFromServer(payload);
                if (entry.qty === 0) hideAddedBar();
            }
            commerce?.postMessage('cart-change');
        }
    }

    function triggerQtyQueueFlush() {
        qtySyncChain = qtySyncChain.then(async () => {
            while (true) {
                let nextKey = null;
                for (const [slug, entry] of pendingQtySync.entries()) {
                    if (entry.timer === null) {
                        nextKey = slug;
                        break;
                    }
                }
                if (nextKey === null) break;
                const entry = pendingQtySync.get(nextKey);
                pendingQtySync.delete(nextKey);
                try {
                    await sendQtySyncRequest(entry);
                } catch (err) {
                    for (const pendingEntry of pendingQtySync.values()) {
                        if (pendingEntry.timer !== null) clearTimeout(pendingEntry.timer);
                    }
                    pendingQtySync.clear();
                    const errEl = getCartErrorElement(active === cartDialog);
                    if (errEl) {
                        errEl.textContent = err?.message || 'تغییر تعداد انجام نشد.';
                        errEl.hidden = false;
                    }
                    if (cartDialog && active === cartDialog) {
                        await refreshCart(cartDialog, {supersede: true});
                    } else {
                        const pageCart = [...document.querySelectorAll('[data-storefront-cart]')].find(el => !cartDialog?.contains(el));
                        if (pageCart) {
                            const doc = await readPage(location.href, 'cart-page-recover').catch(() => null);
                            if (doc) applyPageCartDocument(doc);
                        }
                    }
                    break;
                }
            }
        });
        return qtySyncChain;
    }

    function scheduleQtySync(slug, url, qty, immediate = false, removeScope = 'device') {
        const existing = pendingQtySync.get(slug);
        if (existing && existing.timer !== null) clearTimeout(existing.timer);
        const entry = {slug, url, qty, removeScope, timer: null};
        pendingQtySync.set(slug, entry);
        if (immediate) {
            return triggerQtyQueueFlush();
        }
        entry.timer = setTimeout(() => {
            entry.timer = null;
            triggerQtyQueueFlush();
        }, 200);
        return qtySyncChain;
    }

    async function flushPendingCartSync({cancelSlug = null} = {}) {
        if (cancelSlug && pendingQtySync.has(cancelSlug)) {
            const cancelled = pendingQtySync.get(cancelSlug);
            if (cancelled.timer !== null) clearTimeout(cancelled.timer);
            pendingQtySync.delete(cancelSlug);
        }
        for (const entry of pendingQtySync.values()) {
            if (entry.timer !== null) {
                clearTimeout(entry.timer);
                entry.timer = null;
            }
        }
        if (pendingQtySync.size > 0) {
            await triggerQtyQueueFlush();
        } else {
            await qtySyncChain;
        }
    }

    function syncProductControls(quantities, separate, available) {
        const controls = document.querySelectorAll('[data-product-cart-control]');
        if (!controls.length) return;
        controls.forEach(control => {
            const slug = control.dataset.productSlug || '';
            if (available && typeof available === 'object' && slug in available) {
                control.dataset.productMax = String(Math.max(1, Number(available[slug] || 1)));
            }
            const max = Math.max(1, Number(control.dataset.productMax || 1000));
            const qty = quantities && typeof quantities === 'object' && slug in quantities
                ? Math.max(0, Number(quantities[slug] || 0))
                : Math.max(0, Number(control.dataset.productQty || 0));
            control.dataset.productQty = String(qty);
            if (typeof separate === 'boolean') control.dataset.removeScope = separate ? 'hide' : 'device';
            const formEl = control.querySelector('form[data-cart-mutation]');
            const dockBtn = control.querySelector('[data-dock-add-btn]');
            const dockInCart = control.querySelector('[data-dock-in-cart]');
            const stepper = control.querySelector('[data-inline-stepper]');
            const countEl = control.querySelector('[data-stepper-count]');
            const incBtn = control.querySelector('[data-stepper-inc]');
            const decBtn = control.querySelector('[data-stepper-dec]');
            const minusIcon = control.querySelector('.np-inline-stepper__minus');
            const trashIcon = control.querySelector('.np-inline-stepper__trash');
            if (formEl) formEl.hidden = qty > 0;
            if (dockBtn) dockBtn.hidden = qty > 0;
            if (dockInCart) dockInCart.hidden = qty <= 0;
            if (stepper) stepper.hidden = qty <= 0;
            if (countEl && qty > 0) countEl.textContent = toFaDigits(qty);
            if (incBtn) incBtn.disabled = qty >= max;
            if (decBtn) {
                const isRemove = qty <= 1;
                decBtn.dataset.mode = isRemove ? 'remove' : 'decrement';
                decBtn.setAttribute('aria-label', isRemove ? 'حذف از سبد خرید' : 'کاهش تعداد');
                if (minusIcon) minusIcon.hidden = isRemove;
                if (trashIcon) trashIcon.hidden = !isRemove;
            }
        });
    }

    document.addEventListener('click', event => {
        const checkoutLink = event.target instanceof Element ? event.target.closest('a[href*="/cart/checkout"]') : null;
        if (checkoutLink && pendingQtySync.size > 0) {
            event.preventDefault();
            flushPendingCartSync().finally(() => {
                location.assign(checkoutLink.href);
            });
            return;
        }

        const stepperBtn = event.target instanceof Element ? event.target.closest('[data-stepper-inc], [data-stepper-dec]') : null;
        if (!stepperBtn || cartPending) return;
        const control = stepperBtn.closest('[data-product-cart-control]');
        if (!control) return;
        const slug = control.dataset.productSlug || '';
        const currentQty = Math.max(0, Number(control.dataset.productQty || 0));
        const maxQty = Math.max(1, Number(control.dataset.productMax || 1000));
        const isInc = stepperBtn.hasAttribute('data-stepper-inc');
        const nextQty = isInc ? Math.min(maxQty, currentQty + 1) : Math.max(0, currentQty - 1);
        if (!slug || nextQty === currentQty) return;

        const endpoint = nextQty === 0 ? control.dataset.removeUrl : control.dataset.updateUrl;
        if (!endpoint) return;

        clearCartErrors();
        syncProductControls({[slug]: nextQty});
        updateCartDomOptimistic(slug, nextQty);
        scheduleQtySync(slug, endpoint, nextQty, nextQty === 0, control.dataset.removeScope || 'device');
    });

    const checkoutForm = document.querySelector('[data-store-checkout]');
    if (checkoutForm) {
        const button = checkoutForm.querySelector('[data-checkout-submit]');
        const status = checkoutForm.querySelector('[data-checkout-status]');
        checkoutForm.addEventListener('submit', event => {
            if (event.defaultPrevented) return;
            if (button.getAttribute('aria-disabled') === 'true') {
                event.preventDefault();
                return;
            }
            button.setAttribute('aria-disabled', 'true');
            checkoutForm.setAttribute('aria-busy', 'true');
            status.hidden = false;
            status.textContent = 'در حال ثبت درخواست…';
        });
        window.addEventListener('pageshow', () => {
            button.removeAttribute('aria-disabled');
            checkoutForm.removeAttribute('aria-busy');
            status.hidden = true;
            status.textContent = '';
        });
    }

    function activate(dialog, focus) {
        window.StorefrontScrollLock?.acquire(scrollLockOwner);
        active = dialog;
        triggers.forEach(element => element.setAttribute('aria-expanded', String(element.dataset.storefrontOpen === dialog.id)));
        dialog.showModal();
        layers.get(dialog.id)?.show?.();
        const isSearchDialog = dialog.id === 'search-dialog';
        const target = focus || (isSearchDialog && dialog.querySelector('input:not([type="hidden"])')) || dialog.querySelector('[data-storefront-close]');
        target?.focus({preventScroll: true});
        if (dialog.dataset.cartUrl) {
            if (pendingQtySync.size > 0) {
                flushPendingCartSync().finally(() => {
                    if (active === dialog) refreshCart(dialog, {supersede: true});
                });
            } else if (dialog.hasAttribute('data-cart-preload') && cartSnapshot && cartSnapshot.epoch === cartEpoch) {
                try {
                    const doc = new DOMParser().parseFromString(cartSnapshot.html, 'text/html');
                    applyCartDocument(dialog, doc, cartEpoch);
                } catch {
                    cartSnapshot = null;
                    refreshCart(dialog);
                }
            } else if (dialog.hasAttribute('data-cart-preload') && cartEpoch === 0 && hydrateCartFromSsr(dialog)) {
                // Rendered synchronously from SSR cart payload in 0ms; no network wait needed.
            } else if (dialog.dataset.cartEmptyGuest === '1' && cartEpoch === 0) {
                const emptyTpl = dialog.querySelector('[data-empty-cart]');
                if (emptyTpl) {
                    dialog.querySelector('[data-live-cart]').replaceChildren(emptyTpl.content.cloneNode(true));
                    dialog.querySelector('[data-cart-summary]').replaceChildren();
                    const viewLink = dialog.querySelector('[data-cart-view-link]');
                    if (viewLink) viewLink.hidden = true;
                    dialog.querySelector('[data-cart-footer]').hidden = true;
                    dialog.querySelector('[data-cart-retry]').hidden = true;
                    dialog.querySelector('[data-live-cart]').inert = false;
                } else {
                    refreshCart(dialog);
                }
            } else {
                refreshCart(dialog);
            }
        }
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

    document.querySelector('[data-customer-header]')?.addEventListener('click', event => {
        const trigger = event.target.closest('[data-customer-menu] [data-customer-toggle]');
        if (!trigger || !matchMedia('(max-width: 850px)').matches
            || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const dashboardLink = trigger.closest('[data-customer-menu]')?.querySelector('nav a[href]');
        if (!dashboardLink) return;
        event.preventDefault();
        location.assign(dashboardLink.href);
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
        dialog.addEventListener('click', event => { if (outside && isOutside(event)) close(); outside = false; });
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
    if (cartDialog) {
        const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
        register(cartDialog, {
            show: () => cartDialog.classList.remove('is-closing'),
            hide: () => cartDialog.classList.remove('is-closing'),
            beforeClose: () => {
                if (reducedMotion.matches) return;
                cartDialog.classList.add('is-closing');
                if (getComputedStyle(cartDialog).animationName === 'none') {
                    cartDialog.classList.remove('is-closing');
                    return;
                }
                return new Promise(resolve => {
                    let done = false;
                    const finishAnimation = () => {
                        if (done) return;
                        done = true;
                        clearTimeout(timer);
                        cartDialog.removeEventListener('animationend', onEnd);
                        resolve();
                    };
                    const onEnd = event => { if (event.target === cartDialog) finishAnimation(); };
                    const timer = setTimeout(finishAnimation, 200);
                    cartDialog.addEventListener('animationend', onEnd);
                });
            },
        });
    }
    const filters = document.querySelector('[data-desktop-filter-form]');
    if (filters) {
        filters.querySelectorAll('[data-auto-submit-filter]').forEach((input) => {
            input.addEventListener('change', () => filters.submit());
        });
    }
    const sheetReducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
    document.querySelectorAll('.store-filter-dialog').forEach((sheet) => {
        if (!sheet.id) return;
        register(sheet, {
            show: () => sheet.classList.remove('is-closing'),
            hide: () => sheet.classList.remove('is-closing'),
            beforeClose: () => {
                if (sheetReducedMotion.matches) return;
                sheet.classList.add('is-closing');
                if (getComputedStyle(sheet).animationName === 'none') {
                    sheet.classList.remove('is-closing');
                    return;
                }
                return new Promise((resolve) => {
                    let done = false;
                    const finishAnimation = () => {
                        if (done) return;
                        done = true;
                        clearTimeout(timer);
                        sheet.removeEventListener('animationend', onEnd);
                        sheet.classList.remove('is-closing');
                        resolve();
                    };
                    const onEnd = (event) => {
                        if (event.target === sheet) finishAnimation();
                    };
                    const timer = setTimeout(finishAnimation, 240);
                    sheet.addEventListener('animationend', onEnd);
                });
            },
        });
    });
    window.addEventListener('popstate', restoreLayer);
    window.addEventListener('pagehide', () => finish(false));
    window.addEventListener('pageshow', event => { if (event.persisted || (!active && !closing)) restoreLayer(); });
    commerce?.addEventListener('message', event => {
        if (event.data === 'identity-change') {
            ++cartEpoch;
            cartSnapshot = null;
            renderCartCount(0);
            if (cartDialog) {
                clearCart(cartDialog);
                if (active === cartDialog) close();
            }
        } else if (event.data === 'cart-change' && cartDialog) {
            cartSnapshot = null;
            if (active === cartDialog) refreshCart(cartDialog, {supersede: true});
            else preloadCart(cartDialog);
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') {
            ++cartEpoch;
            cartSnapshot = null;
            if (cartDialog && active === cartDialog) clearCart(cartDialog);
            return;
        }
        if (active?.dataset.accountUrl) refreshAccount(active);
        if (active && active === cartDialog) refreshCart(active, {discard: true});
        else if (cartDialog && (!cartSnapshot || cartSnapshot.epoch !== cartEpoch)) preloadCart(cartDialog);
    });
    if (cartDialog?.dataset.cartUrl && cartDialog.hasAttribute('data-cart-preload') && !cartDialog.querySelector('template[data-ssr-cart]') && !document.querySelector('[data-storefront-cart]')) {
        const schedulePreload = window.requestIdleCallback || (fn => setTimeout(fn, 120));
        schedulePreload(() => preloadCart(cartDialog));
    }
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
    const navigation = document.querySelector('[data-mobile-bottom-nav]');
    const related = document.getElementById('product-related');
    const mobile = matchMedia('(max-width: 700px)');
    const mobileProductSticky = matchMedia('(max-width: 767px)');
    const floatingNavigation = matchMedia('(max-width: 850px)');
    let previousY = Math.max(0, scrollY);
    let travel = 0;
    let navHidden = false;
    function updateNavigation() {
        if (!navigation) return;
        if (body.classList.contains('product-detail-page') && mobileProductSticky.matches) {
            navigation.inert = true;
            return;
        }
        const y = Math.max(0, scrollY);
        const delta = y - previousY;
        previousY = y;
        if (!floatingNavigation.matches || y < 64 || (!delta && navigation.contains(document.activeElement))) {
            navHidden = false;
            travel = 0;
        } else if (delta && !root.classList.contains('storefront-overlay-open')) {
            travel = Math.sign(delta) === Math.sign(travel) ? travel + delta : delta;
            if (travel > 38) navHidden = true;
            if (travel < -20) navHidden = false;
        }
        if (navHidden && navigation.contains(document.activeElement)) {
            document.activeElement.blur();
        }
        navigation.toggleAttribute('data-scroll-hidden', navHidden);
        navigation.inert = navHidden;
    }
    let frame = 0;
    function render() {
        frame = 0;
        if (active || root.classList.contains('storefront-overlay-open')) return;
        updateNavigation();
        if (contact && body.classList.contains('product-detail-page') && mobileProductSticky.matches) {
            const show = !body.classList.contains('keyboard-open');
            contact.toggleAttribute('data-dock-visible', show);
            contact.inert = !show;
        } else if (contact && action && productContent) {
            const headerEdge = productHeader?.getBoundingClientRect().bottom ?? 0;
            const navEdge = !navHidden && navigation ? navigation.getBoundingClientRect().top : innerHeight;
            const contentEdge = Math.min(productContent.getBoundingClientRect().bottom, related?.getBoundingClientRect().top ?? Infinity);
            const show = mobile.matches && action.getClientRects().length > 0
                && action.getBoundingClientRect().bottom <= headerEdge
                && contentEdge > navEdge && !body.classList.contains('keyboard-open');
            contact.toggleAttribute('data-dock-visible', show);
            contact.inert = !show;
        }
        body.classList.remove('floating-support-obstructs');
    }
    function schedule() { if (!frame) frame = requestAnimationFrame(render); }
    window.addEventListener('scroll', schedule, {passive: true});
    window.addEventListener('resize', schedule, {passive: true});
    window.addEventListener('load', schedule, {once: true});
    document.addEventListener('storefront-overlay:closed', schedule);
    document.addEventListener('storefront-scroll:released', schedule);
    document.addEventListener('focusin', schedule);
    document.addEventListener('focusout', schedule);
    window.visualViewport?.addEventListener('resize', schedule, {passive: true});
    if (contact && 'ResizeObserver' in window) {
        new ResizeObserver(([entry]) => {
            const height = entry.borderBoxSize?.[0]?.blockSize ?? contact.offsetHeight;
            if (height) root.style.setProperty('--store-purchase-height', `${height}px`);
            schedule();
        }).observe(contact, {box: 'border-box'});
    }
    render();
})();
