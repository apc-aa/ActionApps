<?php

// To be called from TableEdit::ShowColumnValues

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * 
* Does column type-specific work. 
*
* @param $function      "show" prints HTML code showing the column value
*                       "form" transforms the returned value if needed and returns it
* @param $val           value to be shown ("show") or to be changed ("form")
* @return nothing
*/                    

function ColumnFunctions ($cview, &$val, $function, $name="") 
{
    global $err;
    
    if ($function == "show" && $cview["readonly"])
        $function = "show_ro";
        
    // ********************* UNPACKED *************************
    if ($cview["unpacked"]) {
    
        switch ($function) {
        case 'show':
        case 'show_ro':
            $val = unpack_id ($val);
            break;
        case 'form':
            $val = pack_id ($val);
        }
        
    }
            

    switch ($cview["type"]) {
    
    // ********************** IGNORE **************************
    case 'ignore':
        return;
        
    // ********************** HIDE** **************************
    case 'hide':
    
        switch ($function) {
        case 'show':
        case 'show_ro':
            echo "<INPUT type=\"hidden\" name=\"val[$key][$colname]\" value=\"".$val."\">\n";
            break;
        }
        return;
        
    // ******************** AREA = BLOB ***********************        
    case 'area':
    case 'blob':
    
        switch ($function) {
        case 'show':         
            $val = str_replace ('<','&lt;',$val);
            $val = str_replace ('>','&gt;',$val);
            echo "<TEXTAREA name=\"$name\""
                ." rows=\"".$cview["size"]["rows"]."\" cols=\"".$cview["size"]["cols"]."\">\n"
                .$val."</textarea>"; 
            break;
        case 'show_ro':
            ShowColumnValueReadOnly ($cview, $val);
            break;
        }
        return;
   
    // ********************** SELECT **************************
    case 'select':
    
        switch ($function) {
        case 'show':
            FrmSelectEasy($name, $cview["source"], $val); 
            break;
        case 'show_ro':           
            ShowColumnValueReadOnly ($cview, $cview["source"][$val]);
            break;
        }
        return;

    // *********************** DATE ****************************
    case 'date':        
        
            
        switch ($function) {
        case 'show': 
            if ($val) $val = date($cview["format"], $val); 
            $maxlen = $column["len"] ? "maxlength=$column[len]" : "";
            echo "<INPUT type=\"text\" $maxlen size=\"".$cview["size"]["cols"]."\" name=\"$name\"
                value=\"".$val."\">"; 
            break;
        case 'show_ro':
            if ($val) $val = date($cview["format"], $val); 
            ShowColumnValueReadOnly ($cview, $val);
            break;
        case 'form':                        
            $val = get_formatted_date ($val, $cview["format"]);
            break;
        }
        return;

    // ********************** CHECKBOX **************************       
/*    case 'checkbox':
    
        switch ($function) {
        case 'show':
            echo "<INPUT type=\"checkbox\" name=\"$name\"".($val ? " checked" : "").">"; 
            break;
        case 'show_ro':
            echo $val ? _m("yes") : _m("no");
            break;
        case 'form':
            $val = $val ? 1 : 0;
            break;
        }                    
*/

    // ********************** USERDEF ***************************
    case "userdef" : 
    
        switch ($function) {
        case 'show_ro':
            $fnc = $cview["function"];
            ShowColumnValueReadOnly ($cview, 
                $fnc ($val));
            break;
        default:
            $err[] = "Only readonly fields may be viewed by userdef function.";
            break;
        }     
        return;       

    // ******************* TEXT = DEFAULT *************************
    case 'text':
    default:
    
        switch ($function) {
        case 'show':
            $val = str_replace ('"','&quot;',$val);
            $maxlen = $cview["maxlen"] ? "maxlength=$cview[maxlen]" : "";
            echo "<INPUT type=\"text\" $maxlen size=\"".$cview["size"]["cols"]."\" name=\"$name\"
                value=\"".$val."\">"; 
            break;            
        case 'show_ro':
            ShowColumnValueReadOnly ($cview, $val);
            break;
        }
        return;
        
    }
}

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * 
* Shows a column value for a read-only column.
*/
function ShowColumnValueReadOnly ($cview, $val) {
    if ($val) {
        if (!$cview["html"]) $val = htmlentities ($val);
    }
    else if ($val == 0 && is_field_type_numerical ($cview["dbtype"]) 
        && $cview["type"] != "date")
        $val = "0";
    else $val = "&nbsp;";

    echo $val;
}    

?>
