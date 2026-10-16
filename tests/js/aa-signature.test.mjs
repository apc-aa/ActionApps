// The parameter hint of the code editor: which parameter of {command:...} the cursor is in.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { signature } from '../../dev/codemirror6/aa-syntax.js';

const IFSET = { params: ['condition', 'text', 'else_text'] };
const IFEQ  = { params: ['etalon'], repeat: ['option', 'text'], tail: ['else_text'] };
const JOIN  = { params: ['delimiter'], repeat: ['value'] };
const ASOC  = { params: [], repeat: ['key', 'value'] };

/** the parts as text, the current ones in [brackets] */
const show = (cmd, param) => signature(cmd, param).map(p => (p.current ? `[${p.text}]` : p.text)).join(':');

test('fixed parameters - the one at the cursor', () => {
    assert.equal(show(IFSET, 0), '[condition]:text:else_text');
    assert.equal(show(IFSET, 2), 'condition:text:[else_text]');
});

test('in the name or past the last parameter - none is current', () => {
    assert.equal(show(IFSET, -1), 'condition:text:else_text');
    assert.equal(show(IFSET, 3), 'condition:text:else_text');
    assert.equal(show({ params: [] }, 0), '');
});

test('a repeated group shows once, numbered, with what follows it', () => {
    assert.equal(show(IFEQ, -1), 'etalon:option₁:text₁:…:else_text');
    assert.equal(show(IFEQ, 0), '[etalon]:option₁:text₁:…:else_text');
    assert.equal(show(IFEQ, 1), 'etalon:[option₁]:text₁:…:else_text');
    assert.equal(show(IFEQ, 2), 'etalon:option₁:[text₁]:…:else_text');
});

test('further groups - and where one may start, the else may stand instead', () => {
    assert.equal(show(IFEQ, 3), 'etalon:…:[option₂]:text₂:…:[else_text]');
    assert.equal(show(IFEQ, 4), 'etalon:…:option₂:[text₂]:…:else_text');
    assert.equal(show(IFEQ, 21), 'etalon:…:[option₁₁]:text₁₁:…:[else_text]');
});

test('a group without anything after it', () => {
    assert.equal(show(JOIN, 3), 'delimiter:…:[value₃]:…');
    assert.equal(show(ASOC, 0), '[key₁]:value₁:…');
    assert.equal(show(ASOC, 3), '…:key₂:[value₂]:…');
});
