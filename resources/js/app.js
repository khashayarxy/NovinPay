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
        const hasInlineOtpHandler = Boolean(form?.hasAttribute('data-account-otp'));
        const submitButton = form?.querySelector('button[type="submit"], button:not([type])');
        let autoSubmitted = false;
        let syncingInternal = false;

        const sync = () => {
            const value = boxes.map((b) => normalizeDigits(b.value).slice(0, 1)).join('');
            syncingInternal = true;
            hiddenInput.value = value;
            hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
            syncingInternal = false;
            if (value.length < 6) {
                autoSubmitted = false;
                return;
            }
            if (hasInlineOtpHandler) {
                return;
            }
            if (!autoSubmitted && form instanceof HTMLFormElement) {
                autoSubmitted = true;
                if (submitButton instanceof HTMLButtonElement) {
                    if (!submitButton.dataset.origLabel) submitButton.dataset.origLabel = submitButton.textContent || '';
                    submitButton.innerHTML = '<span class="np-btn-dots" aria-hidden="true"><span></span><span></span><span></span></span><span class="sr-only">در حال بررسی…</span>';
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

        hiddenInput.addEventListener('input', () => {
            if (syncingInternal) return;
            const digits = normalizeDigits(hiddenInput.value).slice(0, 6);
            boxes.forEach((b, i) => {
                b.value = digits.charAt(i) || '';
            });
        });

        container.classList.add('is-enhanced');
        grid.hidden = false;
        hiddenInput.tabIndex = -1;

        boxes.forEach((box, idx) => {
            box.addEventListener('input', () => {
                const digits = normalizeDigits(box.value);
                if (digits.length >= 6) {
                    digits.slice(0, 6).split('').forEach((ch, i) => {
                        if (boxes[i]) boxes[i].value = ch;
                    });
                    boxes[5]?.focus();
                } else if (digits.length > 1) {
                    digits.split('').forEach((ch, offset) => {
                        if (boxes[idx + offset]) boxes[idx + offset].value = ch;
                    });
                    boxes[Math.min(5, idx + digits.length)]?.focus();
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
            let rawText = (iranDataEl.textContent || '{}').trim();
            const wrappedMatch = rawText.match(/^JSON\.parse\('([\s\S]*)'\)$/);
            if (wrappedMatch) {
                rawText = JSON.parse(`"${wrappedMatch[1].replace(/"/g, '\\"')}"`);
            }
            iranLocations = JSON.parse(rawText) || {};
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
                if (preserveCity) {
                    citySelect.value = preserveCity;
                    citySelect.dataset.selectedCity = preserveCity;
                }
            };
            form._populateIranCities = populateCities;
            if (provinceSelect.value) {
                populateCities(citySelect.dataset.selectedCity || citySelect.value || '');
            }
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

    const accountKey = (form.dataset.accountKey || 'default').trim();
    const draftStorageKey = `novinpay_checkout_draft_${accountKey}`;
    const draftUrl = (form.dataset.draftUrl || '').trim();
    const reverseGeocodeUrl = (form.dataset.reverseGeocodeUrl || '').trim();

    const collectDraftPayload = () => {
        const payload = {
            full_name: form.querySelector('[name="full_name"]')?.value.trim() || '',
            mobile: toLatinDigits(form.querySelector('[name="mobile"]')?.value || '').replace(/\s+/g, ''),
            national_code: toLatinDigits(form.querySelector('[name="national_code"]')?.value || '').replace(/\s+/g, ''),
            email: form.querySelector('[name="email"]')?.value.trim() || '',
            address_mode: getAddressMode(),
            address: form.querySelector('select[name="address"]')?.value || '',
            province: form.querySelector('[name="province"]')?.value || '',
            city: form.querySelector('[name="city"]')?.value || '',
            street_address: form.querySelector('[name="street_address"]')?.value.trim() || '',
            postal_code: toLatinDigits(form.querySelector('[name="postal_code"]')?.value || '').replace(/\s+/g, ''),
            latitude: form.querySelector('[data-map-lat]')?.value || '',
            longitude: form.querySelector('[data-map-lng]')?.value || '',
            method: form.querySelector('input[name="method"]:checked')?.value || 'pickup',
            payment_method: form.querySelector('input[name="payment_method"]:checked')?.value || '',
        };
        return payload;
    };

    let draftTimer = null;
    const persistCheckoutDraft = (immediateServer = false) => {
        const payload = collectDraftPayload();
        try {
            window.localStorage?.setItem(draftStorageKey, JSON.stringify(payload));
        } catch {}

        if (!draftUrl || !window.fetch) return;
        const sendToServer = () => {
            const csrfToken = form.querySelector('input[name="_token"]')?.value || '';
            window.fetch(draftUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(payload),
            }).then((res) => (res.ok ? res.json() : null)).then((data) => {
                if (data && data.address_id) {
                    const sel = form.querySelector('select[name="address"]');
                    if (sel instanceof HTMLSelectElement && !sel.value) {
                        const opt = sel.querySelector(`option[value="${data.address_id}"]`);
                        if (opt) sel.value = data.address_id;
                    }
                }
            }).catch(() => {});
        };

        if (draftTimer) window.clearTimeout(draftTimer);
        if (immediateServer) {
            sendToServer();
        } else {
            draftTimer = window.setTimeout(sendToServer, 450);
        }
    };

    // Restore any missing fields from localStorage draft on revisit
    try {
        const savedRaw = window.localStorage?.getItem(draftStorageKey);
        if (savedRaw) {
            const saved = JSON.parse(savedRaw);
            if (saved && typeof saved === 'object') {
                ['full_name', 'mobile', 'national_code', 'email', 'street_address', 'postal_code'].forEach((name) => {
                    const el = form.querySelector(`[name="${name}"]`);
                    if (el && !el.value && saved[name]) {
                        el.value = saved[name];
                    }
                });
                const provinceSelect = form.querySelector('[data-iran-province]');
                const citySelect = form.querySelector('[data-iran-city]');
                if (provinceSelect instanceof HTMLSelectElement && !provinceSelect.value && saved.province) {
                    provinceSelect.value = saved.province;
                    if (typeof form._populateIranCities === 'function') {
                        form._populateIranCities(saved.city || '');
                    }
                } else if (citySelect instanceof HTMLSelectElement && !citySelect.value && saved.city) {
                    if (typeof form._populateIranCities === 'function') {
                        form._populateIranCities(saved.city);
                    }
                }
                const latEl = form.querySelector('[data-map-lat]');
                const lngEl = form.querySelector('[data-map-lng]');
                const mapEl = form.querySelector('[data-checkout-map]');
                if (latEl && lngEl && !latEl.value && !lngEl.value && saved.latitude && saved.longitude) {
                    latEl.value = saved.latitude;
                    lngEl.value = saved.longitude;
                    if (mapEl) {
                        mapEl.dataset.defaultLat = saved.latitude;
                        mapEl.dataset.defaultLng = saved.longitude;
                    }
                }
            }
        }
    } catch {}

    let onStep2Visible = null;
    let currentStep = 1;

    const isStep1QuietlyValid = () => {
        const fullName = form.querySelector('[name="full_name"]')?.value.trim() || '';
        const mobile = toLatinDigits(form.querySelector('[name="mobile"]')?.value || '').replace(/\s+/g, '');
        const nationalCode = toLatinDigits(form.querySelector('[name="national_code"]')?.value || '').replace(/\s+/g, '');
        const email = form.querySelector('[name="email"]')?.value.trim() || '';
        if (fullName.length < 2 || !/^09[0-9]{9}$/.test(mobile)) return false;
        if (nationalCode !== '' && !isValidNationalCode(nationalCode)) return false;
        if (email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return false;
        return true;
    };

    const isStep2QuietlyReady = () => {
        const mode = getAddressMode();
        if (mode === 'saved') {
            const sel = form.querySelector('select[name="address"]');
            return Boolean(sel && sel.value);
        }
        const province = form.querySelector('[name="province"]')?.value.trim() || '';
        const city = form.querySelector('[name="city"]')?.value.trim() || '';
        const street = form.querySelector('[name="street_address"]')?.value.trim() || '';
        const postal = toLatinDigits(form.querySelector('[name="postal_code"]')?.value || '').replace(/\s+/g, '');
        return Boolean(province && city && street.length >= 10 && /^[0-9]{10}$/.test(postal));
    };

    const goToStep = (stepNumber) => {
        const target = Math.max(1, Math.min(4, stepNumber));
        currentStep = target;
        syncSummary();
        panels.forEach((panel) => {
            panel.classList.toggle('is-active', Number(panel.dataset.checkoutStep) === target);
        });
        const step1Done = isStep1QuietlyValid();
        const step2Done = isStep2QuietlyReady();
        indicators.forEach((ind) => {
            const num = Number(ind.dataset.stepIndicator);
            const done = num < target || (num === 1 && step1Done && target !== 1) || (num === 2 && step1Done && step2Done && target !== 2);
            ind.classList.toggle('is-active', num === target);
            ind.classList.toggle('is-done', done);
            if (num === target) ind.setAttribute('aria-current', 'step');
            else ind.removeAttribute('aria-current');
        });
        if (target === 2 && typeof onStep2Visible === 'function') {
            requestAnimationFrame(() => onStep2Visible());
        }
    };

    const tryNavigateToStep = (targetStep) => {
        if (targetStep === currentStep) return;
        if (targetStep < currentStep) {
            persistCheckoutDraft(false);
            goToStep(targetStep);
            return;
        }
        if (targetStep >= 2 && !validateStep1()) {
            goToStep(1);
            return;
        }
        if (targetStep >= 3 && !validateAddressIfShipping(false)) {
            goToStep(2);
            return;
        }
        if (targetStep >= 4 && !validateStep3()) {
            goToStep(3);
            return;
        }
        persistCheckoutDraft(true);
        goToStep(targetStep);
    };

    indicators.forEach((ind) => {
        const targetStep = Number(ind.dataset.stepIndicator);
        ind.addEventListener('click', () => tryNavigateToStep(targetStep));
        ind.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                tryNavigateToStep(targetStep);
            }
        });
    });

    stepper.classList.add('is-stepped');
    syncAddressSections();
    const errorPanel = panels.find((p) => p.querySelector('.checkout-field-error:not([hidden])'));
    goToStep(errorPanel ? Number(errorPanel.dataset.checkoutStep) : 1);

    form.addEventListener('change', () => {
        syncAddressSections();
        syncSummary();
        persistCheckoutDraft(false);
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
            tryNavigateToStep(next);
        });
    });
    stepper.querySelectorAll('[data-checkout-prev]').forEach((btn) => {
        btn.addEventListener('click', () => tryNavigateToStep(Number(btn.dataset.checkoutPrev)));
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
            return;
        }
        persistCheckoutDraft(false);
    });

    // Coordinates dictionary for Iranian provinces & major cities (for two-way dropdown <-> map sync)
    const IRAN_PROVINCE_COORDS = {
        'آذربایجان شرقی': [38.0962, 46.2738],
        'آذربایجان غربی': [37.5527, 45.0761],
        'اردبیل': [38.2498, 48.2933],
        'اصفهان': [32.6546, 51.6680],
        'البرز': [35.8400, 50.9391],
        'ایلام': [33.6374, 46.4227],
        'بوشهر': [28.9234, 50.8203],
        'تهران': [35.6892, 51.3890],
        'چهارمحال و بختیاری': [32.3256, 50.8644],
        'خراسان جنوبی': [32.8649, 59.2262],
        'خراسان رضوی': [36.2605, 59.6168],
        'خراسان شمالی': [37.4747, 57.3310],
        'خوزستان': [31.3183, 48.6706],
        'زنجان': [36.6764, 48.4963],
        'سمنان': [35.5769, 53.3970],
        'سیستان و بلوچستان': [29.4963, 60.8629],
        'فارس': [29.5918, 52.5837],
        'قزوین': [36.2688, 50.0041],
        'قم': [34.6416, 50.8746],
        'کردستان': [35.3219, 46.9862],
        'کرمان': [30.2839, 57.0834],
        'کرمانشاه': [34.3142, 47.0650],
        'کهگیلویه و بویراحمد': [30.6682, 51.5880],
        'گلستان': [36.8427, 54.4358],
        'گیلان': [37.2808, 49.5832],
        'لرستان': [33.4878, 48.3558],
        'مازندران': [36.5659, 53.0586],
        'مرکزی': [34.0917, 49.6892],
        'هرمزگان': [27.1832, 56.2666],
        'همدان': [34.7989, 48.5150],
        'یزد': [31.8974, 54.3569],
    };

    const IRAN_CITY_COORDS = {
        'تهران': [35.6892, 51.3890],
        'شهریار': [35.6597, 51.0592],
        'اندیشه': [35.6803, 51.0194],
        'قدس': [35.7117, 51.1131],
        'ملارد': [35.6659, 50.9767],
        'اسلامشهر': [35.5466, 51.2352],
        'رباط‌کریم': [35.4846, 51.0829],
        'پرند': [35.4769, 50.9464],
        'پردیس': [35.7417, 51.7777],
        'دماوند': [35.7184, 52.0650],
        'ورامین': [35.3242, 51.6457],
        'پاکدشت': [35.4789, 51.6854],
        'قرچک': [35.4394, 51.5719],
        'ری': [35.5935, 51.4340],
        'شمیرانات': [35.8047, 51.4328],
        'بومهن': [35.7303, 51.8639],
        'رودهن': [35.7369, 51.9094],
        'کرج': [35.8400, 50.9391],
        'فردیس': [35.7242, 50.9883],
        'ساوجبلاغ': [35.9553, 50.7069],
        'نظرآباد': [35.9542, 50.6075],
        'هشتگرد': [35.9619, 50.6800],
        'ماهدشت': [35.7278, 50.8136],
        'محمدشهر': [35.7633, 50.9172],
        'کمال‌شهر': [35.8644, 50.8717],
        'مشهد': [36.2605, 59.6168],
        'نیشابور': [36.2133, 58.7958],
        'سبزوار': [36.2126, 57.6819],
        'اصفهان': [32.6546, 51.6680],
        'کاشان': [33.9850, 51.4100],
        'خمینی‌شهر': [32.6856, 51.5361],
        'نجف‌آباد': [32.6344, 51.3667],
        'شاهین‌شهر': [32.8578, 51.5528],
        'شیراز': [29.5918, 52.5837],
        'مرودشت': [29.8742, 52.8025],
        'تبریز': [38.0962, 46.2738],
        'مراغه': [37.3892, 46.2375],
        'مرند': [38.4328, 45.7747],
        'ارومیه': [37.5527, 45.0761],
        'خوی': [38.5503, 44.9522],
        'مهاباد': [36.7631, 45.7222],
        'اهواز': [31.3183, 48.6706],
        'دزفول': [32.3811, 48.4058],
        'آبادان': [30.3392, 48.3043],
        'خرمشهر': [30.4408, 48.1664],
        'قم': [34.6416, 50.8746],
        'رشت': [37.2808, 49.5832],
        'بندر انزلی': [37.4728, 49.4622],
        'لاهیجان': [37.2072, 50.0039],
        'ساری': [36.5659, 53.0586],
        'بابل': [36.5513, 52.6789],
        'آمل': [36.4697, 52.3508],
        'قائم‌شهر': [36.4631, 52.8600],
        'چالوس': [36.6550, 51.4203],
        'تنکابن': [36.8164, 50.8739],
        'رامسر': [36.9069, 50.6583],
        'کرمان': [30.2839, 57.0834],
        'رفسنجان': [30.4067, 55.9939],
        'سیرجان': [29.4519, 55.6808],
        'یزد': [31.8974, 54.3569],
        'کرمانشاه': [34.3142, 47.0650],
        'بندرعباس': [27.1832, 56.2666],
        'کیش': [26.5325, 53.9800],
        'قشم': [26.9581, 56.2719],
        'همدان': [34.7989, 48.5150],
        'ملایر': [34.2969, 48.8236],
        'اراک': [34.0917, 49.6892],
        'ساوه': [35.0213, 50.3566],
        'قزوین': [36.2688, 50.0041],
        'زنجان': [36.6764, 48.4963],
        'سنندج': [35.3219, 46.9862],
        'خرم‌آباد': [33.4878, 48.3558],
        'بروجرد': [33.8972, 48.7517],
        'گرگان': [36.8427, 54.4358],
        'گنبد کاووس': [37.2500, 55.1672],
        'اردبیل': [38.2498, 48.2933],
        'بوشهر': [28.9234, 50.8203],
        'بیرجند': [32.8649, 59.2262],
        'زاهدان': [29.4963, 60.8629],
        'چابهار': [25.2919, 60.6431],
        'سمنان': [35.5769, 53.3970],
        'شاهرود': [36.4181, 54.9764],
        'شهرکرد': [32.3256, 50.8644],
        'یاسوج': [30.6682, 51.5880],
        'ایلام': [33.6374, 46.4227],
        'بجنورد': [37.4747, 57.3310],
    };

    const findNearestIranLocation = (la, ln) => {
        let bestProvince = null;
        let bestProvDist = Infinity;
        for (const [prov, [pLat, pLng]] of Object.entries(IRAN_PROVINCE_COORDS)) {
            const d = (la - pLat) * (la - pLat) + (ln - pLng) * (ln - pLng);
            if (d < bestProvDist) {
                bestProvDist = d;
                bestProvince = prov;
            }
        }
        // Special check for Tehran vs Alborz boundary around Shahriar/Karaj
        let bestCity = null;
        let bestCityDist = Infinity;
        for (const [city, [cLat, cLng]] of Object.entries(IRAN_CITY_COORDS)) {
            const d = (la - cLat) * (la - cLat) + (ln - cLng) * (ln - cLng);
            if (d < bestCityDist) {
                bestCityDist = d;
                bestCity = city;
            }
        }
        const iranDataEl = document.getElementById('iran-locations-data');
        let locs = {};
        try {
            locs = JSON.parse(iranDataEl?.textContent || '{}') || {};
        } catch {}
        if (bestCity) {
            for (const [prov, cities] of Object.entries(locs)) {
                if (Array.isArray(cities) && cities.includes(bestCity)) {
                    bestProvince = prov;
                    break;
                }
            }
        }
        return { province: bestProvince, city: bestCity };
    };

    // Interactive Neshan Web Map (with non-blocked CartoDB Voyager tile fallback when NESHAN_WEB_KEY is empty)
    const mapBox = form.querySelector('[data-checkout-map]');
    if (mapBox) {
        const viewport = mapBox.querySelector('[data-map-viewport]');
        const tilesEl = mapBox.querySelector('[data-map-tiles]');
        const fallbackPin = mapBox.querySelector('[data-map-fallback-pin]');
        const coordsEl = mapBox.querySelector('[data-map-coords]');
        const latInput = mapBox.querySelector('[data-map-lat]');
        const lngInput = mapBox.querySelector('[data-map-lng]');
        const neshanKey = (mapBox.dataset.neshanKey || '').trim();
        const provinceSelect = form.querySelector('[data-iran-province]');
        const citySelect = form.querySelector('[data-iran-city]');

        let lat = parseFloat(latInput?.value || mapBox.dataset.defaultLat || '35.6597') || 35.6597;
        let lng = parseFloat(lngInput?.value || mapBox.dataset.defaultLng || '51.0592') || 51.0592;
        let zoom = 14;
        let neshanMap = null;
        let neshanMarker = null;
        let isSyncingFromMap = false;
        let reverseGeocodeTimer = null;

        const saveCoordinates = (la, ln) => {
            lat = Math.max(-85, Math.min(85, la));
            lng = Math.max(-180, Math.min(180, ln));
            const fLat = lat.toFixed(6);
            const fLng = lng.toFixed(6);
            if (latInput) latInput.value = fLat;
            if (lngInput) lngInput.value = fLng;
            if (coordsEl) coordsEl.textContent = `${fLat}, ${fLng}`;
            if (neshanMarker && typeof neshanMarker.setLatLng === 'function') {
                const cur = neshanMarker.getLatLng();
                if (!cur || Math.abs(cur.lat - lat) > 0.000001 || Math.abs(cur.lng - lng) > 0.000001) {
                    neshanMarker.setLatLng([lat, lng]);
                }
            }
            persistCheckoutDraft(false);
        };

        const applyMapProvinceAndCity = (province, city) => {
            if (!province || !(provinceSelect instanceof HTMLSelectElement)) return;
            const hasOption = [...provinceSelect.options].some((o) => o.value === province);
            if (!hasOption) return;
            isSyncingFromMap = true;
            try {
                provinceSelect.value = province;
                setFieldError('province', '');
                if (typeof form._populateIranCities === 'function') {
                    form._populateIranCities(city || '');
                }
                if (city && citySelect instanceof HTMLSelectElement) {
                    const hasCity = [...citySelect.options].some((o) => o.value === city);
                    if (hasCity) {
                        citySelect.value = city;
                        setFieldError('city', '');
                    }
                }
                syncSummary();
                persistCheckoutDraft(false);
            } finally {
                isSyncingFromMap = false;
            }
        };

        const reverseGeocodeToProvinceCity = (la, ln) => {
            if (reverseGeocodeTimer) window.clearTimeout(reverseGeocodeTimer);
            reverseGeocodeTimer = window.setTimeout(() => {
                if (!reverseGeocodeUrl || !window.fetch) {
                    const fallback = findNearestIranLocation(la, ln);
                    if (fallback.province) applyMapProvinceAndCity(fallback.province, fallback.city);
                    return;
                }
                const url = new URL(reverseGeocodeUrl, window.location.origin);
                url.searchParams.set('lat', la.toFixed(6));
                url.searchParams.set('lng', ln.toFixed(6));
                window.fetch(url.href, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                }).then((res) => (res.ok ? res.json() : null)).then((data) => {
                    if (data && data.ok && data.province) {
                        applyMapProvinceAndCity(data.province, data.city || '');
                    } else {
                        const fallback = findNearestIranLocation(la, ln);
                        if (fallback.province) applyMapProvinceAndCity(fallback.province, fallback.city);
                    }
                }).catch(() => {
                    const fallback = findNearestIranLocation(la, ln);
                    if (fallback.province) applyMapProvinceAndCity(fallback.province, fallback.city);
                });
            }, 220);
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
        const renderMap = (saveInputs = false, shouldReverseGeocode = false) => {
            if (saveInputs) saveCoordinates(lat, lng);
            if (shouldReverseGeocode) reverseGeocodeToProvinceCity(lat, lng);
            if (neshanMap) {
                neshanMap.setView([lat, lng], zoom, { animate: true });
                if (neshanMarker) neshanMarker.setLatLng([lat, lng]);
                return;
            }
            if (!viewport || !tilesEl) return;
            if (fallbackPin) fallbackPin.hidden = false;
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

        // Dropdown -> Map Sync when user selects a province or city
        if (provinceSelect instanceof HTMLSelectElement) {
            provinceSelect.addEventListener('change', () => {
                if (isSyncingFromMap) return;
                const prov = provinceSelect.value;
                const coords = IRAN_PROVINCE_COORDS[prov];
                if (coords) {
                    lat = coords[0];
                    lng = coords[1];
                    zoom = 11;
                    renderMap(true, false);
                }
            });
        }
        if (citySelect instanceof HTMLSelectElement) {
            citySelect.addEventListener('change', () => {
                if (isSyncingFromMap) return;
                const city = citySelect.value;
                const prov = provinceSelect instanceof HTMLSelectElement ? provinceSelect.value : '';
                const coords = IRAN_CITY_COORDS[city] || IRAN_PROVINCE_COORDS[prov];
                if (coords) {
                    lat = coords[0];
                    lng = coords[1];
                    zoom = IRAN_CITY_COORDS[city] ? 14 : 12;
                    renderMap(true, false);
                }
            });
        }

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
            renderMap(true, false);
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
                renderMap(true, true);
            } else {
                reverseGeocodeToProvinceCity(lat, lng);
            }
        });

        mapBox.querySelectorAll('[data-map-zoom]').forEach((btn) => {
            btn.addEventListener('click', () => {
                zoom = btn.dataset.mapZoom === 'in' ? Math.min(18, zoom + 1) : Math.max(5, zoom - 1);
                if (neshanMap) {
                    neshanMap.setZoom(zoom);
                } else {
                    renderMap(Boolean(latInput?.value), false);
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
                    if (neshanMarker) neshanMarker.setLatLng([lat, lng]);
                } else {
                    renderMap(true, false);
                }
                reverseGeocodeToProvinceCity(lat, lng);
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

                    if (typeof window.L.marker === 'function' && typeof window.L.divIcon === 'function') {
                        const pinIcon = window.L.divIcon({
                            className: 'checkout-map__leaflet-pin',
                            html: '<span class="checkout-map__pin-svg"><svg viewBox="0 0 30 38" width="30" height="38" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15 1.5C7.54 1.5 1.5 7.54 1.5 15c0 9.95 11.88 20.66 12.66 21.35a1.25 1.25 0 0 0 1.68 0C16.62 35.66 28.5 24.95 28.5 15 28.5 7.54 22.46 1.5 15 1.5Z" fill="#098CF7" stroke="#fff" stroke-width="2.2"/><circle cx="15" cy="15" r="5.2" fill="#fff"/></svg></span>',
                            iconSize: [30, 38],
                            iconAnchor: [15, 37],
                        });
                        neshanMarker = window.L.marker([lat, lng], {
                            draggable: true,
                            icon: pinIcon,
                        }).addTo(neshanMap);
                        if (fallbackPin) fallbackPin.hidden = true;

                        neshanMarker.on('dragend', () => {
                            const pos = neshanMarker.getLatLng();
                            if (pos) {
                                saveCoordinates(pos.lat, pos.lng);
                                reverseGeocodeToProvinceCity(pos.lat, pos.lng);
                            }
                        });
                    }

                    neshanMap.on('zoomend', () => {
                        zoom = neshanMap.getZoom() || zoom;
                    });
                    neshanMap.on('click', (ev) => {
                        if (ev && ev.latlng) {
                            if (neshanMarker) neshanMarker.setLatLng(ev.latlng);
                            saveCoordinates(ev.latlng.lat, ev.latlng.lng);
                            neshanMap.panTo(ev.latlng);
                            reverseGeocodeToProvinceCity(ev.latlng.lat, ev.latlng.lng);
                        }
                    });
                    setTimeout(() => neshanMap?.invalidateSize(), 60);
                } catch {
                    neshanMap = null;
                    neshanMarker = null;
                    if (fallbackPin) fallbackPin.hidden = false;
                    tilesEl.style.pointerEvents = 'none';
                    renderMap(Boolean(latInput?.value), false);
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
                renderMap(Boolean(latInput?.value), false);
                bootNeshanSdk();
            }
        };

        renderMap(Boolean(latInput?.value), false);
        if (neshanKey) bootNeshanSdk();
    }
});
