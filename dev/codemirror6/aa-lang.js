/**
 * AA template syntax in the code editor: {#…} comments greyed out, as the expander drops them;
 * completion of {command names and the parameter hint of the command the cursor is in; braces
 * matched as the expander pairs them. Over whatever language the editor has, so it works inside
 * HTML attributes too.
 */
import { ViewPlugin, Decoration, EditorView, showTooltip } from '@codemirror/view';
import { RangeSetBuilder, StateEffect, StateField } from '@codemirror/state';
import { autocompletion, pickedCompletion } from '@codemirror/autocomplete';
import { bracketMatching } from '@codemirror/language';
import { scan, contextAt, pairedBrace, signature } from './aa-syntax.js';

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

const matchingMark    = Decoration.mark({ class: 'cm-matchingBracket' });
const nonmatchingMark = Decoration.mark({ class: 'cm-nonmatchingBracket' });

/**
 * Marks the bracket CodeMirror found at the cursor and its pair - for a brace the pair the expander
 * gives it: in a <script> or <style> CodeMirror pairs braces by the JavaScript or CSS syntax tree,
 * which AA expressions break there.
 */
function renderBracketMatch({ start, end, matched }, state) {
    const ch = state.sliceDoc(start.from, start.to);
    if ((ch === '{') || (ch === '}')) {
        const pair = pairedBrace(state.doc.toString(), start.from);
        matched = (pair >= 0);
        end     = matched ? { from: pair, to: pair + 1 } : null;
    }
    const mark = matched ? matchingMark : nonmatchingMark;
    return [start, end].filter(Boolean).map(r => mark.range(r.from, r.to));
}

// the bundle is javascript/codemirror6.min.js; ?_path= - the ECN servers ignore the .htaccess rewrite
const CATALOG_URL = new URL('../api/index.php?_path=v1/expressions', import.meta.url);

/** @type {?Map<string, {params: string[], repeat?: string[], tail?: string[], desc: string, deprecated?: true}>}
 *  the commands, once loaded */
let commands = null;
let loading  = null;
let warned   = false;

/** an expired session or a lost connection may come back - but not a request on every key */
const RETRY_MS = 60000;

/** Once per page, on the first need: most editors are never asked for a hint.
 *  @return {Promise<?Map>} null when the catalog cannot be had now (not logged in) */
function loadCommands() {
    loading ??= fetch(CATALOG_URL, { headers: { Accept: 'application/json' } })
        .then(r => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
        .then(json => (commands = new Map(Object.entries(json.commands ?? {}))))
        .catch((e) => {
            if (!warned) {
                console.warn('aa-code: no hints for AA expressions -', e.message);
                warned = true;
            }
            setTimeout(() => { loading = null; }, RETRY_MS);
            return null;
        });
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

/**
 * "{" and a letter; a bare "{" or "{_" only on Ctrl+Space - "{_#ALIAS" is typed far more often than "{_:".
 * Names starting with what is typed, not CodeMirror's fuzzy match: in CSS and JavaScript ("a{color",
 * "){return") a loose match would keep the list open and Enter would take a command instead of a newline.
 */
async function aaCompletions(context) {
    const word = context.matchBefore(/\{(?:[A-Za-z_]\w*)?$/);
    if (!word || (!context.explicit && !/^\{[A-Za-z]/.test(word.text))) {
        return null;
    }
    const all = await loadCommands();
    if (!all) {
        return null;
    }
    options ??= [...all].filter(([, cmd]) => !cmd.deprecated).map(([name, cmd]) => ({
        label:  name,
        detail: signature(cmd, -1).map(p => p.text).join(':'),
        info:   cmd.desc || undefined,
        apply:  applyCommand(name, cmd),
    }));
    const typed = word.text.slice(1).toLowerCase();
    return { from: word.from + 1, options: options.filter(o => o.label.startsWith(typed)), filter: false };
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
    // the catalog not loaded (yet): hintLoader looks for the parameter itself
    if (!commands?.size) {
        return null;
    }
    const sel = state.selection.main;
    const ctx = (focused && sel.empty) ? paramAt(state, sel.head) : null;
    const cmd = ctx ? commands.get(ctx.name) : null;
    if (!cmd) {
        return null;
    }
    // at the parameter, or at the line typed in when the parameter starts above it - CodeMirror hides
    // a tooltip whose place is scrolled away, and long {switch:...} blocks span many lines
    const pos = Math.max(ctx.start, state.doc.lineAt(sel.head).from);
    const key = `${pos}:${ctx.name}:${ctx.param}`;
    // typing within one parameter keeps the tooltip - a new one on each key would flicker
    return (previous?.key === key) ? previous : { key, pos, above: true, create: () => hintDom({ ...ctx, cmd }) };
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
    waiting = false;    // one dispatch per editor - the fetch itself is shared anyway

    update(update) {
        if (commands || this.waiting || !(update.docChanged || update.selectionSet)) {
            return;
        }
        if (paramAt(update.state, update.state.selection.main.head)) {
            this.waiting = true;
            loadCommands().then((loaded) => {
                if (loaded) {
                    // a view destroyed meanwhile ignores the dispatch
                    update.view.dispatch({ effects: commandsLoaded.of(null) });
                } else {
                    this.waiting = false;   // loadCommands() says when to ask the server again
                }
            });
        }
    }
});

/** @return {import('@codemirror/state').Extension} */
export function aaSyntax() {
    return [
        comments,
        bracketMatching({ renderMatch: renderBracketMatch }),   // CodeMirror 5's matchBrackets + matchTags
        // instead of the language's own completion: HTML tags were never offered here
        autocompletion({ override: [aaCompletions], icons: false }),
        hint,
        hintLoader,
        EditorView.focusChangeEffect.of((state, focusing) => focusChanged.of(focusing)),
    ];
}
