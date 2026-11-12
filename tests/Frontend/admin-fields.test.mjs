import assert from 'node:assert/strict';
import { test } from 'node:test';
import { asciiDigits, persianDigits } from '../../resources/js/admin-digits.js';
import { parseAdminDate } from '../../resources/js/admin-calendar.js';

test('administrative fields round-trip Persian, Arabic and English digits without losing leading zeros', () => {
    assert.equal(asciiDigits('۰٩1۲'), '0912');
    assert.equal(persianDigits(asciiDigits('۰٩1۲')), '۰۹۱۲');
});

test('Jalali dates submit canonical Gregorian dates across Nowruz and leap Esfand', () => {
    assert.equal(parseAdminDate('۱۴۰۳/۱۲/۳۰', false), '2025-03-20');
    assert.equal(parseAdminDate('۱۴۰۴/۰۱/۰۱', false), '2025-03-21');
    assert.equal(parseAdminDate('۱۴۰۴/۱۲/۳۰', false), '');
    assert.equal(parseAdminDate('۱۴۰۴/۰۶/۳۱', false), '2025-09-22');
    assert.equal(parseAdminDate('۱۴۰۴/۰۷/۳۱', false), '');
});

test('date-time inputs retain the Tehran wall clock for the existing server parser', () => {
    assert.equal(parseAdminDate('۱۴۰۵/۰۱/۰۱ ۰:۰۱', true), '2026-03-21T00:01');
    assert.equal(parseAdminDate('۱۴۰۵/۰۱/۰۱ ۲۴:۰۰', true), '');
    assert.equal(parseAdminDate('۱۴۰۵/۰۱/۰۱', true), '');
});
