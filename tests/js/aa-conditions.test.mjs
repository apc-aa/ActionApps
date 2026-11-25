// <aa-conditions>: a new row gets a number no row of the editing had, and the names of its fields carry it.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { NUMBER, nextNumber, numbered } from '../../javascript/widgets/aa-conditions.js';

test('the first row gets the first number', () => {
    assert.equal(nextNumber([], 4), 4);
    assert.equal(nextNumber([], 1), 1);
});

test('a new row goes above every number used', () => {
    assert.equal(nextNumber([4, 5], 4), 6);
    assert.equal(nextNumber([4, 9, 6], 4), 10);
});

test('a number below the first one does not pull new rows down', () => {
    assert.equal(nextNumber([2], 4), 4);
});

test('a row without a number is not counted', () => {
    assert.equal(nextNumber([NaN, 5], 4), 6);
    assert.equal(nextNumber([NaN], 4), 4);
});

test('the template row is numbered in every name', () => {
    const row = `<div data-number="${NUMBER}"><select name="conds[${NUMBER}][fields][]"></select><input name="conds[${NUMBER}][value]"></div>`;
    assert.equal(numbered(row, 7), '<div data-number="7"><select name="conds[7][fields][]"></select><input name="conds[7][value]"></div>');
});
