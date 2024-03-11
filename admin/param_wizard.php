<?php
/**
 * Author: Jakub Adamek
 *
 *   Params: $list = name of an array defined in constants_param_wizard.php
 *                   (or anywhere else, but must be included into this script)
 *           $item = name of the first index in the $$list array
 *
 *   This program should become a parameter wizard:
 *   useful when choosing a field layout (text area, text field, ...)
 *   or a function (f_m etc.)
 *
 *   It shows some help to the chosen function / layout and to all parametres.
 *   It is capable of showing a table of examples as well.
 *
 *   It's an independent tool which can be used to produce another wizards.
 *   You just need to define the appropriate array structure in constants_param_wizard.php.
 *   See se_inputform and the JavaScript callParamWizard function for an example how to call the wizard.
 *
 *  PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @package   UserInput
 * @version   $Id$
 * @author    Jakub Adamek <jakubadamek@ecn.cz>, February 2003
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999-2003 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

// const  AA_REGISTER_GLOBALS = true;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";
mgettext_bind(\AA\Util\Langs::currentLang(), 'param_wizard');
require_once __DIR__ . "/../include/constants_param_wizard.php";

try {
    // array ( 'input_valid…' => 7, 'input_show_…' => 1, )
    $combo_list = InputVars::stringGET('combo_list', true);
    // array ( 'unique' => 5, 'e-unique' => 2, 'mch' => 1, )
    $item = InputVars::stringGET('item', true);
    // array ( 'VALIDATE_TY…' => 7, 'INPUT_TYPES' => 1, )
    $list = InputVars::stringGET('list', true);
    // array ( 'input_valid…' => 7, 'input_show_…' => 1, )
    $text_param = InputVars::stringGET('text_param', true);
    $codemirror = InputVars::stringGET('codemirror', true);
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}


HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

/** processSlashes function
 * @param $s
 * @return string - the HTML tags to be printed verbatim are back-slashed in the text
 *          e.g. <A ....> is written \<A ...\>
 */
function processSlashes($s) {
    $s = str_replace("\\<", safe("<"), $s);
    $s = str_replace("\\>", safe(">"), $s);
    return $s;
}

/** processValue function
 * @param $s
 * @return string replace \r and \n with empty space and " with &quot;
 */
function processValue($s) {
    $s = str_replace("\r", "", $s);
    $s = str_replace("\n", "", $s);
    $s = str_replace('"', '&quot;', $s);
    return $s;
}

/** processJavaScript function
 * @param $s
 * @return string same as processValue, plus replace ' with \'
 */
function processJavaScript($s) {
    $s = str_replace("\r", "", $s);
    $s = str_replace("\n", "", $s);
    $s = str_replace('"', '&quot;', $s);
    $s = str_replace("'", "\\'", $s);
    return $s;
}


/**
 * @param string $name
 * @param $param[]
 * @return string
 */
function GetWizardRow($name, $param) {
    $ret     = '';
    $example = processValue($param['example']);
    $hlp     = '';
    switch (strtolower($param['type'])) {
        case 'int':   $ret = "<input type=number name=\"$name\" size=20 value=\"$example\">";
                      break;
        case 'strid': $ret = "<input type=text name=\"$name\" size=20 value=\"$example\">";     // old param wizard array
                      $hlp = "(" . _m("field&nbsp;id") . ")";
                      break;
        case 'bool':  $ret = "<input type=text name=\"$name\" size=4 value=\"$example\">";
                      $hlp = "(" . _m("boolean:&nbsp;0=false,1=true") . ")";
                      break;
        case 'str':                                                                             // old param wizard array
        case 'string':
        default:      $ret = "<input type=text name=\"$name\" class=aa-w-full value=\"$example\">";
                      break;
    }
    $ret .= "<span class=\"tabhlp\">$hlp<br>". processSlashes($param['desc']) . "</span>";
    return $ret;
}

$CLASS_WIZARDS = [
    'INPUT_TYPES'         => 'AA\Widget\Widget',
    'INSERT_TYPES'        => 'AA_Inserter_',
    'DEFAULT_VALUE_TYPES' => 'AA_Generator_',
];

if ($_GET['list'] AND $CLASS_WIZARDS[$_GET['list']]) {
    $pw = new \AA\Util\Paramwizard();
    $desc = $pw->getParamWizardArr($item, $CLASS_WIZARDS[$_GET['list']]);
} else {
    $desc = $$list;
    $desc = $desc['items'][$item];
}
$title = ucfirst($desc['name']) . " " . _m("Wizard");

// by some item (I know only about the date input type) the parameters are divided with ' rather than :
// so I allow to use ' when all params are INT or BOOL

$allow_quote = 1;
if (is_array($desc['params'])) {
    foreach ($desc['params'] as $param) {
        if ($param['type'] != 'INT' && $param['type'] != 'BOOL') {
            $allow_quote = 3;
            break;
        }
    }
}
?>
<title><?php echo $title ?></title>
</head>

