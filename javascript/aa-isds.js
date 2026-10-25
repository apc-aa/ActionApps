/**
 * <aa-isds-campaign> — the root of a page that sends a letter to many data boxes (aa-isds@1).
 *
 *   {require:aa-isds@1}
 *   <aa-isds-campaign class="aa-isds aa-isds--env-test"> …server-rendered forms… </aa-isds-campaign>
 *
 * The server renders every state of the page and every action is a plain form, so the page works
 * without JS; the element only enhances it. The look is css/aa-isds.css, scoped under .aa-isds.
 * Pure functions are exported, so node --test imports this file without a DOM.
 *
 * Hooks in the markup (a missing one switches its feature off):
 *   form[data-aa-isds-form="<action>"]   the form of an action; the one of save without a file input
 *                                        is the draft: it saves itself (fetch) and swaps the fragments
 *   [data-aa-isds-fragment="<name>"]     what a save may change: replaced by the one of the answer
 *   [data-aa-isds-code] [-status]        on the fragment result: what the action did
 *   [data-aa-isds-field="subject|toHands|body"]  a field of the letter: counter, height, autosave
 *   input[data-aa-isds-confirm="<n>"]    the buttons of its form stay locked until n is typed
 *   [data-aa-isds-live]                  hidden role="status" for screen readers; it also carries the
 *                                        texts the server cannot give: data-aa-isds-text-retry, -login,
 *                                        -reload, -failed, -big (English below when missing)
 *   .shl-env, [data-aa-isds-fragment="sendbar"]  measured into --ai-sticky-top / --ai-sticky-bottom
 *   .snd-bar__more                       the bar's "more" toggle - rendered hidden, for JS only
 */

/** roots set up already - connectedCallback runs again each time the element is moved */
const done = new WeakSet();

/** the fields of the letter; their change saves after a pause in typing */
export const LETTER = Object.freeze(['isds_subject', 'isds_to_hands', 'isds_body']);

/** the controls of the recipient filter: a change saves at once; an answer may bring a filter saved elsewhere */
export const FILTER = Object.freeze(['isds_membership', 'isds_types[]', 'isds_include', 'isds_exclude']);

/** the steps a page shows as an open card - Gate::PARAMS step */
export const STEPS = Object.freeze(['test', 'send', 'search', 'files']);

/** ms: a pause in typing that saves, the longest typing that goes unsaved, slower when a save takes over 1 s */
export const QUIET = 15_000;
export const QUIET_SLOW = 30_000;
export const LONGEST = 60_000;
const SLOW_SAVE = 1_000;
export const BUSY_AGAIN = 5_000;

/** a keepalive request may carry 64 KiB in all */
export const KEEPALIVE_MAX = 60_000;

/** a body this large makes every save and the history of the item heavy */
export const BIG_BODY = 1_000_000;

const TEXTS = {
    retry:  'Not saved - trying again.',
    login:  'The login has expired. Copy your text, log in and paste it back.',
    reload: 'Load the page again',
    failed: 'The server refused to save the draft.',
    big:    'Images pasted into the letter make every save larger, and the history of the draft too.',
};

/** ms until the letter is saved: a pause in typing, or the longest typing goes unsaved; times of the first and last change not sent */
export const saveDelay = ({ firstAt, lastAt, now, slow = false }) =>
    Math.max(0, Math.min(lastAt + (slow ? QUIET_SLOW : QUIET), firstAt + LONGEST) - now);

/** ms before the next try after the n-th network failure in a row (0 = the first): 10, 20, 40 s, then every 2 min */
export const retryDelay = n => ((n < 3) ? 10_000 * (2 ** n) : 120_000);

/**
 * What the page does with the answer of a save, by its code (Actions, Advice):
 *   saved  - the letter and the filter are stored: take the answer
 *   filter - the letter is stored, the filter changed elsewhere: show the one stored, as an alert
 *   input  - the form cannot be taken: say so, try again only after the next change
 *   busy   - another request holds the draft: the same again in a while
 *   again  - an internal error, maybe passing: once more in a while, then as stop
 *   stop   - no draft to save any more, or the form is stale: no autosave, a button to load the page
 * @param {string} code
 * @param {string} status ActionResult status - for a code the page does not know
 * @returns {'saved'|'filter'|'input'|'busy'|'again'|'stop'}
 */
export const reaction = (code, status = '') => {
    switch (code) {
        case 'SAVED':          return 'saved';
        case 'FILTER_CHANGED': return 'filter';
        case 'INPUT':
        case 'TOO_LARGE':      return 'input';
        case 'BUSY':           return 'busy';
        case 'INTERNAL':       return 'again';
        default:               return (status === 'busy') ? 'busy' : 'stop';
    }
};

/**
 * The hidden fields the draft goes on with: those of the answer - a new isds_form, the token, the
 * campaign of a draft saved new -, but the filter (taken apart) and the nonce. The nonce makes the id
 * of a new draft, so it stays until the answer names the draft, or the next save would make another.
 * @param {Array<[string, string]>} current
 * @param {Array<[string, string]>} answered
 * @returns {Array<[string, string]>}
 */
