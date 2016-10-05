/**
 * @license Copyright (c) 2003-2016, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

// when upgrading, keep this file - all others you can delete/replace. Honza

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
    config.removeButtons = 'Underline,JustifyLeft,JustifyBlock,Font,About';
    // config.removeButtons = 'Underline,Subscript,Superscript,Font,About';   // Honza Change

    // Set the most common block elements.
    //config.format_tags = 'p;h1;h2;h3;pre';
    config.format_tags = 'p;h1;h2;h3;h4;h5;h6;pre';  // Honza Change

    // Simplify the dialog windows.
    //config.removeDialogTabs = 'image:advanced;link:advanced'; // Honza Change

    // Honza's changes
    config.entities = false;
    config.extraPlugins = 'confighelper';  // for placeholder of editor
    //config.extraAllowedContent = 'span;*[id];a[rel];*(*);*{*}'; // any class and any inline style...
    config.allowedContent = true;   // disable filtering at all - toto je poslední možnost

    // allow i tags to be empty (for font awesome)
    CKEDITOR.dtd.$removeEmpty['i'] = false

    config.embed_provider = '//iframe.ly/api/oembed?url={url}&callback={callback}&api_key=31993d559460afddc115dc';

    config.filebrowserBrowseUrl      = _aa_url + 'misc/filebrowser/browse.php?opener=ckeditor&type=files';
    config.filebrowserImageBrowseUrl = _aa_url + 'misc/filebrowser/browse.php?opener=ckeditor&type=images';
    config.filebrowserFlashBrowseUrl = _aa_url + 'misc/filebrowser/browse.php?opener=ckeditor&type=flash';
    config.filebrowserUploadUrl      = _aa_url + 'misc/filebrowser/upload.php?opener=ckeditor&type=files';
    config.filebrowserImageUploadUrl = _aa_url + 'misc/filebrowser/upload.php?opener=ckeditor&type=images';
    config.filebrowserFlashUploadUrl = _aa_url + 'misc/filebrowser/upload.php?opener=ckeditor&type=flash';
};
