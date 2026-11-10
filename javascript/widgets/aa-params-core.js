/**
 * Reads and writes a parameter string the way AA_Serializable::parseProperties() reads it
 * ("a:b#:c" = two parameters, the second "b:c"), for <aa-params>.
 * No DOM here, so that tests/js can load it.
 */

// ":" not escaped as "#:" - the same places PHP splits at
const SEPARATOR = /(?<!#):/;
const PHP_TRIM = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g;

const unescape = (segment) => segment.replaceAll('#:', ':');

/**
 * @param {{items: Object<string, {params: Array}>}} list one list of window.AA_PARAMS
 * @param {string} type
 * @returns {Array<{id: string}>} parameters of the type; a type that describes none (or is not known)
 *          gets one that keeps the whole text, so nothing written there is lost
 */
export function paramsOf(list, type) {
    const params = list?.items?.[type]?.params;
    return params?.length ? params : [{ id: '*' + type, name: '', type: 'string', help: '', example: '' }];
}

/**
 * @param {Array<{id: string}>} params
 * @param {string} text
 * @param {boolean} quote the type reads ' as : (old date fields, AA_Field::widgetParams())
 * @returns {Map<string, string>} values by parameter id; what is over joins the last one, as in PHP
 */
export function readParams(params, text, quote = false) {
    const source = (quote && text.includes("'")) ? text.replaceAll("'", ':') : text;
    const segments = source.split(SEPARATOR).map(unescape);
    const values = new Map();
    params.forEach(({ id }, i) => values.set(id, (segments[i] ?? '').replace(PHP_TRIM, '')));
    if (params.length && (segments.length > params.length)) {
        const last = params[params.length - 1].id;
        values.set(last, values.get(last) + ':' + segments.slice(params.length).join(':'));
    }
    return values;
}

/**
 * @param {Array<{id: string}>} params
 * @param {Map<string, string>} values values of any ids, only those of params are written
 * @returns {string} without trailing empty parameters; ":" escaped except in the last parameter
 *          of the type, which PHP reads whole (and older one-parameter classes do not know "#:")
 */
export function writeParams(params, values) {
    const out = params.map(({ id }, i) => {
        const value = values.get(id) ?? '';
        return (i === params.length - 1) ? value : value.replaceAll(':', '#:');
    });
    while (out.length && (out[out.length - 1] === '')) {
        out.pop();
    }
    return out.join(':');
}

/**
 * @param {string} text
 * @returns {{first: string, rest: string}} the first parameter (unescaped) and the rest as it is
 */
export function splitFirst(text) {
    const m = SEPARATOR.exec(text);
    return m ? { first: unescape(text.slice(0, m.index)), rest: text.slice(m.index + 1) } : { first: unescape(text), rest: '' };
}

/**
 * @param {string} value option value of the constants select: v<hex of the group name>, #sLiCe-<id>, 0
 * @returns {string} the group as it is written in the stored string
 */
export function constToText(value) {
    if ((value === '') || (value === '0')) {
        return '';
    }
    if (!/^v(?:[0-9a-f]{2})+$/i.test(value)) {
        return value;
    }
    const bytes = new Uint8Array(value.slice(1).match(/../g).map((h) => parseInt(h, 16)));
    try {
        return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
    } catch {
        return value;
    }
}
