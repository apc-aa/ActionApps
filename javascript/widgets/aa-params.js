/**
 * <aa-params> - the parameters of a type (widget, default value, validation, …) as a form in the page.
 *
 *   <aa-params list="INPUT_TYPES" type-name="input_show_func_f" store-key="<module id>/<field id>">
 *       <div class="flex aa-input-const">… the constants select …</div>
 *       <div class="aa-params-raw"><label …>…</label><textarea name="input_show_func_p">…</textarea></div>
 *   </aa-params>
 *
 * The original controls stay and are what gets submitted; without JavaScript they are the form.
 * Values are kept by parameter id, so switching the type keeps what the new type has under the
 * same name and puts the rest aside (sessionStorage) until a type that has it comes back.
 * Definitions come from window.AA_PARAMS (AA\Util\Paramwizard::script()).
 */

const sibling = (path) => {
    const self = new URL(import.meta.url);
    const url = new URL(path, self);
    url.search = self.search;
    return url.href;
};

const { paramsOf, readParams, writeParams, splitFirst, constToText } = await import(sibling('./aa-params-core.js'));

let instances = 0;

/** element with a class */
const el = (tag, className = '') => {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    return node;
};

class AaParamsElement extends HTMLElement {
    #list;
    #labels;
    #typeSelect;
    #raw;
    #constBlock;
    #constSelect;
    #type = '';
    #values = new Map();
    #uid = '';
    #desc;
    #box;
    #rows;
    #text;
    #msg;

    connectedCallback() {
        if (this.#uid) {
            return;   // moved in the document, already running
        }
        this.#uid = 'aa-params-' + (++instances);
        try {
            this.#startForm();
        } catch (e) {
            console.error('aa-params', e);
        }
    }

    #startForm() {
        const list = window.AA_PARAMS?.lists?.[this.getAttribute('list')];
        const typeSelect = this.closest('form')?.elements[this.getAttribute('type-name')];
        const raw = this.querySelector('.aa-params-raw :is(input, textarea)');
        if (!list || !(typeSelect instanceof HTMLSelectElement) || !raw) {
            return;
        }
        this.#list = list;
        this.#labels = window.AA_PARAMS.labels ?? {};
        this.#typeSelect = typeSelect;
        this.#raw = raw;
        this.#constBlock = this.querySelector('.aa-input-const');
        this.#constSelect = this.#constBlock?.querySelector('select') ?? null;
        this.#type = typeSelect.value;
        this.#values = readParams(this.#params(), raw.value, this.#quote());
        for (const [id, value] of Object.entries(this.#loadAside())) {
            if ((typeof value === 'string') && !this.#values.has(id)) {
                this.#values.set(id, value);
            }
        }

        // everything is built aside first - an error on the way leaves the original controls as they were
        this.#desc = el('div', 'aa-params-desc');
        this.#box = el('div', 'aa-params-box');
        const title = el('h3');
        title.textContent = this.#labels.params ?? '';
        this.#rows = el('div', 'aa-params-rows');
        this.#box.append(title, this.#rows);
        const details = this.#buildText();

        this.#rows.addEventListener('input', (e) => this.#onRowInput(e));
        this.#rows.addEventListener('click', (e) => this.#onRowClick(e));
        typeSelect.addEventListener('change', () => {
            this.#type = typeSelect.value;
            this.#render();
            this.#write();
        });
        this.#constSelect?.addEventListener('change', () => this.#refreshText());

        this.prepend(this.#desc, this.#box);
        this.append(details);
        if (this.#constBlock) {
            this.#box.insertBefore(this.#constBlock, this.#rows);
        }
        this.#render();
        this.#refreshText();
        this.querySelector('.aa-params-raw').hidden = true;
    }

    /** parameters of the current type the form shows (constants have their own select) */
    #params() {
        const params = paramsOf(this.#list, this.#type).filter((p) => !this.#constSelect || (p.id !== 'const'));
        return params.length ? params : paramsOf(null, this.#type);
    }

    #hasConst() {
        return !!this.#constSelect && paramsOf(this.#list, this.#type).some((p) => p.id === 'const');
    }

    /** the type reads ' as : (old date fields) */
    #quote() {
        return this.#list.items[this.#type]?.quote === true;
    }

