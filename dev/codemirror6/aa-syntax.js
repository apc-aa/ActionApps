/**
 * AA template syntax - the {...} expressions - read the way include/stringexpand.php reads it.
 * No editor code here, so node --test checks it (tests/js/aa-syntax.test.mjs).
 *
 * The expander replaces the leftmost innermost {[^{}]+} again and again, so on balanced text a
 * brace stack pairs the same braces. Parameters split on ":" as ParamExplode() splits them: not
 * "#:", not the ":" of "http://" (https, ftp), and not inside a nested {...}, which is expanded
 * first and comes back with its colons quoted.
 */

const OPEN  = 123;  // {
const CLOSE = 125;  // }
const COLON = 58;   // :

const NAME = /^[A-Za-z_]\w*$/;

/** "{#" is a comment; "{" before a space, newline or tab is text - CSS and JavaScript blocks */
function kindOf(text, from) {
    const next = text[from + 1];
    if (next === '#') {
        return 'comment';
    }
    return (next === ' ' || next === '\n' || next === '\t') ? 'literal' : 'expr';
}

function quotedColon(text, i) {
    return (text[i - 1] === '#') || (text.startsWith('//', i + 1) && (text.endsWith('tp', i) || text.endsWith('tps', i)));
}

/** the command name: all after "{" up to the first ":" (or `end`), null when that is no name */
function nameOf(text, open, end) {
    const to = open.seps[0] ?? end;
    return NAME.test(text.slice(open.from + 1, to)) ? { from: open.from + 1, to } : null;
}

/**
 * Pairs the braces of text[0, end). onClose(open, to) gets each pair the expander would expand.
 * @return {{from: number, seps: number[], poisoned: boolean}[]} the braces still open at `end`
 */
function walk(text, end, onClose = null) {
    const stack = [];
    for (let i = 0; i < end; i++) {
        const c = text.charCodeAt(i);
        if (c === OPEN) {
            stack.push({ from: i, seps: [], poisoned: false });
        } else if (c === CLOSE) {
            const open = stack.pop();
            if (!open) {
                continue;
            }
            if (i === open.from + 1) {
                // {} never matches {[^{}]+}, and nothing around it can match any more
                stack.forEach((e) => { e.poisoned = true; });
            } else if (!open.poisoned) {
                onClose?.(open, i + 1);
            }
        } else if ((c === COLON) && stack.length && !quotedColon(text, i)) {
            stack[stack.length - 1].seps.push(i);
        }
    }
    return stack;
}

/**
 * Every brace pair the expander would expand, outer before inner.
 * name: the command name, when the expression starts with one followed by ":" or "}";
 * seps: positions of its own parameter separators.
 * @param {string} text
 * @return {{from: number, to: number, kind: 'comment'|'literal'|'expr', name: ?{from: number, to: number}, seps: number[]}[]}
 */
export function scan(text) {
    const spans = [];
    walk(text, text.length, (open, to) => {
        const kind = kindOf(text, open.from);
        const span = { from: open.from, to, kind, name: null, seps: [] };
        if (kind === 'expr') {
            span.name = nameOf(text, open, to - 1);
            span.seps = open.seps;
        }
        spans.push(span);
    });
    return spans.sort((a, b) => a.from - b.from);
}

/**
 * The innermost expression open at `pos` - typed without its "}" yet, too.
 * @param {string} text
 * @param {number} pos
 * @return {?{name: string, from: number, param: number, start: number}} name lower-cased; param -1 = in
 *         the name; start: where the parameter (or the name) begins;
 *         null in a comment, in text braces and in an expression without a command name
 */
export function contextAt(text, pos) {
    const open = walk(text, pos).at(-1);
    if (!open || open.poisoned || (kindOf(text, open.from) !== 'expr')) {
        return null;
    }
    const name = nameOf(text, open, pos);
    if (!name) {
        return null;
    }
    return { name: text.slice(name.from, name.to).toLowerCase(), from: open.from, param: open.seps.length - 1, start: (open.seps.at(-1) ?? open.from) + 1 };
}

/**
 * The brace paired with the one at `pos`, by the brace stack the expander pairs with - an empty {}
 * pairs too, though the expressions around it then stay unexpanded (see walk()).
 * @param {string} text
 * @param {number} pos
 * @return {number} -1 when it has no pair or `pos` is no brace
 */
export function pairedBrace(text, pos) {
    const own = text.charCodeAt(pos);
    if ((own !== OPEN) && (own !== CLOSE)) {
        return -1;
    }
    const dir = (own === OPEN) ? 1 : -1;
    let depth = 0;
    for (let i = pos; (i >= 0) && (i < text.length); i += dir) {
        const c = text.charCodeAt(i);
        if (c === own) {
            depth++;
        } else if ((c === OPEN) || (c === CLOSE)) {
            depth--;
            if (!depth) {
                return i;
            }
        }
    }
    return -1;
}

const SUBSCRIPT = '₀₁₂₃₄₅₆₇₈₉';
const numbered = (name, n) => name + [...String(n)].map(d => SUBSCRIPT[d]).join('');

/**
 * The parameters of a command for the hint, the one at the cursor current. A repeated group shows
 * once, numbered - the one the cursor is in; where a group may start, the parameter after the
 * groups may stand there instead ({ifeq:x:a:A:else}), so both are current.
 * @param {{params: string[], repeat?: string[], tail?: string[]}} cmd  from the catalog (AA\Stringexpand\Catalog)
 * @param {number} param  of contextAt(); -1 = in the name
 * @return {{text: string, current: boolean}[]}  joined by ":" in the hint
 */
export function signature(cmd, param) {
    const parts = cmd.params.map((text, i) => ({ text, current: i === param }));
    const repeat = cmd.repeat ?? [];
    if (!repeat.length) {
        return parts;
    }
    const k     = param - cmd.params.length;
    const group = (k > 0) ? Math.floor(k / repeat.length) : 0;
    const at    = (k >= 0) ? (k % repeat.length) : -1;
    if (group > 0) {
        parts.push({ text: '…', current: false });
    }
    repeat.forEach((name, i) => parts.push({ text: numbered(name, group + 1), current: i === at }));
    parts.push({ text: '…', current: false });
    const either = (group > 0) && (at === 0);
    (cmd.tail ?? []).forEach((text, i) => parts.push({ text, current: either && (i === 0) }));
    return parts;
}
