// The draft of a data-box mailing saves itself: when, what it posts, what it takes from the answer, what it does with each code.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    Autosave, BUSY_AGAIN, LONGEST, QUIET, QUIET_SLOW, confirmed, filterUpdates, mergeHidden, pageUrl, payload,
    payloadSize, reaction, retryDelay, saveDelay, sameOrigin,
} from '../../javascript/aa-isds.js';

/** time that moves only when told, with the timers due by then */
const fakeClock = () => {
    let now = 1_000_000;
    let timers = [];
    return {
        now: () => now,
        setTimeout: (fn, ms) => {
            const t = { fn, at: now + ms };
            timers.push(t);
            return t;
        },
        clearTimeout: (t) => {
            timers = timers.filter(x => x !== t);
        },
        pending: () => timers.map(t => t.at - now),
        async advance(ms) {
            const until = now + ms;
            for (;;) {
                const next = timers.filter(t => t.at <= until).sort((a, b) => a.at - b.at)[0];
                if (!next) {
                    break;
                }
                timers = timers.filter(t => t !== next);
                now = next.at;
                next.fn();
                await new Promise(resolve => setImmediate(resolve));
            }
            now = until;
            await new Promise(resolve => setImmediate(resolve));
        },
    };
};

/** a server answering each save as the list says, the last answer for any more */
const fakeServer = (answers = [{ kind: 'saved' }]) => {
    const jobs = [];
    let open = 0;
    let most = 0;
    let gate = null;
    return {
        jobs,
        most: () => most,
        hold() {
            let release;
            gate = new Promise((resolve) => {
                release = resolve;
            });
            return () => {
                gate = null;
                release();
            };
        },
        send: async (job) => {
            jobs.push(job);
            most = Math.max(most, ++open);
            if (gate) {
                await gate;
            }
            open--;
            return answers[Math.min(jobs.length - 1, answers.length - 1)];
        },
    };
};

test('typing saves 15 s after the last change, and at the latest 60 s after the first', () => {
    assert.equal(saveDelay({ firstAt: 0, lastAt: 0, now: 0 }), QUIET);
    assert.equal(saveDelay({ firstAt: 0, lastAt: 10_000, now: 12_000 }), 13_000);
    assert.equal(saveDelay({ firstAt: 0, lastAt: 55_000, now: 55_000 }), LONGEST - 55_000);
    assert.equal(saveDelay({ firstAt: 0, lastAt: 70_000, now: 70_000 }), 0);
    assert.equal(saveDelay({ firstAt: 0, lastAt: 0, now: 0, slow: true }), QUIET_SLOW);
});

test('a network failure tries again after 10, 20, 40 s, then every 2 minutes', () => {
    assert.deepEqual([0, 1, 2, 3, 4, 9].map(retryDelay), [10_000, 20_000, 40_000, 120_000, 120_000, 120_000]);
});

test('each code of a save has its reaction', () => {
    const table = {
        SAVED:          'saved',
        FILTER_CHANGED: 'filter',
        INPUT:          'input',
        TOO_LARGE:      'input',
        BUSY:           'busy',
        NOT_DRAFT:      'stop',
        ALREADY:        'stop',
        UNKNOWN:        'stop',
        EXPIRED:        'stop',
        INTERNAL:       'again',
        '':             'stop',
    };
    for (const [code, kind] of Object.entries(table)) {
        assert.equal(reaction(code, ''), kind, code);
    }
    assert.equal(reaction('SOMETHING_NEW', 'busy'), 'busy');
    assert.equal(reaction('SOMETHING_NEW', 'done'), 'stop');
});

test('the hidden fields come from the answer; the nonce stays until the answer names the draft', () => {
    const current = [['isds_action', 'save'], ['isds_campaign', ''], ['isds_token', 'old'], ['isds_form', 'a1'], ['isds_nonce', 'n-page']];
    // not saved yet: the answer has a nonce of its own, which would make a second draft
    assert.deepEqual(
        mergeHidden(current, [['isds_action', 'save'], ['isds_campaign', ''], ['isds_token', 't2'], ['isds_form', 'b2'], ['isds_nonce', 'n-answer']]),
        [['isds_action', 'save'], ['isds_campaign', ''], ['isds_token', 't2'], ['isds_form', 'b2'], ['isds_nonce', 'n-page']],
    );
    // saved: the draft is named, the nonce goes
    assert.deepEqual(
        mergeHidden(current, [['isds_action', 'save'], ['isds_campaign', 'c0ffee'], ['isds_token', 't3'], ['isds_form', 'c3'], ['isds_filter_seen', 'f1']]),
        [['isds_action', 'save'], ['isds_campaign', 'c0ffee'], ['isds_token', 't3'], ['isds_form', 'c3'], ['isds_filter_seen', 'f1']],
    );
    // a field the page did not know is taken too, a control of the filter is not
    assert.deepEqual(
        mergeHidden([['isds_campaign', 'c0ffee']], [['isds_campaign', 'c0ffee'], ['isds_new', 'x'], ['isds_include', 'a-b']]),
        [['isds_campaign', 'c0ffee'], ['isds_new', 'x']],
    );
});

