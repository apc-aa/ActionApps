/**
 * Uploads for an <aa-richtext> AA did not render - a form written in a template: the editor asks
 * api/files.php for the upload-* attributes AA writes into the editors it renders itself.
 * The slice comes from the upload-slice attribute, else from the AA name of the textarea:
 * aa[n1_<slice id>][…] of a new item, aa[u<item id>][…] of a stored one.
 * No DOM here, so that tests/js can load it.
 */

const LONG_ID = /^[0-9a-f]{32}$/;
const AA_NAME = /^aa\[(?:n\d+_([0-9a-f]{32})|u([0-9a-f]{32}))\]/;

/**
 * @param {?string} slice the upload-slice attribute
 * @param {?string} name  the name of the textarea
 * @returns {?URLSearchParams} the query of the question; null when no slice can be told
 */
export function uploadTarget(slice, name) {
    if (LONG_ID.test(slice ?? '')) {
        return new URLSearchParams({ ask: '1', slice });
    }
    const m = AA_NAME.exec(name ?? '');
    if (!m) {
        return null;
    }
    return new URLSearchParams(m[1] ? { ask: '1', slice: m[1] } : { ask: '1', item: m[2] });
}

// all editors of a page asking about one slice share one request
const asked = new Map();

/**
 * @param {URL|string}      api    api/files.php
 * @param {URLSearchParams} target from uploadTarget()
 * @param {typeof fetch}    get    fetch - tests pass their own
 * @returns {Promise<Object<string, string>>} the attributes; {} when the viewer may not upload or the server did not answer
 */
export function askUpload(api, target, get = fetch) {
    const url = new URL(api);
    url.search = target.toString();
    if (!asked.has(url.href)) {
        asked.set(url.href, get(url, { headers: { 'X-AA-Upload-Ask': '1' }, credentials: 'same-origin' })
            .then(response => (response.ok ? response.json() : {}))
            .then(json => (((typeof json?.attrs === 'object') && json.attrs) || {}))
            .then(attrs => Object.fromEntries(Object.entries(attrs).filter(([, value]) => typeof value === 'string')))
            .catch(() => ({})));
    }
    return asked.get(url.href);
}
