// One document lock for storefront layers. Keep sticky and fixed elements in their original containing blocks.
(() => {
    const root = document.documentElement;
    const owners = new Set();
    let saved;
    const viewportProperty = '--storefront-unlocked-viewport';
    function setViewportWidth() {
        root.style.setProperty(viewportProperty, innerWidth + 'px');
    }

    window.addEventListener('resize', () => {
        if (saved?.preserveViewportWidth) setViewportWidth();
    }, {passive: true});

    window.StorefrontScrollLock = {
        acquire(owner) {
            if (owners.has(owner)) return;
            if (!owners.size) {
                saved = {
                    x: scrollX,
                    y: scrollY,
                    overflowY: root.style.overflowY,
                    gutter: root.style.scrollbarGutter,
                    viewport: root.style.getPropertyValue(viewportProperty),
                };
                const needsGutter = innerWidth > root.clientWidth;
                if (needsGutter && getComputedStyle(root).scrollbarGutter === 'auto') {
                    // Chromium sizes vw from a stable gutter. Preserve the prior viewport for width-based artwork.
                    saved.preserveViewportWidth = true;
                    setViewportWidth();
                    root.style.scrollbarGutter = 'stable';
                }
                root.style.overflowY = 'hidden';
                root.classList.add('storefront-overlay-open');
                document.body.classList.add('storefront-overlay-open');
            }
            owners.add(owner);
        },
        release(owner) {
            if (!owners.delete(owner) || owners.size) return;
            root.style.overflowY = saved.overflowY;
            root.style.scrollbarGutter = saved.gutter;
            root.style.setProperty(viewportProperty, saved.viewport);
            root.classList.remove('storefront-overlay-open');
            document.body.classList.remove('storefront-overlay-open', 'keyboard-open');
            if (scrollX !== saved.x || scrollY !== saved.y) {
                scrollTo({left: saved.x, top: saved.y, behavior: 'instant'});
            }
            saved = null;
            document.dispatchEvent(new Event('storefront-scroll:released'));
        },
    };
})();
