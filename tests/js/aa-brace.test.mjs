// Brace matching of the code editor: a brace pairs as the expander pairs it, whatever the language around.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { pairedBrace } from '../../dev/codemirror6/aa-syntax.js';

const cases = JSON.parse(readFileSync(new URL('./aa-syntax-cases.json', import.meta.url), 'utf8'));

test('pairs every span of the shared cases, from both ends', () => {
    for (const { input, spans } of cases.scan) {
        for (const [, from, to] of spans) {
            assert.equal(pairedBrace(input, from), to - 1, input);
            assert.equal(pairedBrace(input, to - 1), from, input);
        }
    }
});

/** the text with the brace at `pos` and its pair in [] */
function show(text, pos) {
    const pair = pairedBrace(text, pos);
    return [...text].map((c, i) => (((i === pos) || (i === pair)) ? `[${c}]` : c)).join('');
}

const LDJSON = '<script type="application/ld+json">{"a": 1,\n {ifset:{item:{xid}:_#HEAD_FOT}:{-\n  "image": [ "_#1" ],\n }}\n}</script>';

test('an expression in JSON - not the object the JavaScript parser recovers', () => {
    assert.equal(show(LDJSON, LDJSON.indexOf('{ifset')), '<script type="application/ld+json">{"a": 1,\n [{]ifset:{item:{xid}:_#HEAD_FOT}:{-\n  "image": [ "_#1" ],\n }[}]\n}</script>');
    assert.equal(show(LDJSON, LDJSON.indexOf('{-')), '<script type="application/ld+json">{"a": 1,\n {ifset:{item:{xid}:_#HEAD_FOT}:[{]-\n  "image": [ "_#1" ],\n [}]}\n}</script>');
    assert.equal(show(LDJSON, LDJSON.lastIndexOf('}<')), '<script type="application/ld+json">[{]"a": 1,\n {ifset:{item:{xid}:_#HEAD_FOT}:{-\n  "image": [ "_#1" ],\n }}\n[}]</script>');
});

test('from either brace of the pair', () => {
    assert.equal(show('{a{b}c}', 0), '[{]a{b}c[}]');
    assert.equal(show('{a{b}c}', 6), '[{]a{b}c[}]');
    assert.equal(show('{a{b}c}', 4), '{a[{]b[}]c}');
});

test('CSS and text braces count as the expander counts them', () => {
    assert.equal(show('.a{color:{_#C};} {ifset:{_#X}:.b{d:n}}', 17), '.a{color:{_#C};} [{]ifset:{_#X}:.b{d:n}[}]');
    assert.equal(show('{}', 1), '[{][}]');
});

test('no pair', () => {
    assert.equal(pairedBrace('{a', 0), -1);
    assert.equal(pairedBrace('a}', 1), -1);
    assert.equal(pairedBrace('{x}}', 3), -1);
    assert.equal(show('}{a}', 1), '}[{]a[}]');
});