test('the controls of the filter take the answer: boxes by value, other fields in order', () => {
    const box = (name, value, checked) => ({ name, value, checkable: true, checked });
    const field = (name, value) => ({ name, value, checkable: false });
    const current = [
        box('isds_types[]', 'city', true), box('isds_types[]', 'district', false),
        box('isds_membership', 'member', true), box('isds_membership', 'all', false),
        field('isds_include', 'a'), field('isds_exclude', ''),
    ];
    const answered = [
        box('isds_types[]', 'city', false), box('isds_types[]', 'district', true), box('isds_types[]', 'region', true),
        box('isds_membership', 'member', false), box('isds_membership', 'all', true),
        field('isds_include', 'a-b'),
    ];
    assert.deepEqual(filterUpdates(current, answered), [
        { checked: false }, { checked: true }, { checked: false }, { checked: true }, { value: 'a-b' }, { value: '' },
    ]);
});

test('a box or radio changed since the save went stays; the lists of picks always come from the answer', () => {
    const current = [
        { name: 'isds_types[]', value: 'city', checkable: true, checked: true, kept: true },
        { name: 'isds_types[]', value: 'district', checkable: true, checked: false, kept: false },
        { name: 'isds_include', value: 'a', checkable: false, kept: true },
    ];
    const answered = [
        { name: 'isds_types[]', value: 'city', checkable: true, checked: false },
        { name: 'isds_types[]', value: 'district', checkable: true, checked: true },
        { name: 'isds_include', value: 'a-x1', checkable: false },
    ];
    assert.deepEqual(filterUpdates(current, answered), [null, { checked: true }, { value: 'a-x1' }]);
});

test('an unreadable filter is shown unchecked, so an autosave posts no membership', () => {
    const current = [{ name: 'isds_membership', value: 'member', checkable: true, checked: true }];
    const answered = [{ name: 'isds_membership', value: 'member', checkable: true, checked: false }];
    assert.deepEqual(filterUpdates(current, answered), [{ checked: false }]);
});

test('an autosave posts no file, the body of the editor, and the step open now', () => {
    const file = { name: 'a.pdf' };
    const entries = [['isds_subject', 'S'], ['isds_body', 'stale'], ['isds_files[]', file], ['isds_remove[]', 'u'], ['isds_q', 'Kol']];
    assert.deepEqual(payload(entries, { step: 'test', bodies: [['isds_body', '<p>new</p>']] }), [
        ['isds_subject', 'S'], ['isds_remove[]', 'u'], ['isds_q', 'Kol'], ['isds_body', '<p>new</p>'], ['isds_step', 'test'],
    ]);
    // no file under any name
    assert.deepEqual(payload([['other', file], ['isds_files[]', 'x']]), []);
});

test('the step comes only from the steps a page knows, and never over a step the form posts', () => {
    assert.deepEqual(payload([], { step: 'evil' }), []);
    assert.deepEqual(payload([], { step: null }), []);
    assert.deepEqual(payload([['isds_step', 'send']], { step: 'test' }), [['isds_step', 'send']]);
    assert.deepEqual(payload([['isds_subject', 'S']], { button: ['isds_pick', 'include-1'], step: 'search' }), [
        ['isds_subject', 'S'], ['isds_pick', 'include-1'], ['isds_step', 'search'],
    ]);
});

test('the size of a keepalive post counts bytes, not characters', () => {
    assert.ok(payloadSize([['isds_body', 'ž'.repeat(30_000)]]) > 60_000);
    assert.ok(payloadSize([['isds_body', 'z'.repeat(30_000)]]) < 60_000);
});

