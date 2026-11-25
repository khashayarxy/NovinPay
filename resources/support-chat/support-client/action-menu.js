// Button-operated menus retain the open property used by existing lifecycle code.
export function actionMenu(panel, button) {
    panel.dataset.supportMenu = '';
    button.type = 'button';
    button.dataset.supportMenuToggle = '';
    Object.defineProperty(panel, 'open', {
        configurable: true,
        get: () => panel.hasAttribute('open'),
        set(value) {
            panel.toggleAttribute('open', !!value);
            button.setAttribute('aria-expanded', String(!!value));
        },
    });
    panel.open = panel.hasAttribute('open');
    button.addEventListener('click', () => { panel.open = !panel.open; });
    panel.addEventListener('keydown', event => {
        if (event.key === 'Escape' && panel.open) {
            panel.open = false;
            button.focus();
            event.preventDefault();
            event.stopPropagation();
        }
    });
    panel.addEventListener('focusout', event => {
        if (event.relatedTarget && !panel.contains(event.relatedTarget)) panel.open = false;
    });
    return panel;
}
