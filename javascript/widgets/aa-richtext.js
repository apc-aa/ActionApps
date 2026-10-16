/**
 * <aa-richtext> — rich-text editing (TinyMCE 8) around a plain <textarea>, with the HTML
 * source in CodeMirror 6 (<aa-code>).
 *
 *   <aa-richtext mode="rich|code|plain" editor switch-name="…" switch-html="1"
 *                upload="user|anon" upload-url="…" upload-token="…" slice="…"
 *                upload-images="jpg,png,…" upload-types='[…]'
 *                ui-lang="cs" l10n='{"editor":"Editor","source":"Zdroj",…}'>
 *     <textarea name="…">…</textarea>
 *   </aa-richtext>
 *
 * The named textarea stays the one thing forms, AA__Serialize() and AA_SendWidgetLive()
 * read. TinyMCE edits an unnamed copy and writes back only after a real edit, so a field
 * opened and saved unchanged keeps its HTML byte for byte.
 *
 * rich = TinyMCE; code = the textarea inside <aa-code syntax="html">; plain = the bare
 * textarea. The HTML / Plain text radios (switch-name, switch-html = the value meaning
 * HTML) pick code or plain; the "Edit in Editor" link ([data-aa-editor-for=<textarea id>])
 * and the Editor button of the source view pick rich; TinyMCE's Source button picks code.
 */

// Carry our own ?v= over, so one AA_JS_VERSION bump busts every module loaded from here.
const sibling = (path) => {
    const self = new URL(import.meta.url);
    const url  = new URL(path, self);
    url.search = self.search;
    return url;
};

/**
 * TinyMCE sits in a shadow root: page CSS written for the page's own markup (".row div:last-child
 * { flex: 0 0 592px }") would otherwise reach its divs and squeeze the edit area to nothing.
 * Only inherited properties get in; the skin and this look are set inside.
 */
let shadowSheet = null;
const editorSheet = () => {
    if (!shadowSheet) {
        shadowSheet = new CSSStyleSheet();
        // CKEditor 4 look: gray bar, framed groups, small buttons - two rows at 920 px; a switched-off
        // field keeps TinyMCE's grey buttons and dims like <aa-code>
        shadowSheet.replaceSync(`
:host { display: block; }
:host .tox.tox-tinymce { margin: 2px; border: 1px solid #b6b6b6; border-radius: 0; }
:host .tox.tox-tinymce .tox-editor-header { padding: 2px 0; background: linear-gradient(#f8f8f8, #e8e8e8); border-bottom: 1px solid #b6b6b6; box-shadow: none; }
:host .tox-tinymce .tox-toolbar-overlord, :host .tox-tinymce .tox-toolbar__primary, :host .tox-tinymce .tox-toolbar { background: transparent; border: 0; }
:host .tox-tinymce .tox-toolbar__group { margin: 3px 3px 2px 5px; padding: 0 2px; background: linear-gradient(#fff, #e4e4e4); border: 1px solid #b6b6b6; border-radius: 3px; }
:host .tox-tinymce .tox-tbtn { width: 26px; height: 24px; margin: 1px 0; border-radius: 2px; }
:host .tox-tinymce .tox-tbtn svg { transform: scale(.8); }
:host .tox-tinymce .tox-tbtn:not(:disabled, .tox-tbtn--disabled) svg { fill: #474747; }
:host .tox-tinymce--disabled .tox-edit-area { opacity: .7; }
:host .tox-tinymce .tox-tbtn--select, :host .tox-tinymce .tox-split-button .tox-tbtn, :host .tox-tinymce .tox-tbtn:has(.tox-tbtn__select-label) { width: auto; }
:host .tox-tinymce .tox-tbtn__select-label { font: 12px Arial, sans-serif; }
:host .tox-tinymce .tox-tbtn--bespoke .tox-tbtn__select-label { width: 4.6em; }
:host .tox-tinymce .tox-split-button { height: 24px; margin: 1px 0; }
:host .tox-tinymce .tox-statusbar { background: #e8e8e8; border-top: 1px solid #d1d1d1; }
`);
    }
    return shadowSheet;
};

class AaRichtextElement extends HTMLElement {
    #textarea = null;           // the named one
    #sync = null;
    #mode = null;               // the view shown
    #target = null;             // the view last asked for
    #queue = Promise.resolve();
    #rich = null;               // {host, api}
    #code = null;               // <aa-code>
    #back = null;               // Editor button of the source view
    #wasRich = false;
    #switches = [];
    #links = [];
    #abort = null;
    #l10n = {};
    #writeQueued = false;
    #connection = null;         // a removal and reconnect while loading starts a new one
    #editorAsked = false;       // showEditor() before the element was set up
    #focused = false;           // the editor announced focus on the textarea

