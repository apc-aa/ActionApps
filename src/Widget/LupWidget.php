<?php

namespace AA\Widget;

use AA\FormArray;
use AA_Content;
use AA_Property;
use Exception;

/** Local URL Picker widget - browse a website and pick a URL */
class LupWidget extends Widget
{
    protected $url;

    public static function name(): string
    {
        return _m('Local URL Picker');
    }

    public static function description(): string
    {
        return _m('Browse a local website and pick a URL');
    }

    public static function getClassProperties(): array
    {
        return Widget::propertiesShop(['url']);
    }

    /** JS functions for the dialog - runs once per page */
    protected function requireLibCode($aa_property, $winfo): string
    {
        return <<<'JS'
function AA_LupOpen(baseId, url) {
    const dlg    = document.getElementById(`lupdlg_${baseId}`);
    const frm    = document.getElementById(`lupfrm_${baseId}`);
    const urlbar = document.getElementById(`lupurl_${baseId}`);
    const input  = document.getElementById(`${baseId}-0`);
    const startUrl = input?.value || url;
    frm.src = startUrl;
    urlbar.textContent = startUrl;
    if (dlg._lupInterval) { clearInterval(dlg._lupInterval); }
    dlg._lupInterval = setInterval(() => {
        try {
            const loc = frm.contentWindow.location.href;
            if (loc && loc !== 'about:blank') { urlbar.textContent = loc; }
        } catch {}
    }, 1000);
    dlg.showModal();
}
function AA_LupPick(baseId) {
    const urlbar = document.getElementById(`lupurl_${baseId}`);
    const input  = document.getElementById(`${baseId}-0`);
    if (input) { input.value = urlbar.textContent; input.dispatchEvent(new Event('change')); }
    AA_LupClose(baseId);
}
function AA_LupClose(baseId) {
    const dlg = document.getElementById(`lupdlg_${baseId}`);
    if (dlg._lupInterval) { clearInterval(dlg._lupInterval); }
    document.getElementById(`lupfrm_${baseId}`).src = 'about:blank';
    dlg.close();
}
JS;
    }

    /** CSS for the dialog */
    protected function requireCssCode($aa_property, $winfo): string
    {
        return <<<'CSS'
.aa-lup-dialog { width: 90vw; height: 85vh; padding: 0; border: 1px solid #888; }
.aa-lup-dialog::backdrop { background: rgba(0,0,0,0.4); }
.aa-lup-toolbar { display: flex; align-items: center; gap: 6px; padding: 4px 8px; background: #f0f0f0; border-bottom: 1px solid #ccc; }
.aa-lup-url { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; color: #333; padding: 2px 6px; background: #fff; border: 1px solid #ccc; border-radius: 3px; }
.aa-lup-iframe { width: 100%; height: calc(100% - 36px); border: none; }
CSS;
    }

    /** Attach click handler - runs per widget instance */
    protected function requireRun($aa_property, $winfo): string
    {
        $base_id = $winfo['base_id'];
        $url     = json_encode($this->getProperty('url', ''), JSON_HEX_APOS | JSON_HEX_QUOT);
        return "document.getElementById('lupbtn_{$base_id}')?.addEventListener('click', function() { AA_LupOpen('{$base_id}', {$url}); });";
    }

    /**
     * @throws Exception
     */
    public function _getRawHtml(AA_Property $aa_property, AA_Content $content, string $type = 'normal'): array
    {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id   = FormArray::formName2Id($base_name);
        $required  = $aa_property->isRequired() ? 'required' : '';
        $attrs     = Widget::stdInputAttrs($type, $base_id, ['dirty']);

        $attrs['type']        = 'url';
        $attrs['placeholder'] = 'https://';
        $attrs['class']       = $attrs['class'] ? $attrs['class'] . ' aa-w-full' : 'aa-w-full';

        $attr_string = htmlattr($attrs);

        $value      = $content->getAaValue($aa_property->getId());
        $input_name = $base_name . '[0]';
        $input_id   = FormArray::formName2Id($input_name);
        $input_val  = safe($value->getValue());

        $widget = "<div class='aa-one-val'>"
            . "<input {$attr_string} name=\"{$input_name}\" id=\"{$input_id}\" value=\"{$input_val}\" {$required}>"
            . Widget::_saveIcon($base_id, $type === 'live')
            . "</div>";

        if ($type === 'normal') {
            $widget .= '<button type="button" id="lupbtn_' . $base_id . '">' . _m('Browse') . '</button>';
            $widget .= '<dialog id="lupdlg_' . $base_id . '" class="aa-lup-dialog" oncancel="AA_LupClose(\'' . $base_id . '\')">'
                . '<div class="aa-lup-toolbar">'
                . '<span id="lupurl_' . $base_id . '" class="aa-lup-url"></span>'
                . '<button type="button" onclick="AA_LupPick(\'' . $base_id . '\')">' . _m('Pick this URL') . '</button>'
                . ' <button type="button" onclick="AA_LupClose(\'' . $base_id . '\')">' . _m('Cancel') . '</button>'
                . '</div>'
                . '<iframe id="lupfrm_' . $base_id . '" class="aa-lup-iframe" sandbox="allow-same-origin allow-scripts allow-forms allow-popups"></iframe>'
                . '</dialog>';
        }

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }
}
