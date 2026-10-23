export function initMobileNavigation() {
    const header = document.querySelector('[data-mobile-header]');
    const menu = header?.querySelector('.np-mobile-menu');
    const toggle = menu?.querySelector('summary');
    if (!header || !menu || !toggle) return;

    const mobile = window.matchMedia('(max-width: 850px)');
    let previousY = window.scrollY;
    let distance = 0;
    let direction = 0;
    let scheduled = false;

    const show = () => header.classList.remove('np-header--hidden');
    const reset = () => {
        show();
        previousY = window.scrollY;
        distance = 0;
        direction = 0;
    };
    const syncMenu = () => {
        toggle.setAttribute('aria-expanded', String(menu.open));
        toggle.setAttribute('aria-label', menu.open ? 'بستن فهرست ناوبری' : 'فهرست ناوبری');
        reset();
    };
    const close = (restoreFocus = false) => {
        menu.open = false;
        syncMenu();
        if (restoreFocus) toggle.focus({ preventScroll: true });
    };

    // Native details remains usable without JavaScript. This is a nonmodal
    // disclosure: normal document tab order and background scrolling remain.
    menu.addEventListener('toggle', syncMenu);
    menu.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => close()));
    header.addEventListener('focusin', show);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.open) {
            event.preventDefault();
            close(true);
        }
    });
    document.addEventListener('pointerdown', (event) => {
        if (menu.open && !header.contains(event.target)) close();
    });
    document.addEventListener('focusin', (event) => {
        if (menu.open && !header.contains(event.target)) close();
    });

    const updateScroll = () => {
        scheduled = false;
        const y = Math.max(0, Math.min(window.scrollY, document.documentElement.scrollHeight - window.innerHeight));
        const delta = y - previousY;
        previousY = y;
        if (!mobile.matches || menu.open || y <= 32) {
            show();
            distance = 0;
            direction = 0;
            return;
        }
        if (!delta) return;
        const nextDirection = Math.sign(delta);
        distance = nextDirection === direction ? distance + Math.abs(delta) : Math.abs(delta);
        direction = nextDirection;
        if (direction < 0 && distance >= 10) show();
        if (direction > 0 && distance >= 20 && y > header.offsetHeight + 24) {
            header.classList.add('np-header--hidden');
        }
    };
    window.addEventListener('scroll', () => {
        if (!scheduled) {
            scheduled = true;
            window.requestAnimationFrame(updateScroll);
        }
    }, { passive: true });

    const resize = () => {
        if (!mobile.matches) close();
        reset();
        header.style.setProperty('--np-header-height', `${header.offsetHeight}px`);
    };
    new ResizeObserver(resize).observe(header);
    window.addEventListener('resize', resize, { passive: true });
    window.addEventListener('pageshow', () => close());
    window.addEventListener('pagehide', () => close());
    syncMenu();
    resize();
}
