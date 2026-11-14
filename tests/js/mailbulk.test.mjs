// The control of {mailbulk}: a send asks first, an answer replaces the control, a sending refreshes itself.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { POLL_EVERY, act, init, watch } from '../../javascript/mailbulk.js';

const LIST = 'ab00000000000000000000000000000c';
const ITEM = 'cd00000000000000000000000000000e';

const control = (state = '') => ({ dataset: { list: LIST, item: ITEM, state }, isConnected: true, html: '' });

/** the browser as far as the module needs it: requests wait until the test answers them */
const fakeEnv = ({ confirm = true } = {}) => {
    const env = {
        requests: [],
        asked: [],
        timers: [],
        notes: [],
        swapped: [],
        respond: (params, ok, err) => env.requests.push({ params, ok, err }),
        ask: (text) => {
            env.asked.push(text);
            return confirm;
        },
        later: (fn, ms) => env.timers.push({ fn, ms }),
        plain: html => html.replaceAll('&nbsp;', ' '),
        swap: (old, html) => {
            const fresh = control(/data-state="(\w*)"/.exec(html)?.[1] ?? '');
            fresh.html = html;
            old.isConnected = false;
            env.swapped.push(fresh);
            return fresh;
        },
        note: (where, html) => env.notes.push({ where, html }),
    };
    return env;
};

const actions = env => env.requests.map(r => r.params.mb_action);

test('prepare_then_confirm_then_send', () => {
    const env = fakeEnv();
    const el = control();
    act(el, 'send', env);
    assert.deepEqual(env.requests[0].params, { mb_action: 'prepare', mb_list: LIST, mb_item: ITEM });
    env.requests[0].ok('Send to 1&nbsp;290 recipients?');
    assert.deepEqual(env.asked, ['Send to 1 290 recipients?']);
    assert.deepEqual(actions(env), ['prepare', 'send']);
    env.requests[1].ok('<span data-aa-mailbulk data-state="done">sent</span>');
    assert.equal(env.swapped.length, 1);
    assert.equal(env.swapped[0].html, '<span data-aa-mailbulk data-state="done">sent</span>');
    assert.equal(env.timers.length, 0);
});

test('cancelledConfirm_sendsNothing', () => {
    const env = fakeEnv({ confirm: false });
    const el = control();
    act(el, 'send', env);
    env.requests[0].ok('Send to 3 recipients?');
    assert.deepEqual(actions(env), ['prepare']);
    // the control is free again for another click
    act(el, 'test', env);
    assert.deepEqual(actions(env), ['prepare', 'test']);
});

test('error_isShownInTheControl', () => {
    const env = fakeEnv();
    const el = control();
    act(el, 'send', env);
    env.requests[0].err('Error 1: Nobody is left to send the item to');
    assert.deepEqual(env.notes, [{ where: el, html: 'Nobody is left to send the item to' }]);
    assert.equal(env.asked.length, 0);
    assert.equal(env.swapped.length, 0);
});

test('a click while an answer is on its way does nothing', () => {
    const env = fakeEnv();
    const el = control('sending');
    act(el, 'stop', env);
    act(el, 'stop', env);
    assert.deepEqual(actions(env), ['stop']);
});

test('sending_pollsState_untilItChanges', () => {
    const env = fakeEnv();
    const el = control('sending');
    watch(el, env);
    watch(el, env);
    assert.deepEqual(env.timers.map(t => t.ms), [POLL_EVERY]);
    env.timers[0].fn();
    assert.deepEqual(actions(env), ['state']);
    env.requests[0].ok('<span data-aa-mailbulk data-state="sending">Sending 340 / 1 290</span>');
    assert.equal(env.timers.length, 2);
    env.timers[1].fn();
    assert.deepEqual(actions(env), ['state', 'state']);
    env.requests[1].ok('<span data-aa-mailbulk data-state="done">sent 1 290</span>');
    assert.equal(env.timers.length, 2);
});

test('a control gone from the page asks no more', () => {
    const env = fakeEnv();
    const el = control('sending');
    watch(el, env);
    el.isConnected = false;
    env.timers[0].fn();
    assert.equal(env.requests.length, 0);
    assert.equal(env.timers.length, 1);
});

test('a failed poll of a sending control polls again', () => {
    const env = fakeEnv();
    const el = control('sending');
    watch(el, env);
    env.timers[0].fn();
    env.requests[0].err('Error 9: You may not send this item');
    assert.equal(env.notes.length, 1);
    assert.equal(env.timers.length, 2);
});

test('init_twice_bindsOnce', () => {
    const env = fakeEnv();
    const listeners = [];
    const sending = control('sending');
    const doc = {
        addEventListener: (type, fn) => listeners.push({ type, fn }),
        querySelectorAll: () => [sending, control('')],
    };
    assert.equal(init(doc, env), true);
    assert.equal(init(doc, env), false);
    assert.deepEqual(listeners.map(l => l.type), ['click']);
    assert.equal(env.timers.length, 1);

    // a click on a button reaches its control
    const el = control();
    const button = { dataset: { action: 'test' }, closest: () => el };
    let prevented = false;
    listeners[0].fn({ target: { closest: () => button }, preventDefault: () => prevented = true });
    assert.equal(prevented, true);
    assert.deepEqual(actions(env), ['test']);
});
