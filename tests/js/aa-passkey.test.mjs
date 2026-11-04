// <aa-passkey>: the options of AA\Passkey\Ceremony into what navigator.credentials takes, and its answer back as the server reads it.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { answerJson, errorText, fromUrl, publicKeyOptions, toUrl } from '../../javascript/aa-passkey.js';

const bytes = (...b) => Uint8Array.from(b);

test('base64url without padding, both ways', () => {
    for (const b of [bytes(), bytes(0xfb), bytes(0xfb, 0xff), bytes(0xfb, 0xff, 0xbf), bytes(0, 1, 2, 3, 250)]) {
        const url = toUrl(b.buffer);
        assert.doesNotMatch(url, /[+/=]/);
        assert.deepEqual(fromUrl(url), b);
    }
    assert.equal(toUrl(bytes(0xfb, 0xff).buffer), '-_8');
});

test('options: the binary values become bytes, the rest stays', () => {
    const o = publicKeyOptions(JSON.stringify({
        challenge: 'AQID', rpId: 'aa.example.org', userVerification: 'required', timeout: 300000,
        user: { id: 'BAU', name: 'honza', displayName: 'Honza' },
        excludeCredentials: [{ id: 'Bgc', type: 'public-key', transports: ['usb'] }],
    }));
    assert.deepEqual(o.challenge, bytes(1, 2, 3));
    assert.deepEqual(o.user.id, bytes(4, 5));
    assert.equal(o.user.name, 'honza');
    assert.deepEqual(o.excludeCredentials[0].id, bytes(6, 7));
    assert.deepEqual(o.excludeCredentials[0].transports, ['usb']);
    assert.equal(o.allowCredentials, undefined);
    assert.equal(o.rpId, 'aa.example.org');
});

test('a login answer: signature and user handle, null handle kept', () => {
    const credential = (userHandle) => ({
        id: 'Cgs', rawId: bytes(10, 11).buffer, type: 'public-key',
        response: { clientDataJSON: bytes(1).buffer, authenticatorData: bytes(2).buffer, signature: bytes(3).buffer, userHandle },
    });
    assert.deepEqual(JSON.parse(answerJson(credential(bytes(4).buffer))), {
        id: 'Cgs', rawId: 'Cgs', type: 'public-key',
        response: { clientDataJSON: 'AQ', authenticatorData: 'Ag', signature: 'Aw', userHandle: 'BA' },
    });
    assert.equal(JSON.parse(answerJson(credential(null))).response.userHandle, null);
});

test('a registration answer: the attestation object only', () => {
    const answer = JSON.parse(answerJson({
        id: 'Cgs', rawId: bytes(10, 11).buffer, type: 'public-key',
        response: { clientDataJSON: bytes(1).buffer, attestationObject: bytes(5).buffer },
    }));
    assert.deepEqual(answer.response, { clientDataJSON: 'AQ', attestationObject: 'BQ' });
});

test('errors: own abort silent, the rest a text of the server', () => {
    assert.equal(errorText({ name: 'AbortError' }), null);
    assert.equal(errorText({ name: 'NotAllowedError' }), 'text-cancelled');
    assert.equal(errorText({ name: 'InvalidStateError' }), 'text-exists');
    assert.equal(errorText({ name: 'SecurityError' }), 'text-failed');
    assert.equal(errorText(undefined), 'text-failed');
});
