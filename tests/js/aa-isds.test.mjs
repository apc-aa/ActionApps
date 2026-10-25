// The root of a data-box mailing page sets itself up once, however often it is attached; its progress and search.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    Autosave, POLL_ERROR_FOR, POLL_EVERY, POLL_FOR, comboKey, initRoot, pageUrl, partUrl, pollDelay, progressNews, shouldPoll,
} from '../../javascript/aa-isds.js';

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

test('in a browser the element is defined once and set up on its first attach only', async (t) => {
    const defined = new Map();
    // a root with no hooks: every setup finds nothing to do
    globalThis.HTMLElement = class {
        attrs = new Map();
        setAttribute(name, value) {
            this.attrs.set(name, value);
        }
        addEventListener() {}
        querySelector() {
            return null;
        }
        querySelectorAll() {
            return [];
        }
    };
    globalThis.addEventListener = () => {};
    const errors = [];
    t.mock.method(console, 'error', (...args) => errors.push(args));
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
        assert.deepEqual(errors, []);
    } finally {
        delete globalThis.customElements;
        delete globalThis.HTMLElement;
        delete globalThis.addEventListener;
    }
});

// The progress of a sending follows it, and the search field suggests recipients.

test('the progress is read in sending, in error only just after the load, only in sight', () => {
    const at = (state, since, visible = true) => shouldPoll({ state, since, visible });
    assert.equal(at('sending', 0), true);
    assert.equal(at('sending', 0, false), false, 'a page out of sight reads nothing');
    assert.equal(at('error', 0), true);
    assert.equal(at('error', POLL_ERROR_FOR - 1), true);
    assert.equal(at('error', POLL_ERROR_FOR), false, 'an error waits for a person after that');
    for (const state of ['watching', 'watch_error', 'done', 'abandoned', 'draft', '', undefined]) {
        assert.equal(at(state, 0), false, String(state));
    }
});

test('the progress is read for an hour since the load, then no more', () => {
    assert.equal(shouldPoll({ state: 'sending', since: POLL_FOR - 1, visible: true }), true);
    assert.equal(shouldPoll({ state: 'sending', since: POLL_FOR, visible: true }), false);
    assert.equal(POLL_FOR, 60 * 60 * 1000);
});

test('the progress is read every 5 s; after network failures in 5, 10, 20, then 60 s', () => {
    assert.equal(POLL_EVERY, 5_000);
    assert.deepEqual([0, 1, 2, 3, 4, 5, 50].map(pollDelay), [5_000, 5_000, 10_000, 20_000, 60_000, 60_000, 60_000]);
});

test('a screen reader hears a new state and each quarter, not every message', () => {
    const at = (done, total, state = 'sending') => ({ state, done, total });
    assert.equal(progressNews(at(10, 100), at(10, 100, 'watching')), 'state');
    assert.equal(progressNews(at(0, 100), at(24, 100)), null);
    assert.equal(progressNews(at(24, 100), at(25, 100)), 'quarter');
    assert.equal(progressNews(at(25, 100), at(49, 100)), null);
    assert.equal(progressNews(at(10, 100), at(80, 100)), 'quarter', 'two quarters at once are told once');
    assert.equal(progressNews(at(99, 100), at(100, 100)), 'quarter');
    assert.equal(progressNews(at(3, 3), at(3, 3)), null);
    assert.equal(progressNews(at(NaN, NaN), at(NaN, NaN)), null, 'no figures in the markup: only the state');
    assert.equal(progressNews(at(0, 0), at(5, 0)), null);
});

