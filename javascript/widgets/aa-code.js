/**
 * <aa-code> — a CodeMirror 6 editor around a plain <textarea>.
 *
 *   <aa-code syntax="html"><textarea name="..." ...></textarea></aa-code>
 *
 * The textarea stays and is what gets submitted: AA's serializers only see native
 * form controls, and without JavaScript the field is still usable.
 * customElements.define() upgrades elements already in the document, so
 * AJAX-rendered forms need no init hook.
 */

// Carry our own ?v= over, so one AA_JS_VERSION bump busts both.
const BUNDLE_URL = (() => {
    const self = new URL(import.meta.url);
    const url  = new URL('../codemirror6.min.js', self);
    url.search = self.search;
    return url;
})();

/** the value last announced by a change event, per textarea - <aa-richtext> puts the
 *  same textarea into an <aa-code> for its source view, and one edit must announce once */
const announced = new WeakMap();

const NATIVE_VALUE = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value');

/** who shows a textarea's value, per textarea - in the source view of <aa-richtext> both
 *  it and the <aa-code> inside listen, only the one showing an editor acts */
const watchers = new WeakMap();

/**
 * Scripts write textarea.value (templates, jQuery .val(), the translate button) and the
 * editor over the textarea has to show it. There is no event for that, so the textarea
 * gets its own value property that tells the watchers after the native write.
 * @return {Function} stops watching
 */
function watchValue(textarea, fn) {
    let fns = watchers.get(textarea);
    if (!fns) {
        fns = new Set();
        watchers.set(textarea, fns);
        Object.defineProperty(textarea, 'value', {
            configurable: true,
            get() { return NATIVE_VALUE.get.call(this); },
            set(v) {
                NATIVE_VALUE.set.call(this, v);
                const now = NATIVE_VALUE.get.call(this);
                fns.forEach(f => f(now));
            },
        });
    }
    fns.add(fn);
    return () => {
        fns.delete(fn);
        if (!fns.size) {
            delete textarea.value;          // the prototype's property again
            watchers.delete(textarea);
        }
    };
}

/** forms whose submit the user is starting - only then an invalid editor takes the focus;
 *  checkValidity() from a script shows nothing, as with a native control */
const submitting = new WeakSet();
const watchedForms = new WeakSet();
const SUBMITTER = 'button:not([type]), button[type=submit], input[type=submit], input[type=image]';

function watchSubmit(form) {
    if (!form || watchedForms.has(form)) {
        return;
    }
    watchedForms.add(form);
    const mark = () => {
        submitting.add(form);
        setTimeout(() => submitting.delete(form));   // validation runs in the same task
    };
    form.addEventListener('click', (e) => {
        if (e.target.closest?.(SUBMITTER)) {
            mark();
        }
    }, true);
    form.addEventListener('keydown', (e) => {
        if ((e.key === 'Enter') && (e.target.tagName === 'INPUT')) {
            mark();                             // implicit submission
        }
    }, true);
}

/**
 * Keeps the named <textarea> an editor stands in for right - it is what forms,
 * AA__Serialize() and AA_SendWidgetLive() read:
 *  - write() on every edit: AA calls form.submit() in many places, which fires no
 *    submit event, so there is no later moment to copy the value;
 *  - commit() fires change once per edit, when focus leaves the editor, as a native
 *    textarea does - in live mode change is a save to the server;
 *  - a value a script writes into the textarea, or form.reset(), goes to onExternal; it is
 *    not announced - a script that wants it saved fires change itself, as it must on a
 *    bare textarea;
 *  - a hidden required textarea gets no browser bubble and the submit would just not
 *    happen: the host shows the message and the editor takes the focus;
 *  - focus entering and leaving the host fires focus / blur on the textarea.
 */
export class TextareaSync {
    #textarea;
    #host;
    #focus;
    #message = null;
    #abort = new AbortController();
    #unwatch = null;