test('the page takes an address of its own origin only, without the key of the result', () => {
    const base = 'https://example.org/cz/rozesilka?isds_step=test#dopis';
    assert.equal(sameOrigin('/cz/x', base), true);
    assert.equal(sameOrigin('https://example.org.evil.cz/', base), false);
    assert.equal(sameOrigin('//evil.cz/x', base), false);
    assert.equal(sameOrigin('javascript:alert(1)', base), false);
    assert.equal(pageUrl('https://evil.cz/cz/rozesilka?isds_r=1', base), null);
    assert.equal(
        pageUrl('https://example.org/cz/rozesilka?x=a%20b+c&isds_campaign=c0ffee&isds_step=search&isds_q=Kol%C3%ADn&isds_r=0123456789abcdef', base),
        'https://example.org/cz/rozesilka?x=a%20b+c&isds_campaign=c0ffee&isds_step=search&isds_q=Kol%C3%ADn#dopis',
    );
    assert.equal(pageUrl('https://example.org/cz/rozesilka?isds_r=0123456789abcdef', base), 'https://example.org/cz/rozesilka#dopis');
    assert.equal(pageUrl('https://example.org/cz/rozesilka?a=%E0&isds_r=1&isds_rx=2', base), 'https://example.org/cz/rozesilka?a=%E0&isds_rx=2#dopis');
});

test('the send button opens only for the count asked for', () => {
    assert.equal(confirmed('67', '67'), true);
    assert.equal(confirmed(' 1 234 ', '1234'), true);
    assert.equal(confirmed('66', '67'), false);
    assert.equal(confirmed('067', '67'), false);
    assert.equal(confirmed('', ''), false);
});


test('nothing is saved before the first change', async () => {
    const clock = fakeClock();
    const server = fakeServer();
    const draft = new Autosave(server.send, clock);
    await clock.advance(10 * LONGEST);
    assert.equal(server.jobs.length, 0);
    assert.equal(draft.dirty, false);
});

test('typing saves after a pause, or after a minute of typing', async () => {
    const clock = fakeClock();
    const server = fakeServer();
    const draft = new Autosave(server.send, clock);
    draft.changed('letter');
    await clock.advance(QUIET - 1);
    assert.equal(server.jobs.length, 0);
    await clock.advance(1);
    assert.equal(server.jobs.length, 1);
    assert.equal(draft.dirty, false);

    for (let t = 0; t < LONGEST; t += 5_000) {
        draft.changed('letter');
        await clock.advance(5_000);
    }
    assert.equal(server.jobs.length, 2, 'saved once within a minute of typing');
});

test('typing during a network failure waits for the next try', async () => {
    const clock = fakeClock();
    const server = fakeServer([{ kind: 'retry' }, { kind: 'saved' }]);
    const draft = new Autosave(server.send, clock);
    draft.changed('filter');
    await clock.advance(0);
    draft.changed('letter');
    draft.changed('left');
    assert.deepEqual(clock.pending(), [10_000]);
    await clock.advance(10_000);
    assert.equal(server.jobs.length, 2);
    assert.equal(draft.dirty, false);
});

test('a filter change, a pick and a field left save at once', async () => {
    const clock = fakeClock();
    const server = fakeServer();
    const draft = new Autosave(server.send, clock);
    draft.changed('filter');
    await clock.advance(0);
    assert.equal(server.jobs.length, 1);

    draft.changed('letter');
    draft.changed('left');
    await clock.advance(0);
    assert.equal(server.jobs.length, 2);

    draft.ask(['isds_pick', 'include-1']);
    await clock.advance(0);
    assert.deepEqual(server.jobs[2].pick, ['isds_pick', 'include-1']);
    assert.equal(draft.dirty, false);

    // leaving a field unchanged saves nothing
    draft.changed('left');
    await clock.advance(LONGEST);
    assert.equal(server.jobs.length, 3);
});

test('never two saves at once; what changed meanwhile goes in the next one - a pick posts the letter too', async () => {
    const clock = fakeClock();
    const server = fakeServer();
    const draft = new Autosave(server.send, clock);
    const release = server.hold();
    draft.changed('filter');
    await clock.advance(0);
    draft.ask(['isds_pick', 'include-1']);
    draft.ask(['isds_pick', 'exclude-2']);
    draft.changed('letter');
    await clock.advance(LONGEST);
    assert.equal(server.jobs.length, 1);
    assert.equal(draft.dirty, true);
    release();
    await clock.advance(0);
    await clock.advance(0);
    await clock.advance(QUIET);
    assert.equal(server.most(), 1);
    assert.deepEqual(server.jobs.map(j => j.pick?.[1] ?? null), [null, 'include-1', 'exclude-2']);
    assert.equal(draft.dirty, false);
});

