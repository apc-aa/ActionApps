// A page that shows batch runs drives them: <aa-batchrun> asks the page for the due step again and again
// (BatchRun::pageLook() on the server), so the steps go one right after another instead of one a queue
// round, and while a step runs it looks at the progress. The page answers JSON {sum, html?, wait?}:
// html of the element's content when it differs from the one with the sum sent as "seen", wait = seconds
// till the next step (null = nothing goes on). Attributes: url, token, run, lost (the text when the page
// stops answering), open (start driving).

/** ms between looks while a step runs */
export const LOOK_EVERY = 3_000;
/** ms a step request may take - a step lasts about 30 s; a look ends with its step */
export const STEP_TIMEOUT = 120_000;
/** ms till the next try after a request that failed on the way */
export const RETRY = 30_000;

/** the answer as an object, null for anything else - the login page after the session expired */
export const parse = (text) => {
    try {
        const value = JSON.parse(text);
        return ((value !== null) && (typeof value === 'object')) ? value : null;
    } catch {
        return null;
    }
};

/** ms till the next step: null = stop */
export const nextStep = (wait) => ((typeof wait === 'number') ? Math.max(0, wait) * 1000 : null);

export class Driver {
    /**
     * @param {HTMLElement} el
     * @param {{fetch?: Function, setTimeout?: Function, clearTimeout?: Function}} env
     */
    constructor(el, env = {}) {
        this.el = el;
        this.fetch = env.fetch ?? globalThis.fetch.bind(globalThis);
        this.setTimeout = env.setTimeout ?? globalThis.setTimeout.bind(globalThis);
        this.clearTimeout = env.clearTimeout ?? globalThis.clearTimeout.bind(globalThis);
        this.seen = '';
        this.pending = null;
        // a report being read is not replaced under the reader - what came meanwhile shows once it is closed
        el.addEventListener('toggle', () => {
            if ((this.pending !== null) && !this.reading()) {
                this.el.innerHTML = this.pending;
                this.pending = null;
            }
        }, true);
    }

    reading() {
        return this.el.querySelector('details[open]') !== null;
    }

    /** @returns {Promise<{status: number, data: ?object}>} */
    async ask(look, signal) {
        const body = new URLSearchParams({
            action: 'step', token: this.el.getAttribute('token') ?? '', run: this.el.getAttribute('run') ?? '', look: look ? '1' : '', seen: this.seen,
        });
        const response = await this.fetch(this.el.getAttribute('url'), {method: 'POST', body, headers: {Accept: 'application/json'}, signal});
        return {status: response.status, data: response.ok ? parse(await response.text()) : null};
    }

    show(data) {
        if (typeof data.html !== 'string') {
            return;
        }
        this.seen = String(data.sum ?? '');
        if (this.reading()) {
            this.pending = data.html;
        } else {
            this.el.innerHTML = data.html;
            this.pending = null;
        }
    }

    lost() {
        this.pending = null;     // it would replace the note
        const note = this.el.ownerDocument.createElement('div');
        note.className = 'aa-err';
        note.textContent = this.el.getAttribute('lost') ?? '';
        this.el.prepend(note);
    }

    /** one step with looks while it runs @returns {Promise<?number>} ms till the next step, null = stop */
    async step() {
        const looks = new AbortController();
        let timer = null;
        const look = async () => {
            try {
                const answer = await this.ask(true, looks.signal);
                if (!looks.signal.aborted && answer.data) {
                    this.show(answer.data);
                }
            } catch {
                // the next look tries again
            }
            if (!looks.signal.aborted) {
                timer = this.setTimeout(look, LOOK_EVERY);
            }
        };
        timer = this.setTimeout(look, LOOK_EVERY);
        let answer = null;
        try {
            answer = await this.ask(false, AbortSignal.timeout?.(STEP_TIMEOUT));
        } catch {
            return RETRY;            // the connection: the queue goes on meanwhile
        } finally {
            looks.abort();
            this.clearTimeout(timer);
        }
        if (answer.data === null) {
            this.lost();
            return null;
        }
        this.show(answer.data);
        return nextStep(answer.data.wait);
    }

    async run() {
        const wait = await this.step();
        if (wait !== null) {
            this.setTimeout(() => this.run(), wait);
        }
    }
}

if (globalThis.customElements && !customElements.get('aa-batchrun')) {
    customElements.define('aa-batchrun', class extends HTMLElement {
        connectedCallback() {
            if (this.hasAttribute('open') && !this.driver) {
                this.driver = new Driver(this);
                this.driver.run();
            }
        }
    });
}
