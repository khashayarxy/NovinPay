import { initSimpleRepair } from './admin-simple-repair';
import { initMobileNavigation } from './mobile-navigation';
import { initAdminCalendar } from './admin-calendar';
import { initAdminDigits } from './admin-digits';

document.addEventListener('DOMContentLoaded', () => {
    initMobileNavigation();
    initSimpleRepair();
    const admin = document.querySelector('.admin-site #main-content');
    if (admin) {
        initAdminCalendar(admin);
        initAdminDigits(admin);
    }
    const adminNavigation = document.querySelector('[data-admin-navigation]');
    if (adminNavigation instanceof HTMLDetailsElement) {
        const desktop = window.matchMedia('(min-width: 801px)');
        const sidebar = adminNavigation.closest('.admin-sidebar');
        const toggle = document.querySelector('[data-admin-sidebar-toggle]');
        const updateNavigation = () => {
            adminNavigation.open = desktop.matches;
            if (sidebar instanceof HTMLElement) sidebar.hidden = false;
            if (toggle instanceof HTMLButtonElement) {
                toggle.hidden = !desktop.matches;
                toggle.setAttribute('aria-expanded', 'true');
            }
        };
        updateNavigation();
        desktop.addEventListener('change', updateNavigation);
        window.addEventListener('pageshow', (event) => {
            if (event.persisted && !desktop.matches) adminNavigation.open = false;
        });
        if (sidebar instanceof HTMLElement && toggle instanceof HTMLButtonElement) {
            toggle.addEventListener('click', () => {
                if (!desktop.matches) return;
                sidebar.hidden = !sidebar.hidden;
                toggle.setAttribute('aria-expanded', String(!sidebar.hidden));
            });
        }
        adminNavigation.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || desktop.matches || !adminNavigation.open) return;
            adminNavigation.open = false;
            adminNavigation.querySelector('summary')?.focus();
        });
        const groups = [...adminNavigation.querySelectorAll('.admin-nav-group')];
        const closeOtherGroups = (current) => {
            groups.forEach((group) => {
                if (group !== current && group.open) group.open = false;
            });
        };
        groups.forEach((group) => group.addEventListener('toggle', () => {
            if (group.open) closeOtherGroups(group);
        }));
        adminNavigation.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const link = event.target instanceof Element ? event.target.closest('.admin-nav a') : null;
            if (!link) return;
            if (link.hasAttribute('data-admin-category')) {
                const group = link.nextElementSibling;
                if (group instanceof HTMLDetailsElement) {
                    closeOtherGroups(group);
                    group.open = true;
                }
            }
            if (!desktop.matches) adminNavigation.open = false;
        });
    }
    document.getElementById('error-summary')?.focus();

    document.querySelectorAll('[data-account-password]').forEach((button) => {
        const input = document.getElementById(button.dataset.accountPassword);
        if (!(input instanceof HTMLInputElement)) return;
        button.hidden = false;
        button.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.textContent = visible ? 'پنهان کردن' : 'نمایش';
        });
    });

    const resend = document.querySelector('[data-account-resend]');
    if (resend instanceof HTMLButtonElement && Number(resend.dataset.accountResend) > 0) {
        const until = Date.now() + Number(resend.dataset.accountResend) * 1000;
        const label = resend.textContent;
        const format = new Intl.NumberFormat('fa-IR');
        const update = () => {
            const remaining = Math.max(0, Math.ceil((until - Date.now()) / 1000));
            resend.disabled = remaining > 0;
            resend.textContent = remaining ? `ارسال دوباره پس از ${format.format(remaining)} ثانیه` : label;
            if (remaining) window.setTimeout(update, 1000);
        };
        update();
    }

    if (window.isSecureContext && navigator.clipboard?.writeText) {
        document.querySelectorAll('[data-copy-address]').forEach((button) => {
            button.hidden = false;
            button.addEventListener('click', async () => {
                const address = document.getElementById(button.dataset.copyAddress);
                const status = document.getElementById(button.getAttribute('aria-describedby'));
                if (!address || !status) return;
                status.textContent = '';
                try {
                    await navigator.clipboard.writeText(address.textContent.trim());
                    status.textContent = 'نشانی کپی شد.';
                } catch {
                    status.textContent = 'کپی خودکار انجام نشد؛ نشانی را انتخاب و کپی کنید.';
                }
            });
        });
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });

    const rows = document.getElementById('spec-rows');
    const template = document.getElementById('spec-row-template');

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-add-order-line]')) {
            const container = document.getElementById('order-line-rows');
            const lineTemplate = document.getElementById('order-line-template');
            if (!container || !lineTemplate || container.children.length >= 30) return;
            const next = Number(container.dataset.nextIndex ?? 0);
            const fragment = lineTemplate.content.cloneNode(true);
            fragment.querySelectorAll('[id],[for],[name]').forEach((element) => {
                ['id','for','name'].forEach((attribute) => {
                    if (element.hasAttribute(attribute)) element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('__INDEX__', String(next)));
                });
            });
            container.append(fragment);
            if (admin) initAdminDigits(container);
            container.dataset.nextIndex = String(next + 1);
            container.lastElementChild?.querySelector('input')?.focus();
            return;
        }
        const removeLine = event.target.closest('[data-remove-order-line]');
        if (removeLine) {
            const container = document.getElementById('order-line-rows');
            if (container?.children.length > 1) removeLine.closest('[data-order-line]')?.remove();
            return;
        }
        if (event.target.closest('[data-print-document]')) {
            window.print();
            return;
        }
        const addButton = event.target.closest('[data-add-spec-row]');
        if (addButton && rows && template) {
            let nextIndex = Number(rows.dataset.specNextIndex ?? 0);
            const fragment = template.content.cloneNode(true);
            fragment.querySelectorAll('*').forEach((element) => {
                if (element.hasAttribute('id')) {
                    element.setAttribute('id', element.getAttribute('id').replace('__INDEX__', String(nextIndex)));
                }
                if (element.hasAttribute('for')) {
                    element.setAttribute('for', element.getAttribute('for').replace('__INDEX__', String(nextIndex)));
                }
            });
            rows.append(fragment);
            rows.dataset.specNextIndex = String(nextIndex + 1);
            rows.lastElementChild?.querySelector('input')?.focus();
            return;
        }

        const removeButton = event.target.closest('[data-remove-spec-row]');
        if (removeButton) {
            removeButton.closest('[data-spec-row]')?.remove();
        }
    });
});


document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-card]');
    if (!button) return;
    const status = button.parentElement.querySelector('[data-copy-result]');
    try {
        await navigator.clipboard.writeText(button.dataset.copyCard);
        if (status) status.textContent = 'شمارهٔ کارت کپی شد.';
    } catch {
        if (status) status.textContent = 'کپی نشد؛ شماره را انتخاب و کپی کنید.';
    }
});
