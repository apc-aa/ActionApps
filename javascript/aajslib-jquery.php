<?php
/** AA Javascripts library usable on the public pages, just like:
 *  <script type="text/javascript" src="http://actionapps.org/apc-aa/javascript/aajslib.php3"></script>
 *  (replace "http://actionapps.org/apc-aa" with your server and aa path
 *
 *  It includes the scripts, which are based on great prototype.js library
 *  (see http://prototype.conio.net/)
 *
 *  @package UserOutput
 *  @version $Id$
 *  @author Honza Malik <honza.malik@ecn.cz>
 *  @copyright Econnect, Honza Malik, December 2006
 *
 */
/*
Copyright (C) 2002 Association for Progressive Communications
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

// include config in order we can define AA_Config variables for javascript
require_once "../include/config.php3";

// headers copied from include/extsess.php3 file
$allowcache_expire = 24*3600; // 1 day
$exp_gmt           = gmdate("D, d M Y H:i:s", time() + $allowcache_expire) . " GMT";
$mod_gmt           = gmdate("D, d M Y H:i:s", getlastmod()) . " GMT";
header('Expires: '       . $exp_gmt);
header('Last-Modified: ' . $mod_gmt);
header('Cache-Control: public');
header('Cache-Control: max-age=' . $allowcache_expire);
header('Content-Type: application/x-javascript');

// next lines are copied from prototype/HEADER and prototype/prototype.js files

?>

// usage: $(div_id_2).update(AA_Config.loader);
var AA_Config = {
  AA_INSTAL_PATH: '<?php echo AA_INSTAL_PATH; ?>',
  SESS_NAME:      '<?php echo isset($_GET['sess_name']) ? $_GET['sess_name'] : ''; ?>',
  SESS_ID:        '<?php echo isset($_GET['sess_id'])   ? $_GET['sess_id']   : ''; ?>',
  loader:         '<img src="<?php echo AA_INSTAL_PATH; ?>images/loader.gif" border="0" width="16" height="16">',
  icon_new:       '<img src="<?php echo AA_INSTAL_PATH; ?>images/icon_new.gif" border="0" width="17" height="17">',
  icon_close:     '<img src="<?php echo AA_INSTAL_PATH; ?>images/icon_close.gif" border="0" width="17" height="17">'
}


// now AA specific functions
function AA_HtmlToggle(link_id, link_text_1, div_id_1, link_text_2, div_id_2) {
    if ( $(jqid(div_id_1)).is(':visible') ) {
        $(jqid(div_id_1)).hide('fast');
        $(jqid(div_id_2)).show('fast');
        $(jqid(link_id)).html(link_text_2);
        $(jqid(link_id)).addClass("is-on");
    } else {
        $(jqid(div_id_2)).hide('fast');
        $(jqid(div_id_1)).show('fast');
        $(jqid(link_id)).html(link_text_1);
        $(jqid(link_id)).removeClass("is-on");
    }
}


function AA_AjaxCss(selector, url, param, onload) {
    $(selector).html(AA_Config.loader);
    $(selector).load(url, param, onload);
}

/** selector_update is optional and is good for updating table rows, where we want to show/hide tr, but update td */
function AA_HtmlAjaxToggleCss(link_id, link_text_1, link_text_2, selector_hide, url, selector_update) {
    var link = jqid(link_id);
    if ( $(link).hasClass('is-on')) {
        $(selector_hide).hide('fast');
        $(link).html(link_text_1);
        $(link).toggleClass('is-on');
    } else {
        $(selector_hide).show('fast');
        $(link).toggleClass('is-on');
        // not loaded from remote url, yet?
        if ( !$(link).hasClass('aa-loaded')) {
            $(link).addClass('aa-loaded');
            AA_AjaxCss(selector_update ? selector_update : selector_hide, url);
        }
        $(link).html(link_text_2);
    }
}

function jqescape(s) {
    // escape all special characters (like [])
    return s.replace(/([^a-zA-Z0-9_-])/g, '\\$1')
}

function jqid(s) {
    // escape all special characters (like [])
    return '#' + jqescape(s);
}

