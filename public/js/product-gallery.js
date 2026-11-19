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
        target.src = image.src;
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
    let frame = 0;
    function updateCurrentSection() {
        frame = 0;
        const documentRoot = document.documentElement;
        const height = documentRoot.clientHeight;
        const reachedEnd = window.scrollY + height >= documentRoot.scrollHeight - 2;
        const headerHeight = header && getComputedStyle(header).position === 'sticky' ? header.offsetHeight : 0;
        const menuBottom = getComputedStyle(sectionMenu).position === 'sticky' ? sectionMenu.offsetHeight + 16 : 0;
        const boundary = reachedEnd ? height - 1 : Math.max(headerHeight + 40, menuBottom);
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
            if (Math.abs(scrollY - Math.max(0, targetY)) <= 2 || (top >= headerHeight && top <= offset + 96)) current = target;
        }
        links.forEach((link, index) => {
            if (index === current) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        });
    }
    function scheduleCurrentSection() {
        if (!frame) frame = requestAnimationFrame(updateCurrentSection);
    }
    window.addEventListener('scroll', scheduleCurrentSection, { passive: true });
    window.addEventListener('resize', scheduleCurrentSection, { passive: true });
    window.addEventListener('hashchange', scheduleCurrentSection);
    window.addEventListener('pageshow', scheduleCurrentSection);
    if (window.ResizeObserver) {
        new ResizeObserver(() => {
            if (sectionMenu.hasAttribute('data-product-sections')) {
                document.documentElement.style.setProperty('--product-tabs-height', sectionMenu.offsetHeight + 'px');
            }
            scheduleCurrentSection();
        }).observe(sectionMenu);
    }
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