    async connectedCallback() {
        if (this.#textarea) {
            return;
        }
        const textarea = this.querySelector(':scope > textarea');
        if (!textarea) {
            return;
        }
        this.#textarea = textarea;
        const connection = {};
        this.#connection = connection;
        let TextareaSync;
        try {
            ({ TextareaSync } = await import(sibling('./aa-code.js')));   // defines <aa-code> too
        } catch (e) {
            console.error('aa-richtext: could not load aa-code.js', e);
            this.#textarea = null;
            return;
        }
        if (this.#connection !== connection) {
            return;                             // removed (and maybe put back) while importing
        }
        this.#sync  = new TextareaSync(textarea, {
            host:       this,                   // in the source view the inner <aa-code> reports instead
            focus:      () => this.#rich?.api?.focus(),
            focusEvents: false,                 // focus in TinyMCE's iframe does not reach this document: its own events
            onExternal: (value) => {
                if (this.#code?.hasAttribute('ready')) {
                    return false;               // <aa-code> shows it and counts it itself
                }
                this.#rich?.api?.load(value);   // a TinyMCE still starting catches up once ready
                return true;
            },
            // TinyMCE follows here; the source view's <aa-code> and the plain textarea follow by themselves
            onDisabled: (disabled) => {
                this.#rich?.api?.setDisabled(disabled);
                this.toggleAttribute('disabled', disabled);
            },
        });
        this.#abort = new AbortController();
        // Reset puts the format radios back without a change event
        textarea.form?.addEventListener('reset', () => setTimeout(() => this.#afterReset()), { signal: this.#abort.signal });
        try {
            this.#l10n = JSON.parse(this.getAttribute('l10n') || '{}');
        } catch {
            this.#l10n = {};
        }
        this.#bindSwitch();
        this.#bindEditorLinks();
        if (this.#editorAsked) {
            this.#editorAsked = false;
            await this.showEditor();
        }
        if (!this.#target) {                    // no editor asked for, or the field is switched off
            await this.#setMode(this.getAttribute('mode') || 'plain');
        }
        this.setAttribute('ready', '');
    }

    disconnectedCallback() {
        this.#connection = null;
        this.#editorAsked = false;
        this.#abort?.abort();
        this.#leave();                          // the textarea back in place, for a reconnect
        this.#queue = Promise.resolve();        // a TinyMCE starting on the removed copy never finishes
        this.#sync?.destroy();
        this.#textarea = null;
        this.#sync = null;
        this.#target = null;
        this.#switches = [];
        this.#links = [];
        this.removeAttribute('ready');
        this.removeAttribute('disabled');
    }

    /** Current content, the editor's pending edit included. */
    get value() {
        this.#flushRich();
        return (this.#textarea ?? this.querySelector('textarea'))?.value ?? '';
    }

    /** A finished edit (the translate button): shown in the current view, announced at once. */
    set value(value) {
        if (this.#code) {
            this.#code.value = value;           // <aa-code> announces it itself
            return;
        }
        if (this.#rich?.api) {
            this.#rich.api.setContent(value);
            this.#flushRich();
        } else if (this.#sync) {
            this.#sync.write(value);            // past the watcher, which would count it as announced
        } else if (this.#textarea) {
            this.#textarea.value = value;
        }
        this.#sync?.commit();
    }

    /** Writes and announces the pending edit - AA_LiveGuard() calls it before the page is left. */
    flush() {
        this.#flushRich();
        this.#sync?.commit();
    }

    /** The "Edit in Editor" link: HTML format, the switch and the link hidden - as with CKEditor 4. */
    showEditor() {
        if (!this.#sync) {
            this.#editorAsked = true;           // a link clicked while the element loads
            return Promise.resolve();
        }
        if (this.#textarea.matches(':disabled')) {
            return Promise.resolve();           // the format switch belongs to the field, which is switched off
        }
        const done = this.#setMode('rich');
        const html = this.getAttribute('switch-html') ?? '1';
        this.#switches.forEach((el) => {
            if (el.type === 'hidden') {
                el.value = html;                // ajax and live forms carry the format hidden
            } else if ((el.type === 'radio') && (el.value === html) && !el.checked) {
                el.checked = true;
                // the other translations of the field follow into the HTML view
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        this.#formatBoxes().forEach((box) => { box.style.display = 'none'; });
        this.#links.forEach((link) => { link.style.display = 'none'; });
        return done;
    }

    #afterReset() {
        if (!this.#switches.length) {
            return;
        }
        const html    = this.getAttribute('switch-html') ?? '1';
        const checked = this.#switches.find(el => (el.type === 'hidden') || el.checked);
        this.#formatBoxes().forEach((box) => { box.style.display = ''; });
        this.#links.forEach((link) => { link.style.display = ''; });
        this.#setMode((checked?.value === html) ? 'code' : 'plain');
    }

    /** what holds the HTML / Plain text switch; hand-written forms name it htmlplainspan<textarea id> */
    #formatBoxes() {
        const id = this.#textarea?.id;
        return [...new Set([...this.#switches.map(el => el.closest('.aa-formatters')), id && document.getElementById(`htmlplainspan${id}`)].filter(Boolean))];
    }

    #bindSwitch() {
        const name = this.getAttribute('switch-name');
        if (!name) {
            return;
        }
        const html = this.getAttribute('switch-html') ?? '1';
        this.#switches = [...document.getElementsByName(name)];
        this.#switches.filter(el => el.type === 'radio').forEach(radio => radio.addEventListener('change', () => {
            if (!radio.checked) {
                return;
            }
            const mode = (radio.value === html) ? 'code' : 'plain';
            if ((mode === 'code') && (this.#target === 'rich')) {
                return;                         // HTML stays in the editor
            }
            this.#setMode(mode);
        }, { signal: this.#abort.signal }));
    }

    #bindEditorLinks() {
        if (!this.hasAttribute('editor') || !this.#textarea.id) {
            return;
        }
        // hand-written forms (custom input forms, old anonymous forms) have only arealinkspan<textarea id>
        const legacy = document.getElementById(`arealinkspan${this.#textarea.id}`);
        this.#links = [...new Set([...document.querySelectorAll(`[data-aa-editor-for="${CSS.escape(this.#textarea.id)}"]`), legacy].filter(Boolean))];
        this.#links.forEach((link) => {
            link.style.display = '';            // rendered hidden: without this element it opens nothing
            link.addEventListener('click', (e) => {
                e.preventDefault();
                this.showEditor();
            }, { signal: this.#abort.signal });
        });
    }

    #setMode(mode) {
        this.#target = mode;
        this.#queue  = this.#queue.then(() => this.#apply(mode)).catch(e => console.error('aa-richtext', e));
        return this.#queue;
    }

    async #apply(mode) {
        if ((mode !== this.#target) || (mode === this.#mode) || !this.#textarea) {
            return;                             // superseded by a later request, or already there
        }
        this.#leave();
        this.#mode = mode;
        this.setAttribute('mode', mode);
        if (mode === 'code') {
            this.#enterCode();
        } else if (mode === 'rich') {
            await this.#enterRich();
        }
    }

    #leave() {
        this.#sync?.clearInvalid();             // its message points at the view going away
        if (this.#rich) {
            this.#flushRich();
            this.#rich.api?.destroy();
            this.#rich.host.remove();
            this.#rich = null;
            if (this.#textarea) {
                this.#textarea.hidden = false;
            }
        }
        if (this.#code) {
            this.#code.before(this.#textarea);  // out first, or <aa-code> would take it along
            this.#code.remove();                // ... which also unhides it
            this.#code = null;
        }
        this.#back?.remove();
        this.#back = null;
        this.#mode = null;
    }

    #enterCode() {
        const code = document.createElement('aa-code');
        code.setAttribute('syntax', 'html');
        const [parent, next] = [this.#textarea.parentNode, this.#textarea.nextSibling];
        code.append(this.#textarea);            // before connecting: <aa-code> looks for it only then
        parent.insertBefore(code, next);
        this.#code = code;
        if (this.#wasRich) {                    // the way back to the editor the user came from
            const back = document.createElement('button');
            back.type = 'button';
            back.className = 'btn aa-arealink aa-richtext-editor';
            // named and placed as the Edit in Editor link it stands in for, which is hidden now
            back.textContent = this.#links.map(link => link.textContent.trim()).find(Boolean) ?? this.#l10n.editor ?? 'Edit in Editor';
            back.addEventListener('click', () => this.#setMode('rich'));
            code.before(back);
            this.#back = back;
        }
    }

    async #enterRich() {
        // TinyMCE writes into its target by itself (on submit, on save()), so it gets a copy
        // without a name: the named textarea changes only after a real edit
        const copy = document.createElement('textarea');
        const start = this.#textarea.value;
        copy.value = start;
        copy.placeholder = this.#textarea.placeholder;
        const host = document.createElement('aa-richtext-frame');
        const root = host.attachShadow({ mode: 'open' });
        root.adoptedStyleSheets = [editorSheet()];
        root.append(copy);
        this.#textarea.after(host);
        this.#textarea.hidden = true;
        const state = { host, api: null };
        this.#rich = state;
        let api;
        try {
            const { createRichEditor } = await import(sibling('../tinymce8.min.js'));
            if (this.#rich !== state) {
                return;                         // left while loading - never start on a removed copy
            }
            api = await createRichEditor(copy, {
                uiLang: this.getAttribute('ui-lang') || document.documentElement.lang,
                height: Math.min(700, Math.max(300, (this.#textarea.rows || 10) * 20 + 120)),
                sourceLabel: this.#l10n.source ?? 'Source',
                onEdit: () => this.#scheduleWrite(),
                // aa_onFocus / aa_onBlur of the slice's field JavaScript hear them
                onFocus: () => {
                    this.#focused = true;
                    this.#textarea?.dispatchEvent(new FocusEvent('focus'));
                },
                onBlur: () => this.#leftEditor(),
                onSource: () => {
                    this.#leftEditor();         // TinyMCE removed while focused says no blur
                    this.#setMode('code');
                },
                upload: this.#uploadOptions(),
                disabled: this.#textarea.matches(':disabled'),
            });
        } catch (e) {
            // the bundle did not load, or TinyMCE refused the page (quirks mode)
            console.error('aa-richtext: the editor did not start', e);
            if (this.#rich === state) {
                this.#leave();
                this.#setMode('code');          // the HTML stays editable
            }
            return;
        }
        if (this.#rich !== state) {
            api.destroy();                      // left while TinyMCE was starting
            return;
        }
        state.api = api;
        this.#wasRich = true;
        if (this.#textarea.value !== start) {
            api.load(this.#textarea.value);     // a script or Reset wrote it while TinyMCE was starting
        }
        api.setDisabled(this.#textarea.matches(':disabled'));   // the same for switching the field off
    }

    /** as a textarea: change before blur */
    #leftEditor() {
        this.flush();
        if (this.#focused) {
            this.#focused = false;
            this.#textarea?.dispatchEvent(new FocusEvent('blur'));
        }
    }

    #scheduleWrite() {
        if (this.#writeQueued) {
            return;
        }
        this.#writeQueued = true;
        // one edit fires several TinyMCE events: serialize once, still before any other
        // task can run - a click on a submit button included
        queueMicrotask(() => {
            this.#writeQueued = false;
            this.#flushRich();
        });
    }

    #flushRich() {
        const api = this.#rich?.api;
        if (api?.isDirty()) {
            this.#sync?.write(api.getContent());
        }
    }

    #uploadOptions() {
        const mode = this.getAttribute('upload');
        const url  = this.getAttribute('upload-url');
        if (!url || !['user', 'anon'].includes(mode)) {
            return null;
        }
        const token = this.getAttribute('upload-token') ?? '';
        const slice = this.getAttribute('slice') ?? '';
        const send  = async (blob, name) => {
            const body = new FormData();
            body.append('file', blob, name);
            body.append('slice', slice);
            const response = await fetch(url, { method: 'POST', body, headers: { 'X-AA-Upload-Token': token }, credentials: 'same-origin' });
            const json = await response.json().catch(() => ({}));
            if (!response.ok || (typeof json.url !== 'string')) {
                throw new Error(json.error || (response.ok ? (this.#l10n.failed ?? 'Upload failed') : response.statusText));
            }
            return json;
        };
        // what the server takes; TinyMCE refuses other pasted and dropped images before sending
        const imageTypes = (this.getAttribute('upload-images') || '').split(',').filter(Boolean);
        if (mode === 'anon') {
            return { image: send, imageTypes };
        }
        let documentTypes = [];
        try {
            documentTypes = JSON.parse(this.getAttribute('upload-types') || '[]');
        } catch {
            documentTypes = [];
        }
        return {
            image: send,
            imageTypes,
            file: send,
            documentTypes,
            pick: async (kind) => {
                const { openFilePicker } = await import(sibling('./aa-filepicker.js'));
                return openFilePicker({ url, token, slice, kind, upload: send, l10n: this.#l10n });
            },
        };
    }
}

if (!customElements.get('aa-richtext')) {
    customElements.define('aa-richtext', AaRichtextElement);
}