    /**
     * @param {HTMLTextAreaElement} textarea
     * @param {{host?: HTMLElement, onExternal?: (value: string) => boolean, focus?: () => void, focusEvents?: boolean}} [options]
     *        onExternal returns whether the value came from outside the editor (not its own mirror);
     *        focusEvents: false when the host fires focus / blur itself (an editor in an iframe)
     */
    constructor(textarea, { host = null, onExternal = null, focus = null, focusEvents = true } = {}) {
        this.#textarea = textarea;
        this.#host     = host;
        this.#focus    = focus;
        if (!announced.has(textarea)) {
            announced.set(textarea, textarea.value);
        }
        const signal = this.#abort.signal;
        // a native change (the bare textarea of the plain view) announces too
        textarea.addEventListener('change', () => announced.set(textarea, textarea.value), { signal });
        // leaving without a blur (keyboard, history); AA_SendWidgetLive sends with keepalive
        window.addEventListener('pagehide', () => this.commit(), { signal });
        if (textarea.classList.contains('live')) {
            window.AA_LiveGuard?.();
        }
        if (onExternal) {
            const external = (value) => {
                if (onExternal(value)) {
                    announced.set(textarea, value);
                }
                this.#checkValid();
            };
            this.#unwatch = watchValue(textarea, external);
            // the reset event comes before the form resets its controls
            textarea.form?.addEventListener('reset', () => setTimeout(() => external(textarea.value)), { signal });
        }
        if (host) {
            watchSubmit(textarea.form);
            textarea.addEventListener('invalid', e => this.#onInvalid(e), { signal });
            textarea.addEventListener('input', () => this.#checkValid(), { signal });   // typed into the bare textarea
        }
        if (host && focusEvents) {
            // focus handlers on the textarea (aa_onFocus of the slice's field JavaScript) hear the editor;
            // the innermost host speaks, a bare textarea fires its own
            const forward = type => (e) => {
                if ((e.target !== textarea) && !host.contains(e.relatedTarget) && (textarea.closest('aa-code, aa-richtext') === host)) {
                    if (type === 'blur') {
                        this.commit();              // change comes before blur, as on a textarea
                    }
                    textarea.dispatchEvent(new FocusEvent(type));
                }
            };
            host.addEventListener('focusin', forward('focus'), { signal });
            host.addEventListener('focusout', forward('blur'), { signal });
        }
    }

    /** @param {string} value */
    write(value) {
        if (NATIVE_VALUE.get.call(this.#textarea) !== value) {
            NATIVE_VALUE.set.call(this.#textarea, value);   // not the watchers: the editor already shows it
        }
        this.#checkValid();
    }

    commit() {
        const value = this.#textarea.value;
        if (!this.#textarea.isConnected || announced.get(this.#textarea) === value) {
            return;
        }
        announced.set(this.#textarea, value);
        // on the textarea: AA hangs onchange="AA_SendWidgetLive(...)" there, and events bubble up, not down
        this.#textarea.dispatchEvent(new Event('change', { bubbles: true }));
    }

    destroy() {
        this.#abort.abort();
        this.#unwatch?.();
        this.clearInvalid();
    }

    #onInvalid(e) {
        const ta = this.#textarea;
        // only while an editor of this host stands in for the textarea; a visible one gets the browser's bubble
        if (!ta.hidden || (ta.closest('aa-code, aa-richtext') !== this.#host)) {
            return;
        }
        if (ta.form && !submitting.has(ta.form)) {
            return;
        }
        e.preventDefault();
        this.#host.setAttribute('invalid', '');
        if (!this.#message) {
            this.#message = document.createElement('p');
            this.#message.className = 'aa-invalid-msg';
            this.#message.setAttribute('role', 'alert');
            this.#host.after(this.#message);
        }
        this.#message.textContent = ta.validationMessage;
        // the first invalid field of the form takes the focus, as the browser does it;
        // live fields (form="") only show the message
        if (ta.form && ([...ta.form.elements].find(el => el.willValidate && !el.validity.valid) === ta)) {
            this.#host.scrollIntoView({ block: 'center' });
            this.#focus?.();
        }
    }

    #checkValid() {
        if (this.#message && this.#textarea.validity.valid) {
            this.clearInvalid();
        }
    }

    /** The message of a failed submit away - when the view it points at is gone. */
    clearInvalid() {
        this.#message?.remove();
        this.#message = null;
        this.#host?.removeAttribute('invalid');
    }
}

class AaCodeElement extends HTMLElement {
    #editor = null;
    #textarea = null;
    #sync = null;
    #onFocusout = (e) => {
        // moving inside the element (the search panel) does not end the edit
        if (!this.contains(e.relatedTarget)) {
            this.#sync?.commit();
        }
    };

    async connectedCallback() {
        if (this.#editor || this.#textarea) {
            return;                         // already upgraded (or moved in the DOM)
        }
        this.#textarea = this.querySelector('textarea');
        if (!this.#textarea) {
            return;                         // nothing to enhance
        }

        let createEditor;
        try {
            ({ createEditor } = await import(BUNDLE_URL));
        } catch (e) {
            // Leave the textarea visible and usable rather than a dead box.
            console.error('aa-code: could not load the editor bundle', e);
            this.#textarea = null;
            return;
        }
        if (!this.isConnected || this.#editor) {
            return;                         // removed or raced while importing
        }

        this.#sync = new TextareaSync(this.#textarea, {
            host:       this,
            focus:      () => this.focus(),
            // the bundle mirrors its own edits into the textarea too - those match already
            onExternal: (value) => {
                if (!this.#editor) {
                    return true;
                }
                if (this.#editor.getValue() === value) {
                    return false;
                }
                this.#editor.setValue(value);
                return true;
            },
        });
        // "syntax" here, "language" in the bundle (CodeMirror's own term): on
        // multilingual fields "language" would sit next to real cs/en/de codes.
        // The bundle writes every change into the textarea itself.
        this.#editor = createEditor(this.#textarea, { language: this.getAttribute('syntax') || 'html' });
        this.addEventListener('focusout', this.#onFocusout);
        this.#textarea.hidden = true;
        // Bare attribute: a custom element owns its own attribute set. Becomes
        // states.add('ready') once :state() is Baseline Widely Available.
        this.setAttribute('ready', '');
    }

    disconnectedCallback() {
        // matters when <aa-richtext> takes the textarea out first; a removed textarea announces nothing
        this.#sync?.commit();
        this.#sync?.destroy();
        this.#editor?.destroy();
        this.removeEventListener('focusout', this.#onFocusout);
        if (this.#textarea) {
            this.#textarea.hidden = false;  // <aa-richtext> takes the textarea out again
        }
        this.#editor = null;
        this.#sync = null;
        this.#textarea = null;
        this.removeAttribute('ready');
    }

    /** Current content. Falls back to the textarea while the bundle is loading. */
    get value() {
        return this.#editor ? this.#editor.getValue() : (this.#textarea?.value ?? this.querySelector('textarea')?.value ?? '');
    }

    /** A finished edit (the translate button) - announced at once, live mode saves it. */
    set value(value) {
        if (this.#editor) {
            this.#editor.setValue(value);   // the bundle mirrors it into the textarea
            this.#sync.commit();
            return;
        }
        const ta = this.#textarea ?? this.querySelector('textarea');
        if (ta) {
            ta.value = value;
            announced.set(ta, value);
            ta.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    /** Announces the pending edit - AA_LiveGuard() calls it before the page is left. */
    flush() {
        this.#sync?.commit();
    }

    focus() {
        if (this.#editor) {
            this.#editor.focus();
        } else {
            this.#textarea?.focus();
        }
    }
}

if (!customElements.get('aa-code')) {
    customElements.define('aa-code', AaCodeElement);
}
