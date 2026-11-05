// An <aa-richtext> written in a template asks AA for its uploads - which slice, and what it gets.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { uploadTarget, askUpload } from '../../javascript/widgets/aa-richtext-upload.js';

const SLICE = '0123456789abcdef0123456789abcdef';
const ITEM  = 'fedcba9876543210fedcba9876543210';
const API   = 'https://aa.example/aaa/api/files.php';

test('upload-slice wins over the name of the textarea', () => {
    assert.equal(uploadTarget(SLICE, `aa[u${ITEM}][full_text_______]`).toString(), `ask=1&slice=${SLICE}`);
});

test('the AA name of a new item tells its slice, of a stored one its item', () => {
    assert.equal(uploadTarget(null, `aa[n1_${SLICE}][full_text_______]`).toString(), `ask=1&slice=${SLICE}`);
    assert.equal(uploadTarget('', `aa[n12_${SLICE}][x]`).toString(), `ask=1&slice=${SLICE}`);
    assert.equal(uploadTarget(null, `aa[u${ITEM}][full_text_______]`).toString(), `ask=1&item=${ITEM}`);
});

test('no slice to tell, nothing to ask', () => {
    for (const [slice, name] of [[null, 'isds_header'], ['', ''], ['abc', 'body'], [null, `x[n1_${SLICE}][a]`], [SLICE.toUpperCase(), 'a']]) {
        assert.equal(uploadTarget(slice, name), null, `${slice} ${name}`);
    }
});

/** a fetch that answers $body with $status and counts its calls */
const fakeFetch = (status, body) => {
    const calls = [];
    const get = async (url, options) => {
        calls.push([String(url), options]);
        return { ok: status === 200, json: async () => body };
    };
    return { get, calls };
};

test('the attributes come back, asked once per slice for every editor of the page', async () => {
    const { get, calls } = fakeFetch(200, { attrs: { upload: 'user', 'upload-token': 't', 'upload-slice': SLICE, bad: 5 } });
    const target = uploadTarget(SLICE, '');
    const [a, b] = await Promise.all([askUpload(API, target, get), askUpload(API, target, get)]);
    assert.deepEqual(a, { upload: 'user', 'upload-token': 't', 'upload-slice': SLICE });
    assert.equal(a, b);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], `${API}?ask=1&slice=${SLICE}`);
    assert.deepEqual(calls[0][1], { headers: { 'X-AA-Upload-Ask': '1' }, credentials: 'same-origin' });
});

test('a refusal or a broken answer leave the editor without uploads', async (t) => {
    t.mock.method(console, 'warn', () => {});
    const cases = [[200, { attrs: [] }], [401, { error: 'Unauthorized' }], [200, { attrs: 'x' }], [200, null]];
    for (const [i, [status, body]] of cases.entries()) {
        const { get } = fakeFetch(status, body);
        assert.deepEqual(await askUpload(API, uploadTarget(null, `aa[u${ITEM.slice(0, 31)}${i}][a]`), get), {});
    }
});

test('a failed question is asked again, an answered one is not', async (t) => {
    const warn = t.mock.method(console, 'warn', () => {});
    const target  = uploadTarget(null, `aa[n1_${ITEM}][a]`);
    const failing = async () => { throw new TypeError('offline'); };
    assert.deepEqual(await askUpload(API, target, failing), {});
    assert.equal(warn.mock.callCount(), 1);
    const { get, calls } = fakeFetch(200, { attrs: { upload: 'none' } });
    assert.deepEqual(await askUpload(API, target, get), { upload: 'none' });
    assert.deepEqual(await askUpload(API, target, get), { upload: 'none' });
    assert.equal(calls.length, 1);
});
