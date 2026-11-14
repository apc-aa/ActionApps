/**
 * AA template syntax in the code editor: {#…} comments greyed out, as the expander drops them;
 * completion of {command names and the parameter hint of the command the cursor is in; braces
 * matched as the expander pairs them. Over whatever language the editor has, so it works inside
 * HTML attributes too.
 */
import { ViewPlugin, Decoration, EditorView, showPanel } from '@codemirror/view';
import { RangeSetBuilder, StateEffect, StateField } from '@codemirror/state';
import { autocompletion, pickedCompletion, selectedCompletion } from '@codemirror/autocomplete';
import { bracketMatching } from '@codemirror/language';
import { scan, contextAt, pairedBrace, signature, commandFor } from './aa-syntax.js';

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

/** Once per page, when an editor first gets the focus: most editors are never written in.
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

/** the command the cursor is in, with the parameter it is in (-1: in the name) */
function commandAt(state, pos) {
    // two characters more: the ":" of "http://" is told by the "//" after it
    const ctx = contextAt(state.sliceDoc(0, pos + 2), pos);
    if (!ctx || (ctx.param >= 0)) {
        return ctx;
    }
    // the whole name when the cursor is inside one
    const name = commandFor(ctx.name + state.sliceDoc(pos, state.doc.lineAt(pos).to).match(/^\w*/)[0].toLowerCase(), commands);
    return name && { ...ctx, name };
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
 * The list opens only on Ctrl+Space - while typing, the line under the editor names the command. After "{"
 * or "{" and a letter: "{_" closes it, as "{_#ALIAS" is typed far more often than "{_:".
 * Names starting with what is typed, not CodeMirror's fuzzy match: in CSS and JavaScript ("a{color",
 * "){return") a loose match would keep the list open and Enter would take a command instead of a newline.
 */
async function aaCompletions(context) {
    const word = context.matchBefore(/\{(?:[A-Za-z]\w*)?$/);
    if (!word) {
        return null;
    }
    const all = await loadCommands();
    if (!all) {
        return null;
    }
    options ??= [...all].filter(([, cmd]) => !cmd.deprecated).map(([name, cmd]) => ({
        label:  name,
        detail: signature(cmd, -1).map(p => p.text).join(':'),     // for screen readers, hidden on screen
        apply:  applyCommand(name, cmd),
    }));
    const typed = word.text.slice(1).toLowerCase();
    return { from: word.from + 1, options: options.filter(o => o.label.startsWith(typed)), filter: false };
}

const commandsLoaded = StateEffect.define();   // a transaction, so the hint field sees the catalog
const focusChanged   = StateEffect.define();

const TIP = '{ + name: help here · { + Ctrl+Space: list of commands';

/** {name:param:…} with the parameter the cursor is in bold, and what the command does */
function hintContent({ name, param }, cmd) {
    const code = document.createElement('code');
    code.append(`{${name}`);
    for (const part of signature(cmd, param)) {
        code.append(':', part.current ? Object.assign(document.createElement('b'), { textContent: part.text }) : part.text);
    }
    code.append('}');
    return cmd.desc ? [code, Object.assign(document.createElement('span'), { textContent: cmd.desc })] : [code];
}

/**
 * A line under the editor rather than a tooltip, which would hide the lines above the cursor. One line
 * in any case (the description is cut, whole in the title), so the page does not jump as the cursor moves.
 */
class HintPanel {
    dom = Object.assign(document.createElement('div'), { className: 'cm-aa-hint' });
    key = null;

    constructor(view) {
        this.render(view);
    }

    update(update) {
        if (update.docChanged || update.selectionSet || update.focusChanged
            || (update.transactions.length && (selectedCompletion(update.state) !== selectedCompletion(update.startState)))) {
            this.render(update.view);
        }
    }

    render(view) {
        // the command picked in the list, else the one the cursor is in
        const picked = view.hasFocus ? selectedCompletion(view.state) : null;
        const sel    = view.state.selection.main;
        const ctx    = picked ? { name: picked.label, param: -1 }
            : ((view.hasFocus && sel.empty) ? commandAt(view.state, sel.head) : null);
        const cmd = ctx ? commands.get(ctx.name) : null;
        const key = view.hasFocus ? (cmd ? `${ctx.name}:${ctx.param}` : 'tip') : '';
        if (key === this.key) {
            return;                     // typing within one parameter
        }
        this.key = key;
        // blank in an editor the focus left - several fields of a page may hold expressions
        const parts = cmd ? hintContent(ctx, cmd) : (view.hasFocus ? [TIP] : []);
        this.dom.replaceChildren(...parts);
        this.dom.title = cmd ? parts.map(part => part.textContent).join(' - ') : '';
    }
}

const hintPanel = view => new HintPanel(view);

/** from the first focus on, and only with the catalog, which is not there for anonymous users */
const hint = StateField.define({
    create: () => ({ focused: false, shown: false }),
    update(value, tr) {
        let focused = value.focused;
        for (const e of tr.effects) {
            if (e.is(focusChanged)) {
                focused = e.value;
            }
        }
        // it stays when the focus leaves: the editor shrinking then would move the page under the
        // pointer, and the click that took the focus away would land on something else
        const shown = value.shown || (focused && !!commands?.size);
        return ((focused === value.focused) && (shown === value.shown)) ? value : { focused, shown };
    },
    provide: f => showPanel.from(f, value => (value.shown ? hintPanel : null)),
});

/** loads the catalog when an editor gets the focus or is written in, and shows the hint then */
const hintLoader = ViewPlugin.fromClass(class {
    waiting = false;    // one dispatch per editor - the fetch itself is shared anyway

    update(update) {
        if (commands || this.waiting || !update.view.hasFocus || !(update.focusChanged || update.docChanged || update.selectionSet)) {
            return;
        }
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
});

/** @return {import('@codemirror/state').Extension} */
export function aaSyntax() {
    return [
        comments,
        bracketMatching({ renderMatch: renderBracketMatch }),   // CodeMirror 5's matchBrackets + matchTags
        // instead of the language's own completion: HTML tags were never offered here. Above the cursor,
        // so as not to cover the line under the editor, which describes the command picked.
        autocompletion({ override: [aaCompletions], icons: false, activateOnTyping: false, aboveCursor: true }),
        hint,
        hintLoader,
        EditorView.focusChangeEffect.of((state, focusing) => focusChanged.of(focusing)),
    ];
}
