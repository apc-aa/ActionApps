// The parameter form reads and writes the stored string as AA_Serializable::parseProperties() reads it;
// tests/Unit/Util/ParamwizardCasesTest.php checks the same cases against PHP.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { paramsOf, readParams, writeParams, splitFirst, constToText } from '../../javascript/widgets/aa-params-core.js';

const cases = JSON.parse(readFileSync(new URL('./aa-params-cases.json', import.meta.url), 'utf8'));

/** parameters of a type of the cases, without the skipped ones */
const paramsFor = (type, skip = []) => cases.types[type].filter((id) => !skip.includes(id)).map((id) => ({ id }));

test('read cases', () => {
    for (const c of cases.read) {
        assert.deepEqual(Object.fromEntries(readParams(paramsFor(c.type, c.skip), c.text, c.quote)), c.values, c.text);
    }
});

test('write cases', () => {
    for (const c of cases.write) {
        assert.equal(writeParams(paramsFor(c.type, c.skip), new Map(Object.entries(c.values))), c.text, JSON.stringify(c.values));
    }
});

test('values keep their names across types', () => {
    const sel = paramsFor('sel', ['const']);
    const v = readParams(sel, 'headline........:1:3:d-a-=-b:headline........+:::1');
    assert.equal(writeParams(paramsFor('rio', ['const']), v), '::headline........:3:d-a-=-b:headline........+');
    assert.equal(writeParams(paramsFor('fld'), v), '');
    assert.equal(writeParams(sel, v), 'headline........:1:3:d-a-=-b:headline........+:::1');
});

const list = { items: { fld: { params: [{ id: 'width' }] }, chb: { params: [] } } };

test('paramsOf', () => {
    assert.deepEqual(paramsOf(list, 'fld'), [{ id: 'width' }]);
    assert.equal(paramsOf(list, 'chb').length, 1);
    assert.equal(paramsOf(list, 'chb')[0].id, '*chb');
    assert.equal(paramsOf(list, 'xyz').length, 1);
    assert.equal(paramsOf(list, 'xyz')[0].id, '*xyz');
});

test('a type without parameters keeps its text', () => {
    const p = paramsOf(list, 'chb');
    assert.equal(readParams(p, 'foo:bar').get('*chb'), 'foo:bar');
    assert.equal(writeParams(p, new Map([['*chb', 'foo:bar']])), 'foo:bar');
});

test('splitFirst', () => {
    assert.deepEqual(splitFirst('a#:b:c:d'), { first: 'a:b', rest: 'c:d' });
    assert.deepEqual(splitFirst('abc'), { first: 'abc', rest: '' });
    assert.deepEqual(splitFirst(''), { first: '', rest: '' });
});

test('constToText', () => {
    assert.equal(constToText('v436f6c6f72735f61735f746573745f5f'), 'Colors_as_test__');
    assert.equal(constToText('vc5a1'), 'š');
    assert.equal(constToText('#sLiCe-0ee39baef0bad8fe56912161f13bd369'), '#sLiCe-0ee39baef0bad8fe56912161f13bd369');
    assert.equal(constToText('0'), '');
    assert.equal(constToText(''), '');
    assert.equal(constToText('vzz'), 'vzz');
    assert.equal(constToText('vff'), 'vff');
});
