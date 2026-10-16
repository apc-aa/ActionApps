/**
 * AA template syntax in the code editor: {#…} comments greyed out, as the expander drops them;
 * completion of {command names and the parameter hint of the command the cursor is in.
 * Over whatever language the editor has, so it works inside HTML attributes too.
 */
import { ViewPlugin, Decoration, EditorView, showTooltip } from '@codemirror/view';
import { RangeSetBuilder, StateEffect, StateField } from '@codemirror/state';
import { autocompletion, pickedCompletion } from '@codemirror/autocomplete';
import { scan, contextAt, signature } from './aa-syntax.js';

const commentMark = Decoration.mark({ class: 'cm-aa-comment' });

function commentDecorations(doc) {
    const builder = new RangeSetBuilder();
    let end = -1;
    // the whole text: a comment may open far above the visible part
    for (const span of scan(doc.toString())) {
        if ((span.kind === 'comment') && (span.from >= end)) {    // one inside a comment is covered already
            builder.add(span.from, span.to, commentMark);
            end = span.to;
        }
    }
    return builder.finish();
}

const comments = ViewPlugin.fromClass(class {
    constructor(view) {
        this.decorations = commentDecorations(view.state.doc);
    }

    update(update) {
        if (update.docChanged) {
            this.decorations = commentDecorations(update.state.doc);
        }
    }
}, { decorations: plugin => plugin.decorations });

// the bundle is javascript/codemirror6.min.js; ?_path= - the ECN servers ignore the .htaccess rewrite
const CATALOG_URL = new URL('../api/index.php?_path=v1/expressions', import.meta.url);

/** @type {?Map<string, {params: string[], repeat?: string[], tail?: string[], desc: string, deprecated?: true}>}
 *  the commands, once loaded - empty when they cannot be (not logged in) */
let commands = null;
let loading  = null;

/** Once per page, on the first need: most editors are never asked for a hint. */
function loadCommands() {
    loading ??= fetch(CATALOG_URL, { headers: { Accept: 'application/json' } })
        .then(r => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
        .then(json => new Map(Object.entries(json.commands ?? {})))
        .catch((e) => {
            console.warn('aa-code: no hints for AA expressions -', e.message);
            return new Map();
        })
        .then(map => (commands = map));
    return loading;
}

/** the parameter the cursor is in, -1 in the name or outside an expression */
function paramAt(state, pos) {
    // two characters more: the ":" of "http://" is told by the "//" after it
    const ctx = contextAt(state.sliceDoc(0, pos + 2), pos);
    return (ctx && (ctx.param >= 0)) ? ctx : null;
}

/** {name: or {name} - the "}" right away for a command without parameters */
function applyCommand(name, cmd) {
    const close = (cmd.params.length || cmd.repeat) ? ':' : '}';
    return (view, completion, from, to) => {
        const typed = view.state.sliceDoc(to, to + 1) === close;
        view.dispatch({
            changes:        { from, to, insert: typed ? name : name + close },
            selection:      { anchor: from + name.length + 1 },
            annotations:    pickedCompletion.of(completion),
            userEvent:      'input.complete',
            scrollIntoView: true,
        });
    };
}

let options = null;

/** "{" and a letter; a bare "{" or "{_" only on Ctrl+Space - "{_#ALIAS" is typed far more often than "{_:" */
async function aaCompletions(context) {
    const word = context.matchBefore(/\{(?:[A-Za-z_]\w*)?$/);
    if (!word || (!context.explicit && !/^\{[A-Za-z]/.test(word.text))) {
        return null;
    }
    const all = await loadCommands();
    options ??= [...all].filter(([, cmd]) => !cmd.deprecated).map(([name, cmd]) => ({
        label:  name,
        detail: signature(cmd, -1).map(p => p.text).join(':'),
        info:   cmd.desc || undefined,
        apply:  applyCommand(name, cmd),
    }));
    return { from: word.from + 1, options, validFor: /^\w*$/ };
}

const commandsLoaded = StateEffect.define();
const focusChanged   = StateEffect.define();

function hintDom({ name, param, cmd }) {
    const dom  = document.createElement('div');
    dom.className = 'cm-aa-hint';
    const code = dom.appendChild(document.createElement('code'));
    code.append(`{${name}`);
    for (const part of signature(cmd, param)) {
        code.append(':', part.current ? Object.assign(document.createElement('b'), { textContent: part.text }) : part.text);
    }
    code.append('}');
    if (cmd.desc) {
        dom.appendChild(document.createElement('div')).textContent = cmd.desc;
    }
    return { dom };
}

function hintTooltip(state, focused, previous) {
    const sel = state.selection.main;
    const ctx = (focused && sel.empty) ? paramAt(state, sel.head) : null;
    const cmd = ctx ? commands?.get(ctx.name) : null;
    if (!cmd) {
        return null;
    }
    const key = `${ctx.from}:${ctx.name}:${ctx.param}`;
    // typing within one parameter keeps the tooltip - a new one on each key would flicker
    return (previous?.key === key) ? previous : { key, pos: ctx.from, above: true, create: () => hintDom({ ...ctx, cmd }) };
}

/** the hint shows in the focused editor only - several fields of a page may hold expressions */
const hint = StateField.define({
    create: () => ({ focused: false, tooltip: null }),
    update(value, tr) {
        let focused = value.focused;
        for (const e of tr.effects) {
            if (e.is(focusChanged)) {
                focused = e.value;
            }
        }
        const stale = tr.docChanged || tr.selection || (focused !== value.focused) || tr.effects.some(e => e.is(commandsLoaded));
        return stale ? { focused, tooltip: hintTooltip(tr.state, focused, value.tooltip) } : value;
    },
    provide: f => showTooltip.from(f, value => value.tooltip),
});

/** loads the catalog when the cursor first stands in a parameter, and shows the hint then */
const hintLoader = ViewPlugin.fromClass(class {
    constructor(view) {
        this.view    = view;
        this.waiting = false;
    }

    update(update) {
        if (commands || this.waiting || !(update.docChanged || update.selectionSet)) {
            return;
        }
        if (paramAt(update.state, update.state.selection.main.head)) {
            this.waiting = true;
            loadCommands().then(() => this.view?.dispatch({ effects: commandsLoaded.of(null) }));
        }
    }

    destroy() {
        this.view = null;
    }
});

/** @return {import('@codemirror/state').Extension} */
export function aaSyntax() {
    return [
        comments,
        // instead of the language's own completion: HTML tags were never offered here
        autocompletion({ override: [aaCompletions], icons: false }),
        hint,
        hintLoader,
        EditorView.focusChangeEffect.of((state, focusing) => focusChanged.of(focusing)),
    ];
}