export const mergeHidden = (current, answered) => {
    const fields   = answered.filter(([name]) => !FILTER.includes(name) && (name !== 'isds_nonce'));
    const campaign = answered.find(([name]) => name === 'isds_campaign')?.[1] ?? '';
    const nonce    = current.find(([name]) => name === 'isds_nonce') ?? answered.find(([name]) => name === 'isds_nonce');
    return ((campaign === '') && nonce) ? [...fields, nonce] : fields;
};

/**
 * What the controls of the filter take from the answer: a box or radio is checked as the answer's of
 * its name and value - unless kept: the user changed it since the save went. Another control - the
 * lists of picks, which only the server changes - always takes the value of the answer's of its name
 * in order, '' if none: a save posts the lists as they are, so lists of before would undo a pick.
 * @param {Array<{name: string, value: string, checkable: boolean, checked?: boolean, kept?: boolean}>} current
 * @param {typeof current} answered
 * @returns {Array<{checked: boolean}|{value: string}|null>} one for each control of current; null = stays
 */
export const filterUpdates = (current, answered) => {
    const seen = new Map();
    return current.map((control) => {
        if (control.checkable && control.kept) {
            return null;
        }
        if (control.checkable) {
            return { checked: answered.some(a => a.checkable && a.checked && (a.name === control.name) && (a.value === control.value)) };
        }
        const nth = seen.get(control.name) ?? 0;
        seen.set(control.name, nth + 1);
        return { value: answered.filter(a => !a.checkable && (a.name === control.name))[nth]?.value ?? '' };
    });
};

/**
 * What a save posts: the form without any file - files have a form of their own -, the body as the
 * editor holds it, the button it stands for (isds_pick), and the step of the URL - the redirect after
 * a save keeps only a step posted, so the card open now would close.
 * @param {Array<[string, string|object]>} entries the form as FormData lists it
 * @param {{step?: string|null, bodies?: Array<[string, string]>, button?: [string, string]|null}} with
 * @returns {Array<[string, string]>}
 */
export const payload = (entries, { step = null, bodies = [], button = null } = {}) => {
    const set = new Map(bodies);
    const out = entries.filter(([name, value]) => (typeof value === 'string') && (name !== 'isds_files[]') && !set.has(name));
    out.push(...set);
    if (button) {
        out.push(button);
    }
    if (STEPS.includes(step) && !out.some(([name]) => name === 'isds_step')) {
        out.push(['isds_step', step]);
    }
    return out;
};

/** bytes of $entries posted as a form, about - multipart adds some to each */
export const payloadSize = (entries) => {
    const utf8 = new TextEncoder();
    return entries.reduce((sum, [name, value]) => sum + utf8.encode(name + value).length + 100, 0);
};

/** $url is of the origin of $base */
export const sameOrigin = (url, base) => {
    try {
        return new URL(url, base).origin === new URL(base).origin;
    } catch {
        return false;
    }
};

/**
 * The address the page shows after the answer from $url (after the redirect): without the key of the
 * result, which a reload would show again - the redirect writes it as isds_r= -, the rest as it came;
 * null for another origin
 */
export const pageUrl = (url, base) => {
    if (!sameOrigin(url, base)) {
        return null;
    }
    const to  = new URL(url, base);
    to.search = to.search.slice(1).split('&').filter(pair => (pair !== '') && !pair.startsWith('isds_r=')).join('&');
    to.hash   = new URL(base).hash;
    return to.href;
};

/** the confirmation typed is the count asked for - spaces aside, "1 234" is 1234 */
export const confirmed = (typed, count) => (String(count) !== '') && (String(typed).replace(/\s/g, '') === String(count));

/**
 * The saves of the draft, one after another - never two at once, so a later one never meets the lock
 * of an earlier one (BUSY) and an answer always belongs to the newest form. Time and the request are
 * handed in, so the plan runs in node --test.
 */
export class Autosave {
    rev = 0;            // changes of the letter or the filter made
    saved = 0;          // the change the last answer confirmed
    blocked = -1;       // the change the server refused (INPUT): no try again until another
    firstAt = null;     // the first change not sent yet
    lastAt = null;
    urgent = false;     // a change of the filter, a field left: save at once
    picks = [];         // buttons the save stands for, in the order clicked
    running = null;     // the request on its way
    stopped = false;
    leaving = false;    // the page goes away with a form posted as usual
    failures = 0;
    again = false;      // an internal error of the server got its one silent try
    retryable = false;  // the autosave stopped on what a form posted as usual may still get past
    slow = false;
    #timer = null;
    #notBefore = 0;     // after a failure: no request before
    #send;
    #clock;

    /**
     * @param {(job: {pick: [string, string]|null, rev: number}) => Promise<{kind: string, stored?: boolean}>} send
     *        posts the form, applies the answer and says what came of it: reaction(), 'retry', or stop
     *        with stored when the letter was saved all the same
     * @param {{now?: () => number, setTimeout?: Function, clearTimeout?: Function}} clock
     */
    constructor(send, clock = {}) {
        this.#send  = send;
        this.#clock = { now: Date.now, setTimeout: (...a) => setTimeout(...a), clearTimeout: t => clearTimeout(t), ...clock };
    }

