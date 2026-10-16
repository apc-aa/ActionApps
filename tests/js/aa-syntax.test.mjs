// The lexer of the code editor against the shared cases - StringexpandSyntaxCasesTest checks the
// same cases' output on the PHP expander, so the editor and the expander read templates alike.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { scan, contextAt } from '../../dev/codemirror6/aa-syntax.js';

const cases = JSON.parse(readFileSync(new URL('./aa-syntax-cases.json', import.meta.url), 'utf8'));

for (const { input, spans } of cases.scan) {
    test(`scan ${JSON.stringify(input)}`, () => {
        const got = scan(input).map(({ kind, from, to, name, seps }) => (kind === 'expr')
            ? [kind, from, to, name ? input.slice(name.from, name.to) : null, seps]
            : [kind, from, to]);
        assert.deepEqual(got, spans);
    });
}

for (const { input, name, param } of cases.contexts) {
    test(`contextAt ${JSON.stringify(input)}`, () => {
        const pos = input.indexOf('|');
        const text = input.slice(0, pos) + input.slice(pos + 1);
        const ctx = contextAt(text, pos);
        if (name === null) {
            assert.equal(ctx, null);
        } else {
            assert.deepEqual({ name: ctx?.name, param: ctx?.param }, { name, param });
        }
    });
}
