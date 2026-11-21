import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/click-map.js', import.meta.url), 'utf8').replace('export function', 'function');
function fixture({observer = true, url = 'https://neshan.org/maps/iframe/places/store#verified'} = {}) {
    class Element extends EventTarget {
        constructor() {super(); this.dataset = {}; this.hidden = false; this.attrs = {}; this.children = [];}
        setAttribute(k, v) {this.attrs[k] = v;}
        removeAttribute(k) {delete this.attrs[k];}
        focus() {document.activeElement = this;}
        remove() {this.parent.children = this.parent.children.filter(child => child !== this);}
        insertBefore(child) {this.children.push(child); child.parent = this;}
        click() {if (!this.disabled) {const event = new Event('click'); Object.defineProperty(event, 'detail', {value: 0}); this.dispatchEvent(event);}}
    }
    const root = new Element(), button = new Element(), overlay = new Element(), viewport = new Element(), status = new Element(), outside = new Element();
    button.hidden = true;
    root.dataset.mapUrl = url;
    const nodes = {'[data-map-activate]': button, '[data-map-placeholder]': overlay, '[data-map-viewport]': viewport, '[data-map-status]': status};
    root.querySelector = key => nodes[key];
    const requests = [], timers = new Map(); let next = 0, callback, options, observations = 0;
    const document = {activeElement: null, querySelectorAll: () => [root], createElement() {
        const frame = new Element();
        Object.defineProperty(frame, 'src', {set(value) {requests.push(value);}});
        return frame;
    }};
    const window = {setTimeout(fn) {timers.set(++next, fn); return next;}, clearTimeout(id) {timers.delete(id);}};
    if (observer) window.IntersectionObserver = class {
        constructor(fn, config) {callback = fn; options = config;}
        observe() {observations++;}
        disconnect() {}
    };
    const context = vm.createContext({window, document, URL, location: {href: 'https://novinpaypos.test/'}});
    vm.runInContext(source + '\ninitClickMap(); initClickMap();', context);
    return {root, button, overlay, viewport, status, outside, requests, timers, document,
        near: () => callback([{target: viewport, isIntersecting: true}]),
        far: () => callback([{target: viewport, isIntersecting: false}]),
        frame: () => viewport.children[0], options: () => options, observations: () => observations};
}

test('far section makes zero requests; near section loads once with inert map behind overlay', () => {
    const f = fixture();
    assert.equal(f.observations(), 1);
    assert.equal(f.options().rootMargin, '200px 0px');
    f.far(); assert.equal(f.requests.length, 0);
    f.near(); f.near(); assert.equal(f.requests.length, 1);
    const frame = f.frame();
    assert.equal(frame.loading, 'lazy'); assert.equal(frame.tabIndex, -1); assert.equal(frame.inert, true);
    assert.equal(frame.attrs['aria-hidden'], 'true'); assert.ok(frame.title);
    frame.dispatchEvent(new Event('load'));
    assert.equal(f.overlay.hidden, false);
    assert.equal(f.button.textContent, 'فعال‌سازی نقشه');
    assert.equal(f.timers.size, 0);
    f.button.focus(); f.button.click();
    assert.equal(f.requests.length, 1); assert.equal(f.overlay.hidden, true);
    assert.equal(f.button.hidden, true);
    assert.equal(frame.inert, false); assert.equal(frame.tabIndex, 0);
    assert.equal(f.document.activeElement, frame);
});

test('activation while loading retains overlay; timeout, stale load and retry are safe', () => {
    const f = fixture();
    f.button.focus(); f.button.click(); const old = f.frame();
    f.button.click(); assert.equal(f.requests.length, 1);
    assert.equal(f.overlay.hidden, false); assert.equal(f.button.disabled, true);
    [...f.timers.values()][0]();
    assert.equal(f.root.dataset.mapState, 'error'); assert.equal(f.frame(), undefined);
    assert.equal(f.button.disabled, false);
    old.dispatchEvent(new Event('load')); assert.equal(f.root.dataset.mapState, 'error');
    f.button.click(); f.frame().dispatchEvent(new Event('error'));
    assert.equal(f.frame(), undefined); assert.equal(f.button.disabled, false);
    f.button.click(); f.frame().dispatchEvent(new Event('load'));
    assert.equal(f.overlay.hidden, true); assert.equal(f.root.attrs['aria-busy'], 'false');
    assert.equal(f.requests.length, 3); assert.equal(f.timers.size, 0);
});

test('unsupported observer uses explicit intent; invalid providers never load', () => {
    const f = fixture({observer: false});
    assert.equal(f.requests.length, 0); f.button.click(); assert.equal(f.requests.length, 1);
    const invalid = fixture({url: 'https://example.com/maps/iframe/places/store'});
    invalid.button.click(); assert.equal(invalid.requests.length, 0); assert.equal(invalid.observations(), 0);
});

test('asynchronous readiness does not steal focus after the customer moves away', () => {
    const f = fixture(); f.button.focus(); f.button.click(); f.outside.focus();
    f.frame().dispatchEvent(new Event('load'));
    assert.equal(f.document.activeElement, f.outside);
});