    /** anything not stored yet - a request on its way included: leaving the page would cancel it */
    get dirty() {
        return (this.rev > this.saved) || (this.picks.length > 0) || (this.running !== null);
    }

    /** @param {'letter'|'filter'|'left'} kind a field typed into, a filter control changed, a field left */
    changed(kind) {
        if (kind !== 'left') {
            this.rev++;
            const now = this.#clock.now();
            this.firstAt ??= now;
            this.lastAt = now;
        }
        if (kind !== 'letter') {
            this.urgent = true;
        }
        this.#plan();
    }

    /** a button of the form the save posts as if clicked (isds_pick); a save asked for (Uložit) passes null */
    ask(pick) {
        if (pick) {
            this.picks.push(pick);
        }
        this.urgent = true;
        this.#plan();
    }

    stop() {
        this.stopped = true;
        this.picks = [];
        this.#clear();
    }

    /**
     * The page is in use again after a form went the usual way and did not leave (Esc, back from the
     * cache). What that form posted counts for nothing - the server may have refused it -, so anything
     * not confirmed is saved at once; a save of the same text again changes nothing.
     */
    resume() {
        this.leaving = false;
        this.urgent  = true;
        this.#plan();
    }

    /** the save due now; resolves with what came of it, null when nothing was sent */
    run() {
        this.#clear();
        if (this.running || this.stopped || this.leaving) {
            return this.running ?? Promise.resolve(null);
        }
        const pick = this.picks.shift() ?? null;
        if (!pick && !((this.rev > this.saved) && (this.rev !== this.blocked))) {
            return Promise.resolve(null);
        }
        const job   = { pick, rev: this.rev };
        const start = this.#clock.now();
        this.firstAt = null;
        this.urgent  = false;
        this.running = (async () => {
            let outcome;
            try {
                outcome = await this.#send(job);
            } catch (e) {
                console.error('aa-isds:', e);
                outcome = { kind: 'stop', retryable: true };
            }
            this.running = null;
            this.slow    = (this.#clock.now() - start) > SLOW_SAVE;
            this.#after(job, outcome);
            return outcome;
        })();
        return this.running;
    }

    /**
     * Before another form goes: the save on its way ends, and what is not stored yet is saved
     * @returns {Promise<boolean>} nothing is left unsaved - a page left now loses no text
     */
    async flush() {
        for (let i = 0; i < 10; i++) {
            if (this.running) {
                await this.running;
            }
            if (this.stopped || !this.dirty) {
                return !this.dirty;
            }
            const outcome = await this.run();
            if (!outcome || ['retry', 'busy', 'again', 'input'].includes(outcome.kind)) {
                return false;
            }
        }
        return !this.dirty;
    }

    #after(job, outcome) {
        const kind = outcome?.kind;
        switch (kind) {
            case 'saved':
            case 'filter':
            case 'input':
                if (kind === 'input') {
                    // a pick refused takes the letter with it no further: the next save goes without it
                    this.blocked = job.pick ? this.blocked : job.rev;
                } else {
                    this.saved = Math.max(this.saved, job.rev);
                }
                this.failures   = 0;
                this.again      = false;
                this.#notBefore = 0;
                break;
            case 'busy':
            case 'again':
            case 'retry':
                if (job.pick) {
                    this.picks.unshift(job.pick);
                }
                this.again      ||= (kind === 'again');
                this.urgent     = true;     // the same again as soon as the failure lets
                this.#notBefore = this.#clock.now() + ((kind === 'retry') ? retryDelay(this.failures++) : BUSY_AGAIN);
                break;
            default:
                if (outcome?.stored) {
                    this.saved = Math.max(this.saved, job.rev);
                }
                this.retryable = !!outcome?.retryable;
                this.stop();
                return;
        }
        this.#plan();
    }

    /** the next save: at once for a pick, a filter or a field left, after the plan for typing - never before a failure lets */
    #plan() {
        if (this.stopped || this.leaving || this.running) {
            return;                             // the answer plans the next one
        }
        const now    = this.#clock.now();
        const letter = (this.rev > this.saved) && (this.rev !== this.blocked);
        let delay;
        if (this.picks.length || (this.urgent && letter)) {
            delay = 0;
        } else if (letter) {
            delay = saveDelay({ firstAt: this.firstAt ?? now, lastAt: this.lastAt ?? now, now, slow: this.slow });
        } else {
            this.urgent = false;                // nothing to save: the next keystroke waits for its pause
            return;
        }
        this.#clear();
        this.#timer = this.#clock.setTimeout(() => this.run(), Math.max(delay, this.#notBefore - now));
    }

    #clear() {
        if (this.#timer !== null) {
            this.#clock.clearTimeout(this.#timer);
        }
        this.#timer = null;
    }
}

/* ---------- the page: everything below needs a DOM ---------- */

/** setups that also run on each fragment swapped in: root => list of function(scope) */
const enhancers = new WeakMap();

