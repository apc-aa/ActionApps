/**
 * The toolbars <aa-richtext toolbar="…"> offers. Plain data without DOM or TinyMCE, so that
 * tests/js reads the same module the bundle is built from.
 */

/** the two rows CKEditor 4 had in AA, so editors find the buttons where they were */
const FULL_ROWS = [
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

const PRESETS = {
    full: {
        rows:         FULL_ROWS,
        plugins:      'lists advlist link image media table charmap anchor searchreplace visualblocks fullscreen autolink',
        blockFormats: 'Paragraph=p; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6; Preformatted=pre',
        styleFormats: STYLE_FORMATS,
        contentStyle: 'body { font: 13px/1.6 Arial, Helvetica, Tahoma, Verdana, sans-serif; margin: 20px; color: #333; } table[border] td { border: 1px solid #333; }',
        linkTargets:  true,
    },
    // A data-message letter: plain text with headings, lists and links is what a letter needs, so
    // the toolbar stays short - its PDF would keep tables, colours and uploaded images too. No
    // link target: the PDF drops it. Font and column as the PDF's line, so lines break about as there.
    letter: {
        rows:         ['undo redo | blocks | bold italic underline | bullist numlist | link | removeformat'],
        plugins:      'lists link autolink',
        blockFormats: 'Paragraph=p; Heading 1=h3; Heading 2=h4',
        styleFormats: [],
        contentStyle: 'body { font: 11pt/1.45 "DejaVu Sans", Verdana, sans-serif; max-width: 170mm; margin: 20px; color: #000; }',
        linkTargets:  false,
    },
};

/**
 * @param {string}  name   the toolbar attribute; unknown or missing = the full toolbar of item editing
 * @param {boolean} source whether the Source button (aasource) is wanted, if the toolbar has one
 * @returns {{toolbar: string[], plugins: string, blockFormats: string, styleFormats: object[], contentStyle: string, linkTargets: boolean}}
 *          a copy - TinyMCE may keep what it is given
 */
export function toolbarPreset(name, source = false) {
    const preset = Object.hasOwn(PRESETS, name) ? PRESETS[name] : PRESETS.full;
    return {
        toolbar:      preset.rows.map(row => (source ? row : row.replace(' | aasource', ''))),
        plugins:      preset.plugins,
        blockFormats: preset.blockFormats,
        styleFormats: structuredClone(preset.styleFormats),
        contentStyle: preset.contentStyle,
        linkTargets:  preset.linkTargets,
    };
}