    #render() {
        const item = this.#list.items[this.#type];
        this.#desc.innerHTML = item?.desc ?? '';
        this.#desc.hidden = !this.#desc.textContent.trim();
        const rows = this.#params()
            .filter((p) => !p.id.startsWith('*') || (this.#values.get(p.id) ?? '') !== '')
            .map((p) => this.#row(p));
        this.#rows.replaceChildren(...rows);
        if (this.#constBlock) {
            this.#constBlock.hidden = !this.#hasConst();
        }
        this.#box.hidden = !rows.length && (!this.#constBlock || this.#constBlock.hidden);
    }

    #row(param) {
        const labels = this.#labels;
        const id = `${this.#uid}-p-${param.id}`;
        const value = this.#values.get(param.id) ?? '';
        const row = el('div', 'aa-params-row');
        const label = el('label');
        label.htmlFor = id;
        label.textContent = param.id.startsWith('*') ? (labels.other ?? '') : (param.name || param.id);
        const ctl = el('div', 'aa-params-ctl');

        let input;
        switch (param.type) {
            case 'bool':
                input = el('input');
                input.type = 'checkbox';
                input.checked = (value !== '') && (value !== '0');
                break;
            case 'enum': {
                input = el('select');
                const options = [['', labels.default ?? ''], ...Object.entries(param.options ?? {})];
                if (!options.some(([v]) => v === value)) {
                    options.push([value, value]);   // a stored value the type does not offer must not get lost
                }
                for (const [v, text] of options) {
                    input.add(new Option(text, v));
                }
                input.value = value;
                break;
            }
            case 'text':
                input = el('textarea');
                input.rows = 3;
                input.value = value;
                break;
            case 'int':
                input = el('input', 'aa-params-num');
                input.type = 'text';
                input.inputMode = 'numeric';   // not type=number: an alias or an expression is a value too
                input.value = value;
                break;
            default:
                input = el('input');
                input.type = 'text';
                input.value = value;
        }
        input.id = id;
        input.dataset.param = param.id;
        if (param.example && ((input.type === 'text') || (input.tagName === 'TEXTAREA'))) {
            input.placeholder = (labels.eg ?? '%1').replace('%1', param.example);
        }
        ctl.append(input);

        if (param.help) {
            const hlp = el('div', 'aa-params-hlp');
            hlp.id = id + '-hlp';
            hlp.innerHTML = param.help;
            input.setAttribute('aria-describedby', hlp.id);
            ctl.append(hlp);
            if ((hlp.textContent.length > 95) || /<br/i.test(param.help)) {
                hlp.classList.add('is-clamped');
                const more = el('button', 'aa-params-more');
                more.type = 'button';
                more.textContent = labels.more ?? '';
                more.setAttribute('aria-expanded', 'false');
                more.setAttribute('aria-controls', hlp.id);
                ctl.append(more);
            }
        }
        row.append(label, ctl);
        return row;
    }

    #onRowInput(e) {
        const input = e.target;
        const id = input.dataset?.param;
        if (!id) {
            return;
        }
        this.#values.set(id, (input.type === 'checkbox') ? (input.checked ? '1' : '0') : input.value);
        this.#write();
    }

    #onRowClick(e) {
        const more = e.target.closest('.aa-params-more');
        if (!more) {
            return;
        }
        const open = more.getAttribute('aria-expanded') !== 'true';
        more.setAttribute('aria-expanded', String(open));
        more.textContent = (open ? this.#labels.less : this.#labels.more) ?? '';
        document.getElementById(more.getAttribute('aria-controls'))?.classList.toggle('is-clamped', !open);
    }