/** fragment => the fragment as the server sent it: enhancing changes the one in the page */
const sources = new WeakMap();

/** buttons JS locked - the only ones a click is kept from; the server never renders that state */
const locked = new WeakSet();

const enhance = (root, enhancer) => {
    enhancers.set(root, [...(enhancers.get(root) ?? []), enhancer]);
    enhancer(root);
};

const text = (root, key) => root.querySelector('[data-aa-isds-live]')?.getAttribute(`data-aa-isds-text-${key}`) || TEXTS[key];

const inFragment = el => !!el.parentElement?.closest('[data-aa-isds-fragment]');

/** the form of the draft: of save, with no file input - that one has a form of its own */
const draftForm = scope => [...scope.querySelectorAll('form[data-aa-isds-form="save"]')]
    .find(form => (form.enctype !== 'multipart/form-data') && !form.querySelector('input[type="file"]')) ?? null;

const isSubmit = el => ((el.localName === 'button') && (el.type === 'submit')) || ((el.localName === 'input') && ['submit', 'image'].includes(el.type));

/** a control the user types into or picks from - what a swap must not pull from under them */
const isEntry = el => el.matches('textarea, select, input:not([type="button"], [type="submit"], [type="reset"], [type="image"], [type="hidden"], [type="checkbox"], [type="radio"])');

/** field or <details> => its state when its fragment came in: the browser's defaults follow rules of their own */
const states = new WeakMap();

const stateOf = (el) => {
    if (el.localName === 'details') {
        return el.open;
    }
    if (el.type === 'file') {
        return el.files?.length ?? 0;
    }
    if (['checkbox', 'radio'].includes(el.type)) {
        return el.checked;
    }
    return (el.localName === 'select') ? [...el.options].map(o => o.selected).join() : el.value;
};

const snap = scope => scope.querySelectorAll('input, select, textarea, details').forEach(el => states.set(el, stateOf(el)));

/** an element of $scope the user changed since its fragment came in */
const changed = el => states.has(el) && (states.get(el) !== stateOf(el));
const changedByUser = scope => [...scope.querySelectorAll('input, select, textarea')].some(changed);

const setLock = (el, on) => {
    if (on) {
        el.setAttribute('aria-disabled', 'true');
        locked.add(el);
    } else {
        el.removeAttribute('aria-disabled');
        locked.delete(el);
    }
};

/** the fragments as sent, before any setup changes them */
const setupSources = (root) => {
    root.querySelectorAll('[data-aa-isds-fragment]').forEach((el) => {
        sources.set(el, el.cloneNode(true));
        snap(el);
    });
};

/** --ai-sticky-top and --ai-sticky-bottom from the strip and the bar as they are - they wrap, and the bar is 128 px at 1000 px */
const setupSticky = (root) => {
    if (!globalThis.ResizeObserver) {
        return;
    }
    const vars = new WeakMap();
    const observer = new ResizeObserver((entries) => {
        for (const entry of entries) {
            const name = vars.get(entry.target);
            const size = Math.ceil(entry.borderBoxSize?.[0]?.blockSize ?? entry.target.offsetHeight);
            if (!entry.target.isConnected) {
                observer.unobserve(entry.target);   // a fragment swapped out
                continue;
            }
            if (size > 0) {
                root.style.setProperty(name, `${size}px`);
            } else {
                root.style.removeProperty(name);
            }
        }
    });
    enhance(root, (scope) => {
        const watch = (el, name) => {
            vars.set(el, name);
            observer.observe(el);
        };
        scope.querySelectorAll('.shl-env').forEach(el => watch(el, '--ai-sticky-top'));
        [scope, ...scope.querySelectorAll('[data-aa-isds-fragment="sendbar"]')]
            .filter(el => el.matches?.('[data-aa-isds-fragment="sendbar"]'))
            .forEach(el => watch(el, '--ai-sticky-bottom'));
    });
};

/** the "more" toggle of the bar on a phone: the server renders it hidden, so the bar shows all without JS */
const setupBar = (root) => {
    root.addEventListener('click', (e) => {
        const more = e.target.closest?.('.snd-bar__more');
        if (more && root.contains(more)) {
            more.setAttribute('aria-expanded', String(more.getAttribute('aria-expanded') !== 'true'));
        }
    });
    enhance(root, (scope) => {
        scope.querySelectorAll('.snd-bar__more').forEach((more) => {
            more.hidden = false;
            if (!more.hasAttribute('aria-expanded')) {
                more.setAttribute('aria-expanded', 'false');
            }
        });
    });
};

/**
 * The send button stays locked until the count of messages is typed - the server checks the count
 * again (COUNT_CHANGED). aria-disabled keeps the button in the tab order with its reason in sight;
 * it does not stop a click, so the page does.
 */
