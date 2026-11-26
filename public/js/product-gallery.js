document.querySelectorAll('[data-product-gallery]').forEach((gallery) => {
    const main = gallery.querySelector('[data-gallery-main]');
    const choices = Array.from(gallery.querySelectorAll('[data-gallery-choice]'));
    const dialog = gallery.querySelector('[data-gallery-dialog]');
    const opener = gallery.querySelector('[data-gallery-open]');
    const zoom = gallery.querySelector('[data-gallery-zoom-image]');
    if (!main || !opener || !zoom || typeof dialog?.showModal !== 'function') return;

    const count = gallery.querySelector('[data-gallery-count]');
    const zoomCount = gallery.querySelector('[data-zoom-count]');
    if (zoomCount) zoomCount.textContent = count.textContent;
    const overlays = window.StorefrontOverlays;
    const forwardKey = getComputedStyle(gallery).direction === 'rtl' ? 'ArrowLeft' : 'ArrowRight';
    let current = 0;
    let touchStart = null;
    let suppressOpenUntil = 0;

    function copyImage(target, image) {
        const fullSrc = image.dataset?.fullSrc || image.getAttribute('src') || image.src;
        target.removeAttribute('srcset');
        target.removeAttribute('sizes');
        target.src = fullSrc;
        if (target.dataset) target.dataset.fullSrc = fullSrc;
        target.alt = image.alt;
        for (const dimension of ['width', 'height']) {
            if (image.hasAttribute(dimension)) {
                target.setAttribute(dimension, image.getAttribute(dimension));
            } else {
                target.removeAttribute(dimension);
            }
        }
    }

    function select(index) {
        current = (index + choices.length) % choices.length;
        const image = choices[current].querySelector('img');
        copyImage(main, image);
        if (dialog && dialog.open) copyImage(zoom, image);
        choices.forEach((button, position) => button.setAttribute('aria-pressed', String(position === current)));
        if (count) count.textContent = `${new Intl.NumberFormat('fa-IR').format(current + 1)} از ${new Intl.NumberFormat('fa-IR').format(choices.length)}`;
        if (zoomCount) zoomCount.textContent = count.textContent;
        const displayed = dialog.open ? zoom : main;
        if (displayed.animate && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
            displayed.getAnimations().forEach(animation => animation.cancel());
            displayed.animate([{ opacity: .7 }, { opacity: 1 }], { duration: 140 });
        }
    }

    function listenForNavigation(element, focusChoice) {
        element.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
            event.preventDefault();
            select(current + (event.key === forwardKey ? 1 : -1));
            if (focusChoice) choices[current].focus();
        });
    }

    function listenForSwipe(element) {
        element.addEventListener('touchstart', (event) => {
            touchStart = event.touches.length === 1 ? event.touches[0] : null;
        }, { passive: true });
        element.addEventListener('touchcancel', () => { touchStart = null; }, { passive: true });
        element.addEventListener('touchend', (event) => {
            if (!touchStart) return;
            const end = event.changedTouches[0];
            const horizontal = end.clientX - touchStart.clientX;
            const vertical = end.clientY - touchStart.clientY;
            touchStart = null;
            if (Math.abs(horizontal) > 45 && Math.abs(horizontal) > Math.abs(vertical) * 1.5) {
                suppressOpenUntil = Date.now() + 350;
                select(current + ((horizontal > 0) === (forwardKey === 'ArrowLeft') ? 1 : -1));
            }
        }, { passive: true });
    }

    choices.forEach((choice, index) => choice.addEventListener('click', () => select(index)));
    if (choices.length > 1) {
        listenForNavigation(choices[0].parentElement, true);
        listenForNavigation(opener.parentElement, false);
        listenForSwipe(main);
    }
    // The coordinator may reopen this dialog from browser history.
    if (overlays) overlays.register(dialog, {show: () => copyImage(zoom, main)});
    else {
        dialog.querySelector('[data-storefront-close]').addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => opener.focus());
    }
    opener.addEventListener('click', event => {
        if (event.detail && Date.now() < suppressOpenUntil) return;
        if (overlays) overlays.open(dialog, opener);
        else {
            copyImage(zoom, main);
            dialog.showModal();
        }
    });
    if (choices.length > 1) {
        gallery.querySelectorAll('[data-gallery-step]').forEach((button) => {
            button.addEventListener('click', () => select(current + Number(button.dataset.galleryStep)));
        });
        listenForNavigation(dialog, false);
        listenForSwipe(zoom);
    }
});

