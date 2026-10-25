// The root of a data-box mailing page sets itself up once, however often it is attached.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initRoot } from '../../javascript/aa-isds.js';

/** an element as far as setting up needs it */
const fakeRoot = () => {
    const attrs = new Map();
    return { attrs, setAttribute: (name, value) => attrs.set(name, value) };
};

test('a root is set up once, a second call does nothing', () => {
    const root  = fakeRoot();
    const calls = [];
    const setups = [(r) => calls.push(['a', r]), (r) => calls.push(['b', r])];
    assert.equal(initRoot(root, setups), true);
    assert.equal(initRoot(root, setups), false);
    assert.deepEqual(calls, [['a', root], ['b', root]]);
    assert.equal(root.attrs.get('ready'), '');
});

test('each root on a page is set up on its own', () => {
    const first  = fakeRoot();
    const second = fakeRoot();
    let calls = 0;
    const setups = [() => calls++];
    assert.equal(initRoot(first, setups), true);
    assert.equal(initRoot(second, setups), true);
    assert.equal(initRoot(first, setups), false);
    assert.equal(calls, 2);
});

test('a failing setup does not stop the others', (t) => {
    const errors = [];
    t.mock.method(console, 'error', (...args) => errors.push(args));
    const root  = fakeRoot();
    const calls = [];
    const setups = [
        () => calls.push('a'),
        () => {
            throw new Error('broken hook');
        },
        () => calls.push('c'),
    ];
    assert.equal(initRoot(root, setups), true);
    assert.deepEqual(calls, ['a', 'c']);
    assert.equal(root.attrs.get('ready'), '');
    assert.equal(errors.length, 1);
    assert.equal(errors[0][1].message, 'broken hook');
});

test('in a browser the element is defined once and set up on its first attach only', async () => {
    const defined = new Map();
    globalThis.HTMLElement = class {
        attrs = new Map();
        setAttribute(name, value) {
            this.attrs.set(name, value);
        }
    };
    globalThis.customElements = {
        get:    (name) => defined.get(name),
        define: (name, cls) => {
            assert.ok(!defined.has(name), `${name} defined twice`);
            defined.set(name, cls);
        },
    };
    try {
        // a query makes a fresh instance of the module, which now sees the browser globals
        await import('../../javascript/aa-isds.js?browser-1');
        await import('../../javascript/aa-isds.js?browser-2');   // loaded twice by a page: no second define
        const Root = defined.get('aa-isds-campaign');
        assert.equal(typeof Root, 'function');

        const root = new Root();
        let writes = 0;
        root.setAttribute = (name, value) => {
            writes++;
            root.attrs.set(name, value);
        };
        root.connectedCallback();
        root.connectedCallback();                                // moved in the page
        assert.equal(writes, 1);
        assert.equal(root.attrs.get('ready'), '');
    } finally {
        delete globalThis.customElements;
        delete globalThis.HTMLElement;
    }
});