const setupConfirm = (root) => {
    const lock = (input) => {
        const open = confirmed(input.value, input.dataset.aaIsdsConfirm ?? '');
        [...(input.form?.elements ?? [])].filter(isSubmit).forEach(button => setLock(button, !open));
    };
    root.addEventListener('input', (e) => {
        if (e.target.matches?.('input[data-aa-isds-confirm]')) {
            lock(e.target);
        }
    });
    // capture: before the default of a click - Enter in a field clicks the first button too
    root.addEventListener('click', (e) => {
        const button = e.target.closest?.('[aria-disabled="true"]');
        if (button && locked.has(button)) {
            e.preventDefault();
            e.stopPropagation();
            button.form?.querySelector('input[data-aa-isds-confirm]')?.focus();
        }
    }, true);
    enhance(root, scope => scope.querySelectorAll('input[data-aa-isds-confirm]').forEach(lock));
};

/** the subject grows with its text and keeps to one line of text; the fields count their characters near the limit */
const setupLetter = (root) => {
    const counters = new WeakMap();
    const grow = (el) => {
        el.style.height = 'auto';
        el.style.height = `${el.scrollHeight}px`;
    };
    const count = (el) => {
        const max = el.maxLength;
        if (!(max > 0)) {
            return;
        }
        let counter = counters.get(el);
        if (!counter) {
            // .ai-help: the stylesheet has no room left for a class of its own
            counter = Object.assign(document.createElement('span'), { className: 'ai-help', id: `${el.id || el.name}-aa-isds-count` });
            counters.set(el, counter);
            el.after(counter);
            el.setAttribute('aria-describedby', `${el.getAttribute('aria-describedby') ?? ''} ${counter.id}`.trim());
        }
        // UTF-16 units, as maxlength counts them
        const n = el.value.length;
        counter.textContent = `${n}/${max}`;
        counter.hidden = n < max * 0.8;
    };
    root.addEventListener('input', (e) => {
        const el = e.target;
        if (!el.matches?.('[data-aa-isds-field="subject"], [data-aa-isds-field="toHands"]')) {
            return;
        }
        if ((el.localName === 'textarea') && /[\r\n]/.test(el.value)) {
            // a subject is one line - the server refuses a line break
            const at = el.selectionStart;
            el.value = el.value.replace(/\r\n?|\n/g, ' ');
            el.setSelectionRange(at, at);
        }
        if (el.localName === 'textarea') {
            grow(el);
        }
        count(el);
    });
    // Enter in the subject saves, as in a one-line field
    root.addEventListener('keydown', (e) => {
        if ((e.key === 'Enter') && !e.isComposing && e.target.matches?.('textarea[data-aa-isds-field="subject"]')) {
            e.preventDefault();
            e.target.form?.requestSubmit();
        }
    });
    enhance(root, (scope) => {
        for (const el of scope.querySelectorAll('input[data-aa-isds-field], textarea[data-aa-isds-field]')) {
            if ((el.dataset.aaIsdsField === 'subject') && (el.localName === 'textarea')) {
                grow(el);
                let width = 0;
                // a narrower field wraps the subject into more lines; its own growth changes no width
                globalThis.ResizeObserver && new ResizeObserver(([entry]) => {
                    if (entry.contentRect.width !== width) {
                        width = entry.contentRect.width;
                        grow(el);
                    }
                }).observe(el);
            }
            if (el.dataset.aaIsdsField !== 'body') {
                count(el);
            }
        }
    });
};

/** the fragment result of the page - an empty one before the draft when the page has none */
const resultBox = (root, form) => {
    let box = root.querySelector('[data-aa-isds-fragment="result"]');
    if (!box) {
        box = document.createElement('div');
        box.dataset.aaIsdsFragment = 'result';
        if (form) {
            form.before(box);
        } else {
            root.prepend(box);
        }
    }
    return box;
};

/**
 * Fragments of the root replaced by those of the answer of the same name, in order; one the answer
 * lacks is emptied. Not replaced: one the answer sends unchanged, and one the user is in now - typing
 * into a field of it, or a field changed since the fragment came in -, which a swap would undo. A
 * <details> the user opened or closed keeps that in the new one (by id or summary), the rest are as the
 * server sends them; focus on a button goes to the same button.
 * @param {string[]|null} only names to replace; null = all
 */