document.querySelectorAll('[data-product-sections]').forEach(sectionMenu => {
    const links = Array.from(sectionMenu.querySelectorAll('a[href^="#"]'));
    const sections = links.map(link => document.getElementById(link.hash.slice(1)));
    const header = document.querySelector('.np-header');
    const compactLayout = matchMedia('(max-width: 1050px)');
    let frame = 0;
    let headerHeight = 0;
    let tabsHeight = 0;
    let currentLink;
    function updateCurrentSection() {
        frame = 0;
        const documentRoot = document.documentElement;
        const height = documentRoot.clientHeight;
        const reachedEnd = window.scrollY + height >= documentRoot.scrollHeight - 2;
        const menuBottom = headerHeight + tabsHeight + 16;
        const boundary = reachedEnd ? height - 1 : Math.max(headerHeight + 40, menuBottom) + (compactLayout.matches ? 1 : 0);
        let current = 0;
        sections.forEach((section, index) => {
            if (section && section.getBoundingClientRect().top <= boundary) current = index;
        });
        const target = links.findIndex(link => link.hash === location.hash);
        const section = sections[target];
        if (section) {
            const offset = parseFloat(getComputedStyle(documentRoot).scrollPaddingTop) || 0;
            const top = section.getBoundingClientRect().top;
            const targetY = Math.min(scrollY + top - offset, documentRoot.scrollHeight - height);
            // History can restore the reading position after an FAQ answer changes the page height.
            if (Math.abs(scrollY - Math.max(0, targetY)) <= 2 || (!compactLayout.matches && top >= headerHeight && top <= offset + 96)) current = target;
        }
        const next = links[current];
        if (!next || next === currentLink) return;
        const previous = currentLink;
        previous?.removeAttribute('aria-current');
        currentLink = next;
        next.setAttribute('aria-current', 'location');
        if (compactLayout.matches && previous && sectionMenu.scrollWidth > sectionMenu.clientWidth) {
            const menu = sectionMenu.getBoundingClientRect();
            const link = next.getBoundingClientRect();
            if (link.left < menu.left || link.right > menu.right) {
                sectionMenu.scrollBy({ left: link.left < menu.left ? link.left - menu.left : link.right - menu.right, behavior: 'instant' });
            }
        }
    }
    function scheduleCurrentSection() {
        if (!frame) frame = requestAnimationFrame(updateCurrentSection);
    }
    window.addEventListener('scroll', scheduleCurrentSection, { passive: true });
    function measureInsets() {
        const root = document.documentElement;
        headerHeight = header && getComputedStyle(header).position === 'sticky' ? header.offsetHeight : 0;
        tabsHeight = sectionMenu.offsetHeight;
        root.style.setProperty('--product-header-height', headerHeight + 'px');
        root.style.setProperty('--product-tabs-height', tabsHeight + 'px');
        scheduleCurrentSection();
    }
    window.addEventListener('resize', measureInsets, { passive: true });
    window.addEventListener('hashchange', scheduleCurrentSection);
    window.addEventListener('pageshow', scheduleCurrentSection);
    sectionMenu.addEventListener('click', event => {
        if (!compactLayout.matches || event.defaultPrevented || event.button !== 0
            || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        const link = event.target.closest('a[href^="#"]');
        const section = sections[links.indexOf(link)];
        if (!section) return;
        event.preventDefault();
        if (location.hash !== link.hash) history.pushState(history.state, '', link.hash);
        const inset = parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop) || 0;
        section.setAttribute('tabindex', '-1');
        section.focus({ preventScroll: true });
        window.scrollTo({ top: scrollY + section.getBoundingClientRect().top - inset, behavior: 'auto' });
        scheduleCurrentSection();
    });
    if (window.ResizeObserver) {
        const observer = new ResizeObserver(measureInsets);
        observer.observe(sectionMenu, { box: 'border-box' });
        if (header) observer.observe(header, { box: 'border-box' });
    }
    measureInsets();
    updateCurrentSection();
});

document.querySelectorAll('.product-feedback-form').forEach(form => {
    const button = form.querySelector('button[type="submit"]');
    const label = button.textContent;
    form.addEventListener('submit', () => {
        button.disabled = true;
        button.textContent = 'در حال ارسال…';
    });
    window.addEventListener('pageshow', () => {
        button.disabled = false;
        button.textContent = label;
    });
});
