// APC-AA javascript functions for HTMLArea

xinha_editors = null;
xinha_config  = null;
xinha_plugins = null;

/** Start all the htmlareas, which are listed in htmlareas array */
function xinha_init() {
    // What are the plugins you will be using in the editors on this page.
    // List all the plugins you will need, even if not all the editors will
    // use all the plugins.
    //
    // FullScreen plugin is loaded automaticaly,
    // EnterParagraphs   is loaded for all gecko based browsers
    //                   (acordingly on mozParaHandler variable)
    //

//    xinha_plugins = ['TableOperations', 'ImageManager', 'InsertFile', 'CSS', 'InsertAnchor', 'EditTag', 'ContextMenu', 'FindReplace', 'InsertWords', 'Stylist'];
    xinha_plugins = ['TableOperations', 'ImageManager', 'InsertFile', 'InsertAnchor'];

    // THIS BIT OF JAVASCRIPT LOADS THE PLUGINS, NO TOUCHING  :)
    if(!HTMLArea.loadPlugins(xinha_plugins, xinha_init)) return;

    // The names of the textareas turning into editors

    xinha_editors = htmlareas;    //  ['myTextArea'];

    // Default configuration to be used by all the editors.
    // (later you will be able to change the defaults for specified textareas
    //
    // If you want to modify the default config you might do something like this
    //   xinha_config = new HTMLArea.Config();
    //   xinha_config.width  = 640;
    //   xinha_config.height = 420;

    xinha_config = new HTMLArea.Config();

    // Create editors for the textareas. You can do this in two ways, either:
    //
    //     xinha_editors   = HTMLArea.makeEditors(xinha_editors, xinha_config, xinha_plugins);
    //
    // if you want all the editors to use the same plugins, OR:
    //
    //   xinha_editors = HTMLArea.makeEditors(xinha_editors, xinha_config);
    //   xinha_editors['myTextArea'].registerPlugins(['Stylist','FullScreen']);
    //   xinha_editors['anotherOne'].registerPlugins(['CSS','SuperClean']);
    //
    // if you want to use a different plugins for editors

    xinha_editors   = HTMLArea.makeEditors(xinha_editors, xinha_config, xinha_plugins);

    // If you want to change the configuration variables of any of the editors,
    // this is the place to do that. For example you might want to change
    // the width and height of one of the editors, like this...
    //
    //   xinha_editors.myTextArea.config.width  = 640;
    //   xinha_editors.myTextArea.config.height = 480;

    // "start" the editors: turns the textareas into Xinha editors

    HTMLArea.startEditors(xinha_editors);
    window.onload = null;
}

function switchHTML(name) {
    elem = $$('input[name="'+name+"html"+'"]');
    //elem = document.inputform.eval(name+"html");
    if ( elem != null ) {
        for (i=0; i<elem.length; i++) {
            if (elem[i].value == "h") {
                elem[i].checked = true;
            }
        }
    }
    if (HTMLArea.checkSupportedBrowser) {
        $("htmlplainspan"+name).hide();
    }
}

function showHTMLAreaLink(name) {
    if (HTMLArea.checkSupportedBrowser()) {
        elem = document.getElementById("arealinkspan"+name);
        if( elem && (elem != null) ) {
            elem.style.display = "inline";
        }
    }
}

function openHTMLAreaFullscreen(name, session) { // open HTMLArea in popupwindow
    var heditor = new HTMLArea(name, HTMLArea.cloneObject(new HTMLArea.Config()));
    heditor.generate();
    setTimeout(function() {
        heditor.activateEditor();
        heditor._fullScreen();
        // doesn't work, for some reason - disabled, Honza 14.12.2006
        // heditor._toolbarObjects[FullScreen].swapImage([_editor_url + cfg.imgURL + 'ed_buttons_main.gif',8,0]);
    }, 500);
    switchHTML(name);

//    alert(xinha_editors);
//    heditor.focusEditor();
//    heditor._fullScreen();
//    xinha_editors['v66756c6c5f746578742e2e2e2e2e2e2e'].focusEditor();
//    FullScreen(editor);
//    xinha_editors[name] = editor;
//    xinha_editors[name].focusEditor();
    //xinha_editors[name]._fullScreen();
//    xinha_editors[name].tb_objects[FullScreen].swapImage([_editor_url + cfg.imgURL + 'ed_buttons_main.gif',9,0]);
//    switchHTML(name);
}

