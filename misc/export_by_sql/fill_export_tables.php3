<?php
require_once dirname(__FILE__). "/../../include/init_page.php3";
require_once AA_INC_PATH."util.php3";

if (!IsSuperadmin()) {
  echo "This script is intended for super admins only.";
  exit;
}

$tables = array (
"constant_slice"=>"WHERE slice_id IN",
"content"=>"INNER JOIN field ON content.field_id = field.id
            WHERE slice_id IN",
"field"=>"WHERE slice_id IN",
"module"=>"WHERE id IN",
"slice"=>"WHERE id IN",
"view"=>"WHERE slice_id IN",
);

if ($slices_chosen) {
    $slice_ids = "";
    foreach ($slices_chosen as $slice_id) {
        if ($slice_ids) $slice_ids .= ",";
        $slice_ids .= "'".q_pack_id($slice_id)."'";
    }

    reset ($tables);
    while (list ($table,$sql) = each ($tables)) {
        $db->query("DELETE FROM export_".$table);
        $db->query("INSERT INTO export_".$table." SELECT ".$table.".* FROM $table "
            .$sql." (".$slice_ids.")");
        echo "INSERT INTO export_".$table.": ".$db->affected_rows()." rows<br>\n";
    }

    $show_types = inputShowFuncTypes ();
    $db->query ("SELECT input_show_func FROM field WHERE slice_id IN (".$slice_ids.")");
    $groups = "";
    while ($db->next_record()) {
        list ($show_func, $group) = split(":", $db->f("input_show_func"));
        if ($group && $show_types[$show_func]['paramformat'] == 'fnc:const:param'
            && substr($group,0,7) != "#sLiCe-")
            $groups[] = $group;
    }

    $group_in = "'".join("','",$groups)."'";

    $db->query ("DELETE FROM export_constant");
    $db->query("INSERT INTO export_constant SELECT * FROM constant
                WHERE (constant.group_id='lt_groupNames' AND constant.name IN ($group_in))
                    OR(constant.group_id IN ($group_in))");
    echo "INSERT INTO export_constant: ".$db->affected_rows()." rows<br>\n";
}

HTMLPageBegin();

reset ($tables);
while (list ($table) = each ($tables)) {
    if ($tables_used) $tables_used .= ",";
    $tables_used .= $table;
}

echo '<title>Export by SQL</title>
</head>
<body>
<h1>Export by SQL</h1>

<p><b>This script allows to export a slice with all its content by using
SQL. It creates tables export_XXX from contents of XXX related
to the chosen slices and you can than use any tool to dump the content
of export_XXX, change the table names to XXX and run the resulting SQL.</b></p>

<p><b>This is a simple and dangerous script. Be sure you are very careful when
using it. </b></p>

<p><b>Usage:</b></p>

<p><b>First create the export tables with exactly the same structure as your
current tables. This may be accomplished by using the file create_export_tables.sql,
which I have created on 5.2.2003. But if the database structure changes, nobody
will update the file. So better YOU update the file. Get the dump of the table
structures for the tables used (constant,'.$tables_used.'), then use some text
editor to replace<br>
"IF EXISTS " for "IF EXISTS export_" and <br>
"CREATE TABLE " for "CREATE TABLE export_".</b></p>

<p><b>Did I say you to run the script on your AA database? But please BE CAREFULL!!</b></p>

<p><b>Choose slices (you may choose several at once by holding Shift or Ctrl):</b></p>
<FORM name=choose method=post action="'.$sess->url("fill_export_tables.php3").'">
<SELECT name=slices_chosen[] MULTIPLE SIZE=30>
';

$db = new DB_AA;
$db->query ("SELECT * FROM slice ORDER BY name");
while ($db->next_record())
    echo "  <OPTION value='".unpack_id ($db->f("id"))."'>".$db->f("name")."\n";

echo "
</SELECT>
<BR><BR>
<INPUT type=submit name=go value='Go!'>
</FORM>
</body>";





