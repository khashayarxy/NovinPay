import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {runInNewContext} from 'node:vm';

const script = readFileSync(new URL('../../public/pay/assets/vpn-warning.js', import.meta.url), 'utf8');

async function check({server, trace, status = 200}) {
    const elements = new Map();
    const element = (id) => {
        if (!elements.has(id)) elements.set(id, {hidden: true, addEventListener() {}});
        return elements.get(id);
    };
    element('go').dataset = {
        verifySplit: '1',
        checkUrl: '/connect',
        indeterminateUrl: '/gateway',
        resultUrl: '/result',
    };
    element('fallbackLink').href = '/gateway?continue=1';
    const navigations = [];
    let pollingStopped = false;
    runInNewContext(script, {
        document: {
            getElementById: element,
            addEventListener() {},
            removeEventListener() {},
        },
        window: {location: {replace: (url) => navigations.push(url)}},
        fetch: async (url) => {
            if (url === '/connect') {
                if (server === 'timeout') throw new DOMException('timeout', 'AbortError');
                return {status, ok: status === 200, json: async () => ({ir: server})};
            }
            return {ok: trace !== null, text: async () => trace ? 'loc=IR\n' : 'loc=DE\n'};
        },
        AbortController,
        setTimeout: () => 1,
        clearTimeout() {},
        setInterval: () => 2,
        clearInterval: () => { pollingStopped = true; },
    });
    await new Promise(setImmediate);
    return {navigations, pollingStopped, warningShown: !element('vpnBox').hidden};
}

test('conflicting, missing, or timed-out checks reach manual continuation', async () => {
    for (const pair of [
        {server: true, trace: false},
        {server: null, trace: true},
        {server: true, trace: null},
        {server: 'timeout', trace: true},
    ]) {
        assert.deepEqual(await check(pair), {
            navigations: ['/gateway'], pollingStopped: true, warningShown: false,
        });
    }
});

test('agreeing checks preserve automatic forwarding or the region warning', async () => {
    assert.deepEqual((await check({server: true, trace: true})).navigations, ['/gateway?continue=1']);
    assert.deepEqual(await check({server: false, trace: false}), {
        navigations: [], pollingStopped: false, warningShown: true,
    });
});

test('an unavailable attempt goes to its result instead of the gateway', async () => {
    assert.deepEqual((await check({status: 409, trace: true})).navigations, ['/result']);
});
