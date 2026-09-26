/**
 * TinyMCE 8 for AA - build input for javascript/tinymce8.min.js (see build.sh).
 * Self-hosted under GPL-2.0-or-later: license_key 'gpl' means no Tiny Cloud, no API key
 * and no request to any other server.
 */
import tinymce from 'tinymce/tinymce.js';
import 'tinymce/models/dom/index.js';
import 'tinymce/themes/silver/index.js';
import 'tinymce/icons/default/index.js';
// skins as JS modules, so no stylesheet is fetched at run time
import 'tinymce/skins/ui/oxide/skin.js';
import 'tinymce/skins/ui/oxide/content.js';
import 'tinymce/skins/content/default/content.js';
import 'tinymce/plugins/lists/index.js';
import 'tinymce/plugins/advlist/index.js';
import 'tinymce/plugins/link/index.js';
import 'tinymce/plugins/image/index.js';
import 'tinymce/plugins/media/index.js';
import 'tinymce/plugins/table/index.js';
import 'tinymce/plugins/charmap/index.js';
import 'tinymce/plugins/anchor/index.js';
import 'tinymce/plugins/searchreplace/index.js';
import 'tinymce/plugins/visualblocks/index.js';
import 'tinymce/plugins/fullscreen/index.js';
import 'tinymce/plugins/autolink/index.js';

const PLUGINS = 'lists advlist link image media table charmap anchor searchreplace visualblocks fullscreen autolink';

/** the two rows CKEditor 4 had in AA, so editors find the buttons where they were */
const TOOLBAR = [
    'cut copy paste pastetext | undo redo | link unlink anchor | image media table hr charmap | fullscreen | aasource',
    'bold italic strikethrough subscript superscript removeformat | numlist bullist | outdent indent | blockquote | alignleft aligncenter alignright | styles blocks fontsize | forecolor backcolor',
];

/** the styles AA offered in CKEditor 4 */
const STYLE_FORMATS = [
    { title: 'Italic Title', block: 'h2', styles: { 'font-style': 'italic' } },
    { title: 'Subtitle', block: 'h3', styles: { color: '#aaa', 'font-style': 'italic' } },
    { title: 'Special Container', block: 'div', wrapper: true, styles: { padding: '5px 10px', background: '#eee', border: '1px solid #ccc' } },
    { title: 'Marker', inline: 'span', classes: 'marker' },
    { title: 'Big', inline: 'big' },
    { title: 'Small', inline: 'small' },
    { title: 'Typewriter', inline: 'tt' },
    { title: 'Computer Code', inline: 'code' },
    { title: 'Keyboard Phrase', inline: 'kbd' },
    { title: 'Sample Text', inline: 'samp' },
    { title: 'Variable', inline: 'var' },
    { title: 'Deleted Text', inline: 'del' },
    { title: 'Inserted Text', inline: 'ins' },
    { title: 'Cited Work', inline: 'cite' },
    { title: 'Inline Quotation', inline: 'q' },
    { title: 'Language: RTL', inline: 'span', attributes: { dir: 'rtl' } },
    { title: 'Language: LTR', inline: 'span', attributes: { dir: 'ltr' } },
    { title: 'Styled Image (left)', selector: 'img', classes: 'left' },
    { title: 'Styled Image (right)', selector: 'img', classes: 'right' },
    { title: 'Compact Table', selector: 'table', attributes: { cellpadding: '5', cellspacing: '0', border: '1', bordercolor: '#ccc' }, styles: { 'border-collapse': 'collapse' } },
    { title: 'Borderless Table', selector: 'table', styles: { 'border-style': 'hidden', 'background-color': '#E6E6FA' } },
    { title: 'Square Bulleted List', selector: 'ul', styles: { 'list-style-type': 'square' } },
];

/** AA UI language => language pack in ./tinymce8/langs (build.sh copies exactly these) */
const LANGS = { bg: 'bg-BG', cs: 'cs', cz: 'cs', de: 'de', es: 'es', fr: 'fr-FR', hr: 'hr', hu: 'hu-HU', ja: 'ja', pl: 'pl', pt: 'pt-PT', ro: 'ro', sk: 'sk', uk: 'uk', vi: 'vi', vn: 'vi' };

/** the ?v= of the bundle busts the language packs too */
const languageUrl = (language) => {
    const self = new URL(import.meta.url);
    const url  = new URL(`./tinymce8/langs/${language}.js`, self);
    url.search = self.search;
    return url.href;
};

/** where attributes wait while they are edited - a prefix stored HTML does not use */
const PARKED = 'data-aa-parked-';
/** every on* attribute this browser knows, plus the SVG animation events */
const EVENT_ATTRS = [...new Set([
    ...[HTMLElement.prototype, Document.prototype, Window.prototype].flatMap(proto => Object.keys(proto)),
    'onbegin', 'onend', 'onrepeat',
])].filter(name => /^on[a-z]+$/.test(name));
/** URLs that run script as soon as the element loads */
const URL_ATTRS  = ['src', 'data', 'action', 'formaction'];
const SCRIPT_URL = /^\s*(javascript|vbscript|data:text\/html)/i;
/** shown as placeholders (media_live_embeds: false), so they load nothing - and the media
 *  plugin keeps their attributes itself, a parked one would be lost */
const PLACEHOLDERS = new Set(['iframe', 'video', 'audio', 'object', 'embed']);

/**
 * With a schema this permissive TinyMCE would run what stored HTML carries - an onerror
 * of an image ran in the edit view (measured). While edited, such attributes wait under
 * data-aa-parked-*; on save they come back unchanged.
 */