    /** only from what the user did - an untouched field is submitted exactly as it came */
    #write() {
        this.#raw.value = writeParams(this.#params(), this.#values);
        this.#refreshText();
        this.#saveAside();
    }

    // --- values the current type does not have ---------------------------------------------------

    #storeKey() {
        const key = this.getAttribute('store-key');
        return key ? `aa-params:${key}:${this.#raw.name}` : '';
    }

    #loadAside() {
        const key = this.#storeKey();
        try {
            const saved = key ? JSON.parse(sessionStorage.getItem(key) ?? '{}') : {};
            return (saved && (typeof saved === 'object') && !Array.isArray(saved)) ? saved : {};
        } catch {
            return {};
        }
    }

    #saveAside() {
        const key = this.#storeKey();
        if (!key) {
            return;
        }
        const ids = new Set(this.#params().map((p) => p.id));
        const aside = Object.fromEntries([...this.#values].filter(([id, value]) => (value !== '') && !ids.has(id)));
        try {
            if (Object.keys(aside).length) {
                sessionStorage.setItem(key, JSON.stringify(aside));
            } else {
                sessionStorage.removeItem(key);
            }
        } catch {
            // no storage (private mode, full) - the values just are not remembered
        }
    }

    // --- the whole setting as one text ------------------------------------------------------------

    #buildText() {
        const labels = this.#labels;
        const details = el('details', 'aa-params-text');
        const summary = el('summary');
        summary.textContent = labels.as_text ?? '';
        const line = el('div', 'aa-params-textline');
        this.#text = el('input');
        this.#text.type = 'text';
        this.#text.id = this.#uid + '-text';
        this.#text.setAttribute('aria-label', labels.as_text ?? '');
        this.#text.spellcheck = false;
        const copy = el('button');
        copy.type = 'button';
        copy.title = labels.copy ?? '';
        copy.setAttribute('aria-label', labels.copy ?? '');
        copy.textContent = '⧉';
        copy.addEventListener('click', () => {
            navigator.clipboard.writeText(this.#text.value).then(() => window.AA_Message?.(labels.copied, 'info'));
        });
        line.append(this.#text, copy);
        this.#msg = el('div', 'aa-params-msg');
        this.#msg.setAttribute('role', 'status');
        this.#msg.textContent = labels.text_note ?? '';
        details.append(summary, line, this.#msg);

        // not on every keystroke - a type half typed would be read as parameters
        this.#text.addEventListener('change', () => this.#applyText(this.#text.value));
        this.#text.addEventListener('input', (e) => {
            if ((e.inputType === 'insertFromPaste') || (e.inputType === 'insertFromDrop')) {
                this.#applyText(this.#text.value);
            }
        });
        return details;
    }

    #refreshText() {
        const parts = [this.#type];
        if (this.#hasConst()) {
            parts.push(constToText(this.#constSelect.value).replaceAll(':', '#:'));
        }
        parts.push(this.#raw.value);
        this.#text.value = parts.join(':').replace(/(?<!#):+$/, '');
    }

    #applyText(input) {
        const labels = this.#labels;
        const text = input.trim();
        let msg = labels.text_note ?? '';
        let { first, rest } = splitFirst(text);
        const typeSelect = this.#typeSelect;
        if ([...typeSelect.options].some((o) => o.value === first)) {
            typeSelect.value = first;
            this.#type = first;
            if (this.#hasConst()) {
                const group = splitFirst(rest);
                rest = group.rest;
                const option = [...this.#constSelect.options].find((o) => constToText(o.value) === group.first);
                if (option) {
                    this.#constSelect.value = option.value;
                } else {
                    msg = (labels.unknown_const ?? '').replace('%1', group.first);
                }
            }
        } else {
            msg = (labels.no_type ?? '').replace('%1', typeSelect.selectedOptions[0]?.text ?? this.#type);
            rest = text;
        }
        for (const [id, value] of readParams(this.#params(), rest, this.#quote())) {
            this.#values.set(id, value);
        }
        this.#render();
        this.#write();
        this.#msg.textContent = msg;
    }
}

if (!customElements.get('aa-params')) {
    customElements.define('aa-params', AaParamsElement);
}
