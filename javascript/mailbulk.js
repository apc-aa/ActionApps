// The control of {mailbulk}: its buttons post to central/responder.php and the answer replaces the whole control.

/** how often a control in the state "sending" asks for its figures */
export const POLL_EVERY = 5_000;

const BOUND = Symbol.for('aa-mailbulk.bound');
// a click while an answer is on its way would start the same action twice
const busy = new WeakSet();
const watched = new WeakSet();

/** "Error 9: text" of the responder → the text, HTML escaped by the server */
export const errorText = data => String(data).replace(/^Error \d+: /, '');

/** what the module does in a browser; tests give their own */
const browserEnv = () => ({
    respond: (params, ok, err) => globalThis.AA_Response('Mailbulk', params, ok, err),
    ask: text => globalThis.confirm(text),
    later: (fn, ms) => setTimeout(fn, ms),
    plain: html => new DOMParser().parseFromString(html, 'text/html').documentElement.textContent ?? '',
    swap: (control, html) => {
        const template = document.createElement('template');
        template.innerHTML = String(html).trim();
        const fresh = template.content.firstElementChild;
        fresh && control.replaceWith(fresh);
        return fresh;
    },
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

/** the new control from the server in place of the old one; it keeps polling while sending */
export const show = (control, html, env) => {
    const fresh = env.swap(control, html);
    if (fresh) {
        watch(fresh, env);
    }
    return fresh;
};

/** a control that is sending asks for its state every POLL_EVERY, as long as it is in the page */
export const watch = (control, env) => {
    if ((control.dataset.state !== 'sending') || watched.has(control)) {
        return;
    }
    watched.add(control);
    env.later(() => {
        watched.delete(control);
        if (!control.isConnected) {
            return;
        }
        // an action on its way replaces the control anyway
        if (busy.has(control)) {
            watch(control, env);
            return;
        }
        act(control, 'state', env);
    }, POLL_EVERY);
};

/**
 * Runs an action of a button. A send asks the server for the number of recipients first and goes only
 * when the user confirms its question.
 */
export const act = (control, action, env) => {
    if (busy.has(control)) {
        return;
    }
    busy.add(control);
    const params = { mb_list: control.dataset.list, mb_item: control.dataset.item };
    const fail = (data) => {
        busy.delete(control);
        env.note(control, errorText(data));
        watch(control, env);
    };
    const run = mb_action => env.respond({ mb_action, ...params }, (html) => {
        busy.delete(control);
        show(control, html, env);
    }, fail);
    if (action !== 'send') {
        run(action);
        return;
    }
    env.respond({ mb_action: 'prepare', ...params }, (question) => {
        if (env.ask(env.plain(question))) {
            run('send');
        } else {
            busy.delete(control);
        }
    }, fail);
};

/** One click handler for all controls of the document, however often the module runs. */
export const init = (doc, env = browserEnv()) => {
    if (doc[BOUND]) {
        return false;
    }
    doc[BOUND] = true;
    doc.addEventListener('click', (e) => {
        const button  = e.target?.closest?.('[data-aa-mailbulk] [data-action]');
        const control = button?.closest('[data-aa-mailbulk]');
        if (control) {
            e.preventDefault();
            act(control, button.dataset.action, env);
        }
    });
    for (const control of doc.querySelectorAll('[data-aa-mailbulk]')) {
        watch(control, env);
    }
    return true;
};

if (globalThis.document) {
    init(document);
}
