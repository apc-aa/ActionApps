// The control of {mailbulk}: its buttons post to central/responder.php and the answer replaces the whole control.

/** the module next to this one, with its ?v= - a cached older one must not mix with this one */
const sibling = (path) => {
    const self = new URL(import.meta.url);
    const url  = new URL(path, self);
    url.search = self.search;
    return url.href;
};

const { POLL_EVERY, pollDelay, shouldPoll } = await import(sibling('./aa-poll.js'));
export { POLL_EVERY, pollDelay };

/** ms: AA_Response() calls nothing on an HTTP error or a lost connection - the control is free again after this */
export const ANSWER_WAIT = 30_000;

const BOUND = Symbol.for('aa-mailbulk.bound');
/** control => the request on its way, {id, poll}; a click meanwhile would start the same action twice */
const inFlight = new WeakMap();
/** control => its polling {since, failures}, handed to the control that replaces it */
const polls = new WeakMap();
/** controls whose next poll is planned */
const planned = new WeakSet();
let lastId = 0;

/** "Error 9: text" of the responder → the text, HTML escaped by the server */
export const errorText = data => String(data).replace(/^Error \d+: /, '');

/**
 * The control of the answer in place of $control - the first one of the answer, not its first element (a PHP
 * notice before it would replace the control). A button that had the focus keeps it in the new control.
 */
export const replaceControl = (control, html, doc) => {
    const template = doc.createElement('template');
    template.innerHTML = String(html);
    const fresh = template.content.querySelector('[data-aa-mailbulk]');
    if (!fresh) {
        return null;
    }
    const focused = control.contains(doc.activeElement) ? doc.activeElement?.dataset?.action : undefined;
    control.replaceWith(fresh);
    if (/^[a-z]+$/.test(focused ?? '')) {
        fresh.querySelector(`[data-action="${focused}"]`)?.focus();
    }
    return fresh;
};

/** what the module does in a browser; tests give their own */
const browserEnv = () => ({
    respond: (params, ok, err) => globalThis.AA_Response('Mailbulk', params, ok, err),
    ask: text => globalThis.confirm(text),
    later: (fn, ms) => setTimeout(fn, ms),
    now: () => Date.now(),
    visible: () => document.visibilityState === 'visible',
    all: () => document.querySelectorAll('[data-aa-mailbulk]'),
    plain: html => new DOMParser().parseFromString(html, 'text/html').documentElement.textContent ?? '',
    swap: (control, html) => replaceControl(control, html, document),
    note: (control, html) => {
        let note = control.querySelector('.aa-mailbulk-error');
        if (!note) {
            note = document.createElement('span');
            note.className = 'aa-mailbulk-error';
            note.setAttribute('role', 'alert');
            control.append(' ', note);
        }
        note.innerHTML = html;
    },
});

const params = control => ({ mb_list: control.dataset.list, mb_item: control.dataset.item });

const pollOf = (control, env) => {
    if (!polls.has(control)) {
        polls.set(control, { since: env.now(), failures: 0 });
    }
    return polls.get(control);
};

/** a request of the control is on its way; when no answer comes, $lost runs and the control is free again */
const begin = (control, env, poll, lost) => {
    const id = ++lastId;
    inFlight.set(control, { id, poll });
    env.later(() => {
        if (inFlight.get(control)?.id === id) {
            inFlight.delete(control);
            lost();
        }
    }, ANSWER_WAIT);
    return id;
};

/** the answer of request $id is the one awaited - not one a later click or the watchdog has replaced; it ends the wait */
const settle = (control, id) => {
    if (inFlight.get(control)?.id !== id) {
        return false;
    }
    inFlight.delete(control);
    return true;
};

/** the new control from the server in place of the old one; it goes on polling while sending */
export const show = (control, html, env) => {
    const fresh = env.swap(control, html);
    if (fresh) {
        polls.set(fresh, pollOf(control, env));
        watch(fresh, env);
        // controls an AJAX part of the page brought meanwhile
        for (const other of env.all()) {
            watch(other, env);
        }
    }
    return fresh;
};

