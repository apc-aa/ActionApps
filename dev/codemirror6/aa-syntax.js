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

const NAME_START = /[A-Za-z_]/;
const NAME_CHAR  = /[A-Za-z0-9_]/;

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

/** the command name right after "{", up to `end` at most */
function nameAt(text, from, end) {
    let i = from + 1;
    if (!NAME_START.test(text[i] ?? '')) {
        return null;
    }
    while ((i < end) && NAME_CHAR.test(text[i])) {
        i++;
    }
    return { from: from + 1, to: i };
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
            const end  = open.seps[0] ?? (to - 1);
            const name = nameAt(text, open.from, end);
            span.name = (name?.to === end) ? name : null;
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
 * @return {?{name: string, from: number, param: number}} name lower-cased; param -1 = in the name;
 *         null in a comment, in text braces and in an expression without a command name
 */
export function contextAt(text, pos) {
    const open = walk(text, pos).at(-1);
    if (!open || open.poisoned || (kindOf(text, open.from) !== 'expr')) {
        return null;
    }
    const end  = open.seps[0] ?? pos;
    const name = nameAt(text, open.from, end);
    if (name?.to !== end) {
        return null;
    }
    return { name: text.slice(name.from, name.to).toLowerCase(), from: open.from, param: open.seps.length - 1 };
}
