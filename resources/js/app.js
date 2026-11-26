import { initMobileNavigation } from './mobile-navigation';
import { initClickMap } from './click-map';
import { initCustomerNavigation } from './customer-navigation';

if (typeof BroadcastChannel !== 'undefined') {
    const commerce = new BroadcastChannel('novinpay-commerce');
    document.addEventListener('submit', event => {
        const path = new URL(event.target.action, location.href);
        if (path.origin === location.origin && /^\/(account\/(verify|password-login|logout|password|email\/verify|email\/password-login)|repair\/auth\/verify)$/.test(path.pathname)) {
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
    if (document.querySelector('[data-repair-confirmed]')) {
        try {
            Object.keys(window.sessionStorage || {}).forEach((key) => {
                if (key.startsWith('np_repair_draft_')) window.sessionStorage.removeItem(key);
            });
        } catch {}
    }

    let neshanSdkPromise = null;
    const loadNeshanSdk = () => {
        if (window.L && typeof window.L.Map === 'function') {
            return Promise.resolve(true);
        }
        if (neshanSdkPromise) return neshanSdkPromise;
        neshanSdkPromise = new Promise((resolve) => {
            if (!document.getElementById('neshan-leaflet-css')) {
                const link = document.createElement('link');
                link.id = 'neshan-leaflet-css';
                link.rel = 'stylesheet';
                link.href = 'https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/index.css';
                document.head.appendChild(link);
            }
            const existing = document.getElementById('neshan-leaflet-js');
            if (existing) {
                existing.addEventListener('load', () => resolve(Boolean(window.L && typeof window.L.Map === 'function')), { once: true });
                existing.addEventListener('error', () => resolve(false), { once: true });
                window.setTimeout(() => resolve(Boolean(window.L && typeof window.L.Map === 'function')), 4500);
                return;
            }
            const script = document.createElement('script');
            script.id = 'neshan-leaflet-js';
            script.src = 'https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/index.js';
            script.async = true;
            let settled = false;
            const finish = (ok) => {
                if (settled) return;
                settled = true;
                resolve(ok);
            };
            const timer = window.setTimeout(() => finish(false), 4500);
            script.onload = () => {
                window.clearTimeout(timer);
                finish(Boolean(window.L && typeof window.L.Map === 'function'));
            };
            script.onerror = () => {
                window.clearTimeout(timer);
                finish(false);
            };
            document.head.appendChild(script);
        });
        return neshanSdkPromise;
    };

    const mountNeshanMapWidget = ({ tilesEl, fallbackPin, neshanKey, lat, lng, zoom, onMove }) => {
        const showUnavailable = () => {
            if (fallbackPin) fallbackPin.hidden = true;
            if (tilesEl) {
                tilesEl.style.pointerEvents = 'none';
                tilesEl.innerHTML = '<div class="checkout-map__unavailable">نقشه نشان در دسترس نیست؛ نشانی دقیق را در کادر زیر وارد کنید.</div>';
            }
        };
        if (!neshanKey || !tilesEl) {
            showUnavailable();
            return Promise.resolve(null);
        }
        return loadNeshanSdk().then((loaded) => {
            if (!loaded || !window.L || typeof window.L.Map !== 'function') {
                showUnavailable();
                return null;
            }
            try {
                tilesEl.innerHTML = '';
                tilesEl.style.pointerEvents = 'auto';
                const map = new window.L.Map(tilesEl, {
                    key: neshanKey,
                    maptype: 'neshan',
                    poi: true,
                    traffic: false,
                    center: [lat, lng],
                    zoom,
                    zoomControl: false,
                });
                let marker = null;
                if (typeof window.L.marker === 'function' && typeof window.L.divIcon === 'function') {
                    const pinIcon = window.L.divIcon({
                        className: 'checkout-map__leaflet-pin',
                        html: '<span class="checkout-map__pin-svg"><svg viewBox="0 0 30 38" width="30" height="38" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15 1.5C7.54 1.5 1.5 7.54 1.5 15c0 9.95 11.88 20.66 12.66 21.35a1.25 1.25 0 0 0 1.68 0C16.62 35.66 28.5 24.95 28.5 15 28.5 7.54 22.46 1.5 15 1.5Z" fill="#098CF7" stroke="#fff" stroke-width="2.2"/><circle cx="15" cy="15" r="5.2" fill="#fff"/></svg></span>',
                        iconSize: [30, 38],
                        iconAnchor: [15, 37],
                    });
                    marker = window.L.marker([lat, lng], {
                        draggable: true,
                        icon: pinIcon,
                    }).addTo(map);
                    if (fallbackPin) fallbackPin.hidden = true;
                    marker.on('dragend', () => {
                        const pos = marker.getLatLng();
                        if (pos) onMove(pos.lat, pos.lng);
                    });
                }
                let lastLeafletClickAt = 0;
                const selectLatLng = (latlng) => {
                    if (!latlng) return;
                    if (marker) marker.setLatLng(latlng);
                    map.panTo(latlng);
                    onMove(latlng.lat, latlng.lng);
                };
                map.on('click', (ev) => {
                    lastLeafletClickAt = Date.now();
                    selectLatLng(ev?.latlng);
                });
                let downPoint = null;
                tilesEl.addEventListener('pointerdown', (ev) => {
                    if (ev.button !== 0 && ev.pointerType === 'mouse') return;
                    if (ev.target instanceof Element && ev.target.closest('button, a, .leaflet-control')) return;
                    downPoint = { x: ev.clientX, y: ev.clientY };
                });
                tilesEl.addEventListener('pointerup', (ev) => {
                    if (!downPoint) return;
                    const dx = Math.abs(ev.clientX - downPoint.x);
                    const dy = Math.abs(ev.clientY - downPoint.y);
                    downPoint = null;
                    if (dx > 6 || dy > 6) return;
                    if (Date.now() - lastLeafletClickAt < 100) return;
                    if (typeof map.mouseEventToLatLng === 'function') {
                        selectLatLng(map.mouseEventToLatLng(ev));
                    }
                });
                window.setTimeout(() => map.invalidateSize(true), 60);
                window.setTimeout(() => map.invalidateSize(true), 220);
                if (typeof ResizeObserver === 'function') {
                    const ro = new ResizeObserver(() => map.invalidateSize(true));
                    ro.observe(tilesEl);
                }
                return { map, marker };
            } catch {
                showUnavailable();
                return null;
            }
        });
    };

    const repairStepper = document.querySelector('[data-repair-stepper]');
    if (repairStepper instanceof HTMLFormElement) {
        const steps = [...repairStepper.querySelectorAll('[data-step]')];
        const indicators = [...repairStepper.querySelectorAll('[data-step-indicator]')];
        const alertBox = repairStepper.querySelector('[data-step-alert]');
        const brandRadios = [...repairStepper.querySelectorAll('input[name="device_brand"]')];
        const modelInput = repairStepper.querySelector('#device_model');
        const serialInput = repairStepper.querySelector('#device_serial');
        const issueInput = repairStepper.querySelector('#issue');
        const nameInput = repairStepper.querySelector('#customer_name');
        const contactPhoneInput = repairStepper.querySelector('#contact_phone');
        const csrfInput = repairStepper.querySelector('input[name="_token"]');
        const submissionTokenInput = repairStepper.querySelector('input[name="submission_token"]');
        const provinceSelect = repairStepper.querySelector('[data-repair-province]');
        const citySelect = repairStepper.querySelector('[data-repair-city]');
        const handoverRadios = [...repairStepper.querySelectorAll('input[name="handover_method"]')];
        const pickupOption = repairStepper.querySelector('[data-handover-option="doorstep_pickup"]');
        const pickupRadio = pickupOption?.querySelector('input[type="radio"]');
        const handoverHint = repairStepper.querySelector('[data-handover-hint]');
        const selfShipPanel = repairStepper.querySelector('[data-handover-panel="self_ship"]');
        const inPersonPanel = repairStepper.querySelector('[data-handover-panel="in_person"]');
        const pickupPanel = repairStepper.querySelector('[data-handover-panel="pickup"]');
        const pickupAddressInput = repairStepper.querySelector('#pickup_address');
        const pickupNotesInput = repairStepper.querySelector('#pickup_notes');
        const otherPersonToggle = repairStepper.querySelector('[data-other-person-toggle]');
        const otherPersonField = repairStepper.querySelector('[data-other-person-field]');
        const responsivePhoneInput = repairStepper.querySelector('#responsive_phone');
        const presetButtons = [...repairStepper.querySelectorAll('[data-issue-preset]')];
        const upgradeEstimateBox = repairStepper.querySelector('[data-upgrade-estimate]');
        const summaryDevice = repairStepper.querySelector('[data-summary-device]');
        const summaryAccessories = repairStepper.querySelector('[data-summary-accessories]');
        const summaryIssue = repairStepper.querySelector('[data-summary-issue]');
        const summaryContact = repairStepper.querySelector('[data-summary-contact]');
        const summaryHandover = repairStepper.querySelector('[data-summary-handover]');
        const submitBtn = repairStepper.querySelector('[data-repair-submit]');
        const draftKey = repairStepper.dataset.draftKey || 'np_repair_draft_v3';
        let currentStep = Math.min(4, Math.max(1, Number(repairStepper.dataset.initialStep || 1)));
        const faDigitsGlobal = (val) => String(val).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
        const normalizeDigitsGlobal = (val) => String(val || '')
            .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
            .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
        const normalizePersianSearch = (val) => String(val || '')
            .trim()
            .replace(/[\u200B-\u200F\u202A-\u202E\u0640\s]+/gu, '')
            .replace(/[يیئ]/gu, 'ی')
            .replace(/ك/gu, 'ک')
            .replace(/ة/gu, 'ه')
            .replace(/[أإآ]/gu, 'ا')
            .toLowerCase();

        let iranLocations = {};
        let courierZones = {};
        try { iranLocations = JSON.parse(document.getElementById('repair-iran-locations')?.textContent || '{}') || {}; } catch {}
        try { courierZones = JSON.parse(document.getElementById('repair-courier-zones')?.textContent || '{}') || {}; } catch {}

        const isPickupSupported = (province, city) => {
            const normProv = normalizePersianSearch(province);
            const normCity = normalizePersianSearch(city);
            if (!normProv || !normCity) return false;
            const entry = Object.entries(courierZones).find(([p]) => normalizePersianSearch(p) === normProv);
            const list = entry ? entry[1] : null;
            return Array.isArray(list) && list.some((c) => normalizePersianSearch(c) === normCity);
        };

        const handoverLabels = {
            self_ship: 'خودم ارسال میکنم',
            in_person: 'خودم حضوری میارم',
            doorstep_pickup: 'به پیک شما تحویل میدم',
        };

        const fieldInputMap = {
            device_model: modelInput,
            device_serial: serialInput,
            issue: issueInput,
            province: repairStepper.querySelector('#repair_province_search') || provinceSelect,
            city: repairStepper.querySelector('#repair_city_search') || citySelect,
            pickup_address: pickupAddressInput,
            pickup_notes: pickupNotesInput,
            responsive_phone: responsivePhoneInput,
            customer_name: nameInput,
            contact_phone: contactPhoneInput,
            repair_auth_phone: contactPhoneInput,
            repair_auth_code: repairStepper.querySelector('#repair_auth_code'),
        };

        const searchableWidgets = new Map();
        const initSearchableSelect = (key, selectEl) => {
            const wrapper = repairStepper.querySelector(`[data-searchable-select="${key}"]`);
            if (!(wrapper instanceof HTMLElement) || !(selectEl instanceof HTMLSelectElement)) return null;
            const inputEl = wrapper.querySelector('[data-ss-input]');
            const listEl = wrapper.querySelector('[data-ss-list]');
            if (!(inputEl instanceof HTMLInputElement) || !(listEl instanceof HTMLElement)) return null;

            const getOptions = () => [...selectEl.options].filter((o) => o.value !== '');
            const syncInputFromSelect = () => {
                inputEl.value = selectEl.value || '';
                if (key === 'city') {
                    inputEl.placeholder = provinceSelect?.value ? 'جستجو یا انتخاب شهر…' : 'ابتدا استان را انتخاب کنید…';
                }
            };
            const closeList = () => {
                listEl.hidden = true;
                inputEl.setAttribute('aria-expanded', 'false');
            };
            const renderList = (query = '') => {
                const q = normalizePersianSearch(query);
                const opts = getOptions().filter((o) => !q || normalizePersianSearch(o.textContent).includes(q));
                listEl.innerHTML = '';
                if (opts.length === 0) {
                    const empty = document.createElement('li');
                    empty.className = 'np-searchable-select__empty';
                    empty.textContent = getOptions().length === 0 ? 'ابتدا استان را انتخاب کنید' : 'موردی یافت نشد';
                    listEl.append(empty);
                } else {
                    opts.forEach((opt) => {
                        const li = document.createElement('li');
                        li.className = 'np-searchable-select__option';
                        li.setAttribute('role', 'option');
                        li.setAttribute('aria-selected', String(opt.value === selectEl.value));
                        li.textContent = opt.textContent;
                        li.addEventListener('mousedown', (ev) => {
                            ev.preventDefault();
                            selectEl.value = opt.value;
                            inputEl.value = opt.textContent;
                            closeList();
                            inputEl.blur();
                            selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                        listEl.append(li);
                    });
                }
                listEl.hidden = false;
                inputEl.setAttribute('aria-expanded', 'true');
            };

            inputEl.addEventListener('focus', () => renderList(''));
            inputEl.addEventListener('click', () => renderList(''));
            inputEl.addEventListener('input', () => {
                const qNorm = normalizePersianSearch(inputEl.value);
                const exact = qNorm ? getOptions().find((o) => normalizePersianSearch(o.textContent) === qNorm) : null;
                if (exact && selectEl.value !== exact.value) {
                    selectEl.value = exact.value;
                    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                } else if (!inputEl.value.trim() && selectEl.value !== '') {
                    selectEl.value = '';
                    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                }
                renderList(inputEl.value);
            });
            inputEl.addEventListener('blur', () => {
                window.setTimeout(() => {
                    closeList();
                    syncInputFromSelect();
                }, 140);
            });
            inputEl.addEventListener('keydown', (ev) => {
                if (ev.key === 'Escape') {
                    closeList();
                    syncInputFromSelect();
                    inputEl.blur();
                } else if (ev.key === 'Enter' && !listEl.hidden) {
                    const first = listEl.querySelector('.np-searchable-select__option');
                    if (first) {
                        ev.preventDefault();
                        first.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                    }
                }
            });

            const api = { syncInputFromSelect, closeList };
            searchableWidgets.set(key, api);
            syncInputFromSelect();
            return api;
        };

        initSearchableSelect('province', provinceSelect);
        initSearchableSelect('city', citySelect);

        const setFieldError = (fieldName, message) => {
            const slot = repairStepper.querySelector(`[data-field-error="${fieldName}"]`);
            const input = fieldInputMap[fieldName];
            if (slot) {
                slot.textContent = message || '';
                slot.hidden = !message;
            }
            if (input instanceof HTMLElement) {
                input.setAttribute('aria-invalid', message ? 'true' : 'false');
            }
            return Boolean(slot);
        };

        const clearAllErrors = () => {
            if (alertBox) {
                alertBox.textContent = '';
                alertBox.hidden = true;
            }
            Object.keys(fieldInputMap).forEach((key) => setFieldError(key, ''));
            setFieldError('device_brand', '');
            setFieldError('handover_method', '');
        };

        const showAlert = (message) => {
            if (!alertBox) return;
            alertBox.textContent = message;
            alertBox.hidden = !message;
        };

        const saveDraft = () => {
            try {
                const draft = {
                    device_brand: brandRadios.find((r) => r.checked)?.value || '',
                    device_model: modelInput?.value || '',
                    device_serial: serialInput?.value || '',
                    accessories: [...repairStepper.querySelectorAll('input[name="accessories[]"]:checked')].map((el) => el.value),
                    issue: issueInput?.value || '',
                    province: provinceSelect?.value || '',
                    city: citySelect?.value || '',
                    handover_method: handoverRadios.find((r) => r.checked && !r.disabled)?.value || '',
                    pickup_address: pickupAddressInput?.value || '',
                    pickup_notes: pickupNotesInput?.value || '',
                    other_person_handover: Boolean(otherPersonToggle?.checked),
                    responsive_phone: otherPersonToggle && !otherPersonToggle.checked ? '' : (responsivePhoneInput?.value || ''),
                    customer_name: nameInput?.value || '',
                };
                window.sessionStorage?.setItem(draftKey, JSON.stringify(draft));
            } catch {}
        };

        const syncHandoverPanels = () => {
            const method = handoverRadios.find((r) => r.checked && !r.disabled)?.value || '';
            if (selfShipPanel) selfShipPanel.hidden = method !== 'self_ship';
            if (inPersonPanel) inPersonPanel.hidden = method !== 'in_person';
            if (pickupPanel) pickupPanel.hidden = method !== 'doorstep_pickup';
            if (method === 'doorstep_pickup' && typeof repairStepper._renderRepairMap === 'function') {
                window.requestAnimationFrame(() => repairStepper._renderRepairMap());
            }
            syncSummary();
        };

        const syncPickupAvailability = () => {
            const province = provinceSelect?.value || '';
            const city = citySelect?.value || '';
            const supported = isPickupSupported(province, city);
            if (pickupOption && pickupRadio) {
                pickupOption.hidden = !supported;
                pickupRadio.disabled = !supported;
                if (!supported && pickupRadio.checked) {
                    pickupRadio.checked = false;
                }
            }
            if (handoverHint) {
                if (!province || !city) {
                    handoverHint.textContent = 'پس از انتخاب استان و شهر، روش‌های در دسترس برای منطقهٔ شما فعال می‌شود.';
                } else if (supported) {
                    handoverHint.textContent = 'تحویل در محل توسط پیک نوین‌پی در این شهر فعال است.';
                } else {
                    handoverHint.textContent = 'در این شهر، ارسال پستی/تیپاکس یا تحویل حضوری فعال است (تحویل به پیک مخصوص شهرهای تحت پوشش است).';
                }
            }
            syncHandoverPanels();
        };

        const populateRepairCities = (targetCity = '') => {
            if (!(provinceSelect instanceof HTMLSelectElement) || !(citySelect instanceof HTMLSelectElement)) return;
            const prov = provinceSelect.value;
            const cities = Array.isArray(iranLocations[prov]) ? iranLocations[prov] : [];
            citySelect.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = prov ? 'انتخاب شهر...' : 'ابتدا استان را انتخاب کنید...';
            citySelect.append(placeholder);
            cities.forEach((city) => {
                const opt = document.createElement('option');
                opt.value = city;
                opt.textContent = city;
                if (city === targetCity) opt.selected = true;
                citySelect.append(opt);
            });
            searchableWidgets.get('province')?.syncInputFromSelect();
            searchableWidgets.get('city')?.syncInputFromSelect();
            syncPickupAvailability();
        };

        const syncSummary = () => {
            const brand = brandRadios.find((r) => r.checked)?.value || '';
            const model = (modelInput?.value || '').trim();
            const serial = (serialInput?.value || '').trim();
            const accessories = [...repairStepper.querySelectorAll('input[name="accessories[]"]:checked')]
                .map((cb) => cb.closest('label')?.querySelector('.repair-choice-card__title')?.textContent?.trim() || '')
                .filter(Boolean);
            const issue = (issueInput?.value || '').trim();
            const name = (nameInput?.value || '').trim();
            const phone = (contactPhoneInput?.value || '').trim();
            const province = provinceSelect?.value || '';
            const city = citySelect?.value || '';
            const method = handoverRadios.find((r) => r.checked && !r.disabled)?.value || '';
            if (summaryDevice) summaryDevice.textContent = [brand, model, serial ? `سریال ${serial}` : ''].filter(Boolean).join(' — ') || '—';
            if (summaryAccessories) summaryAccessories.textContent = accessories.length > 0 ? accessories.join('، ') : 'بدون لوازم اضافه';
            if (summaryIssue) summaryIssue.textContent = issue.length > 110 ? `${issue.slice(0, 110)}…` : (issue || '—');
            if (summaryContact) summaryContact.textContent = [name, phone ? faDigitsGlobal(phone) : ''].filter(Boolean).join(' · ') || '—';
            if (summaryHandover) {
                const loc = [province, city].filter(Boolean).join('، ');
                const modeLabel = handoverLabels[method] || '';
                summaryHandover.textContent = [loc, modeLabel].filter(Boolean).join(' — ') || '—';
            }
            const phonePreview = repairStepper.querySelector('[data-auth-phone-preview]');
            if (phonePreview) phonePreview.textContent = phone ? faDigitsGlobal(phone) : '—';
        };

        const scrollToFormTop = (el) => {
            if (!(el instanceof HTMLElement)) return;
            const rect = el.getBoundingClientRect();
            if (rect.top < 0) {
                const header = document.querySelector('header.np-header, header[role="banner"], .site-header');
                const headerHeight = header instanceof HTMLElement ? header.getBoundingClientRect().height : 72;
                const offset = Math.max(76, Math.min(96, Math.round(headerHeight) + 12));
                const targetY = Math.max(0, window.scrollY + rect.top - offset);
                const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
                window.scrollTo({ top: targetY, behavior: reduced ? 'auto' : 'smooth' });
            }
        };

        let otpIssuedForPhone = normalizeDigitsGlobal(
            repairStepper.querySelector('[data-auth-phone-display]')?.textContent || ''
        ).replace(/\D+/g, '');
        const goToStep = (step, { scroll = false } = {}) => {
            currentStep = Math.min(4, Math.max(1, step));
            showAlert('');
            steps.forEach((panel) => {
                panel.hidden = Number(panel.dataset.step) !== currentStep;
            });
            indicators.forEach((item) => {
                const idx = Number(item.dataset.stepIndicator);
                item.setAttribute('aria-current', idx === currentStep ? 'step' : 'false');
                item.toggleAttribute('data-step-done', idx < currentStep);
            });
            if (currentStep === 3) {
                syncHandoverPanels();
            }
            if (currentStep === 4) {
                syncSummary();
                if (repairStepper.dataset.isGuest === '1') {
                    const normalizedPhone = normalizeDigitsGlobal(contactPhoneInput?.value || '').replace(/\D+/g, '');
                    const phoneStepEl = repairStepper.querySelector('[data-auth-step="phone"]');
                    const otpStepEl = repairStepper.querySelector('[data-auth-step="otp"]');
                    if (otpIssuedForPhone && normalizedPhone && normalizedPhone !== otpIssuedForPhone && otpStepEl && !otpStepEl.hidden) {
                        otpStepEl.hidden = true;
                        if (phoneStepEl) phoneStepEl.hidden = false;
                    }
                }
            }
            if (scroll) {
                scrollToFormTop(repairStepper.closest('.repair-split-card, .public-panel') || repairStepper);
            }
        };

        const validateStep = (step) => {
            clearAllErrors();
            if (step === 1) {
                const brand = brandRadios.find((r) => r.checked);
                if (!brand) {
                    setFieldError('device_brand', 'لطفاً برند دستگاه را انتخاب کنید.');
                    brandRadios[0]?.focus();
                    return false;
                }
                const model = (modelInput?.value || '').trim();
                if (!model) {
                    setFieldError('device_model', 'لطفاً مدل دستگاه را وارد کنید.');
                    modelInput?.focus();
                    return false;
                }
            } else if (step === 2) {
                const issue = (issueInput?.value || '').trim();
                if (issue.length < 10) {
                    setFieldError('issue', 'شرح مشکل دستگاه باید دست‌کم ۱۰ کاراکتر باشد.');
                    issueInput?.focus();
                    return false;
                }
            } else if (step === 3) {
                const name = (nameInput?.value || '').trim();
                if (name.length < 2) {
                    setFieldError('customer_name', 'لطفاً نام و نام خانوادگی را وارد کنید.');
                    nameInput?.focus();
                    return false;
                }
                const normalizedPhone = normalizeDigitsGlobal(contactPhoneInput?.value || '').replace(/\D+/g, '');
                if (!/^09\d{9}$/.test(normalizedPhone)) {
                    setFieldError('contact_phone', 'شماره موبایل ۱۱ رقمی معتبر با ۰۹ وارد کنید.');
                    contactPhoneInput?.focus();
                    return false;
                }
                const method = handoverRadios.find((r) => r.checked && !r.disabled)?.value || '';
                if (!provinceSelect?.value) {
                    setFieldError('province', 'لطفاً استان را انتخاب کنید.');
                    fieldInputMap.province?.focus();
                    return false;
                }
                if (!citySelect?.value) {
                    setFieldError('city', 'لطفاً شهر را انتخاب کنید.');
                    fieldInputMap.city?.focus();
                    return false;
                }
                if (!method) {
                    setFieldError('handover_method', 'لطفاً نحوهٔ تحویل دستگاه را انتخاب کنید.');
                    handoverRadios.find((r) => !r.disabled)?.focus();
                    return false;
                }
                if (method === 'doorstep_pickup') {
                    const addr = (pickupAddressInput?.value || '').trim();
                    if (addr.length < 10) {
                        setFieldError('pickup_address', 'برای تحویل در محل، نشانی دقیق (حداقل ۱۰ کاراکتر) را وارد کنید.');
                        pickupAddressInput?.focus();
                        return false;
                    }
                }
            } else if (step === 4) {
                if (repairStepper.dataset.isGuest === '1') {
                    const inlineOtpStep = repairStepper.querySelector('[data-auth-step="otp"]');
                    if (inlineOtpStep && !inlineOtpStep.hidden) {
                        setFieldError('repair_auth_code', 'لطفاً کد تأیید ۶ رقمی را وارد کنید.');
                        repairStepper.querySelector('#repair_auth_code')?.focus();
                    } else {
                        setFieldError('repair_auth_phone', 'برای ثبت نهایی، ابتدا شماره موبایل خود را تأیید کنید.');
                    }
                    return false;
                }
            }
            return true;
        };

        const syncOtherPersonField = ({ focusInput = false } = {}) => {
            if (!(otherPersonToggle instanceof HTMLInputElement) || !(otherPersonField instanceof HTMLElement)) return;
            const checked = otherPersonToggle.checked;
            otherPersonField.hidden = !checked;
            if (!checked && responsivePhoneInput) {
                responsivePhoneInput.value = '';
                setFieldError('responsive_phone', '');
            } else if (checked && focusInput) {
                responsivePhoneInput?.focus();
            }
        };
        otherPersonToggle?.addEventListener('change', () => {
            syncOtherPersonField({ focusInput: true });
            saveDraft();
        });

        // Clear inline field errors as the user interacts
        brandRadios.forEach((r) => r.addEventListener('change', () => setFieldError('device_brand', '')));
        handoverRadios.forEach((r) => r.addEventListener('change', () => setFieldError('handover_method', '')));
        Object.entries(fieldInputMap).forEach(([key, input]) => {
            if (!input) return;
            input.addEventListener('input', () => setFieldError(key, ''));
            input.addEventListener('change', () => setFieldError(key, ''));
        });

        // Issue categories toggle selection and show blue 2G-to-4G estimate only when selected (never auto-focus textarea)
        presetButtons.forEach((button) => {
            button.addEventListener('click', () => {
                presetButtons.forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
                const isUpgrade = button.dataset.issueUpgrade === '1';
                if (upgradeEstimateBox) {
                    upgradeEstimateBox.hidden = !isUpgrade;
                }
                setFieldError('issue', '');
            });
        });

        // Inline Step 3 guest phone + OTP verification
        const inlineAuth = repairStepper.querySelector('[data-repair-inline-auth]');
        if (inlineAuth instanceof HTMLElement) {
            const phoneStep = inlineAuth.querySelector('[data-auth-step="phone"]');
            const otpStep = inlineAuth.querySelector('[data-auth-step="otp"]');
            const phoneInput = inlineAuth.querySelector('#repair_auth_phone');
            const codeInput = inlineAuth.querySelector('#repair_auth_code');
            const phoneDisplay = inlineAuth.querySelector('[data-auth-phone-display]');
            const sendBtn = inlineAuth.querySelector('[data-auth-send-btn]');
            const verifyBtn = inlineAuth.querySelector('[data-auth-verify-btn]');
            const resendBtn = inlineAuth.querySelector('[data-auth-resend-btn]');
            const changePhoneBtn = inlineAuth.querySelector('[data-auth-change-phone]');
            const timerEl = inlineAuth.querySelector('[data-auth-timer]');
            const verifiedBadge = inlineAuth.querySelector('[data-auth-verified]');
            const sendUrl = inlineAuth.dataset.sendUrl || '';
            const verifyUrl = inlineAuth.dataset.verifyUrl || '';
            const resetUrl = inlineAuth.dataset.resetUrl || '';
            const faDigits = (val) => String(val).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
            const normalizeDigits = (val) => String(val || '')
                .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
                .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
            let remaining = Number(inlineAuth.dataset.resendRemaining || 0);
            let timerId = null;
            let webOtpController = null;

            const stopCountdown = () => {
                if (timerId) window.clearInterval(timerId);
                timerId = null;
            };
            const renderCountdown = () => {
                if (!resendBtn || !timerEl) return;
                if (remaining > 0) {
                    resendBtn.disabled = true;
                    timerEl.textContent = `${faDigits(remaining)} ثانیه تا ارسال مجدد`;
                } else {
                    resendBtn.disabled = false;
                    timerEl.textContent = '';
                    stopCountdown();
                }
            };
            const startCountdown = (seconds) => {
                stopCountdown();
                remaining = Math.max(0, Number(seconds) || 0);
                renderCountdown();
                if (remaining > 0) {
                    timerId = window.setInterval(() => {
                        remaining = Math.max(0, remaining - 1);
                        renderCountdown();
                    }, 1000);
                }
            };
            const startWebOtp = () => {
                if (!('OTPCredential' in window) || !navigator.credentials?.get || !codeInput) return;
                webOtpController?.abort();
                webOtpController = new AbortController();
                navigator.credentials.get({ otp: { transport: ['sms'] }, signal: webOtpController.signal })
                    .then((otp) => {
                        if (otp?.code && codeInput) {
                            codeInput.value = normalizeDigits(otp.code).replace(/\D+/g, '').slice(0, 6);
                            verifyBtn?.click();
                        }
                    }).catch(() => {});
            };
            const postAuth = async (url, payload) => {
                const token = csrfInput?.value || '';
                const body = new URLSearchParams({ _token: token, ...payload });
                const res = await window.fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: body.toString(),
                });
                const data = await res.json().catch(() => ({}));
                return { ok: res.ok && data?.ok !== false, data };
            };

            if (remaining > 0) startCountdown(remaining);
            if (otpStep && !otpStep.hidden) startWebOtp();

            sendBtn?.addEventListener('click', async () => {
                setFieldError('repair_auth_phone', '');
                const raw = normalizeDigits(contactPhoneInput?.value || phoneInput?.value || '').replace(/\D+/g, '');
                if (!/^09\d{9}$/.test(raw)) {
                    setFieldError('repair_auth_phone', 'شماره موبایل ۱۱ رقمی معتبر با ۰۹ در مرحلهٔ قبل وارد کنید.');
                    goToStep(3, { scroll: true });
                    contactPhoneInput?.focus();
                    return;
                }
                sendBtn.disabled = true;
                try {
                    const { ok, data } = await postAuth(sendUrl, { contact_phone: raw });
                    if (!ok) {
                        setFieldError('repair_auth_phone', data?.message || 'ارسال کد تأیید ممکن نیست.');
                        return;
                    }
                    otpIssuedForPhone = raw;
                    if (phoneDisplay) phoneDisplay.textContent = data.phone || faDigits(raw);
                    if (phoneStep) phoneStep.hidden = true;
                    if (otpStep) {
                        otpStep.hidden = false;
                        otpStep.classList.add('is-revealed');
                    }
                    const localOtpBox = inlineAuth.querySelector('[data-local-dev-otp]');
                    const localOtpCode = inlineAuth.querySelector('[data-local-dev-otp-code]');
                    if (localOtpBox && localOtpCode) {
                        if (data.local_dev_otp) {
                            localOtpCode.textContent = data.local_dev_otp;
                            localOtpBox.hidden = false;
                        } else {
                            localOtpBox.hidden = true;
                        }
                    }
                    startCountdown(data.remaining ?? 60);
                    if (window.matchMedia?.('(pointer: fine)').matches) {
                        codeInput?.focus({ preventScroll: true });
                    }
                    startWebOtp();
                } catch {
                    setFieldError('repair_auth_phone', 'خطا در برقراری ارتباط. لطفاً دوباره تلاش کنید.');
                } finally {
                    sendBtn.disabled = false;
                }
            });

            resendBtn?.addEventListener('click', async () => {
                if (resendBtn.disabled) return;
                setFieldError('repair_auth_code', '');
                resendBtn.disabled = true;
                try {
                    const { ok, data } = await postAuth(sendUrl, {});
                    if (!ok) {
                        setFieldError('repair_auth_code', data?.message || 'کمی صبر کنید و دوباره کد بخواهید.');
                        if (data?.resend_at) startCountdown(Math.max(1, data.resend_at - Math.floor(Date.now() / 1000)));
                        else resendBtn.disabled = false;
                        return;
                    }
                    const localOtpBox = inlineAuth.querySelector('[data-local-dev-otp]');
                    const localOtpCode = inlineAuth.querySelector('[data-local-dev-otp-code]');
                    if (localOtpBox && localOtpCode && data.local_dev_otp) {
                        localOtpCode.textContent = data.local_dev_otp;
                        localOtpBox.hidden = false;
                    }
                    startCountdown(data.remaining ?? 60);
                    if (window.matchMedia?.('(pointer: fine)').matches) {
                        codeInput?.focus({ preventScroll: true });
                    }
                    startWebOtp();
                } catch {
                    resendBtn.disabled = false;
                    setFieldError('repair_auth_code', 'خطا در ارسال مجدد کد.');
                }
            });

            changePhoneBtn?.addEventListener('click', async () => {
                webOtpController?.abort();
                stopCountdown();
                otpIssuedForPhone = '';
                setFieldError('repair_auth_code', '');
                setFieldError('repair_auth_phone', '');
                try { await postAuth(resetUrl, {}); } catch {}
                if (otpStep) otpStep.hidden = true;
                if (phoneStep) phoneStep.hidden = false;
                if (codeInput) codeInput.value = '';
                goToStep(3, { scroll: true });
                contactPhoneInput?.focus();
            });

            let verifyingOtp = false;
            const performOtpVerify = async () => {
                if (verifyingOtp) return;
                setFieldError('repair_auth_code', '');
                const code = normalizeDigits(codeInput?.value || '').replace(/\D+/g, '');
                if (!/^\d{6}$/.test(code)) {
                    setFieldError('repair_auth_code', 'کد تأیید شش‌رقمی را وارد کنید.');
                    codeInput?.focus();
                    return;
                }
                verifyingOtp = true;
                if (verifyBtn) verifyBtn.disabled = true;
                try {
                    const { ok, data } = await postAuth(verifyUrl, { code });
                    if (!ok) {
                        setFieldError('repair_auth_code', data?.message || 'کد تأیید نادرست یا منقضی است.');
                        codeInput?.focus();
                        return;
                    }
                    webOtpController?.abort();
                    stopCountdown();
                    repairStepper.dataset.isGuest = '0';
                    if (csrfInput && data.csrf_token) csrfInput.value = data.csrf_token;
                    if (submissionTokenInput && data.submission_token) submissionTokenInput.value = data.submission_token;
                    if (contactPhoneInput && data.phone) {
                        contactPhoneInput.value = data.phone;
                        contactPhoneInput.readOnly = true;
                    }
                    if (nameInput && !nameInput.value.trim() && data.customer_name) nameInput.value = data.customer_name;
                    if (phoneStep) phoneStep.hidden = true;
                    if (otpStep) otpStep.hidden = true;
                    const headEl = inlineAuth.querySelector('.repair-inline-auth-box__head');
                    if (headEl) headEl.hidden = true;
                    if (verifiedBadge) verifiedBadge.hidden = false;
                    if (submitBtn) submitBtn.hidden = false;
                    saveDraft();
                    // Auto-submit the repair request right after OTP verification succeeds
                    if (validateStep(1) && validateStep(2) && validateStep(3)) {
                        repairStepper.requestSubmit ? repairStepper.requestSubmit() : repairStepper.submit();
                    }
                } catch {
                    setFieldError('repair_auth_code', 'خطا در تأیید کد. لطفاً دوباره تلاش کنید.');
                } finally {
                    verifyingOtp = false;
                    if (verifyBtn) verifyBtn.disabled = false;
                }
            };

            verifyBtn?.addEventListener('click', performOtpVerify);
            codeInput?.addEventListener('input', () => {
                const digits = normalizeDigits(codeInput.value || '').replace(/\D+/g, '');
                if (digits.length === 6) {
                    performOtpVerify();
                }
            });
        }

        // Interactive Neshan Web Map (exact same SDK & setup as checkout)
        const repairMap = repairStepper.querySelector('[data-repair-map]');
        if (repairMap instanceof HTMLElement) {
            const viewport = repairMap.querySelector('[data-map-viewport]');
            const tilesEl = repairMap.querySelector('[data-map-tiles]');
            const fallbackPin = repairMap.querySelector('[data-map-fallback-pin]');
            const coordsEl = repairMap.querySelector('[data-map-coords]');
            const latInput = repairMap.querySelector('[data-map-lat]');
            const lngInput = repairMap.querySelector('[data-map-lng]');
            const neshanKey = (repairMap.dataset.neshanKey || '').trim();
            const reverseUrl = repairStepper.dataset.reverseGeocodeUrl || '';
            const cityCenters = {
                'شهریار': [35.6597, 51.0592],
                'تهران': [35.6892, 51.3890],
                'کرج': [35.8400, 50.9391],
                'قدس': [35.7214, 51.1090],
                'شهر قدس': [35.7214, 51.1090],
                'اندیشه': [35.6829, 51.0195],
                'ملارد': [35.6659, 50.9767],
                'فردیس': [35.7239, 50.9885],
                'اسلامشهر': [35.5466, 51.2352],
                'رباط کریم': [35.4846, 51.0829],
                'نسیم شهر': [35.5653, 51.1603],
                'پرند': [35.4769, 50.9464],
                'صفادشت': [35.6836, 50.8350],
                'باغستان': [35.6350, 51.1167],
                'صباشهر': [35.5819, 51.0933],
                'وحیدیه': [35.6095, 51.0315],
                'شاهدشهر': [35.5733, 51.0706],
                'فردوسیه': [35.6025, 51.0695],
            };
            let userPinned = Boolean((latInput?.value || '').trim() && (lngInput?.value || '').trim());
            let lat = parseFloat(latInput?.value || repairMap.dataset.defaultLat || '35.6597') || 35.6597;
            let lng = parseFloat(lngInput?.value || repairMap.dataset.defaultLng || '51.0592') || 51.0592;
            let zoom = 14;
            let neshanMap = null;
            let neshanMarker = null;
            let neshanBooting = false;
            let addrEdited = Boolean((pickupAddressInput?.value || '').trim());
            let geocodeTimer = null;
            pickupAddressInput?.addEventListener('input', () => { addrEdited = (pickupAddressInput.value || '').trim().length > 0; });

            const runReverseGeocode = (fLat, fLng) => {
                if (!reverseUrl) return;
                if (geocodeTimer) window.clearTimeout(geocodeTimer);
                geocodeTimer = window.setTimeout(() => {
                    const url = new URL(reverseUrl, window.location.origin);
                    url.searchParams.set('lat', fLat);
                    url.searchParams.set('lng', fLng);
                    window.fetch(url.href, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                        .then((r) => (r.ok ? r.json() : null))
                        .then((data) => {
                            if (!data?.ok) return;
                            if (data.province && provinceSelect && [...provinceSelect.options].some((o) => o.value === data.province)) {
                                provinceSelect.value = data.province;
                                populateRepairCities(data.city || '');
                            }
                            if (pickupAddressInput && typeof data.address === 'string' && data.address.trim()) {
                                pickupAddressInput.value = data.address.slice(0, pickupAddressInput.maxLength || 500);
                                setFieldError('pickup_address', '');
                            }
                            saveDraft();
                        }).catch(() => {});
                }, 220);
            };

            const updateCoords = (la, ln, triggerReverse = false) => {
                userPinned = true;
                lat = Math.max(-85, Math.min(85, la));
                lng = Math.max(-180, Math.min(180, ln));
                const fLat = lat.toFixed(6);
                const fLng = lng.toFixed(6);
                if (latInput) latInput.value = fLat;
                if (lngInput) lngInput.value = fLng;
                if (coordsEl) coordsEl.textContent = `موقعیت انتخاب شد · ${fLat}, ${fLng}`;
                if (neshanMarker && typeof neshanMarker.setLatLng === 'function') {
                    const cur = neshanMarker.getLatLng();
                    if (!cur || Math.abs(cur.lat - lat) > 0.000001 || Math.abs(cur.lng - lng) > 0.000001) {
                        neshanMarker.setLatLng([lat, lng]);
                    }
                }
                if (triggerReverse) runReverseGeocode(fLat, fLng);
            };

            const ensureRepairMap = () => {
                if (currentStep !== 3 || !pickupPanel || pickupPanel.hidden) return;
                if (!viewport || viewport.offsetWidth === 0) return;
                if (neshanMap) {
                    neshanMap.invalidateSize(true);
                    return;
                }
                if (neshanBooting || !tilesEl) return;
                neshanBooting = true;
                mountNeshanMapWidget({
                    tilesEl,
                    fallbackPin,
                    neshanKey,
                    lat,
                    lng,
                    zoom,
                    onMove: (nextLat, nextLng) => {
                        updateCoords(nextLat, nextLng, true);
                        saveDraft();
                    },
                }).then((instance) => {
                    neshanBooting = false;
                    if (!instance) return;
                    neshanMap = instance.map;
                    neshanMarker = instance.marker;
                    neshanMap.on('zoomend', () => {
                        zoom = neshanMap.getZoom() || zoom;
                    });
                    neshanMap.invalidateSize(true);
                });
            };
            repairStepper._renderRepairMap = ensureRepairMap;
            repairStepper._centerMapForCity = (cityName) => {
                const targetNorm = normalizePersianSearch(cityName);
                const match = targetNorm
                    ? Object.entries(cityCenters).find(([name]) => normalizePersianSearch(name) === targetNorm)
                    : null;
                const coords = match ? match[1] : null;
                if (!coords || userPinned) return;
                lat = coords[0];
                lng = coords[1];
                if (neshanMap) {
                    neshanMap.setView([lat, lng], zoom);
                    if (neshanMarker && typeof neshanMarker.setLatLng === 'function') {
                        neshanMarker.setLatLng([lat, lng]);
                    }
                }
            };
            repairStepper._reverseGeocodeIfPinned = () => {
                if (userPinned && latInput?.value && lngInput?.value) {
                    runReverseGeocode(latInput.value, lngInput.value);
                }
            };

            repairMap.querySelectorAll('[data-map-zoom]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    zoom = btn.dataset.mapZoom === 'in' ? Math.min(18, zoom + 1) : Math.max(5, zoom - 1);
                    if (neshanMap) neshanMap.setZoom(zoom);
                });
            });
            repairMap.querySelector('[data-map-locate]')?.addEventListener('click', () => {
                if (!navigator.geolocation) return;
                navigator.geolocation.getCurrentPosition((pos) => {
                    zoom = 15;
                    updateCoords(pos.coords.latitude, pos.coords.longitude, true);
                    if (neshanMap) neshanMap.setView([lat, lng], zoom);
                    saveDraft();
                }, () => {}, { timeout: 8000 });
            });
        }

        provinceSelect?.addEventListener('change', () => { populateRepairCities(''); saveDraft(); });
        citySelect?.addEventListener('change', () => {
            syncPickupAvailability();
            if (typeof repairStepper._centerMapForCity === 'function') {
                repairStepper._centerMapForCity(citySelect.value);
            }
            saveDraft();
        });
        handoverRadios.forEach((radio) => radio.addEventListener('change', () => { syncHandoverPanels(); saveDraft(); }));

        repairStepper.addEventListener('input', saveDraft);
        repairStepper.addEventListener('change', saveDraft);

        indicators.forEach((btn) => {
            btn.addEventListener('click', () => {
                const target = Number(btn.dataset.stepIndicator || 1);
                if (target === currentStep) return;
                if (target < currentStep) {
                    goToStep(target, { scroll: true });
                    return;
                }
                for (let s = currentStep; s < target; s += 1) {
                    if (!validateStep(s)) {
                        goToStep(s, { scroll: true });
                        validateStep(s);
                        return;
                    }
                }
                goToStep(target, { scroll: true });
            });
        });

        repairStepper.querySelectorAll('[data-step-next]').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (validateStep(currentStep)) goToStep(Math.min(4, currentStep + 1), { scroll: true });
            });
        });

        repairStepper.querySelectorAll('[data-step-prev]').forEach((btn) => {
            btn.addEventListener('click', () => goToStep(Math.max(1, currentStep - 1), { scroll: true }));
        });

        repairStepper.addEventListener('submit', (event) => {
            for (const step of [1, 2, 3, 4]) {
                if (!validateStep(step)) {
                    event.preventDefault();
                    goToStep(step, { scroll: true });
                    validateStep(step);
                    return;
                }
            }
        });

        // Restore draft if no server-rendered old values exist
        const hasServerValues = Boolean(brandRadios.some((r) => r.checked) || (modelInput?.value || '').trim() || (issueInput?.value || '').trim());
        if (!hasServerValues) {
            try {
                const saved = JSON.parse(window.sessionStorage?.getItem(draftKey) || 'null');
                if (saved && typeof saved === 'object') {
                    if (saved.device_brand) {
                        const br = brandRadios.find((r) => r.value === saved.device_brand);
                        if (br) br.checked = true;
                    }
                    if (saved.device_model && modelInput) modelInput.value = saved.device_model;
                    if (saved.device_serial && serialInput) serialInput.value = saved.device_serial;
                    if (Array.isArray(saved.accessories)) {
                        saved.accessories.forEach((val) => {
                            const cb = repairStepper.querySelector(`input[name="accessories[]"][value="${CSS.escape(val)}"]`);
                            if (cb instanceof HTMLInputElement) cb.checked = true;
                        });
                    }
                    if (saved.issue && issueInput) issueInput.value = saved.issue;
                    if (saved.province && provinceSelect) {
                        provinceSelect.value = saved.province;
                        populateRepairCities(saved.city || '');
                    }
                    if (saved.handover_method) {
                        const hr = handoverRadios.find((r) => r.value === saved.handover_method && !r.disabled);
                        if (hr) hr.checked = true;
                    }
                    if (saved.pickup_address && pickupAddressInput) pickupAddressInput.value = saved.pickup_address;
                    if (saved.pickup_notes && pickupNotesInput) pickupNotesInput.value = saved.pickup_notes;
                    if (otherPersonToggle instanceof HTMLInputElement) {
                        otherPersonToggle.checked = Boolean(saved.other_person_handover || saved.responsive_phone);
                    }
                    if (saved.responsive_phone && responsivePhoneInput) responsivePhoneInput.value = saved.responsive_phone;
                    if (saved.customer_name && nameInput && !nameInput.value.trim()) nameInput.value = saved.customer_name;
                }
            } catch {}
        }
        syncOtherPersonField();

        if (provinceSelect?.value) {
            populateRepairCities(citySelect?.dataset.selectedCity || citySelect?.value || '');
        } else {
            syncPickupAvailability();
        }
        goToStep(currentStep);
    }
    const adminNavigation = document.querySelector('[data-admin-navigation]');
    const adminMobileToggle = document.querySelector('[data-admin-mobile-toggle]');
    if (adminNavigation instanceof HTMLElement) {
        const desktop = window.matchMedia('(min-width: 801px)');
        const sidebar = adminNavigation.closest('.admin-sidebar');
        const toggle = document.querySelector('[data-admin-sidebar-toggle]');
        const setMobileOpen = (open) => {
            const shouldOpen = !desktop.matches && open;
            adminNavigation.classList.toggle('is-open', shouldOpen);
            document.body.classList.toggle('admin-nav-open', shouldOpen);
            if (adminMobileToggle instanceof HTMLButtonElement) {
                adminMobileToggle.setAttribute('aria-expanded', String(shouldOpen));
            }
        };
        const updateNavigation = () => {
            if (desktop.matches) {
                setMobileOpen(false);
            }
            if (sidebar instanceof HTMLElement) sidebar.hidden = false;
            if (toggle instanceof HTMLButtonElement) {
                toggle.hidden = !desktop.matches;
                toggle.setAttribute('aria-expanded', 'true');
            }
        };
        updateNavigation();
        desktop.addEventListener('change', updateNavigation);
        window.addEventListener('pageshow', (event) => {
            if (event.persisted && !desktop.matches) setMobileOpen(false);
        });
        if (adminMobileToggle instanceof HTMLButtonElement) {
            adminMobileToggle.addEventListener('click', () => {
                if (desktop.matches) return;
                setMobileOpen(!adminNavigation.classList.contains('is-open'));
            });
        }
        if (sidebar instanceof HTMLElement && toggle instanceof HTMLButtonElement) {
            toggle.addEventListener('click', () => {
                if (!desktop.matches) return;
                sidebar.hidden = !sidebar.hidden;
                toggle.setAttribute('aria-expanded', String(!sidebar.hidden));
            });
        }
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || desktop.matches || !adminNavigation.classList.contains('is-open')) return;
            setMobileOpen(false);
            adminMobileToggle?.focus();
        });
        const groups = [...adminNavigation.querySelectorAll('.admin-nav-group')];

        groups.forEach((group) => {
            const button = group.querySelector('.admin-nav-group-trigger');
            const setOpen = (value) => {
                group.toggleAttribute('open', value);
                button.setAttribute('aria-expanded', String(value));
            };
            button.addEventListener('click', () => {
                const next = !group.hasAttribute('open');
                groups.filter(other => other !== group).forEach(other => {
                    other.removeAttribute('open');
                    other.querySelector('.admin-nav-group-trigger').setAttribute('aria-expanded', 'false');
                });
                setOpen(next);
            });
        });
        adminNavigation.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const link = event.target instanceof Element ? event.target.closest('.admin-nav a') : null;
            if (!link) return;
            if (!desktop.matches) setMobileOpen(false);
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

    document.querySelectorAll('[data-copy-shipping]').forEach((button) => {
        const defaultLabel = button.textContent?.trim() || 'کپی اطلاعات ارسال';
        const copiedLabel = button.dataset.copiedLabel || 'اطلاعات کپی شد';
        let resetTimer = null;
        button.addEventListener('click', async () => {
            const text = button.dataset.copyText || '';
            const statusId = button.getAttribute('aria-describedby');
            const status = statusId ? document.getElementById(statusId) : null;
            if (!text) return;
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const helper = document.createElement('textarea');
                    helper.value = text;
                    helper.setAttribute('readonly', '');
                    helper.style.position = 'fixed';
                    helper.style.opacity = '0';
                    document.body.append(helper);
                    helper.select();
                    document.execCommand('copy');
                    helper.remove();
                }
                button.textContent = copiedLabel;
                button.classList.add('is-copied');
                if (status) status.textContent = copiedLabel;
                if (resetTimer) window.clearTimeout(resetTimer);
                resetTimer = window.setTimeout(() => {
                    button.textContent = defaultLabel;
                    button.classList.remove('is-copied');
                }, 2600);
            } catch {
                if (status) status.textContent = 'کپی خودکار انجام نشد؛ لطفاً متن را دستی کپی کنید.';
            }
        });
    });

    document.querySelectorAll('[data-toggle-track-search]').forEach((button) => {
        button.addEventListener('click', () => {
            const targetId = button.getAttribute('aria-controls');
            const panel = targetId ? document.getElementById(targetId) : null;
            if (!panel) return;
            const expanded = button.getAttribute('aria-expanded') === 'true';
            const next = !expanded;
            button.setAttribute('aria-expanded', String(next));
            panel.hidden = !next;
            button.textContent = next ? 'بستن فرم پیگیری' : 'پیگیری کد دیگر';
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

document.querySelectorAll('[data-delivery-address-form]').forEach((form) => {
    const update = () => {
        const courier = form.elements.carrier.querySelector('[value="courier"]');
        courier.disabled = form.elements.address.selectedOptions[0]?.dataset.courier !== '1';
        courier.hidden = courier.disabled;
        if (courier.disabled && courier.selected) form.elements.carrier.value = 'tipax';
    };
    form.elements.address.addEventListener('change', update);
    update();
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
    const courier = form.querySelector('[name="method"][value="courier"]');
    const zones = JSON.parse(form.dataset.courierZones || '{}');
    const normalizeZone = (value) => (value || '').replace(/[\s\u200c]+/gu, '').replaceAll('ي', 'ی').replaceAll('ك', 'ک');
    const syncCourier = () => {
        if (!courier) return;
        const saved = form.querySelector('[name="address"]')?.selectedOptions[0];
        const mode = form.querySelector('[name="address_mode"]:checked, input[type="hidden"][name="address_mode"]')?.value;
        const province = normalizeZone(mode === 'saved' ? saved?.dataset.province : form.elements.province?.value);
        const city = normalizeZone(mode === 'saved' ? saved?.dataset.city : form.elements.city?.value);
        const cities = Object.entries(zones).find(([region]) => normalizeZone(region) === province)?.[1] || [];
        courier.disabled = !cities.some((place) => normalizeZone(place) === city);
        courier.closest('label').hidden = courier.disabled;
        if (courier.disabled && courier.checked) {
            form.querySelector('[name="method"][value="pickup"]').checked = true;
        }
    };
    const confirmDelivery = form.querySelector('[data-confirm-delivery]');
    const confirmAddressRow = form.querySelector('[data-confirm-address-row]');
    const confirmAddress = form.querySelector('[data-confirm-address]');
    const confirmPayment = form.querySelector('[data-confirm-payment]');

    const syncSummary = () => {
        syncCourier();
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

    const scrollToCheckoutTop = () => {
        const header = document.querySelector('header.np-header, header[role="banner"], .site-header');
        const headerHeight = header instanceof HTMLElement ? header.getBoundingClientRect().height : 72;
        const offset = Math.max(80, Math.min(96, Math.round(headerHeight) + 16));
        const rect = stepper.getBoundingClientRect();
        if (rect.top < offset || rect.top > offset + 24) {
            const targetY = Math.max(0, window.scrollY + rect.top - offset);
            const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
            window.scrollTo({ top: targetY, behavior: reduced ? 'auto' : 'smooth' });
        }
    };

    const tryNavigateToStep = (targetStep) => {
        if (targetStep === currentStep) return;
        if (targetStep < currentStep) {
            persistCheckoutDraft(false);
            goToStep(targetStep);
            scrollToCheckoutTop();
            return;
        }
        if (targetStep >= 2 && !validateStep1()) {
            goToStep(1);
            scrollToCheckoutTop();
            return;
        }
        if (targetStep >= 3 && !validateAddressIfShipping(false)) {
            goToStep(2);
            scrollToCheckoutTop();
            return;
        }
        if (targetStep >= 4 && !validateStep3()) {
            goToStep(3);
            scrollToCheckoutTop();
            return;
        }
        persistCheckoutDraft(true);
        goToStep(targetStep);
        scrollToCheckoutTop();
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

        const streetInput = form.querySelector('[name="street_address"]');
        let streetRevision = 0;
        let lookupRevision = 0;
        streetInput?.addEventListener('input', () => streetRevision++);
        form.querySelectorAll('[name="address_mode"]').forEach((input) => {
            input.addEventListener('change', () => lookupRevision++);
        });

        const reverseGeocode = (la, ln) => {
            if (reverseGeocodeTimer) window.clearTimeout(reverseGeocodeTimer);
            const lookup = ++lookupRevision;
            const street = streetRevision;
            const current = () => lookup === lookupRevision && getAddressMode() === 'new';
            const fallback = () => {
                if (!current()) return;
                const nearest = findNearestIranLocation(la, ln);
                if (nearest.province) applyMapProvinceAndCity(nearest.province, nearest.city);
            };
            reverseGeocodeTimer = window.setTimeout(() => {
                if (!current()) return;
                if (!reverseGeocodeUrl || !window.fetch) {
                    fallback();
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
                    if (!current()) return;
                    if (data?.ok && data.province) {
                        applyMapProvinceAndCity(data.province, data.city || '');
                    } else {
                        fallback();
                    }
                    if (streetInput && street === streetRevision && data?.ok && typeof data.address === 'string' && data.address.trim()) {
                        streetInput.value = data.address.slice(0, streetInput.maxLength);
                        streetInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }).catch(fallback);
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

        const renderMap = (saveInputs = false, shouldReverseGeocode = false) => {
            if (saveInputs) saveCoordinates(lat, lng);
            if (shouldReverseGeocode) reverseGeocode(lat, lng);
            if (neshanMap) {
                neshanMap.setView([lat, lng], zoom, { animate: true });
                if (neshanMarker) neshanMarker.setLatLng([lat, lng]);
                return;
            }
            if (!viewport || !tilesEl) return;
            if (fallbackPin) fallbackPin.hidden = true;
            tilesEl.style.pointerEvents = 'none';
            if (!neshanKey) {
                tilesEl.innerHTML = '<div class="checkout-map__unavailable">نقشه نشان در دسترس نیست؛ نشانی دقیق را در کادر زیر وارد کنید.</div>';
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
                reverseGeocode(lat, lng);
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
                reverseGeocode(lat, lng);
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
                                reverseGeocode(pos.lat, pos.lng);
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
                            reverseGeocode(ev.latlng.lat, ev.latlng.lng);
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
                let settled = false;
                const fallbackTimer = window.setTimeout(() => {
                    if (settled || neshanMap) return;
                    settled = true;
                    if (fallbackPin) fallbackPin.hidden = false;
                    tilesEl.style.pointerEvents = 'none';
                    renderMap(Boolean(latInput?.value), false);
                }, 4000);
                script.onload = () => {
                    if (settled) return;
                    settled = true;
                    window.clearTimeout(fallbackTimer);
                    mountNeshan();
                };
                script.onerror = () => {
                    if (settled) return;
                    settled = true;
                    window.clearTimeout(fallbackTimer);
                    if (fallbackPin) fallbackPin.hidden = false;
                    tilesEl.style.pointerEvents = 'none';
                    renderMap(Boolean(latInput?.value), false);
                };
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
        const step2Panel = document.querySelector('[data-checkout-step="2"]');
        if (neshanKey && (!step2Panel || !step2Panel.hidden)) {
            bootNeshanSdk();
        }
    }

    const recoveryResendBtn = document.querySelector('[data-recovery-resend-btn]');
    const recoveryTimerEl = document.querySelector('[data-recovery-resend-timer]');
    if (recoveryResendBtn instanceof HTMLButtonElement && recoveryTimerEl) {
        const resendAt = Number(recoveryResendBtn.dataset.resendAt || 0);
        const faDigits = (n) => String(n).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
        const tick = () => {
            const left = Math.max(0, resendAt - Math.floor(Date.now() / 1000));
            if (left > 0) {
                recoveryResendBtn.disabled = true;
                recoveryTimerEl.textContent = `(${faDigits(left)} ثانیه تا ارسال مجدد)`;
                window.setTimeout(tick, 1000);
            } else {
                recoveryResendBtn.disabled = false;
                recoveryTimerEl.textContent = '';
            }
        };
        if (resendAt > Math.floor(Date.now() / 1000)) {
            tick();
        }
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-toggle-panel]') : null;
        if (trigger instanceof HTMLButtonElement) {
            const targetId = trigger.getAttribute('data-toggle-panel');
            const panel = targetId ? document.getElementById(targetId) : null;
            if (panel instanceof HTMLElement) {
                const groupName = trigger.getAttribute('data-toggle-group');
                const willOpen = panel.hidden;
                if (willOpen && groupName) {
                    document.querySelectorAll(`[data-toggle-group="${CSS.escape(groupName)}"]`).forEach((otherBtn) => {
                        if (otherBtn === trigger) return;
                        const otherId = otherBtn.getAttribute('data-toggle-panel');
                        const otherPanel = otherId ? document.getElementById(otherId) : null;
                        if (otherPanel instanceof HTMLElement) otherPanel.hidden = true;
                        otherBtn.setAttribute('aria-expanded', 'false');
                    });
                }
                panel.hidden = !willOpen;
                trigger.setAttribute('aria-expanded', String(willOpen));
            }
            return;
        }

        const brandPill = event.target instanceof Element ? event.target.closest('[data-brand-pill]') : null;
        if (brandPill instanceof HTMLButtonElement) {
            const brand = brandPill.getAttribute('data-brand-pill') || '';
            const form = brandPill.closest('form');
            const modelInput = form?.querySelector('input[name="device_model"]');
            form?.querySelectorAll('[data-brand-pill]').forEach((btn) => {
                btn.setAttribute('aria-pressed', String(btn === brandPill));
            });
            if (modelInput instanceof HTMLInputElement) {
                const knownBrands = ['PAX', 'Newland', 'NewPOS', 'SZZT', 'MoreFun', 'TOPWISE'];
                let current = modelInput.value.trim();
                for (const b of knownBrands) {
                    if (current.toLowerCase().startsWith(b.toLowerCase())) {
                        current = current.slice(b.length).trim();
                        break;
                    }
                }
                if (brand && brand !== 'سایر') {
                    modelInput.value = current ? `${brand} ${current}` : `${brand} `;
                } else {
                    modelInput.value = current;
                }
            }
        }
    });

    document.querySelectorAll('[data-repair-handover-form]').forEach((form) => {
        const pickupBox = form.querySelector('[data-handover-pickup-fields]');
        const radios = form.querySelectorAll('[data-handover-radio]');
        const sync = () => {
            const selected = form.querySelector('[data-handover-radio]:checked');
            const isPickup = selected instanceof HTMLInputElement && selected.value === 'doorstep_pickup';
            if (pickupBox instanceof HTMLElement) {
                pickupBox.hidden = !isPickup;
                const addr = pickupBox.querySelector('textarea[name="pickup_address"]');
                if (addr instanceof HTMLTextAreaElement) {
                    addr.required = isPickup;
                }
            }
        };
        radios.forEach((radio) => radio.addEventListener('change', sync));
        sync();
    });

    // Product comparison "Show differences only" toggle
    const compareDiffToggle = document.querySelector('[data-compare-diff-toggle]');
    const compareBoard = document.querySelector('[data-compare-board]');
    if (compareDiffToggle instanceof HTMLInputElement && compareBoard instanceof HTMLElement) {
        const noDiffNotice = document.querySelector('[data-compare-no-diff]');
        const syncCompareDiff = () => {
            const onlyDiff = compareDiffToggle.checked;
            compareBoard.classList.toggle('is-diff-only', onlyDiff);
            let visibleCount = 0;
            const rows = [...compareBoard.querySelectorAll('[data-compare-row]')];
            rows.forEach((row) => {
                const show = !onlyDiff || row.dataset.diff === '1';
                row.hidden = !show;
                if (show) visibleCount += 1;
            });
            compareBoard.querySelectorAll('[data-compare-group]').forEach((groupRow) => {
                let sibling = groupRow.nextElementSibling;
                let hasVisible = false;
                while (sibling && !sibling.hasAttribute('data-compare-group')) {
                    if (sibling.hasAttribute('data-compare-row') && !sibling.hidden) {
                        hasVisible = true;
                        break;
                    }
                    sibling = sibling.nextElementSibling;
                }
                groupRow.hidden = !hasVisible;
            });
            if (noDiffNotice instanceof HTMLElement) {
                noDiffNotice.hidden = !(onlyDiff && visibleCount === 0);
            }
        };
        compareDiffToggle.addEventListener('change', syncCompareDiff);
        syncCompareDiff();
    }

    // In-page handover change modal on account repair details
    const handoverModal = document.querySelector('[data-handover-modal]');
    if (handoverModal instanceof HTMLDialogElement) {
        const form = handoverModal.querySelector('[data-repair-handover-form]');
        const modalMap = handoverModal.querySelector('[data-repair-modal-map]');
        let modalNeshanMap = null;
        let modalNeshanMarker = null;
        let modalMapBooting = false;

        const initModalMap = () => {
            if (!(modalMap instanceof HTMLElement)) return;
            const pickupFields = handoverModal.querySelector('[data-handover-pickup-fields]');
            if (pickupFields instanceof HTMLElement && pickupFields.hidden) return;
            const viewport = modalMap.querySelector('[data-map-viewport]');
            const tilesEl = modalMap.querySelector('[data-map-tiles]');
            const fallbackPin = modalMap.querySelector('[data-map-fallback-pin]');
            const coordsEl = modalMap.querySelector('[data-map-coords]');
            const latInput = modalMap.querySelector('[data-map-lat]');
            const lngInput = modalMap.querySelector('[data-map-lng]');
            const addrInput = form?.querySelector('[data-handover-address]');
            const reverseUrl = form?.dataset.reverseGeocodeUrl || '';
            const neshanKey = (modalMap.dataset.neshanKey || '').trim();
            if (!viewport || !tilesEl || viewport.offsetWidth === 0) return;

            if (modalNeshanMap) {
                modalNeshanMap.invalidateSize(true);
                return;
            }
            if (modalMapBooting) return;
            modalMapBooting = true;

            let lat = parseFloat(latInput?.value || modalMap.dataset.defaultLat || '35.6597') || 35.6597;
            let lng = parseFloat(lngInput?.value || modalMap.dataset.defaultLng || '51.0592') || 51.0592;
            let zoom = 14;
            let geoTimer = null;

            const updateModalCoords = (la, ln, triggerReverse = false) => {
                lat = Math.max(-85, Math.min(85, la));
                lng = Math.max(-180, Math.min(180, ln));
                const fLat = lat.toFixed(6);
                const fLng = lng.toFixed(6);
                if (latInput) latInput.value = fLat;
                if (lngInput) lngInput.value = fLng;
                if (coordsEl) coordsEl.textContent = `${fLat}, ${fLng}`;
                if (modalNeshanMarker && typeof modalNeshanMarker.setLatLng === 'function') {
                    modalNeshanMarker.setLatLng([lat, lng]);
                }
                if (triggerReverse && reverseUrl) {
                    if (geoTimer) window.clearTimeout(geoTimer);
                    geoTimer = window.setTimeout(() => {
                        const url = new URL(reverseUrl, window.location.origin);
                        url.searchParams.set('lat', fLat);
                        url.searchParams.set('lng', fLng);
                        window.fetch(url.href, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                            .then((r) => (r.ok ? r.json() : null))
                            .then((data) => {
                                if (data?.ok && addrInput instanceof HTMLTextAreaElement && typeof data.address === 'string' && data.address.trim()) {
                                    addrInput.value = data.address.slice(0, addrInput.maxLength || 400);
                                }
                            }).catch(() => {});
                    }, 220);
                }
            };

            mountNeshanMapWidget({
                tilesEl,
                fallbackPin,
                neshanKey,
                lat,
                lng,
                zoom,
                onMove: (nextLat, nextLng) => updateModalCoords(nextLat, nextLng, true),
            }).then((instance) => {
                modalMapBooting = false;
                if (!instance) return;
                modalNeshanMap = instance.map;
                modalNeshanMarker = instance.marker;
                modalNeshanMap.invalidateSize(true);
            });

            modalMap.querySelectorAll('[data-map-zoom]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    zoom = btn.dataset.mapZoom === 'in' ? Math.min(18, zoom + 1) : Math.max(5, zoom - 1);
                    if (modalNeshanMap) modalNeshanMap.setZoom(zoom);
                });
            });
            modalMap.querySelector('[data-map-locate]')?.addEventListener('click', () => {
                if (!navigator.geolocation) return;
                navigator.geolocation.getCurrentPosition((pos) => {
                    zoom = 15;
                    updateModalCoords(pos.coords.latitude, pos.coords.longitude, true);
                    if (modalNeshanMap) modalNeshanMap.setView([lat, lng], zoom);
                }, () => {}, { timeout: 8000 });
            });
        };

        const openModal = () => {
            if (!handoverModal.open) {
                handoverModal.showModal();
            }
            window.requestAnimationFrame(initModalMap);
        };
        const closeModal = () => {
            if (handoverModal.open) handoverModal.close();
        };

        document.querySelectorAll('[data-open-handover-modal]').forEach((btn) => {
            btn.addEventListener('click', openModal);
        });
        handoverModal.querySelectorAll('[data-close-handover-modal]').forEach((btn) => {
            btn.addEventListener('click', closeModal);
        });
        handoverModal.addEventListener('click', (event) => {
            if (event.target === handoverModal) closeModal();
        });
        handoverModal.querySelectorAll('[data-handover-radio]').forEach((radio) => {
            radio.addEventListener('change', () => window.requestAnimationFrame(initModalMap));
        });
        if (handoverModal.dataset.autoOpen === '1') {
            openModal();
        }
    }

    document.querySelectorAll('[data-toggle-handover-editor]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('aria-controls');
            const panel = targetId ? document.getElementById(targetId) : null;
            if (!(panel instanceof HTMLElement)) return;
            const willOpen = panel.hidden;
            panel.hidden = !willOpen;
            btn.setAttribute('aria-expanded', String(willOpen));
        });
    });

    const sanitizeDigitsOnly = (el) => {
        if (!(el instanceof HTMLInputElement)) return;
        const max = Number.parseInt(el.dataset.digitsOnly || el.getAttribute('maxlength') || '0', 10);
        const filtered = (el.value || '').replace(/[^0-9\u06F0-\u06F9\u0660-\u0669]+/g, '');
        const clamped = max > 0 ? filtered.slice(0, max) : filtered;
        if (el.value !== clamped) {
            el.value = clamped;
        }
    };

    document.addEventListener('input', (event) => {
        const target = event.target;
        if (target instanceof HTMLInputElement && target.hasAttribute('data-digits-only')) {
            sanitizeDigitsOnly(target);
        }
    });

    document.addEventListener('paste', (event) => {
        const target = event.target;
        if (target instanceof HTMLInputElement && target.hasAttribute('data-digits-only')) {
            setTimeout(() => sanitizeDigitsOnly(target), 0);
        }
    });

    const telemetryMeta = document.querySelector('meta[name="np-telemetry-url"]');
    const telemetryUrl = telemetryMeta?.getAttribute('content') || '';
    if (telemetryUrl) {
        let reportedCount = 0;
        const reportedKeys = new Set();
        const stripQuery = (value) => String(value || '').split('?')[0].split('#')[0].slice(0, 300);
        const sendTelemetry = (payload) => {
            if (reportedCount >= 3) return;
            const message = String(payload.message || '').trim().slice(0, 300);
            if (!message) return;
            const key = `${payload.kind || 'error'}:${message}:${payload.line || 0}`;
            if (reportedKeys.has(key)) return;
            reportedKeys.add(key);
            reportedCount += 1;
            try {
                fetch(telemetryUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        message,
                        source: stripQuery(payload.source || ''),
                        line: Number.isFinite(payload.line) ? Math.max(0, Math.trunc(payload.line)) : undefined,
                        column: Number.isFinite(payload.column) ? Math.max(0, Math.trunc(payload.column)) : undefined,
                        path: stripQuery(window.location.pathname || '/'),
                        kind: payload.kind === 'unhandledrejection' ? 'unhandledrejection' : 'error',
                    }),
                    keepalive: true,
                    credentials: 'omit',
                }).catch(() => {});
            } catch {
                // Telemetry must never disrupt the storefront UI.
            }
        };

        window.addEventListener('error', (event) => {
            sendTelemetry({
                message: event.message || event.error?.message || 'Script error',
                source: event.filename || '',
                line: event.lineno,
                column: event.colno,
                kind: 'error',
            });
        });

        window.addEventListener('unhandledrejection', (event) => {
            const reason = event.reason;
            const message = typeof reason === 'string'
                ? reason
                : (reason && typeof reason.message === 'string' ? reason.message : 'Unhandled promise rejection');
            sendTelemetry({
                message,
                source: '',
                kind: 'unhandledrejection',
            });
        });
    }
});