/** This function replaces the older one - proposeChange
 *  The main chane is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function AA_SendWidgetAjax(id) {
    var valdiv = jqid('ajaxv_'+id);
    var code   = $(valdiv + ' *').serialize();
    $(valdiv).append(AA_Config.loader);

    var alias_name = $(valdiv).attr('data-aa-alias');

    code += '&inline=1&ret_code_enc='+alias_name;
    
    $.post(AA_Config.AA_INSTAL_PATH + 'filler.php3', code, function(data) {
        var res;
        // just one iteration, but without the loop we are not able to get the item_id
        for (var item_id in data) {
            res = data[item_id];
            $(valdiv).html(res.length>0 ? res : '--');
            break;
        }
        $(valdiv).attr("aaedit", "0");
    });
}

/** This function replaces the older one - proposeChange
 *  The main chane is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function AA_SendWidgetLive(id) {
    $$('*[id ^="'+id+'"]').invoke('addClassName', 'updating');
    var valdivid   = 'widget-' + id;
    var code = Form.serialize(valdivid);

    code += '&inline=1';  // do not send us whole page as result

    new Ajax.Request(AA_Config.AA_INSTAL_PATH + 'filler.php3', {
        parameters: code,
        requestHeaders: {Accept: 'application/json'},
        onSuccess: function(transport) {
            $$('*[id ^="'+id+'"]').invoke('removeClassName', 'updating');
        }
    });
}




function displayInput(valdivid, item_id, fid) {
    var valdiv = jqid(valdivid);

    // already editing ?
    switch ($(valdiv).attr('aaedit')) {
       case '1': return;
       case '2': $(valdiv).attr("aaedit", "0");  // the state 2 is needed for Firefox 3.0 - Storno not works
                 return;
    }
    var alias_name = $(valdiv).attr('data-aa-alias');
    $(valdiv).attr("data-aa-oldval", $(valdiv).html());

    AA_AjaxCss(valdiv, AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', {
        field_id:   fid,
        item_id:    item_id,
        alias_name: alias_name,
        aaaction:   'DISPLAYINPUT'
    }, function(data) {
        $(this).attr('aaedit', '1');
        var aa_input = $(this).children('select,textarea,input').first();
        $(aa_input).focus();  // select the input field (<select> or <input>)
        $(aa_input).keydown( function(event) {
            switch (event.which) {
            case 13: $(this).nextAll('input.save-button').click();   break; // Enter
            case 27: $(this).nextAll('input.cancel-button').click(); break; // Esc
//            case 9:  $(this).nextAll('input.save-button').click(); $(this).closest('div.ajax_container').nextAll('div.ajax_container').click(); break; // Tab
            case 9:  // Tab
                     // we must grab the next input right now - after save-button click we have no current div
                     var next_input = $('div.ajax_container').eq($('div.ajax_container').index($(this).parents('div.ajax_container'))+1);
                     $(this).nextAll('input.save-button').click();
                     $(next_input).click();
                     break;
            }
        });
    });
}

function _getInputContent(input_id) {
    var content    = Array();
    var i          = 0;
    var add_empty  = false;
    var val        = '';

    var jq_input   = jqid(input_id+'[]');

    if ( $(jq_input).length ) {
        val = $(jq_input).val();
        if ( $(jq_input).is('input:checkbox:not(:checked)')) { // val contains value also for unchecked checkboxesunchecked checkbox is undefined
            val = '0';   // should be changed to '' I think
        }
        content = content.concat( val );
    }

    while ( $(jq_input = jqid(input_id+'['+ i +']')).length ) {
        val = $(jq_input).val();
        if ( $(jq_input).is('input:checkbox:not(:checked)')) { // val contains value also for unchecked checkboxesunchecked checkbox is undefined
            add_empty = true;
        } else {
            content = content.concat( val );
        }
        i++;
    }
    if ( add_empty && content.count < 1 ) {
        content.push('');  // it is different from push('0') above, because single chbox is 1|0, but multi is value..value|''
    }
    return content;
}

/** This function replaces the older one - proposeChange
 *  The main chane is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function DoChange(input_id) {
    var valdiv   = jqid('ajaxv_'+input_id);
    var alias_name = $(valdiv).attr('data-aa-alias');
    var content    = _getInputContent(input_id);

    AA_AjaxCss(valdiv, AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', {
        input_id:   input_id,
        alias_name: alias_name,
        aaaction:   'DOCHANGE',
        'content[]':    content
    }, function(data) {
        $(jqid('ajaxch_'+input_id)).text('');
        $(valdiv).attr("aaedit", "0");

    });
}

/** updates database for given iten and field by Ajax
 */
function DoChangeLive(input_id) {

    $('*[id ^="'+jqescape(input_id)+'"]').addClass('updating');
    var content    = _getInputContent(input_id);

    AA_AjaxCss(valdiv, AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', {
        input_id:   input_id,
        alias_name: '',
        aaaction:   'DOCHANGE',
        'content[]':    content
    }, function(data) {
        $('*[id ^="'+jqescape(input_id)+'"]').removeClass('updating');
    });
}

/** return back old value - CANCEL pressed on AJAX widget */
function DisplayInputBack(input_id) {
    var valdiv   = jqid('ajaxv_'+input_id);
    $(valdiv).html( $(valdiv).attr('data-aa-oldval') );
    $(valdiv).attr('aaedit', '2');
}

/* Cookies */

function SetCookie(name, value) {
   var expires = new Date();
   expires.setTime (expires.getTime() + (1000 * 60 * 60 * 24 * 1)); // a day
   document.cookie = name + "=" + escape(value) +
                      "; expires=" + expires.toGMTString() +
                      "; path=/";
    // + ((expires == null) ? "" : ("; expires=" + expires.toGMTString()))
    // + ((path == null)    ? "" : ("; path=" + path))
    // + ((domain == null)  ? "" : ("; domain=" + domain))
    // + ((secure == true)  ? "; secure" : "");
}

function getCookieVal(offset) {
    var endstr = document.cookie.indexOf(";", offset);
    if (endstr == -1) {
        endstr = document.cookie.length;
    }
    return unescape(document.cookie.substring(offset, endstr));
}

