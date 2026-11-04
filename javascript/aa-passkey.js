/**
 * <aa-passkey> — login by a passkey, or a new passkey of the logged-in account (aa-passkey@1).
 *
 *   <form method="post"> …
 *     <input type="hidden" name="passkey_login">
 *     <aa-passkey mode="get" field="passkey_login" options="{…}" expires="300" autofill
 *                 text-cancelled="…" text-exists="…" text-failed="…" text-unsupported="…">
 *       <button type="button" hidden>…</button> <span role="status"></span>
 *     </aa-passkey>
 *   </form>
 *
 * The server makes the options (AA\Passkey\Ceremony) and checks the answer; the element only asks the
 * browser, puts its answer into the hidden field and sends the form. mode="get" logs in, "create" makes a
 * passkey; autofill also offers the passkeys in the name field (autocomplete="username webauthn").
 * The options carry a one-time challenge valid for `expires` seconds - after that a click reloads the page.
 * Without WebAuthn the button stays hidden, text-unsupported is shown and the form works as before.
 * Pure functions are exported, so node --test imports this file without a DOM.
 */

/** base64url (no padding) of bytes */
export const toUrl = (buffer) => {
    const bytes = new Uint8Array(buffer);
    let bin = '';
    for (const b of bytes) {
        bin += String.fromCharCode(b);
    }
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

/** bytes of base64url */
export const fromUrl = (text) => {
    const b64 = text.replace(/-/g, '+').replace(/_/g, '/');
    const bin = atob(b64 + '='.repeat((4 - (b64.length % 4)) % 4));
    return Uint8Array.from(bin, (c) => c.charCodeAt(0));
};

/** the options of the server (binary values in base64url) as navigator.credentials wants them */
export const publicKeyOptions = (json) => {
    const o = JSON.parse(json);
    o.challenge = fromUrl(o.challenge);
    if (o.user) {
        o.user = { ...o.user, id: fromUrl(o.user.id) };
    }
    for (const list of ['allowCredentials', 'excludeCredentials']) {
        if (Array.isArray(o[list])) {
            o[list] = o[list].map((c) => ({ ...c, id: fromUrl(c.id) }));
        }
    }
    return o;
};

/** the answer of the browser as JSON for the server - the shape of PublicKeyCredential.toJSON() */
export const answerJson = (credential) => {
    const r = credential.response;
    const response = { clientDataJSON: toUrl(r.clientDataJSON) };
    if (r.attestationObject) {
        response.attestationObject = toUrl(r.attestationObject);
    } else {
        response.authenticatorData = toUrl(r.authenticatorData);
        response.signature = toUrl(r.signature);
        response.userHandle = r.userHandle ? toUrl(r.userHandle) : null;
    }
    return JSON.stringify({ id: credential.id, rawId: toUrl(credential.rawId), type: credential.type, response });
};

/** the attribute with the text for the error the browser gave; null for none to show */
export const errorText = (error) => {
    switch (error?.name) {
        case 'AbortError':
            return null;   // our own abort - the autofill request gives way to the button
        case 'NotAllowedError':
            return 'text-cancelled';   // closed the dialog, or no passkey of this site on the device
        case 'InvalidStateError':
            return 'text-exists';   // create: this authenticator already holds a passkey of the account
        default:
            return 'text-failed';
    }
};

const supported = () => typeof globalThis.PublicKeyCredential === 'function' && !!navigator.credentials;

if (globalThis.customElements && !customElements.get('aa-passkey')) {
    customElements.define('aa-passkey', class extends HTMLElement {
        connectedCallback() {
            if (this.hasAttribute('ready')) {
                return;
            }
            this.status = this.querySelector('[role="status"]');
            if (!supported() || !window.isSecureContext) {
                this.say(this.getAttribute('text-unsupported') ?? '');
                return;
            }
            this.setAttribute('ready', '');
            this.button = this.querySelector('button');
            this.form = this.closest('form');
            if (!this.button || !this.form) {
                return;
            }
            this.until = Date.now() + 1000 * Number(this.getAttribute('expires') ?? 300);
            this.button.hidden = false;
            this.button.addEventListener('click', () => this.ask(false));
            if (this.getAttribute('mode') === 'create') {
                // Enter in the name field would send the form without a passkey
                this.form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.ask(false);
                });
            } else if (this.hasAttribute('autofill')) {
                this.autofill();
            }
        }

        async autofill() {
            if (await PublicKeyCredential.isConditionalMediationAvailable?.()) {
                this.ask(true);
            }
        }

        /** @param {boolean} conditional the passkeys offered in the name field, no dialog */
        async ask(conditional) {
            if (!conditional && (Date.now() > this.until)) {
                location.replace(location.href);   // a new challenge; a GET, so no form is sent again
                return;
            }
            const mode = this.getAttribute('mode') === 'create' ? 'create' : 'get';
            this.pending?.abort();   // only one request at a time - the button takes over the autofill
            const pending = new AbortController();
            this.pending = pending;
            if (!conditional) {
                this.say('');
                this.button.disabled = true;
            }
            try {
                const publicKey = publicKeyOptions(this.getAttribute('options') ?? '{}');
                const credential = (mode === 'create')
                    ? await navigator.credentials.create({ publicKey, signal: pending.signal })
                    : await navigator.credentials.get({ publicKey, signal: pending.signal, ...(conditional ? { mediation: 'conditional' } : {}) });
                const field = this.form.elements.namedItem(this.getAttribute('field') ?? '');
                if (!credential || !field) {
                    return;
                }
                field.value = answerJson(credential);
                this.sent = true;
                this.form.submit();
            } catch (e) {
                const text = errorText(e);
                if (text !== null && !(conditional && e?.name === 'NotAllowedError')) {
                    this.say(this.getAttribute(text) ?? e.message);
                }
            } finally {
                if (this.pending === pending) {
                    this.button.disabled = false;
                    // the button aborted the autofill request - offer the passkeys in the name field again
                    if (!conditional && !this.sent && (mode === 'get') && this.hasAttribute('autofill') && (Date.now() <= this.until)) {
                        this.autofill();
                    }
                }
            }
        }

        say(text) {
            if (this.status) {
                this.status.textContent = text;
            }
        }
    });
}