<body onload="readParams()">
<div style="text-align: center;">

    <table class="aa-table">
        <tr class="aa-tbl-header"><th><?php echo $title ?></th></tr>
    </table>

    <form name="f" method="post" onSubmit="self.close()">

        <script>
            function changeFunction(combo) {
                let page = "<?php echo StateUrl(self_base() . "param_wizard.php") ?>"
                    + "<?php echo "&list=$list" . "&combo_list='$combo_list'&text_param='$text_param'&codemirror='$codemirror'" ?>"
                    + "&item=" + combo.value;
                window.location = page;
            }
            function writeParams() {
                let params = '';
                for (let i = 0; i < <?php echo count($desc['params']) ?>; i++) {
                    if (i > 0) params += ":";
                    let val = document.f.elements["param" + i].value;
                    params += val.replace(/:/g, "#:");
                }
                window.opener.document.f.elements["<?php echo $text_param ?>"].value = params;
                <?php if ($codemirror) { echo "window.opener.$codemirror.setValue(params);"; } ?>
            }
            function fillParams(params) {
                params = params.replace(/#:/g,"#~") + ":";
<?php if ($allow_quote)
    echo "params = params.replace(/'/g,\":\");" ?>
                let str;
                for (let i = 0; i < <?php echo count($desc['params']) ?>; i++) {
                    if (params > "") {
                        str = params.substring(0, params.indexOf(":"));
                        params = params.substring(params.indexOf(":") + 1);
                    } else {
                        str = "";
                    }
                    document.f.elements["param" + i].value = str.replace(/#~/g, ":");
                }
            }
            function readParams() {
                <?php
                if ($codemirror) {
                    echo "fillParams( window.opener.$codemirror.getValue());";
                } else {
                    echo "fillParams( window.opener.document.f.elements[\"$text_param\"].value );";
                }
                ?>
            }
            function useExample(iExample) {
                switch(iExample) {
<?php
$ino = count($desc['examples'] ?? []);
for ($i = 0; $i < $ino; ++$i) {
    $exm = $desc['examples'][$i];
    echo "case $i: pars=\"" . $exm['params'] . "\"; break;";
}
?>
                }
                fillParams(pars);
            }
        </script>

        <table class="aa-table">
            <tr class="aa-tbl-header"><th>
                    <?php
                    if ($desc['name']) {
                        echo $desc['name'] . ": " . processSlashes($desc['desc']);
                    } else {
                        $what = $$list;
                        printf(_m("This is an undocumented %s. We don't recommend to use it."), $what['name']);
                        echo "<p><input type=\"submit\" value=\"" . _m("Close the wizard") . "\">";
                        echo "</th></tr></table></body></html>";
                        exit;
                    }

                    echo '
</th></tr>
<tr><td>
<table>
<tr><td>';

                    // show the parameter boxes with hints

                    if (is_array($desc['params'])) {
                        echo _m("Available parameters: ") . "<br><br>";
                        echo "<table>";
                        $iparam = 0;
                        $example = "";
                        foreach ($desc['params'] as $param) {
                            echo "<tr><td size=\"50%\">"
                            . "<b>" . strtolower("$param[name]:") . "</b></td>
            <td>";
                            echo GetWizardRow("param$iparam", $param);
                            echo "</td></tr>";
                            if ($iparam > 0) {
                                $example .= ":";
                            }
                            $example .= $param['example'];
                            ++$iparam;
                        }
                        echo "</table>";
                    } else {
                        echo "<table><tr><td>";
                        $what = $$list;
                        printf(_m("This %s has no parameters.") . "<br>", strtolower($what['name']));
                        echo "</td></tr></table>";
                    }

                    echo "
</td></tr></table>
</td></tr>
<tr><td height=\"30\">";

                    if (is_array($desc['examples'])) {
                        echo _m("Have a look at these examples of parameters sets:");
                        echo "<table>";
                        for ($i = 0, $ino = count($desc['examples']); $i < $ino; ++$i) {
                            $exm = $desc['examples'][$i];
                            echo "<tr class=\"aa-tbl-header\"><th>";
                            echo $exm['desc'];
                            echo "</th><th>";
                            echo "<a href=\"javascript:useExample($i)\">" . _m("Show") . "</a></th></tr>";
                        }
                        echo "</table>";
                    }

                    $what = $$list;
                    echo $what['hint'];
                    echo '
<p>';
                    if (is_array($desc['params']))
                        echo '
    <input type="button" value="' . _m("OK - Save") . '" onclick="writeParams(); self.close()">&nbsp;&nbsp;
    <input type="button" value="' . _m("Cancel") . '" onclick="self.close()">&nbsp;&nbsp;
    <input type="button" value="' . _m("Show example params") . '" onclick="fillParams(\'' . processJavaScript($example) . '\')">';
                    else
                        echo '
    <input type="button" value="' . _m("OK") . '" onclick="self.close()">';
                    echo '
</p>
</td></tr></table>
</form>
</body>
</html>';
                    ?>
