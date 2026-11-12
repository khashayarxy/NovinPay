import assert from 'node:assert/strict';
import {test} from 'node:test';
import {messageDate,persianDate,tehranDateTime,tehranDay} from '../../resources/support-chat/support-client/persian-date.js';

test('chat uses Tehran midnight and time-first Jalali labels', () => {
    const before = new Date('2026-03-20T20:29:00Z');
    const after = new Date('2026-03-20T20:30:00Z');
    assert.equal(tehranDay(before), '2026-03-20');
    assert.equal(tehranDay(after), '2026-03-21');
    assert.equal(persianDate(before, after).date, 'دیروز');
    assert.equal(persianDate(before, after).label, '۲۳:۵۹ | ۱۴۰۴/۱۲/۲۹');
    assert.equal(persianDate(after, after).label, '۰۰:۰۰');
    assert.equal(persianDate(after, after).full, '۰۰:۰۰ | ۱۴۰۵/۰۱/۰۱');
});

test('legacy Tehran wall time and explicit offsets show the same instant', () => {
    const legacy = messageDate({time:'2026-11-12 00:35:00'});
    assert.equal(tehranDateTime(legacy), '۰۰:۳۵ | ۱۴۰۵/۰۸/۲۱');
    assert.equal(tehranDateTime(messageDate({accepted_at:'2026-11-11T21:05:00Z'})), tehranDateTime(legacy));
    assert.equal(tehranDateTime(messageDate({time:'2026-11-11T16:05:00-05:00'})), tehranDateTime(legacy));
});
