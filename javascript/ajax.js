// aajslib.php3 should be always included before this script - we are using
// AA_Config variable from there (at least)

// function replaces html code of a an HTML element (identified by id)
// by another code
function SetContent(id,txt,d) {
    if(!d) d=document;
    if (d.all) {                    // IE 4+
        el = d.all[id];
        if ( el != null ) {
            el.innerHTML=txt;
        }
    } else if (d.layers) {            // NS 4
        getElementByName(id, d).document.write(txt);   // eval('document.ids.'+id).document.write(txt);
        getElementByName(id, d).document.close();
    } else if (d.getElementById) {   // NS 6 (new DOM)
        rng = d.createRange();
        el = d.getElementById(id);
        if ( el != null ) {  // in case the element do not exist => do nothing
            rng.setStartBefore(el);
            htmlFrag = rng.createContextualFragment(txt);
            while (el.hasChildNodes())
                el.removeChild(el.lastChild);
            el.appendChild(htmlFrag);
        }
    }
}


/** This code comes from: http://www.devpro.it/JSL/JSLOpenSource.js */
// (C) Andrea Giammarchi - JSL 1.4b
function $JSL(){
    this.charCodeAt=function(str){return $JSL.$charCodeAt(str.charCodeAt(0))};
    this.$charCodeAt=function(i){
        var str=i.toString(16).toUpperCase();
        return str.length<2?"0"+str:str;
    };
    this.encodeURI=function(str){return str.replace(/"/g,"%22").replace(/\\/g,"%5C")};
    this.$encodeURI=function(str){return $JSL.$charCodeAt(str)};
    this.$encodeURIComponent=function(a,b){
        var i=b.charCodeAt(0),str=[];
        if(i<128)		str.push(i);
        else if(i<2048)		str.push(0xC0+(i>>6),0x80+(i&0x3F));
        else if(i<65536)	str.push(0xE0+(i>>12),0x80+(i>>6&0x3F),0x80+(i&0x3F));
        else			str.push(0xF0+(i>>18),0x80+(i>>12&0x3F),0x80+(i>>6&0x3F),0x80+(i&0x3F));
        return "%"+str.map($JSL.$encodeURI).join("%");
    };
}
$JSL=new $JSL();
if(typeof(encodeURI)==="undefined"){function encodeURI(str){
    var elm=/([\x00-\x20]|[\x25|\x3C|\x3E|\x5B|\x5D|\x5E|\x60|\x7F]|[\x7B-\x7D]|[\x80-\uFFFF])/g;
    return $JSL.encodeURI(str.toString().replace(elm,$JSL.$encodeURIComponent));
}}
if(typeof(encodeURIComponent)==="undefined"){function encodeURIComponent(str){
    var elm=/([\x23|\x24|\x26|\x2B|\x2C|\x2F|\x3A|\x3B|\x3D|\x3F|\x40])/g;
    return $JSL.encodeURI(encodeURI(str).replace(elm,function(a,b){return "%"+$JSL.charCodeAt(b)}));
}}

function displayInput(valdivid, item_id, fid) {
    var valdivtag = document.getElementById(valdivid);
    if (valdivtag.getAttribute("data-aa-edited")=="1") {
        return;
    }
    var alias_name = valdivtag.getAttribute("aaalias");

    var xmlHttpReq = false;
    var self = this;
    // Mozilla/Safari
    if (window.XMLHttpRequest) {
        self.xmlHttpReq = new XMLHttpRequest();
    }
    // IE
    else if (window.ActiveXObject) {
        self.xmlHttpReq = new ActiveXObject("Microsoft.XMLHTTP");
    }
    self.xmlHttpReq.open('POST', AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', true);
    self.xmlHttpReq.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    self.xmlHttpReq.onreadystatechange = function() {
        if (self.xmlHttpReq.readyState == 4) {
            SetContent(valdivid, self.xmlHttpReq.responseText);  // new value
            // mark the div as converted
            valdivtag.setAttribute("data-aa-edited", "1")
        }
    }
    var qs = 'field_id='+escape(fid)+'&item_id='+escape(item_id)+'&alias_name='+escape(alias_name)+'&form=1';
    SetContent(valdivid, 'moment...');  // new value
    self.xmlHttpReq.send(qs);
}

function proposeChange(combi_id, item_id, fid, change) {
    var valdivtag = document.getElementById('ajaxv_'+combi_id);
    var alias_name = valdivtag.getAttribute("aaalias");
    var xmlHttpReq = false;
    var self = this;
    // Mozilla/Safari
    if (window.XMLHttpRequest) {
        self.xmlHttpReq = new XMLHttpRequest();
    }
    // IE
    else if (window.ActiveXObject) {
        self.xmlHttpReq = new ActiveXObject("Microsoft.XMLHTTP");
    }
    self.xmlHttpReq.open('POST', AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', true);
    self.xmlHttpReq.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    self.xmlHttpReq.onreadystatechange = function() {
        if (self.xmlHttpReq.readyState == 4) {
            if ( change ) {
                SetContent('ajaxv_'+combi_id, self.xmlHttpReq.responseText);  // new value
                SetContent('ajaxch_'+combi_id, '');
                // SetContent('zmena'+divid, '');
            } else {
                SetContent('ajaxv_'+combi_id, document.getElementById('ajaxh_'+combi_id).value);  // restore old content
                SetContent('ajaxch_'+combi_id, document.getElementById('ajaxch_'+combi_id).innerHTML + '<span class="ajax_change">Navrhovaná změna: ' + self.xmlHttpReq.responseText +'</span><br>');
            }
            valdivtag.setAttribute("data-aa-edited", "0");
        }
    }
    var qs = 'field_id='+escape(fid)+'&item_id='+escape(item_id)+'&content='+encodeURIComponent(document.getElementById('ajaxi_'+combi_id).value) + '&alias_name='+escape(alias_name);
    var perms = (typeof do_change == 'undefined') ? 1 : do_change;
    if ( perms ) {
        qs += '&do_change=1'
    }
    self.xmlHttpReq.send(qs);
}

function AcceptChange(change_id, divid) {
    var xmlHttpReq = false;
    var self = this;
    // Mozilla/Safari
    if (window.XMLHttpRequest) {
        self.xmlHttpReq = new XMLHttpRequest();
    }
    // IE
    else if (window.ActiveXObject) {
        self.xmlHttpReq = new ActiveXObject("Microsoft.XMLHTTP");
    }
    self.xmlHttpReq.open('POST', AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', true);
    self.xmlHttpReq.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    self.xmlHttpReq.onreadystatechange = function() {
        if (self.xmlHttpReq.readyState == 4) {
            SetContent(divid, self.xmlHttpReq.responseText);  // new value
            SetContent('zmena_cmds'+divid, '');
            SetContent('zmena'+divid, '');
        }
    }
    var qs = 'change_id='+escape(change_id);
    self.xmlHttpReq.send(qs);
}

function CancelChanges(item_id, fid, divid) {
    var xmlHttpReq = false;
    var self = this;
    // Mozilla/Safari
    if (window.XMLHttpRequest) {
        self.xmlHttpReq = new XMLHttpRequest();
    }
    // IE
    else if (window.ActiveXObject) {
        self.xmlHttpReq = new ActiveXObject("Microsoft.XMLHTTP");
    }
    self.xmlHttpReq.open('POST', AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', true);
    self.xmlHttpReq.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    self.xmlHttpReq.onreadystatechange = function() {
        if (self.xmlHttpReq.readyState == 4) {
            SetContent(divid, self.xmlHttpReq.responseText);  // new value
            SetContent('zmena_cmds'+divid, '');
            SetContent('zmena'+divid, '');
        }
    }
    var qs = 'cancel_changes=1&field_id='+escape(fid)+'&item_id='+escape(item_id);
    self.xmlHttpReq.send(qs);
}