test('a part of the page is asked at its address with no key of a result and no old part', () => {
    const base = 'https://a.example/cz/rozesilka?isds_campaign=c1&isds_r=0123&isds_step=search&isds_q=old&isds_part=search&isds_query=1&isds_q#dopis';
    assert.equal(partUrl(base, 'progress'), 'https://a.example/cz/rozesilka?isds_campaign=c1&isds_step=search&isds_query=1&isds_part=progress#dopis');
    assert.equal(partUrl('https://a.example/p', 'search', 'Kolín & co=1 %_#?'),
        'https://a.example/p?isds_part=search&isds_q=Kol%C3%ADn%20%26%20co%3D1%20%25_%23%3F');
    assert.equal(new URL(partUrl('https://a.example/p?x=1', 'search', 'a+b')).searchParams.get('isds_q'), 'a+b');
});

test('a name PHP reads as isds_r is left out however it is written', () => {
    const base = 'https://a.example/p';
    for (const pair of ['isds%5Fr=1', 'isds.r=1', 'isds+r=1', 'isds%20r=1', 'isds_r[]=1', 'isds_r', '%20isds_r=1']) {
        assert.equal(pageUrl(`${base}?x=1&${pair}`, base), `${base}?x=1`, pair);
    }
    assert.equal(pageUrl(`${base}?isds_rx=1&isds%E0=2&isds_part=progress`, base, ['isds_r', 'isds_part']), `${base}?isds_rx=1&isds%E0=2`);
});

test('keys in the search field move through the suggestions as APG combobox says', () => {
    const key = (open, active, count, k, alt = false) => comboKey({ open, active, count }, k, alt);
    assert.deepEqual(key(false, -1, 3, 'ArrowDown'), { open: true, active: 0, act: null, handled: true });
    assert.deepEqual(key(true, 0, 3, 'ArrowDown'), { open: true, active: 1, act: null, handled: true });
    assert.equal(key(true, 2, 3, 'ArrowDown').active, 0, 'round from the last');
    assert.equal(key(false, -1, 3, 'ArrowUp').active, 2, 'up opens at the last');
    assert.equal(key(true, 0, 3, 'ArrowUp').active, 2);
    assert.equal(key(true, -1, 3, 'ArrowUp').active, 2);
    assert.deepEqual(key(false, -1, 0, 'ArrowDown'), { open: false, active: -1, act: 'search', handled: true }, 'nothing found yet: ask');
    assert.deepEqual(key(true, -1, 0, 'ArrowDown'), { open: true, active: -1, act: null, handled: true });
    assert.deepEqual(key(false, -1, 3, 'ArrowDown', true), { open: true, active: -1, act: null, handled: true }, 'Alt+Down opens only');
    assert.deepEqual(key(true, 1, 3, 'ArrowUp', true), { open: false, active: -1, act: null, handled: true }, 'Alt+Up closes');
    assert.deepEqual(key(true, 1, 3, 'Enter'), { open: false, active: -1, act: 'pick', handled: true });
    assert.deepEqual(key(true, -1, 3, 'Enter'), { open: true, active: -1, act: 'search', handled: true }, 'no option active: ask now, never post the draft');
    assert.deepEqual(key(false, -1, 0, 'Enter'), { open: false, active: -1, act: 'search', handled: true });
    assert.deepEqual(key(true, 1, 3, 'Escape'), { open: false, active: -1, act: null, handled: true });
    assert.equal(key(false, -1, 3, 'Escape').handled, false, 'a closed list leaves Escape to the page');
    for (const k of ['Home', 'End', 'ArrowLeft', 'ArrowRight']) {
        assert.deepEqual(key(true, 1, 3, k), { open: true, active: -1, act: null, handled: false }, `${k} moves in the text`);
    }
    assert.deepEqual(key(true, 1, 3, 'Tab'), { open: false, active: -1, act: null, handled: false });
    assert.deepEqual(key(true, 1, 3, 'a'), { open: true, active: 1, act: null, handled: false });
});

test('a stopped autosave takes no pick: it would wait there for ever and hold every step', () => {
    const saves = new Autosave(async () => ({ kind: 'saved' }), { setTimeout: () => 0, clearTimeout: () => {} });
    saves.stop();
    saves.ask(['isds_pick', 'include-x1']);
    assert.deepEqual(saves.picks, []);
    assert.equal(saves.dirty, false);
});