const swapFragments = (root, form, answered, only) => {
    const groups = (scope) => {
        const map = new Map();
        for (const el of scope.querySelectorAll('[data-aa-isds-fragment]')) {
            const name = el.dataset.aaIsdsFragment;
            if (!inFragment(el) && (!only || only.includes(name))) {
                map.set(name, [...(map.get(name) ?? []), el]);
            }
        }
        return map;
    };
    const fresh = groups(answered);
    if (fresh.has('result')) {
        resultBox(root, form);                  // so that a refusal is told even on a page without one
    }
    const added = [];
    for (const [name, olds] of groups(root)) {
        olds.forEach((old, i) => {
            const neu = fresh.get(name)?.[i];
            const active = old.contains(document.activeElement) ? document.activeElement : null;
            if ((active && isEntry(active)) || changedByUser(old)) {
                return;
            }
            if (!neu) {
                old.replaceChildren();
                return;
            }
            if (sources.get(old)?.isEqualNode(neu)) {
                return;
            }
            const copy = document.importNode(neu, true);
            // a parsed document has scripting off: the content of <noscript> is markup there, text in the page
            copy.querySelectorAll('script, noscript').forEach(s => s.remove());
            sources.set(copy, neu);
            const expanded = old.querySelector('.snd-bar__more[aria-expanded="true"]');
            const toggled  = [...old.querySelectorAll('details')].filter(changed);
            old.replaceWith(copy);
            if (expanded) {
                copy.querySelector('.snd-bar__more')?.setAttribute('aria-expanded', 'true');
            }
            if (active) {
                const same = (active.id && copy.querySelector(`#${CSS.escape(active.id)}`))
                    || (active.name && [...copy.querySelectorAll(`[name="${CSS.escape(active.name)}"]`)].find(el => el.value === active.value));
                if (same) {
                    same.focus({ preventScroll: true });
                } else {
                    copy.tabIndex = -1;
                    copy.focus({ preventScroll: true });
                }
            }
            added.push([copy, toggled]);
        });
    }
    const label = d => (d.id ? `#${d.id}` : d.querySelector(':scope > summary')?.textContent.trim());
    for (const [copy, toggled] of added) {
        for (const enhancer of enhancers.get(root) ?? []) {
            try {
                enhancer(copy);
            } catch (e) {
                console.error('aa-isds:', e);
            }
        }
        snap(copy);
        // after the snapshot: the user's choice stays one in the next swap too
        for (const d of toggled) {
            const twin = [...copy.querySelectorAll('details')].find(t => label(t) === label(d));
            if (twin) {
                twin.open = d.open;
            }
        }
    }
};

const hiddenOf = form => [...form.elements].filter(el => (el.type === 'hidden') && el.name && !inFragment(el) && !FILTER.includes(el.name));
const filterOf = form => [...form.elements].filter(el => FILTER.includes(el.name) && !inFragment(el) && (el.localName === 'input'));
const described = el => ({ name: el.name, value: el.value, checkable: ['checkbox', 'radio'].includes(el.type), checked: el.checked });

/**
 * The hidden fields of the answer - changed in place, as a script may hold one -, and its filter
 * @param {function(Element): boolean} kept a box or radio the user changed since the save was sent
 */
const takeForm = (form, answered, kept) => {
    const have = hiddenOf(form);
    const left = [...have];
    for (const [name, value] of mergeHidden(have.map(el => [el.name, el.value]), hiddenOf(answered).map(el => [el.name, el.value]))) {
        const i = left.findIndex(el => el.name === name);
        if (i < 0) {
            form.append(Object.assign(document.createElement('input'), { type: 'hidden', name, value }));
        } else {
            left[i].value = value;
            left.splice(i, 1);
        }
    }
    left.forEach(el => el.remove());
    const controls = filterOf(form);
    filterUpdates(controls.map(el => ({ ...described(el), kept: kept(el) })), filterOf(answered).map(described))
        .forEach((update, i) => update && Object.assign(controls[i], update));
};

/** links outside the fragments as the answer has them - the first save names a new draft in them */
const takeLinks = (root, answered) => {
    const outside = scope => [...scope.querySelectorAll('a[href]')].filter(a => !a.closest('[data-aa-isds-fragment]'));
    const [mine, theirs] = [outside(root), outside(answered)];
    if (mine.length === theirs.length) {
        mine.forEach((a, i) => (a.getAttribute('href') !== theirs[i].getAttribute('href')) && a.setAttribute('href', theirs[i].getAttribute('href')));
    }
};

/** a message of the page itself in the fragment result - the answer, if any, had none */
const say = (root, form, message, status, role = 'alert') => {
    const box = resultBox(root, form);
    sources.delete(box);                        // no longer as sent: the next answer replaces it
    box.dataset.aaIsdsStatus = status;
    box.dataset.aaIsdsCode = '';
    box.setAttribute('role', role);
    box.replaceChildren(message);
    return box;
};

const offerReload = (root, box) => {
    const button = Object.assign(document.createElement('button'), { type: 'button', className: 'ai-btn', textContent: text(root, 'reload') });
    button.addEventListener('click', () => location.reload());
    box.append(' ', button);
};

const announce = (root, message) => {
    const live = root.querySelector('[data-aa-isds-live]');
    if (live) {
        live.textContent = message;
    }
};

