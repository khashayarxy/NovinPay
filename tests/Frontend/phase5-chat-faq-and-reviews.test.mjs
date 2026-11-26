import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRouter} from '../../resources/support-chat/operator/modules/router.js';

const root = new URL('../../', import.meta.url);

test('support chat FAQ wraps question text in .faq-question-text with <bdi dir="ltr"> for 2G/4G and strips trailing left arrows', () => {
    const customerV2Js = readFileSync(new URL('resources/support-chat/support-client/customer-v2.js', root), 'utf8');
    const customerV2Css = readFileSync(new URL('resources/support-chat/support-client/customer-v2.css', root), 'utf8');
    const bplusCss = readFileSync(new URL('resources/support-chat/support-client/bplus.css', root), 'utf8');
    const toolsJs = readFileSync(new URL('resources/support-chat/support-client/customer-v2-tools.js', root), 'utf8');

    assert.match(customerV2Js, /questionSpan\.className='faq-question-text'/);
    assert.match(customerV2Js, /appendLatinText\(questionSpan,item\.q\|\|''\)/);
    assert.doesNotMatch(customerV2Js, /\(item\.link_label\|\|'مشاهده بیشتر'\)\+' ←'/);

    // Verify 2G/4G regex wraps both 2G and 4G tokens inside mixed Persian text
    const re = /(?<![A-Za-z0-9_])(?:[0-9]+[A-Za-z][A-Za-z0-9._-]*|[A-Za-z][A-Za-z0-9._-]*)(?![A-Za-z0-9_])/g;
    const sample = 'حدود هزینه ارتقای کارتخوان 2G به 4G و سایر تعمیرات چقدر است؟';
    assert.deepEqual([...sample.matchAll(re)].map(m => m[0]), ['2G', '4G']);

    // Verify CSS flex wrapping & circular header controls & popover z-index
    assert.match(customerV2Css, /\.faq-question-text\{flex:1;min-width:0;text-align:right/);
    assert.match(customerV2Css, /\.v2-notification-popover\{[^}]*z-index:40/);
    assert.match(customerV2Css, /\.chat-header-actions button\{[^}]*border-radius:999px/);
    assert.match(bplusCss, /--support-canvas:#0f172a!important/);

    // Verify notification popover positioning ignores hidden #inputArea when FAQ tab is open
    assert.match(toolsJs, /composer&&!composer\.hidden&&composer\.offsetParent!==null/);
});

test('operator router supports direct ?conversation=user_... deep link from Telegram support_chat topic', () => {
    const convId = `user_${'a'.repeat(32)}_1730000000`;
    let navigated = null;
    const fakeEl = () => ({hidden: false, classList: {toggle() {}}});
    const prevWindow = globalThis.window;
    const prevDoc = globalThis.document;
    const prevLoc = globalThis.location;

    try {
        globalThis.location = {hash: '', search: `?conversation=${convId}`};
        globalThis.window = {addEventListener() {}, removeEventListener() {}};
        globalThis.document = {
            getElementById: () => ({scrollTop: 0}),
            querySelectorAll: () => [],
        };

        const router = createRouter({
            views: {conversations: fakeEl(), dashboard: fakeEl()},
            navLinks: [],
            titleHost: {textContent: ''},
            isAdmin: () => true,
            onNavigate: (route, params) => {
                navigated = {route, params};
            },
        });
        router.init();
        assert.deepEqual(navigated, {route: 'conversations', params: {id: convId}});
        router.destroy();
    } finally {
        globalThis.window = prevWindow;
        globalThis.document = prevDoc;
        globalThis.location = prevLoc;
    }
});
