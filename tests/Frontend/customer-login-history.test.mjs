import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';
import {runInNewContext} from 'node:vm';

test('login revalidates a browser-restored document only on a BFCache hit', () => {
    const events = new Map();
    let reloads = 0;
    runInNewContext(readFileSync(new URL('../../public/js/customer-otp.js', import.meta.url), 'utf8'), {
        window: {
            addEventListener: (type, listener) => events.set(type, listener),
            location: {reload: () => reloads++},
        },
        document: {querySelectorAll: () => [], querySelector: () => null},
    });
    events.get('pageshow')({persisted: false});
    assert.equal(reloads, 0);
    events.get('pageshow')({persisted: true});
    assert.equal(reloads, 1);
});