test('an answer confirms only the change it was sent with', async () => {
    const clock = fakeClock();
    const server = fakeServer();
    const draft = new Autosave(server.send, clock);
    const release = server.hold();
    draft.changed('letter');
    draft.changed('left');
    await clock.advance(0);
    draft.changed('letter');                // typed while the save is on its way
    release();
    await clock.advance(0);
    assert.equal(draft.dirty, true);
    assert.equal(server.jobs.length, 1);
    await clock.advance(QUIET);
    assert.equal(server.jobs.length, 2);
    assert.equal(draft.dirty, false);
});

test('a save on its way makes the page unsaved: leaving would cancel it', async () => {
    const clock = fakeClock();
    const server = fakeServer();
    const draft = new Autosave(server.send, clock);
    const release = server.hold();
    draft.ask(['isds_pick', 'include-1']);
    await clock.advance(0);
    assert.equal(draft.picks.length, 0);
    assert.equal(draft.dirty, true);
    release();
    await clock.advance(0);
    assert.equal(draft.dirty, false);
});

test('an internal error gets one silent try, then stops', async () => {
    const clock = fakeClock();
    const server = fakeServer([{ kind: 'again' }, { kind: 'saved' }, { kind: 'again' }, { kind: 'stop' }]);
    const draft = new Autosave(server.send, clock);
    draft.changed('filter');
    await clock.advance(0);
    assert.equal(draft.again, true);
    await clock.advance(BUSY_AGAIN);
    assert.equal(server.jobs.length, 2);
    assert.equal(draft.again, false, 'a success forgets it');
    assert.equal(draft.dirty, false);
    draft.changed('filter');
    await clock.advance(0);
    await clock.advance(BUSY_AGAIN);
    assert.equal(server.jobs.length, 4);
    assert.equal(draft.stopped, true);
});

test('a refused pick does not hold the letter back', async () => {
    const clock = fakeClock();
    const server = fakeServer([{ kind: 'input' }, { kind: 'saved' }]);
    const draft = new Autosave(server.send, clock);
    draft.changed('letter');
    draft.ask(['isds_pick', 'include-1']);
    await clock.advance(0);
    assert.equal(server.jobs.length, 1);
    await clock.advance(QUIET);
    assert.equal(server.jobs.length, 2, 'the letter went on its own');
    assert.equal(server.jobs[1].pick, null);
    assert.equal(draft.dirty, false);
});

test('a stop after the letter was stored all the same leaves nothing unsaved', async () => {
    const clock = fakeClock();
    const server = fakeServer([{ kind: 'stop', stored: true }]);
    const draft = new Autosave(server.send, clock);
    draft.changed('filter');
    await clock.advance(0);
    assert.equal(draft.stopped, true);
    assert.equal(draft.dirty, false);
});

test('Uložit with nothing to save leaves the next keystroke its pause', async () => {
    const clock = fakeClock();
    const server = fakeServer();
    const draft = new Autosave(server.send, clock);
    draft.ask(null);
    await clock.advance(0);
    assert.equal(server.jobs.length, 0);
    draft.changed('letter');
    await clock.advance(QUIET - 1);
    assert.equal(server.jobs.length, 0);
    await clock.advance(1);
    assert.equal(server.jobs.length, 1);
});

test('back after a form went the usual way: what it posted counts for nothing, the rest is saved at once', async () => {
    const clock = fakeClock();
    const server = fakeServer();
    const draft = new Autosave(server.send, clock);
    draft.changed('letter');
    draft.leaving = true;
    await clock.advance(LONGEST);
    assert.equal(server.jobs.length, 0);
    draft.resume();
    assert.equal(draft.dirty, true);
    await clock.advance(0);
    assert.equal(server.jobs.length, 1);
    assert.equal(draft.dirty, false);
});

test('only a stop on an error of the page or a second internal error lets Uložit go the usual way', async (t) => {
    t.mock.method(console, 'error', () => {});
    const clock = fakeClock();
    const stale = new Autosave(fakeServer([{ kind: 'stop' }]).send, clock);
    stale.changed('filter');
    await clock.advance(0);
    assert.equal(stale.retryable, false);
    const broken = new Autosave(async () => {
        throw new Error('broken page');
    }, clock);
    broken.changed('filter');
    await clock.advance(0);
    assert.equal(broken.retryable, true);
    const internal = new Autosave(fakeServer([{ kind: 'stop', retryable: true }]).send, clock);
    internal.changed('filter');
    await clock.advance(0);
    assert.equal(internal.retryable, true);
});

