import { initMobileNavigation } from './mobile-navigation';
import { initClickMap } from './click-map';
import { initCustomerNavigation } from './customer-navigation';

if (typeof BroadcastChannel !== 'undefined') {
    const commerce = new BroadcastChannel('novinpay-commerce');
    document.addEventListener('submit', event => {
        const path = new URL(event.target.action, location.href);
        if (path.origin === location.origin && /^\/account\/(verify|password-login|logout|password)$/.test(path.pathname)) {
            commerce.postMessage('identity-change');
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initClickMap();
    initMobileNavigation();
    initCustomerNavigation();
    const admin = document.querySelector('.admin-site #main-content');
    if (admin) {
        import('./admin-fields.js').then(({initAdminFields}) => initAdminFields(admin));
    }
    const staffRoleForm = document.querySelector('[data-staff-role-form]');
    if (staffRoleForm) {
        const radios = [...staffRoleForm.querySelectorAll('input[name="access_level"]')];
        const panel = staffRoleForm.querySelector('[data-staff-permissions-panel]');
        const note = staffRoleForm.querySelector('[data-staff-manager-note]');
        const toggleAll = staffRoleForm.querySelector('[data-staff-toggle-all]');
        const boxes = [...staffRoleForm.querySelectorAll('input[name="permissions[]"]')];
        const syncToggleLabel = () => {
            if (!toggleAll || !boxes.length) return;
            const allChecked = boxes.every((box) => box.checked);
            toggleAll.textContent = allChecked ? 'لغو همه' : 'انتخاب همه';
        };
        const syncRole = () => {
            const isManager = radios.some((radio) => radio.checked && radio.value === 'manager');
            if (panel) panel.hidden = isManager;
            if (note) note.hidden = !isManager;
        };
        radios.forEach((radio) => radio.addEventListener('change', syncRole));
        boxes.forEach((box) => box.addEventListener('change', syncToggleLabel));
        toggleAll?.addEventListener('click', () => {
            const shouldCheck = !boxes.every((box) => box.checked);
            boxes.forEach((box) => { box.checked = shouldCheck; });
            syncToggleLabel();
        });
        syncRole();
        syncToggleLabel();
    }
    const repairStepper = document.querySelector('[data-repair-stepper]');
    if (repairStepper instanceof HTMLFormElement) {
        const steps = [...repairStepper.querySelectorAll('[data-step]')];
        const indicators = [...repairStepper.querySelectorAll('[data-step-indicator]')];
        const alertBox = repairStepper.querySelector('[data-step-alert]');
        const brandRadios = [...repairStepper.querySelectorAll('input[name="device_brand"]')];
        const modelInput = repairStepper.querySelector('#device_model');
        const issueInput = repairStepper.querySelector('#issue');
        const nameInput = repairStepper.querySelector('#customer_name');
        const presetButtons = [...repairStepper.querySelectorAll('[data-issue-preset]')];
        const summaryDevice = repairStepper.querySelector('[data-summary-device]');
        const summaryIssue = repairStepper.querySelector('[data-summary-issue]');
        let currentStep = Math.min(3, Math.max(1, Number(repairStepper.dataset.initialStep || 1)));

        const showAlert = (message) => {
            if (!alertBox) return;
            alertBox.textContent = message;
            alertBox.hidden = !message;
        };

        const syncSummary = () => {
            const brand = brandRadios.find((r) => r.checked)?.value || '';
            const model = (modelInput?.value || '').trim();
            const issue = (issueInput?.value || '').trim();
            if (summaryDevice) summaryDevice.textContent = [brand, model].filter(Boolean).join(' — ') || '—';
            if (summaryIssue) summaryIssue.textContent = issue.length > 90 ? `${issue.slice(0, 90)}…` : (issue || '—');
        };

        const goToStep = (step) => {
            currentStep = step;
            showAlert('');
            steps.forEach((panel) => {
                panel.hidden = Number(panel.dataset.step) !== currentStep;
            });
            indicators.forEach((item) => {
                const idx = Number(item.dataset.stepIndicator);
                item.setAttribute('aria-current', idx === currentStep ? 'step' : 'false');
                item.toggleAttribute('data-step-done', idx < currentStep);
            });
            if (currentStep === 3) syncSummary();
        };

        const validateStep = (step) => {
            if (step === 1) {
                const brand = brandRadios.find((r) => r.checked);
                if (!brand) {
                    showAlert('لطفاً برند دستگاه را انتخاب کنید.');
                    brandRadios[0]?.focus();
                    return false;
                }
                const model = (modelInput?.value || '').trim();
                if (!model) {
                    showAlert('لطفاً مدل دستگاه را وارد کنید.');
                    modelInput?.focus();
                    return false;
                }
            } else if (step === 2) {
                const issue = (issueInput?.value || '').trim();
                if (issue.length < 10) {
                    showAlert('شرح مشکل دستگاه باید دست‌کم ۱۰ کاراکتر باشد.');
                    issueInput?.focus();
                    return false;
                }
            } else if (step === 3) {
                const name = (nameInput?.value || '').trim();
                if (name.length < 2) {
                    showAlert('لطفاً نام و نام خانوادگی را وارد کنید.');
                    nameInput?.focus();
                    return false;
                }
            }
            return true;
        };

        presetButtons.forEach((button) => {
            button.addEventListener('click', () => {
                presetButtons.forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
                const presetText = button.dataset.issuePreset || '';
                if (issueInput) {
                    if (presetText) {
                        issueInput.value = presetText;
                    } else {
                        issueInput.value = '';
                        issueInput.focus();
                    }
                }
                showAlert('');
            });
        });

        repairStepper.querySelectorAll('[data-step-next]').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (validateStep(currentStep)) goToStep(Math.min(3, currentStep + 1));
            });
        });

        repairStepper.querySelectorAll('[data-step-prev]').forEach((btn) => {
            btn.addEventListener('click', () => goToStep(Math.max(1, currentStep - 1)));
        });

        repairStepper.addEventListener('submit', (event) => {
            for (const step of [1, 2, 3]) {
                if (!validateStep(step)) {
                    event.preventDefault();
                    goToStep(step);
                    validateStep(step);
                    return;
                }
            }
        });

        goToStep(currentStep);
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

    document.querySelectorAll('[data-otp-boxes]').forEach((container) => {
        const hiddenInput = container.querySelector('input[name="code"]');
        const grid = container.querySelector('[data-otp-grid]');
        const boxes = [...container.querySelectorAll('.np-otp-box')];
        if (!(hiddenInput instanceof HTMLInputElement) || !(grid instanceof HTMLElement) || boxes.length !== 6) return;

        const normalizeDigits = (val) => String(val || '')
            .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
            .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
            .replace(/[^0-9]/g, '');

        const form = container.closest('form');
        const submitButton = form?.querySelector('button[type="submit"], button:not([type])');
        let autoSubmitted = false;

        const sync = () => {
            const value = boxes.map((b) => normalizeDigits(b.value).slice(0, 1)).join('');
            hiddenInput.value = value;
            hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
            if (value.length < 6) {
                autoSubmitted = false;
                return;
            }
            if (!autoSubmitted && form instanceof HTMLFormElement) {
                autoSubmitted = true;
                if (submitButton instanceof HTMLButtonElement) {
                    if (!submitButton.dataset.origLabel) submitButton.dataset.origLabel = submitButton.textContent || '';
                    submitButton.textContent = 'در حال بررسی…';
                    submitButton.disabled = true;
                }
                window.setTimeout(() => {
                    if (submitButton instanceof HTMLButtonElement) submitButton.disabled = false;
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                    if (submitButton instanceof HTMLButtonElement) submitButton.disabled = true;
                }, 60);
            }
        };

        container.classList.add('is-enhanced');
        grid.hidden = false;
        hiddenInput.tabIndex = -1;

        boxes.forEach((box, idx) => {
            box.addEventListener('input', () => {
                const digits = normalizeDigits(box.value);
                if (digits.length > 1) {
                    digits.slice(0, 6).split('').forEach((ch, i) => {
                        if (boxes[i]) boxes[i].value = ch;
                    });
                    boxes[Math.min(5, digits.length - 1)]?.focus();
                } else {
                    box.value = digits;
                    if (digits && idx < 5) boxes[idx + 1]?.focus();
                }
                sync();
            });

            box.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !box.value && idx > 0) {
                    boxes[idx - 1].value = '';
                    boxes[idx - 1].focus();
                    sync();
                } else if (e.key === 'ArrowLeft' && idx > 0) {
                    e.preventDefault();
                    boxes[idx - 1].focus();
                } else if (e.key === 'ArrowRight' && idx < 5) {
                    e.preventDefault();
                    boxes[idx + 1].focus();
                }
            });

            box.addEventListener('paste', (e) => {
                const pasted = normalizeDigits(e.clipboardData?.getData('text') || '');
                if (!pasted) return;
                e.preventDefault();
                pasted.slice(0, 6).split('').forEach((ch, i) => {
                    if (boxes[i]) boxes[i].value = ch;
                });
                boxes[Math.min(5, pasted.length - 1)]?.focus();
                sync();
            });
        });

        boxes[0]?.focus();
    });

    // Dependent Iranian Province -> City selector (offline local dataset)
    const iranDataEl = document.getElementById('iran-locations-data');
    if (iranDataEl) {
        let iranLocations = {};
        try {
            iranLocations = JSON.parse(iranDataEl.textContent || '{}') || {};
        } catch {}
        document.querySelectorAll('[data-iran-address-form]').forEach((form) => {
            const provinceSelect = form.querySelector('[data-iran-province]');
            const citySelect = form.querySelector('[data-iran-city]');
            if (!(provinceSelect instanceof HTMLSelectElement) || !(citySelect instanceof HTMLSelectElement)) return;
            const populateCities = (preserveCity = '') => {
                const province = provinceSelect.value;
                const cities = Array.isArray(iranLocations[province]) ? [...iranLocations[province]] : [];
                if (preserveCity && !cities.includes(preserveCity)) cities.unshift(preserveCity);
                citySelect.innerHTML = '';
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = province ? 'انتخاب شهر' : 'ابتدا استان را انتخاب کنید';
                citySelect.append(placeholder);
                cities.forEach((city) => {
                    const option = document.createElement('option');
                    option.value = city;
                    option.textContent = city;
                    if (city === preserveCity) option.selected = true;
                    citySelect.append(option);
                });
            };
            provinceSelect.addEventListener('change', () => populateCities(''));
        });
    }

    // Live Persian/Arabic digit normalization on numeric customer inputs
    document.querySelectorAll('[data-normalize-digits]').forEach((input) => {
        if (!(input instanceof HTMLInputElement)) return;
        input.addEventListener('input', () => {
            const normalized = input.value
                .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
                .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
            if (normalized !== input.value) {
                const pos = input.selectionStart;
                input.value = normalized;
                if (pos !== null) input.setSelectionRange(pos, pos);
            }
        });
    });

    // Lightweight live password strength meter
    document.querySelectorAll('[data-password-strength-input]').forEach((wrapper) => {
        const input = wrapper instanceof HTMLInputElement ? wrapper : wrapper.querySelector('input');
        const meter = wrapper.closest('form')?.querySelector('[data-password-strength]');
        const label = meter?.querySelector('[data-password-strength-label]');
        if (!(input instanceof HTMLInputElement) || !(meter instanceof HTMLElement) || !(label instanceof HTMLElement)) return;
        const evaluate = () => {
            const val = input.value || '';
            if (!val) {
                meter.hidden = true;
                meter.removeAttribute('data-level');
                return;
            }
            let score = 0;
            if (val.length >= 10) score++;
            if (val.length >= 14) score++;
            if (/[a-z]/.test(val) && /[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val) && /[^A-Za-z0-9]/.test(val)) score++;
            if (score === 0) score = 1;
            const labels = { 1: 'ضعیف (حداقل ۱۰ کاراکتر)', 2: 'متوسط', 3: 'خوب', 4: 'قوی' };
            meter.hidden = false;
            meter.dataset.level = String(score);
            label.textContent = `قدرت رمز عبور: ${labels[score]}`;
        };
        input.addEventListener('input', evaluate);
    });

    // Prevent double-submission on customer forms
    document.querySelectorAll('form[data-prevent-double-submit]').forEach((form) => {
        form.addEventListener('submit', () => {
            const btn = form.querySelector('button[type="submit"], button:not([type])');
            if (btn instanceof HTMLButtonElement && !btn.disabled) {
                btn.disabled = true;
                btn.dataset.loading = 'true';
            }
        });
    });

    const authShowcase = document.querySelector('[data-auth-showcase]');
    if (authShowcase instanceof HTMLElement) {
        const slides = [...authShowcase.querySelectorAll('[data-showcase-slide]')];
        const dots = [...authShowcase.querySelectorAll('[data-showcase-dot]')];
        if (slides.length > 1) {
            let current = 0;
            let timer = null;
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const showSlide = (index) => {
                current = (index + slides.length) % slides.length;
                slides.forEach((slide, i) => {
                    const active = i === current;
                    slide.hidden = !active;
                    slide.classList.toggle('is-active', active);
                });
                dots.forEach((dot, i) => {
                    const active = i === current;
                    dot.classList.toggle('is-active', active);
                    dot.setAttribute('aria-selected', String(active));
                });
            };
            const start = () => {
                if (reduced || timer !== null) return;
                timer = window.setInterval(() => showSlide(current + 1), 5500);
            };
            const stop = () => {
                if (timer !== null) {
                    window.clearInterval(timer);
                    timer = null;
                }
            };
            dots.forEach((dot, i) => {
                dot.addEventListener('click', () => {
                    stop();
                    showSlide(i);
                    start();
                });
            });
            authShowcase.addEventListener('mouseenter', stop);
            authShowcase.addEventListener('mouseleave', start);
            authShowcase.addEventListener('focusin', stop);
            authShowcase.addEventListener('focusout', start);
            start();
        }
    }

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

    if (window.isSecureContext && navigator.clipboard?.writeText) {
        document.querySelectorAll('[data-copy-account]').forEach((button) => {
            button.hidden = false;
            button.addEventListener('click', async () => {
                const source = document.getElementById(button.dataset.copyAccount);
                const status = document.getElementById(button.getAttribute('aria-describedby'));
                if (!source || !status) return;
                status.textContent = '';
                try {
                    await navigator.clipboard.writeText(source.textContent.trim());
                    status.textContent = 'شناسه کپی شد.';
                } catch {
                    status.textContent = 'کپی خودکار انجام نشد. شناسه را انتخاب و کپی کنید.';
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