/** the state of the control from the server: after a refusal it may be other than the page shows */
const refresh = (control, env, text) => {
    const id = begin(control, env, true, () => watch(control, env));
    env.respond({ mb_action: 'state', ...params(control) }, (html) => {
        if (settle(control, id)) {
            const fresh = show(control, html, env);
            if (fresh && (text !== '')) {
                env.note(fresh, text);
            }
        }
    }, () => {
        if (settle(control, id)) {
            watch(control, env);
        }
    });
};

/** a refused action is said in the control, then the control shows the state the server has */
const refused = (control, data, env) => {
    const text = errorText(data);
    env.note(control, text);
    refresh(control, env, text);
};

/** the control asks for its state again: in sight, while sending, for an hour; slower after failures */
const poll = (control, env) => {
    const p = pollOf(control, env);
    const failed = () => {
        p.failures++;
        watch(control, env);
    };
    const id = begin(control, env, true, failed);
    env.respond({ mb_action: 'state', ...params(control) }, (html) => {
        if (settle(control, id)) {
            p.failures = 0;
            show(control, html, env);
        }
    }, (data) => {
        if (settle(control, id)) {
            env.note(control, errorText(data));
            failed();
        }
    });
};

/** a control in a state that changes by itself asks again as shouldPoll() says; once planned, never twice */
export const watch = (control, env) => {
    const p = pollOf(control, env);
    const due = () => shouldPoll({ state: control.dataset.state, since: env.now() - p.since, visible: env.visible() });
    if (planned.has(control) || !due()) {
        return;
    }
    planned.add(control);
    env.later(() => {
        planned.delete(control);
        if (!control.isConnected || !due()) {
            return;
        }
        // an action on its way replaces the control anyway
        if (inFlight.has(control)) {
            watch(control, env);
            return;
        }
        poll(control, env);
    }, pollDelay(p.failures));
};

/**
 * Runs an action of a button. A send asks the server for the number of recipients first and goes only
 * when the user confirms its question. It wins over a poll on its way, whose answer is then left out.
 */
export const act = (control, action, env) => {
    if (inFlight.get(control)?.poll === false) {
        return;
    }
    // a person acted: the sending is followed from now on, as after a load
    Object.assign(pollOf(control, env), { since: env.now(), failures: 0 });
    const lost = () => refresh(control, env, '');
    const run = (mb_action) => {
        const id = begin(control, env, false, lost);
        env.respond({ mb_action, ...params(control) }, (html) => {
            if (settle(control, id)) {
                show(control, html, env);
            }
        }, (data) => {
            if (settle(control, id)) {
                refused(control, data, env);
            }
        });
    };
    if (action !== 'send') {
        run(action);
        return;
    }
    const id = begin(control, env, false, lost);
    env.respond({ mb_action: 'prepare', ...params(control) }, (question) => {
        if (!settle(control, id)) {
            return;
        }
        if (env.ask(env.plain(question))) {
            run('send');
        } else {
            watch(control, env);
        }
    }, (data) => {
        if (settle(control, id)) {
            refused(control, data, env);
        }
    });
};

/** One click handler for all controls of the document, however often the module runs. */
export const init = (doc, env = browserEnv()) => {
    if (doc[BOUND]) {
        return false;
    }
    doc[BOUND] = true;
    const watchAll = () => {
        for (const control of env.all()) {
            watch(control, env);
        }
    };
    doc.addEventListener('click', (e) => {
        const button  = e.target?.closest?.('[data-aa-mailbulk] [data-action]');
        const control = button?.closest('[data-aa-mailbulk]');
        if (control) {
            e.preventDefault();
            act(control, button.dataset.action, env);
            watchAll();
        }
    });
    // out of sight it asks nothing; back in sight it goes on
    doc.addEventListener('visibilitychange', watchAll);
    watchAll();
    return true;
};

if (globalThis.document) {
    init(document);
}
