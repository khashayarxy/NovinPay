import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatRepairToman } from '../../resources/js/admin-simple-repair.js';

test('repair costs show the full toman value with Persian grouping', () => {
    for (const [input, output] of [
        ['', ''], ['0', '۰'], ['1000', '۱٬۰۰۰'], ['۱۲۵۰۰', '۱۲٬۵۰۰'],
        ['١٢٥٠٠٠٠', '۱٬۲۵۰٬۰۰۰'], ['123,456,789', '۱۲۳٬۴۵۶٬۷۸۹'],
        ['۱۲۳٬۴۵۶٬۷۸۹', '۱۲۳٬۴۵۶٬۷۸۹'], ['12.5', '12.5'],
    ]) assert.equal(formatRepairToman(input), output);
});
