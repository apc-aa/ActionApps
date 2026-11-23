// <aa-batchrun>: a page drives its batch runs - one step after another, looks while a step runs,
// content replaced only when it changed and never under an open report, stop when the page stops answering.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Driver, LOOK_EVERY, RETRY, nextStep, parse } from '../../javascript/aa-batchrun.js';

/** an element with just what the driver touches */
const element = (attrs = {}) => {
    const listeners = [];
    const el = {
        innerHTML: 'first',
        open: false,
        notes: [],
        getAttribute: name => attrs[name] ?? null,
        querySelector: sel => ((sel === 'details[open]') && el.open ? {} : null),
        addEventListener: (type, fn) => listeners.push([type, fn]),
        prepend: node => el.notes.push(node.textContent),
        ownerDocument: { createElement: () => ({ textContent: '', className: '' }) },
        toggle: () => listeners.filter(([type]) => type === 'toggle').forEach(([, fn]) => fn()),
    };
    return el;
};

/** a fetch answering from $answers in turn; each answer: an object (JSON), a string (other text), an
 *  Error (thrown) or a function of the request; requests are kept */
const fakeFetch = (answers) => {
    const requests = [];
    const fetch = async (url, init) => {
        const body = Object.fromEntries(new URLSearchParams(init.body));
        requests.push({ url, ...body });
        let answer = answers.shift();
        if (typeof answer === 'function') {
            answer = await answer(body, init);
        }
        if (answer instanceof Error) {
            throw answer;
        }
        const status = answer?.status ?? 200;
        const text = (typeof answer === 'string') ? answer : JSON.stringify(answer?.json ?? answer);
        return { status, ok: (status >= 200) && (status < 300), text: async () => text };
    };
    return { fetch, requests };
};

/** timers that run only when told */
const fakeTimers = () => {
    let timers = [];
    return {
        setTimeout: (fn, ms) => {
            const t = { fn, ms };
            timers.push(t);
            return t;
        },
        clearTimeout: (t) => {
            timers = timers.filter(x => x !== t);
        },
        pending: () => timers.map(t => t.ms),
        async fire() {
            const t = timers.shift();
            t.fn();
            await new Promise(resolve => setImmediate(resolve));
        },
    };
};

const driver = (answers, attrs = { url: '/opt.php', token: 'tk', run: 'r1', lost: 'Reload the page' }) => {
    const el = element(attrs);
    const { fetch, requests } = fakeFetch(answers);
    const timers = fakeTimers();
    return { el, requests, timers, d: new Driver(el, { fetch, ...timers }) };
};

test('parse takes an object only - a login page or a bare value is no answer', () => {
    assert.deepEqual(parse('{"wait":0}'), { wait: 0 });
    assert.equal(parse('<html>login</html>'), null);
    assert.equal(parse('null'), null);
    assert.equal(parse('5'), null);
});

test('nextStep: seconds to ms, null stops', () => {
    assert.equal(nextStep(0), 0);
    assert.equal(nextStep(4), 4000);
    assert.equal(nextStep(null), null);
    assert.equal(nextStep(undefined), null);
});

test('a step posts the run and shows new content', async () => {
    const { el, requests, d } = driver([{ sum: 's1', html: 'second', wait: 0 }]);

    assert.equal(await d.step(), 0);

    assert.equal(el.innerHTML, 'second');
    assert.deepEqual(requests, [{ url: '/opt.php', action: 'step', token: 'tk', run: 'r1', look: '', seen: '' }]);
    assert.equal(d.seen, 's1');
});

test('content the page has already is not sent nor replaced', async () => {
    const { el, requests, d } = driver([{ sum: 's1', html: 'second', wait: 0 }, { sum: 's1', wait: 7 }]);
    await d.step();
    el.innerHTML = 'kept';

    assert.equal(await d.step(), 7000);

    assert.equal(el.innerHTML, 'kept');
    assert.equal(requests[1].seen, 's1');
});

test('an open report is not replaced - the new content shows once it is closed', async () => {
    const { el, d } = driver([{ sum: 's1', html: 'second', wait: null }]);
    el.open = true;

    assert.equal(await d.step(), null, 'nothing goes on');

    assert.equal(el.innerHTML, 'first');
    el.toggle();
    assert.equal(el.innerHTML, 'first', 'still open');
    el.open = false;
    el.toggle();
    assert.equal(el.innerHTML, 'second');
});

test('an answer that is no JSON or a refusal stops it with a word to reload', async () => {
    for (const answer of ['<html>login</html>', { status: 403, json: {} }, { status: 500, json: {} }]) {
        const { el, d } = driver([answer]);

        assert.equal(await d.step(), null);

        assert.deepEqual(el.notes, ['Reload the page']);
        assert.equal(el.innerHTML, 'first');
    }
});

test('a failed connection is tried again later', async () => {
    const { el, d } = driver([new Error('offline')]);

    assert.equal(await d.step(), RETRY);
    assert.deepEqual(el.notes, []);
});

test('while a step runs, looks show the progress; a look cannot outlive its step', async () => {
    let endStep;
    const stepDone = new Promise(resolve => {
        endStep = resolve;
    });
    let endLate;
    const late = new Promise(resolve => {
        endLate = resolve;
    });
    const { el, requests, timers, d } = driver([
        () => stepDone,
        { sum: 'a', html: 'one AA done' },
        () => late,
    ]);

    const step = d.step();
    assert.deepEqual(timers.pending(), [LOOK_EVERY]);
    await timers.fire();
    assert.equal(el.innerHTML, 'one AA done');
    assert.equal(requests[1].look, '1');
    assert.deepEqual(timers.pending(), [LOOK_EVERY], 'the next look after this one has answered');

    await timers.fire();                       // a look that hangs
    endStep({ sum: 'b', html: 'all done', wait: null });
    assert.equal(await step, null);
    endLate({ sum: 'old', html: 'stale' });
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(el.innerHTML, 'all done');
    assert.deepEqual(timers.pending(), [], 'no look after the step');
});

test('run goes on after the wait and stops on null', async () => {
    const { requests, timers, d } = driver([{ sum: 'a', html: 'x', wait: 2 }, { sum: 'a', wait: null }]);

    await d.run();
    const pending = timers.pending();
    assert.ok(pending.includes(2000), 'the next step in 2 s');
    while (timers.pending().length) {
        await timers.fire();
    }

    assert.equal(requests.filter(r => r.look === '').length, 2);
});
