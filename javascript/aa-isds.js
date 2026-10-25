/**
 * <aa-isds-campaign> — the root of a page that sends a letter to many data boxes (aa-isds@1).
 *
 *   {require:aa-isds@1}
 *   <aa-isds-campaign class="aa-isds aa-isds--env-test"> …server-rendered forms… </aa-isds-campaign>
 *
 * The server renders every state of the page and every action is a plain form, so the page works
 * without JS; the element only enhances it. The look is css/aa-isds.css, scoped under .aa-isds.
 * Pure functions are exported, so node --test imports this file without a DOM.
 */

/** roots set up already - connectedCallback runs again each time the element is moved */
const done = new WeakSet();

/** what each root is set up with, in order */
const SETUPS = Object.freeze([]);

/**
 * Sets a root up once; any later call for the same root does nothing.
 * A setup that fails switches off only its own feature - the forms work without it.
 * @param {Element} root
 * @param {ReadonlyArray<function(Element): void>} setups
 * @returns {boolean} whether it was set up by this call
 */
export const initRoot = (root, setups = SETUPS) => {
    if (done.has(root)) {
        return false;
    }
    done.add(root);
    for (const setup of setups) {
        try {
            setup(root);
        } catch (e) {
            console.error('aa-isds:', e);
        }
    }
    root.setAttribute('ready', '');
    return true;
};

if (globalThis.customElements && !customElements.get('aa-isds-campaign')) {
    customElements.define('aa-isds-campaign', class extends HTMLElement {
        connectedCallback() {
            initRoot(this);
        }
    });
}
