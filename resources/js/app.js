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
    const activeAccountTab = document.querySelector('.np-account-nav a[aria-current="page"]');
    if (activeAccountTab instanceof HTMLElement && typeof activeAccountTab.scrollIntoView === 'function') {
        activeAccountTab.scrollIntoView({ behavior: 'instant', inline: 'center', block: 'nearest' });
    }
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

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const dropdown = document.querySelector('[data-desktop-shop-dropdown]:is(:hover, :focus-within)');
    if (!dropdown) return;
    dropdown.classList.add('is-dismissed');
    if (document.activeElement instanceof HTMLElement && dropdown.contains(document.activeElement)) {
        document.activeElement.blur();
    }
});

document.querySelectorAll('[data-desktop-shop-dropdown]').forEach((dropdown) => {
    dropdown.addEventListener('mouseleave', () => dropdown.classList.remove('is-dismissed'));
    dropdown.addEventListener('focusin', () => dropdown.classList.remove('is-dismissed'));
});

// 4-step fintech checkout stepper, Persian inline validation & interactive OSM pin map
document.querySelectorAll('[data-checkout-stepper]').forEach((stepper) => {
    const form = stepper.querySelector('[data-store-checkout]');
    if (!(form instanceof HTMLFormElement)) return;
    const panels = Array.from(stepper.querySelectorAll('[data-checkout-step]'));
    const indicators = Array.from(stepper.querySelectorAll('[data-step-indicator]'));
    if (panels.length < 4) return;

    const toLatinDigits = (str) => String(str || '')
        .replace(/[\u06F0-\u06F9]/g, (d) => String(d.charCodeAt(0) - 0x06F0))
        .replace(/[\u0660-\u0669]/g, (d) => String(d.charCodeAt(0) - 0x0660));

    const isValidNationalCode = (raw) => {
        const code = toLatinDigits(raw).replace(/\s+/g, '');
        if (!/^[0-9]{10}$/.test(code) || /^(\d)\1{9}$/.test(code)) return false;
        let sum = 0;
        for (let i = 0; i < 9; i++) sum += Number(code[i]) * (10 - i);
        const rem = sum % 11;
        const chk = Number(code[9]);
        return rem < 2 ? chk === rem : chk === 11 - rem;
    };

    const setFieldError = (name, message) => {
        const el = form.querySelector(`[name="${name}"]`);
        const err = form.querySelector(`[data-error-for="${name}"]`);
        if (el) el.classList.toggle('is-invalid', Boolean(message));
        if (err) {
            err.textContent = message || '';
            err.hidden = !message;
        }
    };

    const clearStepErrors = (stepNum) => {
        const panel = panels.find((p) => Number(p.dataset.checkoutStep) === stepNum);
        if (!panel) return;
        panel.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        panel.querySelectorAll('.checkout-field-error').forEach((el) => {
            el.textContent = '';
            el.hidden = true;
        });
    };

    const validateStep1 = () => {
        clearStepErrors(1);
        let ok = true;
        const fullName = form.querySelector('[name="full_name"]')?.value.trim() || '';
        const mobile = toLatinDigits(form.querySelector('[name="mobile"]')?.value || '').replace(/\s+/g, '');
        const nationalCode = toLatinDigits(form.querySelector('[name="national_code"]')?.value || '').replace(/\s+/g, '');
        const email = form.querySelector('[name="email"]')?.value.trim() || '';

        if (fullName.length < 2) {
            setFieldError('full_name', 'لطفاً نام و نام خانوادگی معتبر (حداقل ۲ کاراکتر) وارد کنید.');
            ok = false;
        }
        if (!/^09[0-9]{9}$/.test(mobile)) {
            setFieldError('mobile', 'لطفاً شماره موبایل معتبر ۱۱ رقمی وارد کنید (مانند ۰۹۱۲۳۴۵۶۷۸۹).');
            ok = false;
        }
        if (nationalCode !== '' && !isValidNationalCode(nationalCode)) {
            setFieldError('national_code', 'کد ملی واردشده معتبر نیست.');
            ok = false;
        }
        if (email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            setFieldError('email', 'لطفاً نشانی ایمیل معتبر وارد کنید.');
            ok = false;
        }
        if (!ok) {
            form.querySelector('[data-checkout-step="1"] .is-invalid')?.focus();
        }
        return ok;
    };

    const getAddressMode = () => {
        const checked = form.querySelector('input[name="address_mode"]:checked');
        if (checked) return checked.value;
        const hidden = form.querySelector('input[type="hidden"][name="address_mode"]');
        return hidden ? hidden.value : 'new';
    };

    const validateAddressIfShipping = (strict) => {
        clearStepErrors(2);
        const mode = getAddressMode();
        let ok = true;
        if (mode === 'saved') {
            const sel = form.querySelector('select[name="address"]');
            if (strict && (!sel || !sel.value)) {
                setFieldError('address', 'لطفاً یک نشانی ذخیره‌شده انتخاب کنید یا گزینه ثبت نشانی جدید را بزنید.');
                ok = false;
            }
            return ok;
        }
        const province = form.querySelector('[name="province"]')?.value.trim() || '';
        const city = form.querySelector('[name="city"]')?.value.trim() || '';
        const street = form.querySelector('[name="street_address"]')?.value.trim() || '';
        const postal = toLatinDigits(form.querySelector('[name="postal_code"]')?.value || '').replace(/\s+/g, '');
        const touched = Boolean(province || city || street || postal);

        if (!strict && !touched) return true;

        if (!province) {
            setFieldError('province', 'لطفاً استان محل تحویل را انتخاب کنید.');
            ok = false;
        }
        if (!city) {
            setFieldError('city', 'لطفاً شهر محل تحویل را انتخاب کنید.');
            ok = false;
        }
        if (street.length < 10) {
            setFieldError('street_address', 'آدرس دقیق باید حداقل ۱۰ کاراکتر باشد.');
            ok = false;
        }
        if (!/^[0-9]{10}$/.test(postal)) {
            setFieldError('postal_code', 'کد پستی باید دقیقاً ۱۰ رقم بدون خط تیره باشد.');
            ok = false;
        }
        return ok;
    };

    const validateStep3 = () => {
        clearStepErrors(3);
        const methodInput = form.querySelector('input[name="method"]:checked');
        if (!methodInput) {
            setFieldError('method', 'لطفاً نحوه ارسال سفارش را انتخاب کنید.');
            return false;
        }
        if (methodInput.value !== 'pickup' && !validateAddressIfShipping(true)) {
            const errBox = form.querySelector('[data-step3-address-error]');
            if (errBox) {
                errBox.textContent = 'برای ارسال غیرحضوری، تکمیل نشانی در مرحله ۲ الزامی است. به مرحله قبل بازگردید و نشانی را کامل کنید.';
                errBox.hidden = false;
            }
            return false;
        }
        return true;
    };

    const submitBtn = form.querySelector('[data-checkout-submit]');
    const confirmPerson = form.querySelector('[data-confirm-person]');
    const confirmDelivery = form.querySelector('[data-confirm-delivery]');
    const confirmAddressRow = form.querySelector('[data-confirm-address-row]');
    const confirmAddress = form.querySelector('[data-confirm-address]');
    const confirmPayment = form.querySelector('[data-confirm-payment]');

    const syncSummary = () => {
        const fullName = form.querySelector('[name="full_name"]')?.value.trim() || '';
        const mobile = form.querySelector('[name="mobile"]')?.value.trim() || '';
        if (confirmPerson) {
            confirmPerson.textContent = [fullName, mobile].filter(Boolean).join(' · ') || '—';
        }
        const methodInput = form.querySelector('input[name="method"]:checked');
        const method = methodInput ? methodInput.value : 'pickup';
        if (confirmDelivery) {
            confirmDelivery.textContent = methodInput?.dataset.methodLabel || 'تحویل حضوری در فروشگاه نوین پی';
        }
        if (confirmAddressRow && confirmAddress) {
            if (method === 'pickup') {
                confirmAddressRow.hidden = true;
            } else {
                confirmAddressRow.hidden = false;
                if (getAddressMode() === 'saved') {
                    const sel = form.querySelector('select[name="address"]');
                    confirmAddress.textContent = sel instanceof HTMLSelectElement && sel.value ? sel.options[sel.selectedIndex]?.text : '—';
                } else {
                    const p = form.querySelector('[name="province"]')?.value || '';
                    const c = form.querySelector('[name="city"]')?.value || '';
                    const s = form.querySelector('[name="street_address"]')?.value || '';
                    confirmAddress.textContent = [p, c, s].filter(Boolean).join('، ') || '—';
                }
            }
        }
        const paymentInput = form.querySelector('input[name="payment_method"]:checked');
        const payment = paymentInput ? paymentInput.value : 'request';
        if (confirmPayment) {
            confirmPayment.textContent = paymentInput?.dataset.paymentLabel || (payment === 'zibal' ? 'پرداخت آنلاین با درگاه زیبال' : 'ثبت درخواست سفارش');
        }
        if (submitBtn instanceof HTMLButtonElement) {
            submitBtn.textContent = payment === 'zibal'
                ? (submitBtn.dataset.labelZibal || 'پرداخت آنلاین با زیبال')
                : (submitBtn.dataset.labelRequest || 'ثبت درخواست سفارش');
        }
    };

    const syncAddressSections = () => {
        const mode = getAddressMode();
        form.querySelectorAll('[data-address-section]').forEach((box) => {
            box.hidden = box.dataset.addressSection !== mode;
        });
    };

    let onStep2Visible = null;

    const goToStep = (stepNumber) => {
        const target = Math.max(1, Math.min(4, stepNumber));
        syncSummary();
        panels.forEach((panel) => {
            panel.classList.toggle('is-active', Number(panel.dataset.checkoutStep) === target);
        });
        indicators.forEach((ind) => {
            const num = Number(ind.dataset.stepIndicator);
            ind.classList.toggle('is-active', num === target);
            ind.classList.toggle('is-done', num < target);
            if (num === target) ind.setAttribute('aria-current', 'step');
            else ind.removeAttribute('aria-current');
        });
        if (target === 2 && typeof onStep2Visible === 'function') {
            requestAnimationFrame(() => onStep2Visible());
        }
    };

    stepper.classList.add('is-stepped');
    syncAddressSections();
    const errorPanel = panels.find((p) => p.querySelector('.checkout-field-error:not([hidden])'));
    goToStep(errorPanel ? Number(errorPanel.dataset.checkoutStep) : 1);

    form.addEventListener('change', () => {
        syncAddressSections();
        syncSummary();
    });
    form.addEventListener('input', (e) => {
        const target = e.target;
        if (target instanceof HTMLElement && target.classList.contains('is-invalid')) {
            setFieldError(target.getAttribute('name') || '', '');
        }
    });

    stepper.querySelectorAll('[data-checkout-next]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const next = Number(btn.dataset.checkoutNext);
            if (next === 2 && !validateStep1()) return;
            if (next === 3 && !validateAddressIfShipping(false)) return;
            if (next === 4 && !validateStep3()) return;
            goToStep(next);
        });
    });
    stepper.querySelectorAll('[data-checkout-prev]').forEach((btn) => {
        btn.addEventListener('click', () => goToStep(Number(btn.dataset.checkoutPrev)));
    });

    form.addEventListener('submit', (e) => {
        if (!validateStep1()) {
            e.preventDefault();
            goToStep(1);
            return;
        }
        if (!validateStep3()) {
            e.preventDefault();
            goToStep(3);
        }
    });

    // Interactive Neshan Web Map (with non-blocked CartoDB Voyager tile fallback when NESHAN_WEB_KEY is empty)
    const mapBox = form.querySelector('[data-checkout-map]');
    if (mapBox) {
        const viewport = mapBox.querySelector('[data-map-viewport]');
        const tilesEl = mapBox.querySelector('[data-map-tiles]');
        const coordsEl = mapBox.querySelector('[data-map-coords]');
        const latInput = mapBox.querySelector('[data-map-lat]');
        const lngInput = mapBox.querySelector('[data-map-lng]');
        const neshanKey = (mapBox.dataset.neshanKey || '').trim();

        let lat = parseFloat(mapBox.dataset.defaultLat || '35.6597') || 35.6597;
        let lng = parseFloat(mapBox.dataset.defaultLng || '51.0592') || 51.0592;
        let zoom = 14;
        let neshanMap = null;

        const saveCoordinates = (la, ln) => {
            lat = Math.max(-85, Math.min(85, la));
            lng = Math.max(-180, Math.min(180, ln));
            const fLat = lat.toFixed(6);
            const fLng = lng.toFixed(6);
            if (latInput) latInput.value = fLat;
            if (lngInput) lngInput.value = fLng;
            if (coordsEl) coordsEl.textContent = `${fLat}, ${fLng}`;
        };

        const latLngToWorld = (la, ln, z) => {
            const scale = 256 * Math.pow(2, z);
            const x = ((ln + 180) / 360) * scale;
            const sinLat = Math.sin((la * Math.PI) / 180);
            const y = (0.5 - Math.log((1 + sinLat) / (1 - sinLat)) / (4 * Math.PI)) * scale;
            return { x, y };
        };
        const worldToLatLng = (x, y, z) => {
            const scale = 256 * Math.pow(2, z);
            const ln = (x / scale) * 360 - 180;
            const n = Math.PI - (2 * Math.PI * y) / scale;
            const la = (180 / Math.PI) * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
            return { lat: Math.max(-85, Math.min(85, la)), lng: Math.max(-180, Math.min(180, ln)) };
        };

        const subdomains = ['a', 'b', 'c', 'd'];
        const renderMap = (saveInputs = false) => {
            if (saveInputs) saveCoordinates(lat, lng);
            if (neshanMap) {
                neshanMap.setView([lat, lng], zoom, { animate: false });
                return;
            }
            if (!viewport || !tilesEl) return;
            const w = viewport.clientWidth || 420;
            const h = viewport.clientHeight || 230;
            const center = latLngToWorld(lat, lng, zoom);
            const minTx = Math.floor((center.x - w / 2) / 256);
            const maxTx = Math.floor((center.x + w / 2) / 256);
            const minTy = Math.floor((center.y - h / 2) / 256);
            const maxTy = Math.floor((center.y + h / 2) / 256);
            const maxIndex = Math.pow(2, zoom);

            tilesEl.innerHTML = '';
            for (let tx = minTx; tx <= maxTx; tx++) {
                for (let ty = minTy; ty <= maxTy; ty++) {
                    if (ty < 0 || ty >= maxIndex) continue;
                    const wrappedX = ((tx % maxIndex) + maxIndex) % maxIndex;
                    const sub = subdomains[(wrappedX + ty) % subdomains.length];
                    const img = document.createElement('img');
                    img.src = `https://${sub}.basemaps.cartocdn.com/rastertiles/voyager/${zoom}/${wrappedX}/${ty}.png`;
                    img.alt = '';
                    img.decoding = 'async';
                    img.style.left = `${Math.round(tx * 256 - center.x + w / 2)}px`;
                    img.style.top = `${Math.round(ty * 256 - center.y + h / 2)}px`;
                    tilesEl.appendChild(img);
                }
            }
        };

        let dragging = false;
        let dragStart = null;
        let moved = false;

        viewport?.addEventListener('pointerdown', (e) => {
            if (neshanMap) return;
            dragging = true;
            moved = false;
            dragStart = { x: e.clientX, y: e.clientY, world: latLngToWorld(lat, lng, zoom) };
            viewport.setPointerCapture?.(e.pointerId);
        });
        viewport?.addEventListener('pointermove', (e) => {
            if (neshanMap || !dragging || !dragStart) return;
            const dx = e.clientX - dragStart.x;
            const dy = e.clientY - dragStart.y;
            if (Math.abs(dx) > 3 || Math.abs(dy) > 3) moved = true;
            const next = worldToLatLng(dragStart.world.x - dx, dragStart.world.y - dy, zoom);
            lat = next.lat;
            lng = next.lng;
            renderMap(true);
        });
        viewport?.addEventListener('pointerup', (e) => {
            if (neshanMap || !dragging) return;
            dragging = false;
            if (!moved && viewport) {
                const rect = viewport.getBoundingClientRect();
                const dx = e.clientX - rect.left - rect.width / 2;
                const dy = e.clientY - rect.top - rect.height / 2;
                const center = latLngToWorld(lat, lng, zoom);
                const next = worldToLatLng(center.x + dx, center.y + dy, zoom);
                lat = next.lat;
                lng = next.lng;
                renderMap(true);
            }
        });

        mapBox.querySelectorAll('[data-map-zoom]').forEach((btn) => {
            btn.addEventListener('click', () => {
                zoom = btn.dataset.mapZoom === 'in' ? Math.min(18, zoom + 1) : Math.max(5, zoom - 1);
                if (neshanMap) {
                    neshanMap.setZoom(zoom);
                } else {
                    renderMap(Boolean(latInput?.value));
                }
            });
        });

        mapBox.querySelector('[data-map-locate]')?.addEventListener('click', () => {
            if (!navigator.geolocation) return;
            navigator.geolocation.getCurrentPosition((pos) => {
                lat = pos.coords.latitude;
                lng = pos.coords.longitude;
                zoom = 15;
                saveCoordinates(lat, lng);
                if (neshanMap) {
                    neshanMap.setView([lat, lng], zoom);
                } else {
                    renderMap(true);
                }
            });
        });

        // Boot Neshan Leaflet Web SDK if NESHAN_WEB_KEY is configured
        const bootNeshanSdk = () => {
            if (!neshanKey || !viewport || !tilesEl || neshanMap) return;
            const mountNeshan = () => {
                if (!window.L || typeof window.L.Map !== 'function' || neshanMap) return;
                try {
                    tilesEl.innerHTML = '';
                    tilesEl.style.pointerEvents = 'auto';
                    neshanMap = new window.L.Map(tilesEl, {
                        key: neshanKey,
                        maptype: 'neshan',
                        poi: true,
                        traffic: false,
                        center: [lat, lng],
                        zoom,
                        zoomControl: false,
                    });
                    neshanMap.on('move', () => {
                        const c = neshanMap.getCenter();
                        if (c) saveCoordinates(c.lat, c.lng);
                    });
                    neshanMap.on('zoomend', () => {
                        zoom = neshanMap.getZoom() || zoom;
                    });
                    neshanMap.on('click', (ev) => {
                        if (ev && ev.latlng) {
                            saveCoordinates(ev.latlng.lat, ev.latlng.lng);
                            neshanMap.panTo(ev.latlng);
                        }
                    });
                    setTimeout(() => neshanMap?.invalidateSize(), 60);
                } catch {
                    neshanMap = null;
                    tilesEl.style.pointerEvents = 'none';
                    renderMap(Boolean(latInput?.value));
                }
            };

            if (window.L && typeof window.L.Map === 'function') {
                mountNeshan();
                return;
            }
            if (!document.getElementById('neshan-leaflet-css')) {
                const link = document.createElement('link');
                link.id = 'neshan-leaflet-css';
                link.rel = 'stylesheet';
                link.href = 'https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/index.css';
                document.head.appendChild(link);
            }
            if (!document.getElementById('neshan-leaflet-js')) {
                const script = document.createElement('script');
                script.id = 'neshan-leaflet-js';
                script.src = 'https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/index.js';
                script.async = true;
                script.onload = mountNeshan;
                document.head.appendChild(script);
            }
        };

        onStep2Visible = () => {
            if (neshanMap) {
                neshanMap.invalidateSize();
            } else {
                renderMap(Boolean(latInput?.value));
                bootNeshanSdk();
            }
        };

        renderMap(Boolean(latInput?.value));
        if (neshanKey) bootNeshanSdk();
    }
});
