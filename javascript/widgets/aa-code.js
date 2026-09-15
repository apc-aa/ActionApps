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

class AaCodeElement extends HTMLElement {
    #editor = null;
    #textarea = null;

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

        this.#editor = createEditor(this.#textarea, {
            // "syntax" here, "language" in the bundle (CodeMirror's own term): on
            // multilingual fields "language" would sit next to real cs/en/de codes.
            language: this.getAttribute('syntax') || 'html',
            onChange: () => {
                // Must originate on the textarea - that is where AA hangs
                // onchange="AA_SendWidgetLive(...)", and events bubble up, not down.
                this.#textarea.dispatchEvent(new Event('change', { bubbles: true }));
            },
        });
        this.#textarea.hidden = true;
        // Bare attribute: a custom element owns its own attribute set. Becomes
        // states.add('ready') once :state() is Baseline Widely Available.
        this.setAttribute('ready', '');
    }

    disconnectedCallback() {
        this.#editor?.destroy();
        this.#editor = null;
        this.#textarea = null;
        this.removeAttribute('ready');
    }

    /** Current content. Falls back to the textarea while the bundle is loading. */
    get value() {
        return this.#editor ? this.#editor.getValue() : (this.#textarea?.value ?? this.querySelector('textarea')?.value ?? '');
    }

    set value(value) {
        if (this.#editor) {
            this.#editor.setValue(value);   // the editor mirrors it into the textarea
            return;
        }
        const ta = this.#textarea ?? this.querySelector('textarea');
        if (ta) {
            ta.value = value;
        }
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
