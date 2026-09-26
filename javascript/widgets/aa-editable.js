/**
 * {editable}: in-place editing of item fields on a page.
 *
 *   <div contenteditable=true data-aa-id="<item>" data-aa-field="<field>" data-aa-index="0">…</div>
 *
 * A block element gets TinyMCE inline when it first gets focus; a <span> (single-line field)
 * stays a plain contenteditable - TinyMCE inline needs a block to put paragraphs in. Every
 * change dispatches "aa-editable-change" on document (the page then offers its save button);
 * AA_SaveEditors() reads el.aaEditor and saves all changed fields in one request.
 */

// Carry our own ?v= over, so one AA_JS_VERSION bump busts the bundle too.
const BUNDLE_URL = (() => {
    const self = new URL(import.meta.url);
    const url  = new URL('../tinymce8.min.js', self);
    url.search = self.search;
    return url;
})();

// CKEditor 4 loaded by an old template would make every contenteditable its own inline editor as well
if (window.CKEDITOR) {
    window.CKEDITOR.disableAutoInline = true;
}

const changed = el => document.dispatchEvent(new CustomEvent('aa-editable-change', { detail: el }));

function plainEditor(el, saved = el.innerHTML) {
    el.addEventListener('input', () => changed(el));
    return {
        isDirty: () => el.innerHTML !== saved,
        getContent: () => el.innerHTML,
        markSaved: () => { saved = el.innerHTML; },
    };
}

document.addEventListener('focusin', async (e) => {
    const el = e.target.closest?.('[contenteditable][data-aa-field]');
    if (!el || el.aaEditor || el.aaEditorLoading) {
        return;
    }
    if (el.localName === 'span') {
        el.aaEditor = plainEditor(el);
        return;
    }
    el.aaEditorLoading = true;
    const before = el.innerHTML;               // what is typed while the editor loads is an edit too
    try {
        const { createRichEditor } = await import(BUNDLE_URL);
        el.aaEditor = await createRichEditor(el, {
            inline: true,
            uiLang: document.documentElement.lang,
            onEdit: () => changed(el),
        });
        el.aaEditor.focus();
    } catch (error) {
        console.error('aa-editable: could not start the editor', error);
        el.aaEditor = plainEditor(el, before);  // still editable and saved, without the toolbar
        if (el.aaEditor.isDirty()) {
            changed(el);
        }
    } finally {
        el.aaEditorLoading = false;
    }
});
