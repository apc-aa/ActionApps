// The control of {mailbulk}: a send asks first, an answer replaces the control, a sending refreshes itself.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ANSWER_WAIT, POLL_EVERY, act, init, pollDelay, replaceControl, watch } from '../../javascript/mailbulk.js';
import { POLL_FOR } from '../../javascript/aa-poll.js';

const LIST = 'ab00000000000000000000000000000c';
const ITEM = 'cd00000000000000000000000000000e';

const control = (state = '') => ({ dataset: { list: LIST, item: ITEM, state }, isConnected: true, html: '' });

/** the browser as far as the module needs it: requests wait until the test answers them, timers until it runs them */
const fakeEnv = ({ confirm = true } = {}) => {
    const env = {
        requests: [],
        asked: [],
        timers: [],
        notes: [],
        swapped: [],
        clock: 1_000_000,
        shown: true,
        controls: [],
        respond: (params, ok, err) => env.requests.push({ params, ok, err }),
        ask: (text) => {
            env.asked.push(text);
            return confirm;
        },
        later: (fn, ms) => env.timers.push({ fn, ms }),
        now: () => env.clock,
        visible: () => env.shown,
        all: () => env.controls,
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
/** the polls planned, without the watchdogs of the requests */
const polls = env => env.timers.filter(t => t.ms !== ANSWER_WAIT);
const watchdogs = env => env.timers.filter(t => t.ms === ANSWER_WAIT);

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
    assert.equal(polls(env).length, 0);
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

test('an error is said, then the control shows the state the server has', () => {
    const env = fakeEnv();
    const el = control();
    act(el, 'send', env);
    env.requests[0].err('Error 1: A sending of the item to the list is not finished yet');
    assert.deepEqual(env.notes, [{ where: el, html: 'A sending of the item to the list is not finished yet' }]);
    assert.equal(env.asked.length, 0);
    assert.deepEqual(actions(env), ['prepare', 'state']);

    env.requests[1].ok('<span data-aa-mailbulk data-state="sending">Sending 3 / 90</span>');
    assert.equal(env.swapped.length, 1);
    assert.deepEqual(env.notes[1], { where: env.swapped[0], html: 'A sending of the item to the list is not finished yet' }, 'the reason stays in sight');
    assert.deepEqual(polls(env).map(t => t.ms), [POLL_EVERY], 'another editor started it: it is followed');
});

test('an error of the refresh too: only once, no loop', () => {
    const env = fakeEnv();
    const el = control();
    act(el, 'stop', env);
    env.requests[0].err('Error 1: Nothing is being sent');
    env.requests[1].err('Error 9: You may not send this item');
    assert.deepEqual(actions(env), ['stop', 'state']);
    act(el, 'test', env);
    assert.deepEqual(actions(env), ['stop', 'state', 'test'], 'the control is free');
});

test('a click while an answer is on its way does nothing', () => {
    const env = fakeEnv();
    const el = control('sending');
    act(el, 'stop', env);
    act(el, 'stop', env);
    assert.deepEqual(actions(env), ['stop']);
});

test('no answer at all: the watchdog frees the control and asks for its state', () => {
    const env = fakeEnv();
    const el = control('sending');
    act(el, 'stop', env);
    assert.equal(watchdogs(env).length, 1);
    watchdogs(env)[0].fn();
    assert.deepEqual(actions(env), ['stop', 'state']);
    env.requests[1].ok('<span data-aa-mailbulk data-state="stopped">Stopped</span>');
    assert.equal(env.swapped.length, 1);
    // a late answer of the stop changes nothing
    env.requests[0].ok('<span data-aa-mailbulk data-state="sending">old</span>');
    assert.equal(env.swapped.length, 1);
    assert.equal(env.notes.length, 0, 'nothing to say');
});

test('an action wins over a poll on its way; the poll answer is left out', () => {
    const env = fakeEnv();
    const el = control('sending');
    watch(el, env);
    polls(env)[0].fn();
    assert.deepEqual(actions(env), ['state']);
    act(el, 'stop', env);
    assert.deepEqual(actions(env), ['state', 'stop']);
    env.requests[0].ok('<span data-aa-mailbulk data-state="sending">Sending 4 / 9</span>');
    assert.equal(env.swapped.length, 0, 'the stale state does not replace the control');
    env.requests[1].ok('<span data-aa-mailbulk data-state="stopped">Stopped 4 / 9</span>');
    assert.equal(env.swapped[0].dataset.state, 'stopped');
});

test('sending_pollsState_untilItChanges', () => {
    const env = fakeEnv();
    const el = control('sending');
    watch(el, env);
    watch(el, env);
    assert.deepEqual(polls(env).map(t => t.ms), [POLL_EVERY]);
    polls(env)[0].fn();
    assert.deepEqual(actions(env), ['state']);
    env.requests[0].ok('<span data-aa-mailbulk data-state="sending">Sending 340 / 1 290</span>');
    assert.equal(polls(env).length, 2);
    polls(env)[1].fn();
    assert.deepEqual(actions(env), ['state', 'state']);
    env.requests[1].ok('<span data-aa-mailbulk data-state="done">sent 1 290</span>');
    assert.equal(polls(env).length, 2);
});

test('a control gone from the page asks no more', () => {
    const env = fakeEnv();
    const el = control('sending');
    watch(el, env);
    el.isConnected = false;
    polls(env)[0].fn();
    assert.equal(env.requests.length, 0);
    assert.equal(polls(env).length, 1);
});

test('failed polls back off: 5, 10, 20 s, and a success brings back the usual pace', () => {
    const env = fakeEnv();
    const el = control('sending');
    watch(el, env);
    for (let n = 0; n < 3; n++) {
        polls(env).at(-1).fn();
        env.requests.at(-1).err('Error 1: The action failed - see the log');
    }
    assert.deepEqual(polls(env).map(t => t.ms), [POLL_EVERY, pollDelay(1), pollDelay(2), pollDelay(3)]);
    assert.deepEqual([pollDelay(1), pollDelay(2), pollDelay(3)], [5_000, 10_000, 20_000]);
    // a poll that gets no answer counts as failed too
    polls(env).at(-1).fn();
    watchdogs(env).at(-1).fn();
    assert.equal(polls(env).at(-1).ms, pollDelay(4));
    polls(env).at(-1).fn();
    env.requests.at(-1).ok('<span data-aa-mailbulk data-state="sending">Sending</span>');
    assert.equal(polls(env).at(-1).ms, POLL_EVERY);
});

test('out of sight or after an hour it asks nothing', () => {
    const env = fakeEnv();
    const el = control('sending');
    env.shown = false;
    watch(el, env);
    assert.equal(polls(env).length, 0);
    env.shown = true;
    watch(el, env);
    env.shown = false;
    polls(env)[0].fn();
    assert.equal(env.requests.length, 0, 'hidden meanwhile');

    const later = control('sending');
    watch(later, env);
    env.shown = true;
    env.clock += POLL_FOR;
    watch(later, env);
    assert.equal(polls(env).length, 1, 'an hour since it was first watched');
});

test('the control of the answer is taken, not a notice before it; the focused button keeps the focus', () => {
    const button = { dataset: { action: 'stop' } };
    let replacedBy = null;
    const old = { contains: el => el === button, replaceWith: (el) => { replacedBy = el; } };
    let focused = null;
    const doc = {
        activeElement: button,
        createElement: () => {
            const template = {};
            Object.defineProperty(template, 'innerHTML', {
                set: (html) => {
                    template.content = {
                        firstElementChild: { tag: /<(\w+)/.exec(html)?.[1] },
                        querySelector: sel => ((sel === '[data-aa-mailbulk]') && html.includes('<span data-aa-mailbulk'))
                            ? { html, querySelector: s => ({ focus: () => { focused = s; } }) }
                            : null,
                    };
                },
            });
            return template;
        },
    };
    const fresh = replaceControl(old, '<br />\n<b>Notice</b>: x<br /><span data-aa-mailbulk data-state="stopped"></span>', doc);
    assert.equal(replacedBy, fresh);
    assert.match(fresh.html, /data-state="stopped"/);
    assert.equal(focused, '[data-action="stop"]');

    replacedBy = null;
    assert.equal(replaceControl(old, '<b>Fatal error</b>', doc), null);
    assert.equal(replacedBy, null, 'an answer without a control leaves the old one');
});

test('init_twice_bindsOnce', () => {
    const env = fakeEnv();
    const listeners = [];
    const sending = control('sending');
    env.controls = [sending, control('')];
    const doc = { addEventListener: (type, fn) => listeners.push({ type, fn }) };
    assert.equal(init(doc, env), true);
    assert.equal(init(doc, env), false);
    assert.deepEqual(listeners.map(l => l.type), ['click', 'visibilitychange']);
    assert.equal(polls(env).length, 1);

    // a click on a button reaches its control
    const el = control();
    const button = { dataset: { action: 'test' }, closest: () => el };
    let prevented = false;
    // a control an AJAX part brought in meanwhile starts following its sending
    env.controls.push(control('sending'));
    listeners[0].fn({ target: { closest: () => button }, preventDefault: () => prevented = true });
    assert.equal(prevented, true);
    assert.deepEqual(actions(env), ['test']);
    assert.equal(polls(env).length, 2);
});
