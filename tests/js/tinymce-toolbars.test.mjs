// The toolbars of <aa-richtext>: item editing keeps its full toolbar, a letter gets a short one.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { toolbarPreset } from '../../dev/tinymce8/toolbars.js';

/** the toolbar of item editing - pinned, so a preset cannot change it */
const FULL = {
    toolbar: [
        'cut copy paste pastetext | undo redo | link unlink anchor | image media table hr charmap | fullscreen | aasource',
        'bold italic strikethrough subscript superscript removeformat | numlist bullist | outdent indent | blockquote | alignleft aligncenter alignright | styles blocks fontsize | forecolor backcolor',
    ],
    plugins:      'lists advlist link image media table charmap anchor searchreplace visualblocks fullscreen autolink',
    blockFormats: 'Paragraph=p; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6; Preformatted=pre',
    contentStyle: 'body { font: 13px/1.6 Arial, Helvetica, Tahoma, Verdana, sans-serif; margin: 20px; color: #333; } table[border] td { border: 1px solid #333; }',
};

/** a letter's short toolbar: text, headings, lists and links - its PDF would keep more, the page offers no more */
const LETTER_BUTTONS = new Set(['undo', 'redo', 'blocks', 'bold', 'italic', 'underline', 'bullist', 'numlist', 'link', 'unlink', 'removeformat', '|']);
const LETTER_PLUGINS = new Set(['lists', 'link', 'autolink']);
const LETTER_BLOCKS  = new Set(['p', 'h3', 'h4']);

const buttons = preset => preset.toolbar.join(' ').split(/\s+/);

test('an unknown or missing toolbar is the full one of item editing', () => {
    for (const name of [undefined, '', 'full', 'Letter', 'unknown', '__proto__', 'constructor', 'toString']) {
        const preset = toolbarPreset(name, true);
        assert.deepEqual(preset.toolbar, FULL.toolbar, String(name));
        assert.equal(preset.plugins, FULL.plugins);
        assert.equal(preset.blockFormats, FULL.blockFormats);
        assert.equal(preset.contentStyle, FULL.contentStyle);
        assert.equal(preset.linkTargets, true);
        assert.equal(preset.styleFormats.length, 22);
        assert.deepEqual(preset.styleFormats[0], { title: 'Italic Title', block: 'h2', styles: { 'font-style': 'italic' } });
    }
});

test('the Source button only when asked for', () => {
    assert.deepEqual(toolbarPreset('').toolbar, FULL.toolbar.map(row => row.replace(' | aasource', '')));
    assert.ok(!buttons(toolbarPreset('', false)).includes('aasource'));
    assert.ok(buttons(toolbarPreset('', true)).includes('aasource'));
});

test('a letter has a short toolbar without link targets', () => {
    for (const source of [false, true]) {
        const letter = toolbarPreset('letter', source);
        assert.equal(letter.toolbar.length, 1);
        assert.deepEqual(buttons(letter).filter(button => !LETTER_BUTTONS.has(button)), []);
        assert.ok(buttons(letter).includes('bold') && buttons(letter).includes('link'));
        assert.deepEqual(letter.plugins.split(' ').filter(plugin => !LETTER_PLUGINS.has(plugin)), []);
        assert.deepEqual(letter.blockFormats.split('; ').map(format => format.split('=')[1]).filter(tag => !LETTER_BLOCKS.has(tag)), []);
        assert.deepEqual(letter.styleFormats, []);
        assert.match(letter.contentStyle, /^body \{[^}]*max-width: 170mm[^}]*\}$/);
        assert.equal(letter.linkTargets, false);
    }
});

test('a copy each time: what TinyMCE does to one does not reach the next editor', () => {
    const first = toolbarPreset('');
    first.toolbar.push('image');
    first.styleFormats[0].title = 'changed';
    const next = toolbarPreset('');
    assert.equal(next.toolbar.length, 2);
    assert.equal(next.styleFormats[0].title, 'Italic Title');
});