function GetCookie(name) {
    var arg  = name + "=";
    var alen = arg.length;
    var clen = document.cookie.length;
    var i    = 0;
    while (i < clen) {
        var j = i + alen;
        if (document.cookie.substring(i, j) == arg) {
            return getCookieVal(j);
        }
        i = document.cookie.indexOf(" ", i) + 1;
        if (i == 0) break;
    }
    return null;
}

function DeleteCookie(name) {
    var exp = new Date();
    exp.setTime (exp.getTime() - 1);
    var cval = GetCookie (name);
    document.cookie = name + "=" + cval + "; expires=" + exp.toGMTString() + "; path=/";
}

function ToggleCookie(name,val) {
    if ( GetCookie(name) != val ) {
        SetCookie(name,val);
    } else {
        DeleteCookie(name);
    }
}

function NewId() {
    // Private array of chars to use
    var chars = '0123456789abcdefgh'.split('');

    var uuid = [];

    // we do not want to have 0 as the first char in pair
    for (var i = 0; i < 16; i++) {
        uuid[2*i]   = chars[0 | (Math.random()*15+1)];
        uuid[2*i+1] = chars[0 | (Math.random()*16)];
    }
    return uuid.join('');
}

// --------------------------------------------
function addToKosik(produkt_variant, mnozstvi) {

    var kid;

    kid = GetCookie('kosik_id');
    if (kid) {
        addPolozkaToKosik(kid, produkt_variant, mnozstvi);
    } else {

        $.get(AA_Config.AA_INSTAL_PATH + 'filler.php3', {
            inline: 1,
            slice_id: '2d81635df9bbff2a7deebd89808f3cfb',
            "aa[n1_2d81635df9bbff2a7deebd89808f3cfb][highlight_______][]": 1
        }, function(data) {
            // just one iteration, but without the loop we are not able to get the item_id
            for (kid in data) {
                SetCookie('kosik_id', kid);
            }
            addPolozkaToKosik(kid, produkt_variant, mnozstvi);
        });
    }
}

function getCurrentLanguage() {
    var txt = new String(document.location);
    return (txt.indexOf('biofarma.cz/en') == -1) ? 'cz' : 'en';
}

function addPolozkaToKosik(kosik, produkt_variant, mnozstvi) {

    // we want to invalidate the pages for this user to reload pages with new kosik
    SetCookie('changed', new Date().getTime());

    AA_AjaxCss("#obsah-kosiku", AA_Config.AA_INSTAL_PATH + 'filler.php3', {
            inline: 1,
            ok_url: "http://biofarma.cz/aaa/view.php3?vid=29&nocache=1&cmd[29]=o-29-"+ kosik+'&als[XLANG___]='+getCurrentLanguage(),
            slice_id: '38b46aeec3b2bbb70ba48b31957ed322',
            "aa[n1_38b46aeec3b2bbb70ba48b31957ed322][relation________][]": kosik,
            "aa[n1_38b46aeec3b2bbb70ba48b31957ed322][relation_______1][]": produkt_variant,
            "aa[n1_38b46aeec3b2bbb70ba48b31957ed322][text____________][]": mnozstvi
        });

    AA_AjaxCss("#doporucujeme", '/aaa/view.php3?vid=35&cmd[35]=o-35-'+ produkt_variant+'&als[XLANG___]='+getCurrentLanguage());

}

function deleteFromKosik(kosik, polozka_id, prehled) {
    // we want to invalidate the pages for this user to reload pages with new kosik
    SetCookie('changed', new Date().getTime());

    if(typeof(prehled) == 'undefined') {
        prehled = 29;
    }

    var param = {
            inline: 1,
            ok_url: "http://biofarma.cz/aaa/view.php3?vid="+prehled+"&nocache=1&cmd["+prehled+"]=o-"+prehled+"-"+ kosik+'&als[XLANG___]='+getCurrentLanguage(),
            slice_id: '38b46aeec3b2bbb70ba48b31957ed322'
         };
    param["aa[u"+polozka_id+"][status_code_____][]"] = 3;

    $("#obsah-kosiku").load(AA_Config.AA_INSTAL_PATH + 'filler.php3', param, function() {});
}

function QuantuitySub(fld) {
    $(jqid(fld)).val(Math.max(1, parseInt($(jqid(fld)).val())-1));
}
function QuantuityAdd(fld) {
    $(jqid(fld)).val(Math.max(1, parseInt($(jqid(fld)).val())+1));
}

function ChangeVariant(sitem_id, sb) {
    var variant;
    variant = $(sb).val().split('|');
    $('#cena'+sitem_id).html('<strong>'+variant[1]+',-</strong>/'+variant[2]+'</strong>');
    $('#variant'+sitem_id).val(variant[0]);
    $('.cls2hide'+sitem_id).css('visibility', (variant[3]==1) ? 'visible' : 'hidden');
    $('#jednotka'+sitem_id).html(variant[2]);
    $('#dostupnost'+sitem_id).html(variant[4]);
}
