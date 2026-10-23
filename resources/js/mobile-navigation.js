export function initMobileNavigation() {
    const header = document.querySelector('[data-mobile-header]');
    const menu = header?.querySelector('.np-mobile-menu');
    const toggle = menu?.querySelector('summary');
    if (!header || !menu || !toggle) return;

    const mobile = window.matchMedia('(max-width: 850px)');
    const syncMenu = () => {
        toggle.setAttribute('aria-expanded', String(menu.open));
        toggle.setAttribute('aria-label', menu.open ? 'بستن فهرست ناوبری' : 'فهرست ناوبری');
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

    const resize = () => {
        if (!mobile.matches) close();
        header.style.setProperty('--np-header-height', `${header.offsetHeight}px`);
    };
    new ResizeObserver(resize).observe(header);
    window.addEventListener('resize', resize, { passive: true });
    window.addEventListener('pageshow', () => close());
    window.addEventListener('pagehide', () => close());
    syncMenu();
    resize();
}