function guardEditView(editor) {
    const park = (nodes, name) => nodes.forEach((node) => {
        node.attr(PARKED + name, node.attr(name));
        node.attr(name, null);
    });
    const unpark = (nodes, name) => nodes.forEach((node) => {
        node.attr(name.slice(PARKED.length), node.attr(name));
        node.attr(name, null);
    });
    editor.parser.addAttributeFilter([...EVENT_ATTRS, 'srcdoc'].join(','), park);
    editor.parser.addAttributeFilter(URL_ATTRS.join(','), (nodes, name) => park(nodes.filter(node => !PLACEHOLDERS.has(node.name) && SCRIPT_URL.test(node.attr(name) ?? '')), name));
    editor.serializer.addAttributeFilter([...EVENT_ATTRS, 'srcdoc', ...URL_ATTRS].map(name => PARKED + name).join(','), unpark);
}

function uploadOptions({ image, imageTypes, file, pick, documentTypes }, onEdit) {
    return {
        automatic_uploads: true,
        ...(imageTypes?.length ? { images_file_types: imageTypes.join(',') } : {}),
        images_upload_handler: blobInfo => image(blobInfo.blob(), blobInfo.filename()).then(
            (stored) => {
                // TinyMCE swaps the blob: URL for this one after we return, without an event of its own
                setTimeout(() => onEdit?.(), 0);
                return stored.url;
            },
            error => Promise.reject({ message: error.message, remove: true }),
        ),
        ...(pick ? {
            file_picker_types: 'file image media',
            file_picker_callback: (callback, value, meta) => {
                pick((meta.filetype === 'image') ? 'image' : 'all').then((picked) => {
                    if (picked) {
                        callback(picked.url, (meta.filetype === 'file') ? { text: picked.name } : { alt: '' });
                    }
                });
            },
        } : {}),
        ...((file && documentTypes?.length) ? {
            link_uploadtab: true,
            files_upload_handler: blobInfo => file(blobInfo.blob(), blobInfo.filename()).then(stored => ({ url: stored.url, fileName: stored.name })),
            documents_file_types: documentTypes,
        } : { link_uploadtab: false }),
    };
}

/**
 * @param {HTMLElement} target  an unnamed textarea (never the one a form submits), or with
 *                              inline: true the element edited in place - only for content the
 *                              page already shows, as the iframe's CSP does not guard it
 * @param {object} [options]    see the task description above
 */
export async function createRichEditor(target, { uiLang, inline = false, height = 300, sourceLabel = 'Source', onEdit, onBlur, onSource, upload = null } = {}) {
    const language = LANGS[String(uiLang ?? '').slice(0, 2).toLowerCase()];
    const [editor] = await tinymce.init({
        target,
        inline,
        height,
        license_key: 'gpl',
        branding: false,
        promotion: false,
        menubar: false,
        elementpath: false,
        resize: true,
        toolbar_mode: 'wrap',
        toolbar: onSource ? TOOLBAR : TOOLBAR.map(row => row.replace(' | aasource', '')),
        plugins: PLUGINS,
        ...(language ? { language, language_url: languageUrl(language) } : {}),
        block_formats: 'Paragraph=p; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6; Preformatted=pre',
        style_formats: STYLE_FORMATS,
        browser_spellcheck: true,
        image_caption: true,
        content_style: 'body { font: 13px/1.6 Arial, Helvetica, Tahoma, Verdana, sans-serif; margin: 20px; color: #333; } table[border] td { border: 1px solid #333; }',
        // keep what AA stores: any element, any attribute, URLs as written, entities only where they were
        valid_elements: '*[*]',
        extended_valid_elements: '*[*]',
        valid_children: '+body[style|script|iframe|video|center|form|a],+p[iframe|video]',
        convert_urls: false,
        convert_fonts_to_spans: false,
        entity_encoding: 'named',
        entities: '160,nbsp,173,shy',
        sandbox_iframes: false,
        convert_unsafe_embeds: false,
        xss_sanitization: false,
        allow_script_urls: true,
        // SVG would render - and run - in the edit view, and a script at the top level would
        // get wrapped in <p>; protected, both are kept as written, not shown
        protect: [/<svg[\s\S]*?<\/svg>/gi, /<script[\s\S]*?<\/script>/gi],
        // iframes, video and audio as placeholders, as in CKEditor 4: nothing embedded loads
        // or plays in the edit view
        media_live_embeds: false,
        // the edit iframe shares the admin's origin: whatever slips past guardEditView() (an unclosed
        // SVG, an iframe in an embed wrapper, mXSS on re-parse) must still not run
        content_security_policy: "script-src 'none'; object-src 'none'; frame-src 'none'",
        paste_data_images: !!upload,
        ...(upload ? uploadOptions(upload, onEdit) : {}),
        setup: (ed) => {
            ed.on('PreInit', () => guardEditView(ed));
            if (onSource) {
                ed.ui.registry.addButton('aasource', { icon: 'sourcecode', text: sourceLabel, tooltip: sourceLabel, onAction: () => onSource() });
            }
            // typing makes TinyMCE dirty only with its next undo level; the textarea needs it now
            ed.on('input', () => ed.setDirty(true));
            ed.on('input change undo redo ExecCommand SetContent keyup', (e) => {
                if (!e.initial && ed.isDirty()) {
                    onEdit?.();
                }
            });
            ed.on('blur', () => onBlur?.());
        },
    });
    return {
        getContent: () => editor.getContent(),
        setContent: (html) => {
            editor.setContent(html);
            editor.setDirty(true);
            onEdit?.();
        },
        // what a script wrote into the textarea: shown, not an edit - the textarea keeps it as written
        load: (html) => {
            editor.setContent(html);
            editor.setDirty(false);
        },
        isDirty: () => editor.isDirty(),
        markSaved: () => editor.setDirty(false),
        focus: () => editor.focus(),
        destroy: () => editor.remove(),
    };
}