test('BUSY tries the same again in 5 s', async () => {
    const clock = fakeClock();
    const server = fakeServer([{ kind: 'busy' }, { kind: 'saved' }]);
    const draft = new Autosave(server.send, clock);
    draft.ask(['isds_pick', 'include-1']);
    await clock.advance(0);
    assert.equal(server.jobs.length, 1);
    await clock.advance(BUSY_AGAIN - 1);
    assert.equal(server.jobs.length, 1);
    await clock.advance(1);
    assert.deepEqual(server.jobs.map(j => j.pick), [['isds_pick', 'include-1'], ['isds_pick', 'include-1']]);
    assert.equal(draft.dirty, false);
});

test('a network failure tries again later and later, and goes on after success', async () => {
    const clock = fakeClock();
    const server = fakeServer([{ kind: 'retry' }, { kind: 'retry' }, { kind: 'retry' }, { kind: 'retry' }, { kind: 'saved' }]);
    const draft = new Autosave(server.send, clock);
    draft.changed('filter');
    await clock.advance(0);
    const times = [];
    for (let i = 0; i < 4; i++) {
        times.push(clock.pending()[0]);
        await clock.advance(clock.pending()[0]);
    }
    assert.deepEqual(times, [10_000, 20_000, 40_000, 120_000]);
    assert.equal(server.jobs.length, 5);
    assert.equal(draft.dirty, false);
    assert.equal(draft.failures, 0);
});

test('a refused form waits for the next change', async () => {
    const clock = fakeClock();
    const server = fakeServer([{ kind: 'input' }, { kind: 'saved' }]);
    const draft = new Autosave(server.send, clock);
    draft.changed('letter');
    draft.changed('left');
    await clock.advance(0);
    draft.changed('left');
    await clock.advance(10 * LONGEST);
    assert.equal(server.jobs.length, 1);
    assert.equal(draft.dirty, true);
    assert.equal(await draft.flush(), false, 'another form does not go with the letter refused');
    draft.changed('letter');
    await clock.advance(QUIET);
    assert.equal(server.jobs.length, 2);
    assert.equal(draft.dirty, false);
});

test('a stale form stops the autosave, the text stays unsaved', async () => {
    const clock = fakeClock();
    const server = fakeServer([{ kind: 'stop' }]);
    const draft = new Autosave(server.send, clock);
    draft.changed('filter');
    await clock.advance(0);
    draft.changed('letter');
    draft.ask(['isds_pick', 'include-1']);
    await clock.advance(10 * LONGEST);
    assert.equal(server.jobs.length, 1);
    assert.equal(draft.stopped, true);
    assert.equal(draft.dirty, true, 'the page still warns before it is left');
    assert.equal(await draft.flush(), false, 'no form goes while leaving the page would lose the text');
    draft.saved = draft.rev;
    draft.picks = [];
    assert.equal(await draft.flush(), true, 'nothing to lose: the forms go, the server tells the rest');
});

test('a failing request stops the autosave instead of looping', async (t) => {
    t.mock.method(console, 'error', () => {});
    const clock = fakeClock();
    const draft = new Autosave(async () => {
        throw new Error('broken page');
    }, clock);
    draft.changed('filter');
    await clock.advance(0);
    assert.equal(draft.stopped, true);
    assert.deepEqual(clock.pending(), []);
});

test('another form waits for the save on its way and for what is not saved yet', async () => {
    const clock = fakeClock();
    const server = fakeServer();
    const draft = new Autosave(server.send, clock);
    const release = server.hold();
    draft.changed('filter');
    await clock.advance(0);
    draft.changed('letter');
    let flushed = null;
    draft.flush().then((ok) => {
        flushed = ok;
    });
    await clock.advance(0);
    assert.equal(flushed, null);
    release();
    await clock.advance(0);
    await clock.advance(0);
    assert.equal(flushed, true);
    assert.equal(server.jobs.length, 2);
    assert.equal(server.most(), 1);
});

test('a page left with a form posted saves nothing more', async () => {
    const clock = fakeClock();
    const server = fakeServer();
    const draft = new Autosave(server.send, clock);
    draft.changed('letter');
    draft.leaving = true;
    await clock.advance(LONGEST);
    assert.equal(server.jobs.length, 0);
});
