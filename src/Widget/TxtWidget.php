<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:17
 */

namespace AA\Widget;
use AA\FormArray;
use AA_Content;
use AA_Formatters;
use AA_Item;
use AA\Util\Langs;
use AA_Property;
use Exception;

/** Textarea widget */
class TxtWidget extends Widget
{
    protected $rows;
    protected $max_characters;
    protected $ajaxtype;
    protected $audio_enable;
    protected $audio_max_seconds;
    protected $audio_lang;
    protected $audio_mode;

    /** @var array  list of textareas for CodeMirror creation */
    protected $ids_4_editor = [];

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public static function name(): string
    {
        return _m('Text Area');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    public static function getClassProperties(): array {
        return Widget::propertiesShop(['rows', 'max_characters', 'ajaxtype', 'audio_enable', 'audio_max_seconds', 'audio_lang', 'audio_mode']);
    }

    /** Whether the speech-dictation UI should render on this widget instance.
     *  Only the plain TxtWidget gets it today; EdtWidget (CKEditor) and
     *  CodWidget (CodeMirror) override the textarea surface so their
     *  integration is deferred. */
    protected function speechEnabled(): bool {
        if (static::class !== self::class) { return false; }
        return (bool)$this->getProperty('audio_enable', false);
    }

    /** libs to run after the page DOM is loaded */
    protected function requireLibs($aa_property, $winfo): array {
        $ret = ( count($aa_property->getTranslations())>1 ) ? ['aa-jslib@3'] : [];
        if ($aa_property->getContentTypeSwitches()) {
            $ret[] = 'ckeditor@4';
        }
        if ($this->speechEnabled()) {
            $ret[] = 'aa-speech@1';
        }
        return $ret;
    }

    /** Kick auto-attach again after the widget HTML lands. The JS also
     *  auto-attaches on DOMContentLoaded; this call covers AJAX-rendered
     *  forms where that event has already fired. AA_Speech.autoInit() is
     *  idempotent — already-attached textareas are skipped. */
    protected function requireRun($aa_property, $winfo): string {
        if (!$this->speechEnabled()) { return ''; }
        return 'if (window.AA_Speech) { AA_Speech.autoInit(); }';
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     *
     * @param  AA_Property $aa_property
     * @param  AA_Content  $content
     * @param  string      $type
     *
     * @return array
     * @throws Exception
     */
    function _getRawHtml(AA_Property $aa_property, AA_Content $content, string $type = 'normal'): array {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);

        $attrs = Widget::stdInputAttrs($type, $base_id, ['dirty']);

        $widget_add2 = ($type === 'live') ? '<img width=16 height=16 title="' . _m('To save changes click here or outside the field.') . '" alt="' . _m('Save') . '" class="' . $base_id . 'ico" src="' . AA_INSTAL_PATH . 'images/px.gif" style="position:absolute; right:0;">' : '';

        $widget = '';
        if ($this instanceof \AA\Widget\CodWidget) {
            // use specific GetWidgetValue for CodeMirror (we need some non-breaking-space replacement there)
            $widget .= Widget::getWidgetIdentificationCode($base_name, 'cod');
        }
        $widget .= $widget_add2;

        if ($type === 'ajax') {
            $attrs['autofocus'] = 'autofocus';
        }

        $value = $content->getAaValue($aa_property->getId());
        $def_rows = $this->getProperty('rows', 4);

        if ($maxlength = (int)$this->getProperty('max_characters', 0)) {
            $attrs['maxlength'] = $maxlength;
        }
        $attrs['class'] = $attrs['class'] ? $attrs['class']. ' aa-w-full' : 'aa-w-full';

        if ($this->speechEnabled()) {
            $attrs['data-aa-speech']         = '1';
            $attrs['data-aa-speech-max-sec'] = (int)$this->getProperty('audio_max_seconds', defined('AA_SPEECH_MAX_SECONDS') ? AA_SPEECH_MAX_SECONDS : 120);
            // Absolute path to the API endpoint. AA_INSTAL_PATH already ends with "/"
            // and starts with "/"; the site-root "/" default would land at the wrong
            // host path when AA is installed under a subdirectory (e.g. /aadev/).
            $attrs['data-aa-speech-api-url'] = (defined('AA_INSTAL_PATH') ? AA_INSTAL_PATH : '/') . 'api/v1/speech/transcribe';
            // Transcription path selector. Validated to a known value so we can't
            // leak arbitrary strings to the JS switch.
            $mode = strtolower((string)$this->getProperty('audio_mode', 'auto'));
            $attrs['data-aa-speech-mode'] = in_array($mode, ['auto', 'webspeech', 'openai'], true) ? $mode : 'auto';
            // audio_lang override wins; per-translation langcode fills in from the
            // foreach loop below for multilingual fields; otherwise JS infers from <html lang>.
            if ($audio_lang_prop = trim((string)$this->getProperty('audio_lang', ''))) {
                $attrs['data-aa-speech-lang'] = $audio_lang_prop;
            }
        }

        $required = $aa_property->isRequired() ? 'required' : '';

        if ($this instanceof \AA\Widget\EdtWidget) { // Rich edit textarea allways produces HTML code
            $widget .= AA_Formatters::getHiddenFormatters($base_name, $aa_property, $value, FLAG_HTML);
            // if the value is in plain text - we have to convert it to HTML
            $value->reformatToHtml();
        } else {
            $widget .= ($type === 'normal') ? AA_Formatters::getFormattersRadio($base_name, $aa_property, $value) : AA_Formatters::getHiddenFormatters($base_name, $aa_property, $value); // ajax, live
        }

        if ($value->isEmpty()) {
            $value->addValue('');   // display empty textarea at least
        }
        $value->fixTranslations($aa_property->getTranslations());
        $base = [];
        foreach ($value as $i => $val) {
            $attrs['rows'] = (($my_rows = substr_count($val, "\n")) > $def_rows) ? min($my_rows + 2, 30) : $def_rows;
            // $rows         = max( $def_rows, min($my_rows>$def_rowssubstr_count($val,"\n")+4, 30));

            $input_name = $base_name . "[$i]";
            if ( !$base ) {
                $base['id']       = FormArray::formName2Id($input_name);
                $base['langcode'] = Langs::getLangName2Code(Langs::getLangNum2Name($i));
            }
            if ($this->speechEnabled()) {
                // Only override the property-level default when we actually have
                // a per-translation language code. For single-language fields
                // both lookups yield '' — keep the audio_lang property (or let JS infer).
                $per_lang = Langs::getLangName2Code(Langs::getLangNum2Name($i)) ?: $base['langcode'];
                if ($per_lang) {
                    $attrs['data-aa-speech-lang'] = $per_lang;
                }
            }
            $widget .= $this->getOneInput($input_name, $i, $val, $attrs, $required, !$aa_property->getContentTypeSwitches() || $type === 'live', $base);
            $required = ''; // only one is required
            unset($attrs['autofocus']);
        }

        // if CKEditor called outside AA - in sitemodule, we have to tell, which slice to use for image uploads
        $_SESSION['r_last_module_id']=$content->getOwnerID();

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /**
     * @param string $input_name
     * @param $i
     * @param string $val
     * @param array $attrs
     * @param string $widget_add
     * @param string $widget_add2
     * @param string $required
     * @param bool $no_htmleditor_link
     * @param array $base
     * @return string
     */
    protected function getOneInput($input_name, $i, $val, $attrs, $required, $no_htmleditor_link, $base): string
    {

        $showhtmlarea   = $this instanceof \AA\Widget\EdtWidget;
        $showcodeeditor = $this instanceof \AA\Widget\CodWidget;

        //if ( $showhtmlarea ) {
        // $this->html_radio($showhtmlarea ? false : 'convertors');

        // fix for IE - where the textarea icons are too big so there is
        // no space for the text
        // if ($showhtmlarea) {
        //    $rows += 8;
        //}
        //}

        $input_id = FormArray::formName2Id($input_name);
        $input_value = safe($val);

        $areaclass = '';
        $htmlareaedit = '';

        if ($showhtmlarea || $showcodeeditor) {
            // add this textarea to the list for CKEditor creation or CodeMirror
            $this->ids_4_editor[] = '#'.$input_id;
            // $attrs['class'] = 'ckeditor';

            // we do not mark base textarea as required for CodeMirror, since it causes "An invalid form control with name='' is not focusable."
            if ($showcodeeditor) {
                $required = '';
            }

        } elseif (!$no_htmleditor_link) {
            $htmlareaedit = "<a href=\"javascript:;\" class=\"btn aa-arealink\" style=\"display:none\" onclick=\"this.parentNode.parentNode.querySelector('input[value=\'1\']').checked=true;this.parentNode.parentNode.querySelector('.aa-formatters').style.display='none'; this.style.display='none';CKEDITOR.replace('$input_id');\">" . _m("Edit in Editor") . "</a>"; // used for HTMLArea
            // conversions menu
            //$convertor   = $convert ? $this->get_convertors() : false;
            //if ( $convertor ) {
            //    $this->echoo('  <table>\n   <tr><td>");
            //}
        }

        $attr_string = htmlattr($attrs);

        $ret = "<textarea id=\"$input_id\" name=\"$input_name\" $attr_string $required $areaclass>$input_value</textarea>";
        if ($lang = Langs::getLangNum2Name($i)) {
            $langcode  = Langs::getLangName2Code($lang);
            $translate = '';

            // the AA_Translate do not work on CodeMirror, yet
            // @todo make AA_Translate work on CodeMirror
            if ($langcode != $base['langcode']) {
                if ($showhtmlarea) {
                    $translate =  "<span class=\"btn aa-langtrans-tbtn\" onclick=\"AA_Translate(CKEDITOR.instances['".$base['id']."'].getData(), '" . $base['langcode'] . "', '$langcode').then(txt => CKEDITOR.instances['$input_id'].setData(txt));\">" . _m("translate from") . ' ' . Langs::getLangCode2Name($base['langcode']) . "</span>";
                } elseif ($showcodeeditor) {
                    $translate = "<span class=\"btn aa-langtrans-tbtn\" onclick=\"AA_Translate(document.getElementById('".$base['id']."').value, '".$base['langcode']."', '$langcode').then(txt => document.getElementById('$input_id').nextSibling.CodeMirror.setValue(txt));\">" ._m("translate from").' '.Langs::getLangCode2Name($base['langcode'])."</span>";
                } else {
                    $translate = "<span class=\"btn aa-langtrans-tbtn\" onclick=\"AA_Translate(document.getElementById('".$base['id']."').value, '".$base['langcode']."', '$langcode').then(txt => document.getElementById('$input_id').value = txt);\">" ._m("translate from").' '.Langs::getLangCode2Name($base['langcode'])."</span>";
                }
            }
            $ret = "<div class=\"aa-langtrans aa-lang-$lang\"><strong class=\"aa-langtrans-label\">$lang</strong>$ret$translate</div>";
        }
        return "<div class='aa-one-val'>$htmlareaedit$ret</div>";
    }

    /** @inheritDoc */
    public static function description(): string {
        return ''; // TODO: Implement description() method.
    }
}