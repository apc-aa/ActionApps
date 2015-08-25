/**
 * @license Copyright (c) 2003-2015, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.editorConfig = function( config ) {
    // Define changes to default configuration here.
    // For complete reference see:
    // http://docs.ckeditor.com/#!/api/CKEDITOR.config

    // The toolbar groups arrangement, optimized for two toolbar rows.
    config.toolbarGroups = [
        { name: 'clipboard',   groups: [ 'clipboard', 'undo' ] },
        { name: 'editing',     groups: [ 'find', 'selection', 'spellchecker' ] },
        { name: 'links' },
        { name: 'insert' },
        { name: 'forms' },
        { name: 'tools' },
        { name: 'document',	   groups: [ 'mode', 'document', 'doctools' ] },
        { name: 'others' },
        '/',
        { name: 'basicstyles', groups: [ 'basicstyles', 'cleanup' ] },
        { name: 'paragraph',   groups: [ 'list', 'indent', 'blocks', 'align', 'bidi' ] },
        { name: 'styles' },
        { name: 'colors' },
        { name: 'about' }
    ];

    // Remove some buttons provided by the standard plugins, which are
    // not needed in the Standard(s) toolbar.
    config.removeButtons = 'Underline,Subscript,Superscript';

    // Set the most common block elements.
    config.format_tags = 'p;h1;h2;h3;pre';

    // Simplify the dialog windows.
    config.removeDialogTabs = 'image:advanced;link:advanced';

    // Honza's changes
    config.entities = false;

    //config.filebrowserBrowseUrl = '/aaa/misc/filebrowser/browse.php?opener=ckeditor&type=files';
    //config.filebrowserImageBrowseUrl = '/aaa/misc/filebrowser/browse.php?opener=ckeditor&type=images';
    //config.filebrowserFlashBrowseUrl = '/aaa/misc/filebrowser/browse.php?opener=ckeditor&type=flash';
    //config.filebrowserUploadUrl = '/aaa/misc/filebrowser/upload.php?opener=ckeditor&type=files';
    //config.filebrowserImageUploadUrl = '/aaa/misc/filebrowser/upload.php?opener=ckeditor&type=images';
    //config.filebrowserFlashUploadUrl = '/aaa/misc/filebrowser/upload.php?opener=ckeditor&type=flash';

};