/** the draft saves itself; other forms wait for it, and none goes twice */
const setupForms = (root) => {
    const form = draftForm(root);
    let sent = new WeakSet();           // forms posted the usual way
    const marked = new Set();           // buttons locked as sent
    const touched = new Map();          // box or radio group of the filter => the change it was made in
    let draft = null;
    let passing = null;                 // the submit the page sends on itself
    let sentAt = 0;                     // when a form went the usual way
    let replaying = false;

    const entriesOf = button => payload([...new FormData(form)], {
        step:   new URL(location.href).searchParams.get('isds_step'),
        // the editor's pending edit included
        bodies: [...form.querySelectorAll('aa-richtext')]
            .map(el => [el.querySelector('textarea[name]')?.name, el.value])
            .filter(([name, value]) => name && (typeof value === 'string')),
        button,
    });
    const bodyOf = (entries) => {
        const body = new FormData();
        entries.forEach(([name, value]) => body.append(name, value));
        return body;
    };
    const keyOf = el => ((el.type === 'radio') ? el.name : `${el.name}\n${el.value}`);
    const showResult = () => {
        const box = resultBox(root, form);
        box.tabIndex = -1;
        box.focus();
    };

    const exchange = async ({ pick, rev }) => {
        const entries = entriesOf(pick);
        let response;
        let html;
        try {
            response = await fetch(location.href, { method: 'POST', body: bodyOf(entries), credentials: 'same-origin', mode: 'same-origin' });
            // a refusal by the server or a proxy (413, 403) would come again and again; a timeout or a limit of requests not
            if ((response.status >= 400) && (response.status < 500) && ![408, 429].includes(response.status)) {
                offerReload(root, say(root, form, `${text(root, 'failed')} (HTTP ${response.status})`, 'refused'));
                return { kind: 'stop' };
            }
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            html = await response.text();
        } catch {
            say(root, form, text(root, 'retry'), 'busy', 'status');
            return { kind: 'retry' };
        }
        // a parsed document runs no script and loads nothing
        const doc    = new DOMParser().parseFromString(html, 'text/html');
        const index  = [...document.querySelectorAll('aa-isds-campaign')].indexOf(root);
        const answer = doc.querySelectorAll('aa-isds-campaign')[Math.max(index, 0)];
        if (!answer) {
            say(root, form, text(root, 'login'), 'expired');
            return { kind: 'stop' };
        }
        const result = answer.querySelector('[data-aa-isds-fragment="result"]');
        const kind   = reaction(result?.dataset.aaIsdsCode ?? '', result?.dataset.aaIsdsStatus ?? '');
        const next   = draftForm(answer);
        const stored = ['saved', 'filter'].includes(kind);
        if ((kind === 'busy') || ((kind === 'again') && !draft.again)) {
            return { kind };
        }
        if (!stored || !next) {
            swapFragments(root, form, answer, ['result']);
            if (kind === 'input') {
                return { kind };
            }
            offerReload(root, resultBox(root, form));
            // only an internal error may pass when tried again; stale, sent or refused stays so
            return { kind: 'stop', stored, retryable: kind === 'again' };
        }
        swapFragments(root, form, answer, null);
        // the lists of picks are the server's alone; a box or radio changed since the save went stays, unless the filter was refused
        takeForm(form, next, el => (kind === 'saved') && ((touched.get(keyOf(el)) ?? 0) > rev));
        takeLinks(root, answer);
        const url = pageUrl(response.url, location.href);
        if (url) {
            history.replaceState(history.state, '', url);
        }
        const box = root.querySelector('[data-aa-isds-fragment="result"]');
        if (kind === 'filter') {
            box?.setAttribute('role', 'alert');
        } else {
            announce(root, box?.textContent.trim() ?? '');
        }
        if (box && (entries.find(([name]) => name === 'isds_body')?.[1].length > BIG_BODY)) {
            sources.delete(box);
            box.append(Object.assign(document.createElement('p'), { className: 'ai-help', textContent: text(root, 'big') }));
        }
        return { kind };
    };

    /** a form went the usual way and the page stayed: it is in use again */
    const reset = () => {
        draft?.resume();
        marked.forEach(el => setLock(el, false));
        marked.clear();
        sent = new WeakSet();
        replaying = true;                       // the confirmations locked again
        root.querySelectorAll('input[data-aa-isds-confirm]').forEach(el => el.dispatchEvent(new Event('input', { bubbles: true })));
        replaying = false;
    };
    /**
     * Typing into the letter or the filter 2 s after a form went the usual way: the page did not leave
     * (Esc, an answer that stays). Not sooner, and no other key - a second Enter in the confirmation
     * while the page loads must not unlock its form.
     */
    const back = () => {
        if ((draft.leaving || marked.size) && !replaying && ((Date.now() - sentAt) > 2_000)) {
            reset();
        }
    };

    if (form) {
        draft = new Autosave(exchange);
        const kindOf = (el) => {
            if (el?.form !== form) {
                return null;
            }
            if (LETTER.includes(el.name) || el.closest('[data-aa-isds-field]')) {
                return 'letter';
            }
            return FILTER.includes(el.name) ? 'filter' : null;
        };
        root.addEventListener('input', (e) => {
            if (kindOf(e.target) === 'letter') {
                back();
                draft.changed('letter');
            }
        });
        // change: a box or radio of the filter, or a field left - the editor announces its blur so too
        root.addEventListener('change', (e) => {
            const kind = kindOf(e.target);
            if (kind === 'filter') {
                back();
                draft.changed('filter');
                if (['checkbox', 'radio'].includes(e.target.type)) {
                    touched.set(keyOf(e.target), draft.rev);
                }
            } else if (kind === 'letter') {
                draft.changed('left');
            }
        });
        // the editor types into its textarea with keypress, which does not bubble
        root.addEventListener('keypress', (e) => {
            if (e.target.closest?.('aa-richtext') && (kindOf(e.target) === 'letter')) {
                back();
                draft.changed('letter');
            }
        }, true);
        // a link of the page (tabs, the pager) saves first, so the warning below is left for real losses
        root.addEventListener('click', (e) => {
            const link = e.target.closest?.('a[href]');
            if (!link || e.defaultPrevented || e.button || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || link.target || link.hasAttribute('download')
                || !draft.dirty || draft.stopped || !sameOrigin(link.href, location.href) || (link.href.split('#')[0] === location.href.split('#')[0])) {
                return;
            }
            e.preventDefault();
            draft.flush().then((ok) => {
                draft.leaving = ok;
                location.assign(link.href);
            });
        });
        addEventListener('beforeunload', (e) => {
            if (draft.dirty && !draft.leaving) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        // After the warning was passed: the last change, if it fits a request that outlives the page.
        // Only a bonus - keepalive is not in every browser of the target yet; the warning stays the guard.
        addEventListener('pagehide', () => {
            if (!draft.dirty || draft.running || draft.stopped || draft.leaving) {
                return;
            }
            const entries = entriesOf(null);
            if (payloadSize(entries) < KEEPALIVE_MAX) {
                fetch(location.href, { method: 'POST', body: bodyOf(entries), credentials: 'same-origin', mode: 'same-origin', keepalive: true }).catch(() => {});
            }
        });
    }

    /** what tells a form of an action from another: its action and the row it is for */
    const signature = f => [f.dataset.aaIsdsForm, ...['isds_action', 'isds_campaign', 'isds_message', 'isds_recipient']
        .map(name => [...f.elements].find(el => el.name === name)?.value ?? '')].join('\n');

    /** sends on the form waited for - its twin if a save swapped it - with the same button */
    const goOn = (target, sig, submitter) => {
        const again = target.isConnected ? target : [...root.querySelectorAll('form[data-aa-isds-form]')].find(f => signature(f) === sig);
        if (!again) {
            showResult();
            return;
        }
        const button = submitter && [...again.elements].find(el => isSubmit(el) && (el.name === submitter.name) && (el.value === submitter.value));
        passing = again;
        try {
            again.requestSubmit(button || null);
        } finally {
            passing = null;             // the submit event ran within; a form the browser found invalid sends none
        }
    };

    /** a step posts the letter itself; it goes only with no pick waiting and no text the server refused or would refuse */
    const stepOk = () => !draft.picks.length && !(draft.dirty && (draft.stopped || (draft.rev === draft.blocked)));

    root.addEventListener('submit', (e) => {
        const target = e.target;
        if (!target.matches?.('form[data-aa-isds-form]')) {
            return;
        }
        const submitter = e.submitter ?? null;
        if (passing !== target) {
            if ((submitter && locked.has(submitter)) || sent.has(target)) {
                e.preventDefault();
                return;
            }
            const step = (target === form) && (submitter?.name === 'isds_step');
            if (draft && (target === form) && !step && draft.stopped && !draft.retryable) {
                // stale, sent, refused or logged out: the server would answer the same and the text left would be lost
                e.preventDefault();
                showResult();
                return;
            }
            if (draft && (target === form) && !step && !draft.stopped) {
                // Uložit, Enter, a pick: saved in place
                e.preventDefault();
                draft.ask(((submitter?.name === 'isds_pick') && submitter.value) ? ['isds_pick', submitter.value] : null);
                return;
            }
            if (draft && (step ? (draft.running || !stepOk()) : ((target !== form) && draft.dirty))) {
                e.preventDefault();
                const sig   = signature(target);
                const ready = step
                    ? (async () => {
                        if (draft.running) {
                            await draft.running;
                        }
                        if (draft.picks.length && !draft.stopped) {
                            await draft.flush();
                        }
                        return stepOk();
                    })()
                    : draft.flush();
                ready.then(ok => (ok ? goOn(target, sig, submitter) : showResult()));
                return;
            }
        }
        sent.add(target);
        if (draft) {
            draft.leaving = true;
        }
        sentAt = Date.now();
        if (submitter && isSubmit(submitter)) {
            setLock(submitter, true);
            marked.add(submitter);
        }
    });

    // back from the history cache
    addEventListener('pageshow', (e) => {
        if (e.persisted) {
            reset();
        }
    });
};

/** what each root is set up with, in order */
const SETUPS = Object.freeze([setupSources, setupSticky, setupBar, setupConfirm, setupLetter, setupForms]);

/**
 * Sets a root up once; any later call for the same root does nothing.
 * A setup that fails switches off only its own feature - the forms work without it.
 * @param {Element} root
 * @param {ReadonlyArray<function(Element): void>} setups
 * @returns {boolean} whether it was set up by this call
 */
export const initRoot = (root, setups = SETUPS) => {
    if (done.has(root)) {
        return false;
    }
    done.add(root);
    for (const setup of setups) {
        try {
            setup(root);
        } catch (e) {
            console.error('aa-isds:', e);
        }
    }
    root.setAttribute('ready', '');
    return true;
};

if (globalThis.customElements && !customElements.get('aa-isds-campaign')) {
    customElements.define('aa-isds-campaign', class extends HTMLElement {
        connectedCallback() {
            initRoot(this);
        }
    });
}
