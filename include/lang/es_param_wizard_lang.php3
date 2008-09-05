<?php
// $Id$
// Language: ES
// This file was created automatically by the Mini GetText environment
// on 5.9.2008 16:32

// Do not change this file otherwise than by typing translations on the right of =

// Before each message there are links to program code where it was used.

$mgettext_lang = "es";

# Unused messages
$_m["Do you want to check for uniqueness this slice only \n"
   ."                  or all slices?"]
 = "¿Quiere verificar unicidad en este canal solamente o en todos los canales?";

$_m["<b>1</b> = This slice only. \n"
   ."                <b>2</b> = All slices.<br>\n"
   ."                <b>0</b> = Username, special: Checks uniqueness in reader management\n"
   ."                slices and in the permission system. Always uses field ID %1"]
 = "<b>1</b> = Sólo este canal. \n"
   ."<b>2</b> = Todos los canales.<br>\n"
   ."<b>0</b> = Usuario. Comprueba unicidad en canales de Suscriptores y \n"
   ."sistema de permisos. Siempre usa el ID %1";

$_m["PHP-like format - see <a href=\"http://www.php.cz/manual/en/function.date.php\" target=_blank>PHP manual</a>"]
 = "especificar formato según sintaxis de PHP: ver <a href=\"http://www.php.cz/manual/en/function.date.php\" target=_blank>el manual de PHP</a";

$_m["print HTML"]
 = "mostrar HTML";

$_m["prints <i>the field</i> content (or <i>unalias string</i>) depending on the html flag (if html flag is not set, it converts the content to html. In difference to f_h function, it converts to html line-breaks, too. Obviously this function is used for fultexts.)"]
 = "muestra <i>el campo</i> (o la <b>cadena</b>), alterando o no su contenido dependiendo de la selección hecha en 'HTML / texto plano': si se seleccionó 'texto plano', convierte el contenido a HTML, añadiendo cambios de linea etc.";

$_m["field will be displayed in select box. if not specified, in select box are displayed headlines. (only for constants input type: slice)"]
 = "(solo para cuando seleccione un canal para mostrar en la caja de selección) mostrar este campo en vez del campo por defecto (titulares)";

$_m["show all"]
 = "mostrar todo";

$_m["used only for slices - if set (=1), then all items are shown (including expired and pending ones)"]
 = "(solo para cuando seleccione un canal para mostrar en la caja de selección) si es '1', se muestran en la lista todos los ítems del canal, incluyendo los caducados y los pendientes";

$_m["Defines, which buttons to show in item selection:<br>A - 'Add'<br>M - 'Add Mutual<br>B - 'Backward'.<br> Use 'AMB' (default), 'MA', just 'A' or any other combination. The order of letters A,M,B is important."]
 = "Define qué botones se muestran en la lista de items:<br>A - 'Añadir'<br>M - 'Añadir mutuo'<br>B - 'Viceversa'. El orden de las letras A,M,B es importante.";

$_m["number of characters from the <b>fulltext</b> field"]
 = "número de caracteres del campo <b>texto completo</b>";

$_m["field id of fulltext field (like full_text.......)"]
 = "id del campo de texto completo (por ejemplo full_text.......)";

$_m["take first paragraph (text until \\<BR\\> or \\<P\\> or \\</P\\) if shorter then <b>length</b>"]
 = "tomar sólo el primer párrafo (el texto hasta \\<BR\\> or \\<P\\> or \\</P\\) si es más corto que <b>longitud</b>";

$_m["prints <i>the field</i> as image width (height='xxx' width='yyy') empty string if cant work out, does not special case URLs from uploads directory, might do later! "]
 = "Función anticuada. Use mejor la función f_c";

# End of unused messages
// admin/param_wizard.php3, row 94
$_m["Wizard"]
 = " - Asistente";

// admin/param_wizard.php3, row 174
$_m["This is an undocumented %s. We don't recommend to use it."]
 = "Esta %s no está documentada. No se recomienda su uso.";

// admin/param_wizard.php3, row 175
$_m["Close the wizard"]
 = "Cerrar asistente";

// admin/param_wizard.php3, row 189
$_m["Available parameters: "]
 = "Parámetros disponibles: ";

// admin/param_wizard.php3, row 201
$_m["integer&nbsp;number"]
 = "número&nbsp;entero";

// admin/param_wizard.php3, row 202
$_m["any&nbsp;text"]
 = "cuarquier&nbsp;texto";

// admin/param_wizard.php3, row 203
$_m["field&nbsp;id"]
 = "id&nbsp;campo";

// admin/param_wizard.php3, row 204
$_m["boolean:&nbsp;0=false,1=true"]
 = "booleano:&nbsp;0=falso,1=verdadero";

// admin/param_wizard.php3, row 217
$_m["This %s has no parameters."]
 = "Esta %s no tiene parámetros.";

// admin/param_wizard.php3, row 227
$_m["Have a look at these examples of parameters sets:"]
 = "Puede tomar ideas de estos ejemplos:";

// admin/param_wizard.php3, row 234
$_m["Show"]
 = "Mostrar";

// admin/param_wizard.php3, row 244
$_m["OK - Save"]
 = "OK - Guardar";

// admin/param_wizard.php3, row 245
$_m["Cancel"]
 = "Cancelar";

// admin/param_wizard.php3, row 246
$_m["Show example params"]
 = "Mostrar ejemplo";

// admin/param_wizard.php3, row 248
$_m["OK"]
 = "";

// include/constants_param_wizard.php3, row 59
$_m["Insert Function"]
 = "Función de Inserción";

// include/constants_param_wizard.php3, row 61
$_m["Text = don't modify"]
 = "Texto = no modificar";

// include/constants_param_wizard.php3, row 62
$_m["Does not modify the value."]
 = "No modifica el valor del campo.";

// include/constants_param_wizard.php3, row 64
$_m["Date = don't modify"]
 = "";

// include/constants_param_wizard.php3, row 65
$_m["Does not modify the value (just like Text), but it is better to separate it for future ussage."]
 = "";

// include/constants_param_wizard.php3, row 67
$_m["Boolean = store 0 or 1"]
 = "Booleano = guardar 0 o 1";

// include/constants_param_wizard.php3, row 69
$_m["File = uploaded file"]
 = "Archivo = subir archivo";

// include/constants_param_wizard.php3, row 70
$_m["Stores the uploaded file and a link to it, parameters only apply if type is image/something."]
 = "Coloca el archivo en la carpeta de archivos de las AA, y almacena el URL que permite acceder a él. Los parámetros sólo sirven si el tipo MIME es image/*.";

// include/constants_param_wizard.php3, row 72
$_m["Mime types accepted"]
 = "Tipos MIME aceptados";

// include/constants_param_wizard.php3, row 73
$_m["Only files of matching mime types will be accepted"]
 = "Solo se aceptarán los tipos de archivo que coincidan";

// include/constants_param_wizard.php3, row 76
$_m["Maximum image width"]
 = "Ancho máximo de imágen";

// include/constants_param_wizard.php3, row 79
$_m["Maximum image height"]
 = "Altura máxima imágen";

// include/constants_param_wizard.php3, row 80
$_m["The image will be resampled to be within these limits, while retaining aspect ratio."]
 = "La imágen será redimensionada para que esté dentro de estos límites, manteniendo su proporción.";

// include/constants_param_wizard.php3, row 83
$_m["Other fields"]
 = "Otros campos";

// include/constants_param_wizard.php3, row 84
$_m["List of other fields to receive this image, separated by ##"]
 = "Lista de otros campos que reciben esta imágen, separados por ##";

// include/constants_param_wizard.php3, row 87
$_m["Upload policy"]
 = "";

// include/constants_param_wizard.php3, row 92
$_m["new | overwrite | backup<br>This parameter controls what to do if uploaded file alredy exists:\n"
   ."                       <br>new - AA creates new filename (by adding _x postfix) and store it with this new name (default)\n"
   ."                       <br>overwrite - the old file of the same name is overwritten\n"
   ."                       <br>backup - the old file is copied to new (non-existing) file and current file is stored with current name.\n"
   ."                       <br>In all cases the filename is escaped, so any non-word characters will be replaced by an underscore."]
 = "";

// include/constants_param_wizard.php3, row 95
$_m["Exact dimensions"]
 = "";

// include/constants_param_wizard.php3, row 97
$_m["If set to 1 the image will be downsized exactly to the specified dimensions (and croped if needed).\n"
   ."                       Default is 0 or empty: Maintain aspect ratio while resizing the image."]
 = "";

// include/constants_param_wizard.php3, row 102
$_m["User ID = always store current user ID"]
 = "ID usuario = almacenar el ID usuario actual";

// include/constants_param_wizard.php3, row 105, 156
$_m["Login name"]
 = "Nombre de usuario";

// include/constants_param_wizard.php3, row 107
$_m["Item IDs"]
 = "IDs ítems";

// include/constants_param_wizard.php3, row 109
$_m["Now = always store current time"]
 = "Ahora = almacenar hora actual";

// include/constants_param_wizard.php3, row 110
$_m["Inserts the current time, no matter what the user sets."]
 = "Inserta la hora actual, independientemente del valor del campo introducido por el autor.";

// include/constants_param_wizard.php3, row 112, 246, 689
$_m["Password and Change Password"]
 = "Clave y Cambiar Clave";

// include/constants_param_wizard.php3, row 115
$_m["Stores value from a 'Password and Change Password' field type.\n"
   ."           First prooves the new password matches the retyped new password,\n"
   ."           and if so, MD5-encrypts the new password and stores it."]
 = "Almacena el valor de un tipo de entrada 'Clave y Cambio de Clave'.\n"
   ."Primero comprueba que las dos claves coinciden, y si es así,\n"
   ."inserta la clave encriptada con MD5.";

// include/constants_param_wizard.php3, row 117
$_m["Computed field"]
 = "";

// include/constants_param_wizard.php3, row 118
$_m["Deprecated (use Computed field for INSERT/UPDATE). The field is the result of expression written in \"Code for unaliasing\". It is good solution for all values, which could be precomputed, since its computation on item-show-time would be slow. Yes, you can use {view...}, {include...}, {switch...} here"]
 = "";

// include/constants_param_wizard.php3, row 120
$_m["Code for unaliasing (INSERT+UPDATE)"]
 = "";

// include/constants_param_wizard.php3, row 121, 132
$_m["There you can write any string. The string will be unaliased on item store, so you can use any {...} construct as well as field aliases here"]
 = "";

// include/constants_param_wizard.php3, row 128
$_m["Computed field for INSERT/UPDATE"]
 = "";

// include/constants_param_wizard.php3, row 129
$_m["The field is the result of expression written in \"Code for unaliasing\". It is good solution for all values, which could be precomputed, since its computation on item-show-time would be slow. Yes, you can use {view...}, {include...}, {switch...} here"]
 = "";

// include/constants_param_wizard.php3, row 131
$_m["Code for unaliasing (INSERT)"]
 = "";

// include/constants_param_wizard.php3, row 136
$_m["Code for unaliasing (UPDATE)"]
 = "";

// include/constants_param_wizard.php3, row 137
$_m["The same as above, but just for UPDATE operation. If unfilled, the value of the field stays unchanged"]
 = "";

// include/constants_param_wizard.php3, row 146
$_m["Default Value Type"]
 = "Valor por defecto";

// include/constants_param_wizard.php3, row 148
$_m["Text from 'Parameter'"]
 = "Texto de 'parámetro'";

// include/constants_param_wizard.php3, row 149
$_m["Text"]
 = "Texto";

// include/constants_param_wizard.php3, row 151
$_m["Date + 'Parameter' days"]
 = "Fecha + 'parámetro' días";

// include/constants_param_wizard.php3, row 152
$_m["Number of days"]
 = "Número de días";

// include/constants_param_wizard.php3, row 154
$_m["User ID"]
 = "ID usuario";

// include/constants_param_wizard.php3, row 158
$_m["Now, i.e. current date"]
 = "Ahora (fecha actual)";

// include/constants_param_wizard.php3, row 160
$_m["Variable"]
 = "";

// include/constants_param_wizard.php3, row 161
$_m["A dangerous function. Do not use."]
 = "Función peligrosa. No la use.";

// include/constants_param_wizard.php3, row 163
$_m["Random string"]
 = "Cadena aleatória";

// include/constants_param_wizard.php3, row 164
$_m["Random alphanumeric [A-Z0-9] string."]
 = "Una cadena alfanumérica [A-Z0-9] elegida al azar.";

// include/constants_param_wizard.php3, row 166
$_m["String length"]
 = "Longitud cadena";

// include/constants_param_wizard.php3, row 169
$_m["Field to check"]
 = "Campo a verificar";

// include/constants_param_wizard.php3, row 171
$_m["If you need a unique code, you must send the field ID,\n"
   ."                  the function will then look into this field to ensure uniqueness."]
 = "Si necesita un código único, rellene el ID del camp, y la función asegurará que el valor sea único para ese campo.";

// include/constants_param_wizard.php3, row 174, 239
$_m["Slice only"]
 = "Sólo en el canal";

// include/constants_param_wizard.php3, row 176, 241
$_m["Do you want to check for uniqueness this slice only\n"
   ."                  or all slices?"]
 = "Desea verificar la unicidad de este canal solamente o en todos los canales?";

// include/constants_param_wizard.php3, row 183
$_m["Input Validate Type"]
 = "Tipo de validación de entrada";

// include/constants_param_wizard.php3, row 185
$_m["No validation"]
 = "No validar";

// include/constants_param_wizard.php3, row 187, 732
$_m["URL"]
 = "";

// include/constants_param_wizard.php3, row 189
$_m["E-mail"]
 = "Correo-e";

// include/constants_param_wizard.php3, row 191
$_m["Number = positive integer number"]
 = "Número = entero positivo";

// include/constants_param_wizard.php3, row 193
$_m["Id = 1-32 hexadecimal digits [0-9a-f]"]
 = "Id = 1-32 dígitos hexadecimales";

// include/constants_param_wizard.php3, row 195
$_m["Date = store as date"]
 = "Fecha = guardar como fecha";

// include/constants_param_wizard.php3, row 197
$_m["Bool = store as bool"]
 = "Bool = guardar como booleano";

// include/constants_param_wizard.php3, row 199
$_m["User = does nothing ???"]
 = "Usuario";

// include/constants_param_wizard.php3, row 201
$_m["Unique = proove uniqueness"]
 = "Unico = verificar unicidad";

// include/constants_param_wizard.php3, row 203
$_m["Validates only if the value is not yet used. Useful e.g.\n"
   ."        for emails or user names."]
 = "Validar si el valor no está en uso todavía. Util para nombres de usuario o e-mails";

// include/constants_param_wizard.php3, row 205, 235
$_m["Field ID"]
 = "ID campo";

// include/constants_param_wizard.php3, row 206, 236
$_m["Field in which to look for matching values."]
 = "Campo en el que buscar valores que coincidan.";

// include/constants_param_wizard.php3, row 209
$_m["Scope"]
 = "Ambito";

// include/constants_param_wizard.php3, row 213
$_m["<b>1</b> = This slice only.\n"
   ."                <b>2</b> = All slices.<br>\n"
   ."                <b>0</b> = Username, special: Checks uniqueness in reader management\n"
   ."                slices and in the permission system. Always uses field ID %1"]
 = "<b>1</b> = Este canal solamente.\n"
   ."                <b>2</b> = Todos los canales.<br>\n"
   ."                <b>0</b> = Nombre de usuario, especial: Verifica la unicidad en el administrador de lectura\n"
   ."                canales y en el sistema de permisos. Siempre utilice el Id del campo %1";

// include/constants_param_wizard.php3, row 219
$_m["Regular Expression"]
 = "";

// include/constants_param_wizard.php3, row 220
$_m["Validation based on Regular Expressions"]
 = "";

// include/constants_param_wizard.php3, row 222
$_m["Regular Expressions"]
 = "";

// include/constants_param_wizard.php3, row 223
$_m["use something like: /^[0-9]*\$/ - see \"Regular Expressions\" manual."]
 = "";

// include/constants_param_wizard.php3, row 226
$_m["Error text"]
 = "";

// include/constants_param_wizard.php3, row 227
$_m["error message"]
 = "";

// include/constants_param_wizard.php3, row 229
$_m["Wrong value"]
 = "";

// include/constants_param_wizard.php3, row 232
$_m["Unique e-mail"]
 = "e-mail único";

// include/constants_param_wizard.php3, row 233
$_m["Combines the e-mail and unique validations. Validates only if the value is a valid email address and not yet used."]
 = "Combina las validaciones de email y unicidad. Admite sólo los valores que sean direcciones válidas y que no estén en uso.";

// include/constants_param_wizard.php3, row 250
$_m["Validates the passwords do not differ when changing password.\n"
   ."        <i>The validation is provided only by JavaScript and not by ValidateInput()\n"
   ."        because the insert\n"
   ."        function does the validation again before inserting the new password.</i>"]
 = "Verifica que las claves no sean distintas al cambiar la clave.\n"
   ."<i>La validación se hace por JavaScript y no en el servidor porque la función de inserción no valida antes de insertar la nueva clave</i>.";

// include/constants_param_wizard.php3, row 257
$_m["Input Type"]
 = "Tipo de entrada";

// include/constants_param_wizard.php3, row 259
$_m["Hierarchical constants"]
 = "Constantes jerárquicas";

// include/constants_param_wizard.php3, row 260
$_m["A view with level boxes allows to choose constants."]
 = "Una vista con cajas para seleccionar constantes.";

// include/constants_param_wizard.php3, row 262
$_m["Level count"]
 = "Niveles";

// include/constants_param_wizard.php3, row 263
$_m["Count of level boxes"]
 = "Cajas de nivel";

// include/constants_param_wizard.php3, row 266
$_m["Box width"]
 = "Ancho de la caja";

// include/constants_param_wizard.php3, row 267
$_m["Width in characters"]
 = "Ancho en caracteres";

// include/constants_param_wizard.php3, row 270
$_m["Size of target"]
 = "Tamaño del destino";

// include/constants_param_wizard.php3, row 271
$_m["Lines in the target select box"]
 = "Número de lineas visibles en la caja de selección";

// include/constants_param_wizard.php3, row 274
$_m["Horizontal"]
 = "";

// include/constants_param_wizard.php3, row 275
$_m["Show levels horizontally"]
 = "Mostrar niveles en horizontal";

// include/constants_param_wizard.php3, row 278
$_m["First selectable"]
 = "Primer seleccionable";

// include/constants_param_wizard.php3, row 279
$_m["First level which will have a Select button"]
 = "Primer nivel a tener un botón de selección";

// include/constants_param_wizard.php3, row 282
$_m["Level names"]
 = "Nombres de niveles";

// include/constants_param_wizard.php3, row 283
$_m["Names of level boxes, separated by tilde (~). Replace the default Level 0, Level 1, ..."]
 = "Nombres de las cajas de nivel, separadas por virgulilla (~).";

// include/constants_param_wizard.php3, row 285
$_m["Top level~Second level~Keyword"]
 = "General~Segundo nivel~Palabras clave";

// include/constants_param_wizard.php3, row 287
$_m["Text Area"]
 = "Area de texto";

// include/constants_param_wizard.php3, row 288
$_m["Text area with 60 columns"]
 = "Area de texto con 60 columnas";

// include/constants_param_wizard.php3, row 290, 298
$_m["row count"]
 = "filas";

// include/constants_param_wizard.php3, row 295
$_m["Rich Edit Area"]
 = "Area de Texto Enriquecido";

// include/constants_param_wizard.php3, row 296
$_m["Rich edit text area. This operates the same way as Text Area in browsers which don't support the Microsoft TriEdit library. In IE 5.0 and higher and in Netscape 4.76 and higher (after installing the necessary features) it uses the TriEdit to provide an incredibly powerful HTML editor.<br><br>\n"
   ."Another possibility is to use the <b>iframe</b> version which should work in IE on Windows and Mac (set the 3rd parameter to \"iframe\").<br><br>\n"
   ."The code for this editor is taken from the Wysiwyg open project (http://www.unica.edu/uicfreesoft/) and changed to fullfill our needs. See http://www.unica.edu/uicfreesoft/wysiwyg_web_edit/Readme_english.txt on details how to prepare Netscape.<br><br>\n"
   ."The javascript code needed to provide the editor is saved in two HTML files, so that the user doesn't have to load it every time she reloads the Itemedit web page."]
 = "Editor de HTML in situ. En IE 5.0 o superior, así como en Netscape 4.76 o superior con los plugins necesarios, muestra un editor de HTML en el formulario. En navegadores que no lo soportan, muestra un area de texto normal.<br> Otra posibilidad es usar la versión <b>iframe</b> que debería  funcionar en IE para Windows y MAC (poner \"iframe\" en el tercer  parámetro)<br><br> <b>ATENCION: no olvide marcar <i>las dos</i> opciones 'Mostrar HTML/texto plano' y 'HTML por defecto' cuando use este tipo de entrada</b>";

// include/constants_param_wizard.php3, row 302
$_m["column count"]
 = "columnas";

// include/constants_param_wizard.php3, row 306, 843
// doc/param_wizard_list.php3, row 96
$_m["type"]
 = "tipo";

// include/constants_param_wizard.php3, row 307
$_m["type: class (default) / iframe"]
 = "tipo: class (por defecto) / iframe";

// include/constants_param_wizard.php3, row 309
$_m["class"]
 = "";

// include/constants_param_wizard.php3, row 311
$_m["Text Field"]
 = "Campo de texto";

// include/constants_param_wizard.php3, row 312
$_m["A text field."]
 = "Un simple campo de texto de una linea";

// include/constants_param_wizard.php3, row 314, 378
$_m["max characters"]
 = "máx. caracteres";

// include/constants_param_wizard.php3, row 315
$_m["max count of characters entered (maxlength parameter)"]
 = "número máximo de caracteres que se pueden introducir";

// include/constants_param_wizard.php3, row 318, 382
$_m["width"]
 = "ancho";

// include/constants_param_wizard.php3, row 319
$_m["width of the field in characters (size parameter)"]
 = "ancho del campo en el formulario de entrada (en columnas de texto)";

// include/constants_param_wizard.php3, row 324
$_m["Multiple Text Field"]
 = "Campo de Texto Múltiple";

// include/constants_param_wizard.php3, row 325
$_m["Text field input type which allows you to enter more than one (multiple) values into one field (just like if you select multiple values from Multiple Selectbox). The new values are filled by popup box."]
 = "Tipo de entrada de campo que permite entrar más de un (múltiple) valor dentro de un campo (tal como se realiza cuando se utilizan casillas de selección múltiple). Los nuevos valores son llenados por la caja popup.";

// include/constants_param_wizard.php3, row 327, 607
$_m["Show Actions"]
 = "Mostrar Acciones";

// include/constants_param_wizard.php3, row 333
$_m["Which action buttons to show:\n"
   ."                       <br>M - Move (up and down)\n"
   ."                       <br>D - Delete value,\n"
   ."                       <br>A - Add new value\n"
   ."                       <br>C - Change the value\n"
   ."                       <br>Use 'MDAC' (default), 'DAC', just 'M' or any other combination. The order of letters M,D,A,C is not important."]
 = "Qué botones de acción mostrar:\n"
   ."                       <br>M - Mover (arriba y abajo)\n"
   ."                       <br>D - Borrar un valor,\n"
   ."                       <br>A - Añadir un valor,\n"
   ."                       <br>C - Cambiar un valor\n"
   ."                       <br>Use 'MDAC' (por defecto), 'DAC', solamente 'M' u otra combinación. El orden de las letras M,D,A,C no es importante.";

// include/constants_param_wizard.php3, row 336, 538, 587, 647
$_m["Row count"]
 = "Filas";

// include/constants_param_wizard.php3, row 337
$_m["Number of rows (values) displayed at once"]
 = "Número de columnas (valores) mostrados a la vez";

// include/constants_param_wizard.php3, row 343
$_m["Select Box"]
 = "Caja selección";

// include/constants_param_wizard.php3, row 344
$_m["A selectbox field with a values list.<br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice, usually with the f_v alias function)"]
 = "Una caja de selección con una lista de valores.<br><br>Utiliza la caja de selección de constantes: si usted selecciona ahí un grupo de constantes, ese grupo se mostrará en la lista de valores; si selecciona un nombre de canal se mostrarán los títulos de los ítems (útil para hacer relaciones con otro canal, normalmente usando un alias con la función f_v)";

// include/constants_param_wizard.php3, row 346, 386
$_m["slice field"]
 = "campo del otro canal";

// include/constants_param_wizard.php3, row 347, 387, 543
$_m["field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"]
 = "campo (o cadena de formato) que se mostrará en la caja de selección (del canal relacionado). Si no se especifica, en la caja de selección se mostrarán los encabezados. Usted puede utilizar acá un formato de las AA (como: _#HEADLINE - _#PUB_DATE). (solamente para constantes de tipo entrada: slice)";

// include/constants_param_wizard.php3, row 349, 389, 453, 512, 545, 662, 969
$_m["category........"]
 = "";

// include/constants_param_wizard.php3, row 350, 390
$_m["use name"]
 = "usar nombre";

// include/constants_param_wizard.php3, row 351, 391
$_m["if set (=1), then the name of selected constant is used, insted of the value. Default is 0"]
 = "si es '1', se usará el nombre de las constantes en vez del valor";

// include/constants_param_wizard.php3, row 354, 406, 454, 513, 546, 663
$_m["Show items from bins"]
 = "Mostrar ítems desde las carpetas";

// include/constants_param_wizard.php3, row 362, 414, 462, 521, 554, 671
$_m["(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"]
 = "(para canales solamente). Para mostrar ítems seleccionados de las carpetas utilice los siguientes valores:<br>Aprobados -  '%1'<br>Pendientes - '%2'<br>Expirados - '%3'<br>Por aprobar - '%4'<br>Papelera - '%5'<br>El valor se genera como se muestra a continuación: p.e. Si Usted desea mostrar los encabezados desde Aprobados, Caducados y Por Aprobar. El valor de esta combinación se cuentas así %1+%3+%4&nbsp;=&nbsp;13";

// include/constants_param_wizard.php3, row 365, 417, 465, 524, 557, 674
$_m["Filtering conditions"]
 = "Condiciones de filtrado";

// include/constants_param_wizard.php3, row 366, 418, 466, 525, 558, 675
$_m["(for slices only) Conditions for filtering items in selection. Use conds[] array."]
 = "(para canales solamente) Condiciones de filtrado de ítems en la selección. Utilice el arreglo conds[]";

// include/constants_param_wizard.php3, row 369, 421, 469, 528, 561, 678
$_m["Sort by"]
 = "Ordenado por";

// include/constants_param_wizard.php3, row 370, 422, 470, 529, 562, 679
$_m["(for slices only) Sort the items in specified order. Use sort[] array"]
 = "(para canales solamente) Ordenar los ítems en un orden específico. Utilice el arreglo sort[]";

// include/constants_param_wizard.php3, row 375
$_m["Text Field with Presets"]
 = "Campo de texto con preselección";

// include/constants_param_wizard.php3, row 376
$_m["Text field with values names list. When you choose a name from the list, the appropriate value is printed in the text field"]
 = "Un campo de texto con una lista de valores al lado. Al seleccionar un valor de la lista, este valor se muestra en el campo de texto";

// include/constants_param_wizard.php3, row 379
$_m["max count of characteres entered in the text field (maxlength parameter)"]
 = "número máximo de caracteres que acepta el campo de texto";

// include/constants_param_wizard.php3, row 383
$_m["width of the text field in characters (size parameter)"]
 = "ancho del campo de texto a mostra (número de columnas de texto)r";

// include/constants_param_wizard.php3, row 394
$_m["adding"]
 = "añadir";

// include/constants_param_wizard.php3, row 395
$_m["adding the selected items to input field comma separated"]
 = "en vez de sobreescribir, ir añadiendo los valores separados por comas";

// include/constants_param_wizard.php3, row 398
$_m["secondfield"]
 = "otro campo";

// include/constants_param_wizard.php3, row 399
$_m["field_id of another text field, where value of this selectbox will be propagated too (in main text are will be text and there will be value)"]
 = "identificador de otro campo de texto donde propagar el valor de esta selección";

// include/constants_param_wizard.php3, row 401, 918
$_m["source_href....."]
 = "";

// include/constants_param_wizard.php3, row 402
$_m["add2constant"]
 = "crear constantes";

// include/constants_param_wizard.php3, row 403
$_m["if set to 1, user typped value in inputform is stored into constants (only if the value is not already there)"]
 = "si es '1' y el texto que se entra no se encuentra ya en la lista de valores, se añade una nueva constante";

// include/constants_param_wizard.php3, row 427
$_m["Text Area with Presets"]
 = "Area de texto con Preselección";

// include/constants_param_wizard.php3, row 428
$_m["Text area with values names list. When you choose a name from the list, the appropriate value is printed in the text area"]
 = "Un campo de texto con un area de texto al lado rellena con una lista de valores. Al seleccionar un valor de la lista, se escribe en el campo de texto";

// include/constants_param_wizard.php3, row 430
$_m["rows"]
 = "filas";

// include/constants_param_wizard.php3, row 431
$_m["Textarea rows"]
 = "número de filas del area de texto";

// include/constants_param_wizard.php3, row 434
$_m["cols"]
 = "columnas";

// include/constants_param_wizard.php3, row 435
$_m["Text area columns"]
 = "número de columnas del area de texto";

// include/constants_param_wizard.php3, row 439
$_m["Radio Button"]
 = "";

// include/constants_param_wizard.php3, row 440
$_m["Radio button group - the user may choose one value of the list. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"]
 = "Grupo de botones para chequear, donde el usuario sólo puede seleccionar uno a la vez de entre toda la lista. <br><br>Usa la caja de selección de constantes para determinar la lista de botones (puede seleccionar ahí un grupo de constantes o un canal)";

// include/constants_param_wizard.php3, row 442, 501
$_m["Columns"]
 = "Columnas";

// include/constants_param_wizard.php3, row 443, 502
$_m["Number of columns. If unfilled, the checkboxes are all on one line. If filled, they are formatted in a table."]
 = "Número de columnas. Si no se especifica, todos los ítems aparecen en linea. Si se pone aquí un valor, se formatean como una tabla";

// include/constants_param_wizard.php3, row 446, 505
$_m["Move right"]
 = "a la derecha";

// include/constants_param_wizard.php3, row 447, 506
$_m["Should the function move right or down to the next value?"]
 = "Cuando se formatea en tabla, poner los items de izquierda a derecha (si es '1') o de arriba a abajo (si es '0')";

// include/constants_param_wizard.php3, row 450, 509, 542, 637, 659
$_m["Slice field"]
 = "Campo de canal";

// include/constants_param_wizard.php3, row 451
$_m["Field (or format string) that will be displayed as radiobuton's option (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"]
 = "Campo (o cadena de formato) que será mostrada como una opción de botón de selección (desde un canal relacionado). Si no se especifica, en la caja de selección se mostrarán los encabezados. Usted puede utilizar acá un formato de las AA (como: _#HEADLINE - _#PUB_DATE). (solamente para constantes de tipo entrada: slice)";

// include/constants_param_wizard.php3, row 475
$_m["Date"]
 = "Fecha";

// include/constants_param_wizard.php3, row 476
$_m["you can choose an interval from which the year will be offered"]
 = "puede seleccionar un intervalo de años a ofrecer";

// include/constants_param_wizard.php3, row 478
$_m["Starting Year"]
 = "Año de inicio";

// include/constants_param_wizard.php3, row 479
$_m["The (relative) start of the year interval"]
 = "El primer año del rango (relativo al año actual)";

// include/constants_param_wizard.php3, row 482
$_m["Ending Year"]
 = "Año final";

// include/constants_param_wizard.php3, row 483
$_m["The (relative) end of the year interval"]
 = "El último año del rango (relativo al actual)";

// include/constants_param_wizard.php3, row 486
$_m["Relative"]
 = "Relativo";

// include/constants_param_wizard.php3, row 487
$_m["If this is 1, the starting and ending year will be taken as relative - the interval will start at (this year - starting year) and end at (this year + ending year). If this is 0, the starting and ending years will be taken as absolute."]
 = "Si es '1', los años de inicio y final se tomarán relativos al año en curso, es decir, el año inicial será este año menos el año inicial, y el final será este año más el final. Si es '0', los valores de año inicial y final se tomarán como absolutos";

// include/constants_param_wizard.php3, row 490
$_m["Show time"]
 = "Mostrar hora";

// include/constants_param_wizard.php3, row 491
$_m["show the time box? (1 means Yes, undefined means No)"]
 = "Mostrar una caja para especificar la hora";

// include/constants_param_wizard.php3, row 495
$_m["Checkbox"]
 = "Selección";

// include/constants_param_wizard.php3, row 496
$_m["The field value will be represented by a checkbox."]
 = "Mostrar una cajita de selección que permita 'activar' o 'desactivar' el valor de este campo. Sirve para los campos de tipo booleano (cierto o falso)";

// include/constants_param_wizard.php3, row 498
$_m["Multiple Checkboxes"]
 = "Selección Múltiple";

// include/constants_param_wizard.php3, row 499
$_m["Multiple choice checkbox group. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"]
 = "Mostrar una lista de valores con cajitas para seleccionarlos individualmente.<br><br>La lista de valores se especifica en la caja de selección de constantes, y puede ser un grupo de constantes o un canal.";

// include/constants_param_wizard.php3, row 510
$_m["Field (or format string) that will be displayed as checbox options (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"]
 = "Campo (o cadena de formato) que será mostrada como una opción de botón de selección (desde un canal relacionado). Si no se especifica, en la caja de selección se mostrarán los encabezados. Usted puede utilizar acá un formato de las AA (como: _#HEADLINE - _#PUB_DATE). (solamente para constantes de tipo entrada: slice)";

// include/constants_param_wizard.php3, row 535
$_m["Multiple Selectbox"]
 = "Caja de selección múltiple";

// include/constants_param_wizard.php3, row 536
$_m["Multiple choice select box. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"]
 = "Muestra una caja de selección de donde se puede seleccionar más de un valor a la vez (con la tecla control o shift).<br><br>La lista de valores se especifica en la caja de selección de constantes, y puede ser un grupo de constantes o un canal.";

// include/constants_param_wizard.php3, row 568
$_m["File"]
 = "Archivo";

// include/constants_param_wizard.php3, row 569
$_m["File upload - a text field with the file find button"]
 = "Campo para subir archivo: un campo de texto con un botón que permite buscar un archivo en el disco local";

// include/constants_param_wizard.php3, row 571
$_m["Allowed file types"]
 = "Tipos aceptados";

// include/constants_param_wizard.php3, row 574
$_m["image/*"]
 = "";

// include/constants_param_wizard.php3, row 575
$_m["Label"]
 = "Etiqueta";

// include/constants_param_wizard.php3, row 576
$_m["To be printed before the file upload field"]
 = "Se muestra delante del campo";

// include/constants_param_wizard.php3, row 578
$_m["File: "]
 = "Archivo#: ";

// include/constants_param_wizard.php3, row 579
$_m["Hint"]
 = "Ayuda";

// include/constants_param_wizard.php3, row 580
$_m["appears beneath the file upload field"]
 = "Aparece bajo el campo de subir archivo";

// include/constants_param_wizard.php3, row 582
$_m["You can select a file ..."]
 = "Solo se aceptan imágenes en formato JPG.";

// include/constants_param_wizard.php3, row 584
$_m["Related Item Window"]
 = "Ventana para relacionar ítems";

// include/constants_param_wizard.php3, row 585
$_m["List of items connected with the active one - by using the buttons Add and Delete you show a window, where you can search in the items list"]
 = "Abre otra ventana para interrelacionar ítems, mediatne los botones Añadir y Borrar";

// include/constants_param_wizard.php3, row 588
$_m["Row count in the list"]
 = "Filas a listar";

// include/constants_param_wizard.php3, row 591
$_m["Buttons to show"]
 = "Mostrar botones";

// include/constants_param_wizard.php3, row 596
$_m["Defines, which buttons to show in item selection:\n"
   ."                       <br>A - Add\n"
   ."                       <br>M - add Mutual\n"
   ."                       <br>B - Backward\n"
   ."                       <br> Use 'AMB' (default), 'MA', just 'A' or any other combination. The order of letters A,M,B is important."]
 = "Define que botones se muestran en la selección de un ítem:\n"
   ."                       <br>A - Añadir\n"
   ."                       <br>M - Mutuo\n"
   ."                       <br>B - Inverso\n"
   ."                       <br> Utilice 'AMB' (por defecto), 'MA', solo 'A' u otra combinación. El orden de las letras A,M,B es importante.";

// include/constants_param_wizard.php3, row 598, 606
$_m["AMB"]
 = "";

// include/constants_param_wizard.php3, row 599
$_m["Admin design"]
 = "diseño administrador";

// include/constants_param_wizard.php3, row 600
$_m["If set (=1), the items in related selection window will be listed in the same design as in the Item manager - 'Design - Item Manager' settings will be used. Only the checkbox will be replaced by the buttons (see above). It is important that the checkbox must be defined as:<br> <i>&lt;input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"&gt;</i> (which is default).<br> If unset (=0), just headline is shown (default)."]
 = "Si es verdadero (1), la lista de items se muestra usando el diseño del administrador de ítems ('Diseño - Administrador de ítems'), solo que reemplazando la caja de selección por los botones.<br><b>Importante:</b> el diseño de la caja de selección debe ser  <i>&lt;input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"&gt;</i> (es así por defecto).<br> Si es falso (0), mostrar solo los títulos.";

// include/constants_param_wizard.php3, row 603
$_m["Tag Prefix"]
 = "Prefijo de etiqueta";

// include/constants_param_wizard.php3, row 604
$_m["Selects tag set ('AMB' / 'GYR'). Ask Mitra for more details."]
 = "Selecciona el set de etiquetas ('AMB' / 'GYR').";

// include/constants_param_wizard.php3, row 614
$_m["Which action buttons to show:\n"
   ."                       <br>M - Move (up and down)\n"
   ."                       <br>D - Delete relation,\n"
   ."                       <br>R - add Relation to existing item\n"
   ."                       <br>N - insert new item in related slice and make it related\n"
   ."                       <br>E - Edit related item\n"
   ."                       <br>Use 'DR' (default), 'MDRNE', just 'N' or any other combination. The order of letters M,D,R,N,E is not important."]
 = "Qué botones de acción mostrar:\n"
   ."                       <br>M - Mover (arriba y abajo)\n"
   ."                       <br>D - Borrar relación,\n"
   ."                       <br>R - añadir relación al ítem actual\n"
   ."                       <br>N - insertar nuevo ítem en el canal relacionado y haga la relación\n"
   ."                       <br>E - Editar el ítem relacionado\n"
   ."                       <br>Use 'DR' (por defecto), 'MDRNE', solo 'N' u otra combinación. El orden de las letras M,D,R,N,E no es importante.";

// include/constants_param_wizard.php3, row 617
$_m["Show headlines from selected bins"]
 = "Mostrar encabezados de las carpetas seleccionadas";

// include/constants_param_wizard.php3, row 626
$_m["To show headlines in related window from selected bins.<br>Use this values for bins:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"]
 = "Para mostrar ítems seleccionados de las carpetas utilice los siguientes valores:<br>Aprobados -  '%1'<br>Pendientes - '%2'<br>Expirados - '%3'<br>Por aprobar - '%4'<br>Papelera - '%5'<br>El valor se genera como se muestra a continuación: p.e. Si Usted desea mostrar los encabezados desde Aprobados, Caducados y Por Aprobar. El valor de esta combinación se cuentas así %1+%3+%4&nbsp;=&nbsp;13";

// include/constants_param_wizard.php3, row 629
$_m["Filtering conditions - unchangeable"]
 = "Condiciones de filtrado - No es posible cambiarlas";

// include/constants_param_wizard.php3, row 630
$_m["Conditions for filtering items in related items window. This conds user can't change."]
 = "Condiciones para filtrado de ítems en la ventana de ítems relacionados. Estas condiniones no pueden ser cambiadas por el usuario";

// include/constants_param_wizard.php3, row 633
$_m["Filtering conditions - changeable"]
 = "Condiciones de filtrado - No es posible cambiarlas";

// include/constants_param_wizard.php3, row 634
$_m["Conditions for filtering items in related items window. This conds user can change."]
 = "Condiciones para filtrado de ítems en la ventana de ítems relacionados. Estas condiniones no pueden ser cambiadas por el usuario";

// include/constants_param_wizard.php3, row 638
$_m["field (or format string) that will be displayed in the boxes (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - {publish_date....})."]
 = "";

// include/constants_param_wizard.php3, row 640
$_m["publish_date...."]
 = "";

// include/constants_param_wizard.php3, row 644
$_m["Two Windows"]
 = "Dos cajas";

// include/constants_param_wizard.php3, row 645
$_m["Two Windows. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"]
 = "Muestra dos cajas con botones para intercambiar entre los valores ofrecidos y seleccionados. La lista de valores se especifica en la caja de selección de constantes, y puede ser un grupo de constantes o un canal.";

// include/constants_param_wizard.php3, row 651
$_m["Title of \"Offer\" selectbox"]
 = "Título de la caja \"oferta\"";

// include/constants_param_wizard.php3, row 654
$_m["Our offer"]
 = "Oferta";

// include/constants_param_wizard.php3, row 655
$_m["Title of \"Selected\" selectbox"]
 = "Título de la caja \"seleccionados\"";

// include/constants_param_wizard.php3, row 658
$_m["Selected"]
 = "Seleccionados";

// include/constants_param_wizard.php3, row 660
$_m["field (or format string) that will be displayed in the boxes (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"]
 = "Campo (o cadena de formato) que será mostrada como una opción de botón de selección (desde un canal relacionado). Si no se especifica, en la caja de selección se mostrarán los encabezados. Usted puede utilizar acá un formato de las AA (como: _#HEADLINE - _#PUB_DATE). (solamente para constantes de tipo entrada: slice)";

// include/constants_param_wizard.php3, row 685
$_m["Hidden field"]
 = "Campo oculto";

// include/constants_param_wizard.php3, row 686
$_m["The field value will be shown as &lt;input type='hidden'. You will probably set this filed by javascript trigger used on any other field."]
 = "El valor de este campo no aparecerá en el formulario, sino que estará ahi en la forma &lt;input type='hidden'&gt;. Este tipo de entrada es útil para usar Verificadores de Campos con JavaScript sobre cualquier otro campo.";

// include/constants_param_wizard.php3, row 694
$_m["Password input boxes allowing to send password (for password-protected items)\n"
   ."        and to change password (including the \"Retype password\" box).<br><br>\n"
   ."        When a user fills new password, it is checked against the retyped password,\n"
   ."        MD5-encrypted so that nobody may learn it and stored in the database.<br><br>\n"
   ."        If the field is not Required, shows a 'Delete Password' checkbox."]
 = "Campos de texto para claves. Si el campo no es obligatorio, también muestra una caja para 'Borrar Clave'";

// include/constants_param_wizard.php3, row 696
$_m["Field size"]
 = "Tamaño del campo";

// include/constants_param_wizard.php3, row 697
$_m["Size of the three fields"]
 = "Tamaño de los campos";

// include/constants_param_wizard.php3, row 700
$_m["Label for Change Password"]
 = "Etiqueta para Cambio de clave";

// include/constants_param_wizard.php3, row 701
$_m["Replaces the default 'Change Password'"]
 = "Sustituye la normal 'Cambiar Clave'";

// include/constants_param_wizard.php3, row 703
$_m["Change your password"]
 = "Nueva clave";

// include/constants_param_wizard.php3, row 704
$_m["Label for Retype New Password"]
 = "Etiqueta para confirmar clave";

// include/constants_param_wizard.php3, row 705
$_m["Replaces the default \"Retype New Password\""]
 = "Sustituye la normal 'Reescriba nueva clave'";

// include/constants_param_wizard.php3, row 707
$_m["Retype the new password"]
 = "Confirmar Nueva clave";

// include/constants_param_wizard.php3, row 708
$_m["Label for Delete Password"]
 = "Etiqueta para Borrar Clave";

// include/constants_param_wizard.php3, row 709
$_m["Replaces the default \"Delete Password\""]
 = "Sustituye la normal 'Borrar Clave'";

// include/constants_param_wizard.php3, row 711
$_m["Delete password (set to empty)"]
 = "Borrar (vaciar)";

// include/constants_param_wizard.php3, row 712
$_m["Help for Change Password"]
 = "Ayuda";

// include/constants_param_wizard.php3, row 713
$_m["Help text under the Change Password box (default: no text)"]
 = "Texto a mostrar debajo de la caja de camibo de clave";

// include/constants_param_wizard.php3, row 715
$_m["To change password, enter the new password here and below"]
 = "Para cambiar su clave, escribala aquí y debajo para confirmar";

// include/constants_param_wizard.php3, row 716
$_m["Help for Retype New Password"]
 = "Ayuda para Confirmación";

// include/constants_param_wizard.php3, row 717
$_m["Help text under the Retype New Password box (default: no text)"]
 = "Texto a mostrar debajo de la caja de confirmación de clave";

// include/constants_param_wizard.php3, row 720
$_m["Retype the new password exactly the same as you entered into \"Change Password\"."]
 = "Reescriba aquí su clave exactamente como la introdujo en el campo anterior";

// include/constants_param_wizard.php3, row 724
$_m["Do not show"]
 = "No mostrar";

// include/constants_param_wizard.php3, row 725
$_m["This option hides the input field"]
 = "Esta opción esconde el campo de entrada";

// include/constants_param_wizard.php3, row 729
$_m["Local URL Pick"]
 = "";

// include/constants_param_wizard.php3, row 730
$_m["You can use this window to browse your local web site and pick the URL that you want to use."]
 = "";

// include/constants_param_wizard.php3, row 735, 782, 861
$_m["http#://www.ecn.cz/articles/solar.shtml"]
 = "http#://mi.sitio.com/noticias.shtml";

// include/constants_param_wizard.php3, row 740
$_m["Function"]
 = "Función";

// include/constants_param_wizard.php3, row 741
$_m["How the formatting in the text on this page is used:<br><i>the field</i> in italics stands for the field edited in the \"configure Fields\" window,<br><b>parameter name</b> in bold stands for a parameter on this screen."]
 = "Convenciones de presentación: <br><i>el campo</i> en cursiva significa el valor del campo.,<br><b>parámetro</b> en negrilla se refiera a uno de los parámetros de esta ventana";

// include/constants_param_wizard.php3, row 744
$_m["file information"]
 = "";

// include/constants_param_wizard.php3, row 745
$_m["prints <i>the field</i> as file information, its size or type"]
 = "";

// include/constants_param_wizard.php3, row 747, 880
$_m["information"]
 = "información";

// include/constants_param_wizard.php3, row 748
$_m["specifies returned information: <br> - <i>size</i> - returns size of file in kB o MB<br> - <i>type</i> - returns type of file)"]
 = "";

// include/constants_param_wizard.php3, row 751
$_m["null function"]
 = "función nula";

// include/constants_param_wizard.php3, row 752
$_m["prints nothing"]
 = "no muestra nada";

// include/constants_param_wizard.php3, row 753
$_m["abstract"]
 = "resumen";

// include/constants_param_wizard.php3, row 754
$_m["prints abstract (if exists) or the beginning of the <b>fulltext</b>"]
 = "muestra el resúmen (si existe), o el principio del <b>texto completo</b>";

// include/constants_param_wizard.php3, row 756
$_m["length"]
 = "longitud";

// include/constants_param_wizard.php3, row 757
$_m["max number of characters grabbed from the <b>fulltext</b> field"]
 = "máximo número de caracteres tomados del campo de <b>texto completo</b>";

// include/constants_param_wizard.php3, row 760
$_m["fulltext"]
 = "texto completo";

// include/constants_param_wizard.php3, row 761
$_m["field id of fulltext field (like full_text.......), from which the text is grabbed. If empty, the text is grabbed from <i>the field</i> itself."]
 = "id de campo o campo de texto completo (como full_text.......), desde donde el texto es tomado. Si se deja vacío, el texto se tomará del <i>mismo</i> campo.";

// include/constants_param_wizard.php3, row 763, 790, 981
$_m["full_text......."]
 = "texto_completo..";

// include/constants_param_wizard.php3, row 764
$_m["paragraph"]
 = "párrafo";

// include/constants_param_wizard.php3, row 765
$_m["take first paragraph (text until \\<BR\\> or \\<P\\> or \\</P\\> or at least '.' (dot)) if shorter then <b>length</b>"]
 = "tome el primer párrafo (texto hasta  \\<BR\\> o \\<P\\> o \\</P\\> o al menos '.' (punto)) si es más corto entonces <b>longitud</b>";

// include/constants_param_wizard.php3, row 768
$_m["extended fulltext link"]
 = "enlace a texto completo extendido";

// include/constants_param_wizard.php3, row 769
$_m["Prints some <b>text</b> (or field content) with a link to the fulltext. A more general version of the f_f function. This function doesn't use <i>the field</i>."]
 = "Esta es una versión más genérica de la función f_f que no usa <i>el campo</i>. Muestra el <i>texto</i> (o su contenido) como un enlace al texto completo.";

// include/constants_param_wizard.php3, row 771, 854
$_m["link only"]
 = "externo";

// include/constants_param_wizard.php3, row 772
$_m["field id (like 'link_only.......') where switch between external and internal item is stored.  (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior)."]
 = "identificador del campo que decide si el enlace debe ser al texto completo o bien externo.";

// include/constants_param_wizard.php3, row 774, 857
$_m["link_only......."]
 = "";

// include/constants_param_wizard.php3, row 775
$_m["url_field"]
 = "campo url";

// include/constants_param_wizard.php3, row 776
$_m["field id if field, where external URL is stored (like hl_href.........)"]
 = "identificador del campo que contiene el URL en caso de enlaces externos";

// include/constants_param_wizard.php3, row 778
$_m["hl_href........."]
 = "";

// include/constants_param_wizard.php3, row 779, 858
$_m["redirect"]
 = "redirección";

// include/constants_param_wizard.php3, row 780, 859
$_m["The URL of another page which shows the content of the item. (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior)."]
 = "URL de la página que sabe mostrar el texto completo (esta página es la que contiene el SSI include de slice.php3). Si no se pone nada, se asume la página actual.";

// include/constants_param_wizard.php3, row 783
$_m["text"]
 = "texto";

// include/constants_param_wizard.php3, row 784
$_m["The text to be surrounded by the link. If this parameter is a field id, the field's content is used, else it is used verbatim"]
 = "El texto del enlace. Puede poner un identificador de campo aquí, en cuyo caso se muestra el contenido de dicho campo. De lo contrario, se muestra este texto literalmente.";

// include/constants_param_wizard.php3, row 787
$_m["condition field"]
 = "campo condición";

// include/constants_param_wizard.php3, row 788
$_m["when the specified field hasn't any content, no link is printed, but only the <b>text</b>"]
 = "cuando el campo especificado no tenga contenido no se va a generar un enlace, sólo se va a mostrar el <b>texto</b>";

// include/constants_param_wizard.php3, row 791, 938
$_m["tag addition"]
 = "añadir al tag";

// include/constants_param_wizard.php3, row 792, 939
$_m["additional text to the \"\\<a\\>\" tag"]
 = "atributos adicionales para el tag 'A'";

// include/constants_param_wizard.php3, row 794, 941
$_m["target=_blank"]
 = "";

// include/constants_param_wizard.php3, row 795, 862
$_m["no session id"]
 = "sin sesión";

// include/constants_param_wizard.php3, row 796, 863
$_m["If 1, the session id (AA_SL_Session=...) is not added to url"]
 = "Si es '1', se omite el identificador de sesión al generar los enlaces a texto completo";

// include/constants_param_wizard.php3, row 799, 802
$_m["condition"]
 = "condición";

// include/constants_param_wizard.php3, row 800
$_m["This is a very powerful function. It may be used as a better replace of some previous functions. If <b>cond_field</b> = <b>condition</b>, prints <b>begin</b> <i>field</i> <b>end</b>, else prints <b>else</b>. If <b>cond_field</b> is not specified, <i>the field</i> is used. Condition may be reversed (negated) by the \"!\" character at the beginning of it."]
 = "Esta función es muy poderosa. Entre otras cosas, puede remplazar a otras funciones. Si <b>campo_condición</b> = <b>condición</b>, muestra <b>inicio</b> <i>el campo</i> <b>fin</b>, de lo contrario muestra <b>si_no</b>. Si no se especifica <b>campo_condición</b>, se usa <i>el campo</i>. La condición se puede invertir (negar) poniendo un signo de admiración (\"!\") al principio.";

// include/constants_param_wizard.php3, row 803
$_m["you may use \"!\" to reverse (negate) the condition"]
 = "puede usar \"!\" para invertir (negar) la condición";

// include/constants_param_wizard.php3, row 806, 922
$_m["begin"]
 = "inicio";

// include/constants_param_wizard.php3, row 807
$_m["text to print before <i>field</i>, if condition is true"]
 = "texto a mostrar antes de <i>el campo</i> si la condición es cierta";

// include/constants_param_wizard.php3, row 809
$_m["Yes"]
 = "Si";

// include/constants_param_wizard.php3, row 810
$_m["end"]
 = "fin";

// include/constants_param_wizard.php3, row 811
$_m["text to print after <i>field</i>, if condition is true"]
 = "texto a mostrar después de <i>el campo</i> si la condición es cierta";

// include/constants_param_wizard.php3, row 814
$_m["else"]
 = "si_no";

// include/constants_param_wizard.php3, row 815
$_m["text to print when condition is not satisfied"]
 = "texto a mostrar cuando la condicion no se satisface";

// include/constants_param_wizard.php3, row 817
$_m["No"]
 = "";

// include/constants_param_wizard.php3, row 818
$_m["cond_field"]
 = "campo_condicion";

// include/constants_param_wizard.php3, row 819
$_m["field to compare with the <b>condition</b> - if not filled, <i>field</i> is used"]
 = "campo sobre el que evaluar la <b>condición</b>. Si no se pone nada, se compara la condición con <i>el campo</i>";

// include/constants_param_wizard.php3, row 822
$_m["skip_the_field"]
 = "omitir_campo";

// include/constants_param_wizard.php3, row 823
$_m["if set to 1, skip <i>the field</i> (print only <b>begin end</b> or <b>else</b>)"]
 = "si se pone '1', no muestra <i>el campo</i> (muestra solo <b>inicio fin</b>)";

// include/constants_param_wizard.php3, row 827
$_m["This example is usable e.g. for Highlight field - it shows Yes or No depending on the field content"]
 = "Ejemplo para mostrar el campo 'Resaltado': muestra 'Si' o 'No' dependiendo del contenido del campo";

// include/constants_param_wizard.php3, row 828
$_m["1:Yes::No::1"]
 = "1:Si::No::1";

// include/constants_param_wizard.php3, row 829
$_m["When e-mail field is filled, prints something like \"Email: email@apc.org\", when it is empty, prints nothing"]
 = "Si se rellena el campo 'Fuente', muestra algo así: \"Fuente: Reuters\"; de lo contrario, no muestra nada";

// include/constants_param_wizard.php3, row 830
$_m["!:Email#:&nbsp;"]
 = "!:Fuente#:&nbsp;";

// include/constants_param_wizard.php3, row 831
$_m["Print image height attribute, if <i>the field</i> is filled, nothing otherwise."]
 = "Igual que el anterior, pero si no se conoce la fuente, muestra el autor (suponiendo que el alias _#AUTOR___ lo muestra)";

// include/constants_param_wizard.php3, row 832
$_m["!:height="]
 = "!:Fuente#:&nbsp;::_#AUTOR___";

// include/constants_param_wizard.php3, row 833
$_m["date"]
 = "fecha";

// include/constants_param_wizard.php3, row 834
$_m["prints date in a user defined format"]
 = "muestra la fecha en el formato definido";

// include/constants_param_wizard.php3, row 836
$_m["format"]
 = "formato";

// include/constants_param_wizard.php3, row 837
$_m["PHP-like format - see <a href=\"http://php.net/manual/en/function.date.php\" target=_blank>PHP manual</a>"]
 = "Formato estilo PHP - vea <a href=\"http://php.net/manual/en/function.date.php\" target=_blank>el manual de PHP</a>";

// include/constants_param_wizard.php3, row 839
$_m["m-d-Y"]
 = "d-m-Y";

// include/constants_param_wizard.php3, row 840
$_m["edit item"]
 = "editar ítem";

// include/constants_param_wizard.php3, row 841
$_m["_#EDITITEM used on admin page index.php3 for itemedit url"]
 = "alias _#EDITITEM usado en la página de administración index.php3 para el enlace para editar el ítem";

// include/constants_param_wizard.php3, row 844
$_m["disc - for editing a discussion<br>itemcount - to output an item count<br>safe - for safe html<br>slice_info - select a field from the slice info<br>edit - URL to edit the item<br>add - URL to add a new item"]
 = "disc - para editar comentarios<br>itemcount - recuento de ítems<br>safe - HTML seguro<br>slice_info - seleccionar un campo del canal<br>edit - URL para editar el ítem<br>add - URL para añadir un ítem";

// include/constants_param_wizard.php3, row 846
$_m["edit"]
 = "editar";

// include/constants_param_wizard.php3, row 847
$_m["return url"]
 = "url retorno";

// include/constants_param_wizard.php3, row 848
$_m["Return url being called from, usually leave blank and allow default"]
 = "URL de la página a regresar. Si no se especifica, regresa a la actual.";

// include/constants_param_wizard.php3, row 850
$_m["/mysite.shtml"]
 = "/noticias.shtml";

// include/constants_param_wizard.php3, row 851
$_m["fulltext link"]
 = "enlace a texto completo";

// include/constants_param_wizard.php3, row 852
$_m["Prints the URL name inside a link to the fulltext - enables using external items. To be used immediately after \"\\<a href=\""]
 = "URL para el texto completo del ítem. Permite usar enlaces externos. Para usar dentro de \"\\<a href=\"";

// include/constants_param_wizard.php3, row 855
$_m["field id (like 'link_only.......') where switch between external and internal item is stored. Usually this field is represented as checkbox. If the checkbox is checked, <i>the field</i> is printed, if unchecked, link to fulltext is printed (it depends on <b>redirect</b> parameter, too)."]
 = "identificador del campo que determina si el enlace es externo o interno (normalmente este campo se muestra como un checkbox). Si el checkbox está seleccionado, se muestra <i>el campo</i>, y si no está seleccionado se genera un enlace al texto completo (que depende del parámetro <b>redirección</b>).";

// include/constants_param_wizard.php3, row 866
$_m["image height"]
 = "alto imágen";

// include/constants_param_wizard.php3, row 867
$_m["An old-style function. Prints <i>the field</i> as image height value (\\<img height=...\\>) or erases the height tag. To be used immediately after \"height=\".The f_c function provides a better way of doing this with parameters \":height=\". "]
 = "Función anticuada. Use mejor la función f_c";

// include/constants_param_wizard.php3, row 868
$_m["print HTML multiple"]
 = "múltiples - HTML";

// include/constants_param_wizard.php3, row 869
$_m["prints <i>the field</i> content depending on the html flag (escape html special characters or just print)"]
 = "Muestra <i>el campo</i>, adminitiendo que éste tenga múltiples valores. Además, dependiendo del flag 'HTML / texto plano', puede preformatear la salida";

// include/constants_param_wizard.php3, row 871
$_m["delimiter"]
 = "separador";

// include/constants_param_wizard.php3, row 872
$_m["if specified, a field with multiple values is displayed with the values delimited by it"]
 = "Si se especifica, cuando hay múltiples valores se muestran separados por este delimitador";

// include/constants_param_wizard.php3, row 875
$_m["image src"]
 = "url imagen";

// include/constants_param_wizard.php3, row 876
$_m["prints <i>the field</i> as image source (\\<img src=...\\>) - NO_PICTURE for none. The same could be done by the f_c function with parameters :::NO_PICTURE. "]
 = "Función anticuada. Use mejor la función f_c";

// include/constants_param_wizard.php3, row 877
$_m["image size"]
 = "tamaño imágen";

// include/constants_param_wizard.php3, row 878
$_m["prints <i>the field</i> as image size (height='xxx' width='yyy') (or other image information) or empty string if cant work out, does not special case URLs from uploads directory, might do later! "]
 = "imprima <i>el campo</i> como tamaño de imagen (height='xxx' width='yyy') (u otra información de la imagen).";

// include/constants_param_wizard.php3, row 881
$_m["specifies returned information: <br> - <i>html</i> - (default) - returns image size as HTML atributes (height='xxx' width='yyy')<br> - <i>width</i> - returns width of image in pixels<br> - <i>height</i> - returns height of image in pixels<br> - <i>imgtype</i> - returns flag indicating the type of the image: 1 = GIF, 2 = JPG, 3 = PNG, 4 = SWF, 5 = PSD, 6 = BMP, 7 = TIFF(intel byte order), 8 = TIFF(motorola byte order), 9 = JPC, 10 = JP2, 11 = JPX, 12 = JB2, 13 = SWC, 14 = IFF, 15 = WBMP, 16 = XBM<br> - <i>mime</i> - returns mimetype of the image (like 'image/gif', 'application/x-shockwave-flash', ...)"]
 = "especifica la información regresada: <br> - <i>html</i> - (por defecto) - retorna el tamaño de la imagen como atributos de HTML (height='xxx' width='yyy')<br> - <i>width</i> - retorna el ancho de la imagen en pixels.<br> - <i>height</i> - retorna el alto de la imagen en pixels<br> - <i>imgtype</i> - retorna una bandera indicando el tipo de la imagen: 1 = GIF, 2 = JPG, 3 = PNG, 4 = SWF, 5 = PSD, 6 = BMP, 7 = TIFF(orden byte intel), 8 = TIFF(orden byte motorola), 9 = JPC, 10 = JP2, 11 = JPX, 12 = JB2, 13 = SWC, 14 = IFF, 15 = WBMP, 16 = XBM<br> - <i>mime</i> - retorna el mimetype de la imagen (como 'image/gif', 'application/x-shockwave-flash', ...)";

// include/constants_param_wizard.php3, row 885
$_m["expanded string"]
 = "expandir cadena";

// include/constants_param_wizard.php3, row 886
$_m["expands the string in the parameter"]
 = "expande la cadena del parámetro";

// include/constants_param_wizard.php3, row 888
$_m["string to expand"]
 = "cadena";

// include/constants_param_wizard.php3, row 889
$_m["if specified then this string is expanded, if not specified then expands the contents of the field"]
 = "si se especifica, expande esta cadena. Si no, se expande <i>el campo</i>";

// include/constants_param_wizard.php3, row 892
$_m["substring with case change"]
 = "subcadena y corrige caja";

// include/constants_param_wizard.php3, row 893
$_m["prints a part of <i>the field</i>"]
 = "muestra una parte d<i>el campo</i>";

// include/constants_param_wizard.php3, row 895
$_m["start"]
 = "inicio";

// include/constants_param_wizard.php3, row 896
$_m["position of substring start (0=first, 1=second, -1=last,-2=two from end)"]
 = "posición para empezar a escribir: 0=primer caracter. 1=segundo, -1=último, -2=penúltimo";

// include/constants_param_wizard.php3, row 899
$_m["count"]
 = "cuantos";

// include/constants_param_wizard.php3, row 900
$_m["count of characters (0=until the end)"]
 = "número de caracteres a mostrar (0=hasta el final)";

// include/constants_param_wizard.php3, row 903
$_m["case"]
 = "caja";

// include/constants_param_wizard.php3, row 904
$_m["upper - convert to UPPERCASE, lower - convert to lowercase, first - convert to First Upper; default is don't change"]
 = "upper - convertir a MAYUSCULAS<br>lower - convertir a minúsculas<br>first - convertir Primera Mayúscula<br>si no se pone nada, no cambia la caja";

// include/constants_param_wizard.php3, row 907
$_m["add string"]
 = "añadir cadena";

// include/constants_param_wizard.php3, row 908
$_m["if string is shorted, <i>add string</i> is appended to the string (probably something like [...])"]
 = "si la cadena es recortada, <i>añadir cadena</i> será adicionada a la cadena (probablemente algo como [...])";

// include/constants_param_wizard.php3, row 911
$_m["Auto Update Checkbox"]
 = "auto-actualización";

// include/constants_param_wizard.php3, row 912
$_m["linked field"]
 = "campo relacionado";

// include/constants_param_wizard.php3, row 913
$_m["prints <i>the field</i> as a link if the <b>link URL</b> is not NULL, otherwise prints just <i>the field</i>"]
 = "muestra <i>el campo</i> como un enlace si el <b>URL</b> no está vacío. De lo contrario, muestra solamente <i>el campo</i>";

// include/constants_param_wizard.php3, row 915
$_m["link URL"]
 = "URL";

// include/constants_param_wizard.php3, row 919
$_m["e-mail or link"]
 = "e-mail o enlace";

// include/constants_param_wizard.php3, row 920
$_m["mailto link - prints: <br>\"<b>begin</b>\\<a href=\"(mailto:)<i>the field</i>\" <b>tag adition</b>\\><b>field/text</b>\\</a\\>. If <i>the field</i> is not filled, prints <b>else_field/text</b>."]
 = "enlace a correo - muestra:<br>\"<b>inicio</b>\\<a href=\"(mailto:)<i>el campo</i>\" <b>añadir al tag</b>\\><b>campo/texto</b>\\</a\\>. Si <i>el campo</i> no tiene contenido, muestra <b>si_no/texto</b>.";

// include/constants_param_wizard.php3, row 923
$_m["text before the link"]
 = "texto antes del enlace";

// include/constants_param_wizard.php3, row 925
$_m["e-mail"]
 = "Correo-e:";

// include/constants_param_wizard.php3, row 926
$_m["field/text"]
 = "campo/texto";

// include/constants_param_wizard.php3, row 927
$_m["if this parameter is a field id, the field's content is used, else it is used verbatim"]
 = "si escribe un id de campo, se muestra el contenido de ese campo. De lo contrario, se muestra el texto literal";

// include/constants_param_wizard.php3, row 930
$_m["else_field/text"]
 = "si_no/texto";

// include/constants_param_wizard.php3, row 931
$_m["if <i>the field</i> is empty, only this text (or field content) is printed"]
 = "si <i>el campo</i> está vacío, muestra solo este texto (si escribe un id de campo, se mostrará el contenido de ese campo)";

// include/constants_param_wizard.php3, row 934
$_m["linktype"]
 = "tipo";

// include/constants_param_wizard.php3, row 935
$_m["mailto / href (default is mailto) - it is possible to use f_m function for links, too - just type 'href' as this parameter"]
 = "mailto / href (por defecto es mailto). Si quiere usar esta función para enlaces normales, escriba href en este parámetro";

// include/constants_param_wizard.php3, row 937
$_m["href"]
 = "";

// include/constants_param_wizard.php3, row 942
$_m["hide email"]
 = "esconder correo-e";

// include/constants_param_wizard.php3, row 943
$_m["if 1 then hide email from spam robots. Default is 0."]
 = "si el valor es 1 entonces oculte el correo-e de robots de spam. Por defecto es 0";

// include/constants_param_wizard.php3, row 946
$_m["'New' sign"]
 = "signo 'Nuevo'";

// include/constants_param_wizard.php3, row 947
$_m["prints 'New' or 'Old' or any other text in <b>newer text</b> or <b>older text</b> depending on <b>time</b>. Time is specified in minutes from current time."]
 = "para mostrar 'Nuevo' o 'Viejo' o cualquier otro texto en <b>texto nuevo</b> o <b>texto viejo</b> en función del <b>tiempo</b>, relativo a la fecha y hora actuales.";

// include/constants_param_wizard.php3, row 949
$_m["time"]
 = "tiempo";

// include/constants_param_wizard.php3, row 950
$_m["Time in minutes from current time."]
 = "minutos desde la hora actual";

// include/constants_param_wizard.php3, row 952
$_m["1440"]
 = "";

// include/constants_param_wizard.php3, row 953
$_m["newer text"]
 = "texto nuevo";

// include/constants_param_wizard.php3, row 954
$_m["Text to be printed, if the date in <i>the filed</i> is newer than <i>current_time</i> - <b>time</b>."]
 = "Texto que se muestra si la fecha en <i>el campo</i> es más reciente que la hora actual menos <b>tiempo</b>";

// include/constants_param_wizard.php3, row 956
$_m["NEW"]
 = "<b>¡NUEVO!</b>";

// include/constants_param_wizard.php3, row 957
$_m["older text"]
 = "texto viejo";

// include/constants_param_wizard.php3, row 958
$_m["Text to be printed, if the date in <i>the filed</i> is older than <i>current_time</i> - <b>time</b>"]
 = "Texto que se muestra si la fecha en <i>el campo</i> es posterior a la hora actual menos <b>tiempo</b>";

// include/constants_param_wizard.php3, row 960, 1009
$_m[""]
 = "";

// include/constants_param_wizard.php3, row 961
$_m["id"]
 = "";

// include/constants_param_wizard.php3, row 962
$_m["prints unpacked id (use it, if you watn to show 'item id' or 'slice id')"]
 = "mostrar el identificador interno dentro de las ActionApps, sean de items o de canales.";

// include/constants_param_wizard.php3, row 963
$_m["text (blurb) from another slice"]
 = "texto de otro canal";

// include/constants_param_wizard.php3, row 964
$_m["prints 'blurb' (piece of text) from another slice, based on a simple condition.<br>If <i>the field</i> (or the field specifield by <b>stringToMatch</b>) in current slice matches the content of <b>fieldToMatch</b> in <b>blurbSliceId</b>, it returns the content of <b>fieldToReturn</b> in <b>blurbSliceId</b>."]
 = "muestra un pedazo de texto de otro canal, basándose en una codición simple.<br>Si <i>el campo</i> (o el campo especificado en <b>cadenaAComparar</b>) del canal actual coincide con el contenido de <b>campoAComparar</b> en <b>canalPedazo</b>, muestra el contenido de <b>campoContenido</b> del <b>canalContenido</b>";

// include/constants_param_wizard.php3, row 966
$_m["stringToMatch"]
 = "cadenaAComparar";

// include/constants_param_wizard.php3, row 967
$_m["By default it is <i>the field</i>.  It can be formatted either as the id of a field (headline........) OR as static text."]
 = "Por defecto es <i>el campo</i>. Puede ser formateado tanto como id de un campo (headline........) O como texto estático.";

// include/constants_param_wizard.php3, row 970
$_m["blurbSliceId"]
 = "canalPedazo";

// include/constants_param_wizard.php3, row 971
$_m["unpacked slice id of the slice where the blurb text is stored"]
 = "id del canal desempaquetado donde el pedazo de texto es almacenado";

// include/constants_param_wizard.php3, row 973
$_m["41415f436f72655f4669656c64732e2e"]
 = "";

// include/constants_param_wizard.php3, row 974
$_m["fieldToMatch"]
 = "campoAComparar";

// include/constants_param_wizard.php3, row 975
$_m["field id of the field in <b>blurbSliceId</b> where to search for <b>stringToMatch</b>"]
 = "id del campo del campo en <b>IdCanalPedazo</b> donde se busca por <b>cadenaABuscar</b>";

// include/constants_param_wizard.php3, row 977
$_m["headline........"]
 = "";

// include/constants_param_wizard.php3, row 978
$_m["fieldToReturn"]
 = "campoContenido";

// include/constants_param_wizard.php3, row 979
$_m["field id of the field in <b>blurbSliceId</b> where the blurb text is stored (what to print)"]
 = "id de campo del campo en <b>IdCanalPropaganda</b> donde el pedazo de texto es almacenado (que imprimir)";

// include/constants_param_wizard.php3, row 982
$_m["RSS tag"]
 = "tag RSS";

// include/constants_param_wizard.php3, row 983
$_m["serves for internal purposes of the predefined RSS aliases (e.g. _#RSS_TITL). Adds the RSS 0.91 compliant tags."]
 = "función usada internamente para los alias de RSS predefinidos (ej. _#RSS_TITL). Añade los tags RSS 0.91";

// include/constants_param_wizard.php3, row 984, 987, 1093
$_m["default"]
 = "por defecto";

// include/constants_param_wizard.php3, row 985
$_m["prints <i>the field</i> or a default value if <i>the field</i> is empty. The same could be done by the f_c function with parameters :::<b>default</b>."]
 = "muestra <i>el campo</i> o un valor por defecto si <i>el campo</i> está vacío. Se puede hacer exactamente lo mismo con la función f_c con los parámetros :::<b>valor por defecto</b>.";

// include/constants_param_wizard.php3, row 988
$_m["default value"]
 = "valor por defecto";

// include/constants_param_wizard.php3, row 990
$_m["javascript: window.alert('No source url specified')"]
 = "javascript#:window.alert(\"No se especificó URL de la fuente\")";

// include/constants_param_wizard.php3, row 991
$_m["print field"]
 = "imprimir campo";

// include/constants_param_wizard.php3, row 992
$_m["prints <i>the field</i> content (or <i>unalias string</i>) depending on the html flag (if html flag is not set, it converts the content to html. In difference to f_h function, it converts to html line-breaks, too (in its basic variant)"]
 = "imprima el contenido <i>del campo</i> (o <i>cadena alias</i>) dependiendo de la bandera HTML (si la bandera HTML no está asignada, convierte el contenido a HTML. En diferencia a la función f_h, ésta convierte a HTML los saltos de página, también (en su variante básica)";

// include/constants_param_wizard.php3, row 994
$_m["unalias string"]
 = "cadena";

// include/constants_param_wizard.php3, row 995
$_m["if the <i>unalias string</i> is defined, then the function ignores <i>the field</i> and it rather prints the <i>unalias string</i>. You can of course use any aliases (or fields like {headline.........}) in this string"]
 = "si está definida, la función ignora <i>el campo</i> y muestra esta cadena. Tenga en cuenta que puede escribir aquí otros alias.";

// include/constants_param_wizard.php3, row 997
$_m["<img src={img_src.........1} _#IMG_WDTH _#IMG_HGHT>"]
 = "";

// include/constants_param_wizard.php3, row 998
$_m["output modify"]
 = "modificar salida";

// include/constants_param_wizard.php3, row 1007
$_m["You can use some output modifications:<br>\n"
   ."                   &nbsp; - [<i>empty</i>] - no modification<br>\n"
   ."                   &nbsp; - <i>csv</i>  - prints the field for CSV file (Comma Separated Values) export<br>\n"
   ."                   &nbsp; - <i>urlencode</i> - URL-encodes string (see <a href=\"http://php.net/urlencode\">urlencode<a> PHP function)<br>\n"
   ."                   &nbsp; - <i>safe</i> - converts special characters to HTML entities (see <a href=\"http://php.net/htmlspecialchars\">htmlspecialchars<a> PHP function)<br>\n"
   ."                   &nbsp; - <i>javascript</i> - escape ' (replace ' with \\')<br>\n"
   ."                   &nbsp; - <i>striptags</i>  - strip HTML and PHP tags from the string<br>\n"
   ."                   &nbsp; - <i>asis</i>  - prints field content 'as is' - it do not add &lt;br&gt; at line ends even if field is marked as 'Plain text'. 'asis' parameter is good for Item Manager's 'Modify content...' feature, for example<br>\n"
   ."                   "]
 = "Usted puede utilizar algunas modificaciones de salida:<br>\n"
   ."                   &nbsp; - [<i>empty</i>] - sin modificación<br>\n"
   ."                   &nbsp; - <i>csv</i>  - imprime el campo para exportación a un archivo CSV (Comma Separated Values - Valores Separados por Coma)<br>\n"
   ."                   &nbsp; - <i>urlencode</i> - cadenas de URL-encodes (vea función <a href=\"http://php.net/urlencode\">urlencode<a> de PHP)<br>\n"
   ."                   &nbsp; - <i>safe</i> - convierte caracteres especiales a entidades de HTML (vea la función <a href=\"http://php.net/htmlspecialchars\">htmlspecialchars<a> de PHP)<br>\n"
   ."                   &nbsp; - <i>javascript</i> - escape ' (reemplazar ' con \\')<br>\n"
   ."                   &nbsp; - <i>striptags</i>  - quitar etiquetas de HTML y PHP de la cadena<br>\n"
   ."                   &nbsp; - <i>asis</i>  - imprime los contenidos del campo 'como son' - no añade &lt;br&gt; al final de la línea aunque el campo esté marcado como 'Texto plano'. El parámetro 'asis' es bueno para la función de modificar contenido 'Modify content...' del administrador de ítems.<br>\n"
   ."                   ";

// include/constants_param_wizard.php3, row 1010
$_m["transformation"]
 = "transformación";

// include/constants_param_wizard.php3, row 1011
$_m["Allows to transform the field value to another value.<br>Usage: <b>content_1</b>:<b>return_value_1</b>:<b>content_1</b>:<b>return_value_1</b>:<b>default</b><br>If the content <i>the field</i> is equal to <b>content_1</b> the <b>return_value_1</b> is returned. If the content <i>the field</i> is equal to <b>content_2</b> the <b>return_value_2</b> is returned. If <i>the field is not equal to any <b>content_x</b>, <b>default</b> is returned</i>."]
 = "Permite transformar el valor del campo en otro valor.<br>Uso: <b>contenido_1</b>:<b>valor_retorno_1</b>:<b>contenido_1</b>:<b>valor_retorno_1</b>:<b>por_defecto</b><br>Si el contenido <i>del campo</i> es igual a <b>contenido_1</b> el <b>valor_retorno_1</b> es mostrado. Si el contenido <i>del campo</i> es igual a <b>contenido_2</b> el <b>valor_retorno_2</b> es mostrado. Si <i>el campo no es igual a ningún <b>contenido_x</b>, <b>por_defecto</b> es mostrado</i>.";

// include/constants_param_wizard.php3, row 1013, 1021, 1029, 1037, 1045, 1053, 1061, 1069, 1077, 1085
$_m["content"]
 = "contenido";

// include/constants_param_wizard.php3, row 1014, 1022, 1030, 1038, 1046, 1054, 1062, 1070, 1078, 1086
$_m["string for comparison with <i>the field</i> for following return value"]
 = "valor a comparar con <i>el campo</i> para que se muestre el valor siguiente";

// include/constants_param_wizard.php3, row 1017, 1025, 1033, 1041, 1049, 1057, 1065, 1073, 1081, 1089
$_m["return value"]
 = "valor retornado";

// include/constants_param_wizard.php3, row 1018, 1026, 1034, 1042, 1050, 1058, 1066, 1074, 1082, 1090
$_m["string to return if previous content matches - You can use field_id too"]
 = "texto a retornar si el contenido anterior coincide - También puede usar id_campo para mostrar el contenido de otro campo";

// include/constants_param_wizard.php3, row 1020, 1028, 1036, 1044, 1052, 1060, 1068, 1076, 1084, 1092
$_m["Environment"]
 = "Educación";

// include/constants_param_wizard.php3, row 1094
$_m["if no content matches, use this string as return value"]
 = "si ninguno coincide, mostrar este valor";

// include/constants_param_wizard.php3, row 1097
$_m["user function"]
 = "definida por el usuario";

// include/constants_param_wizard.php3, row 1098
$_m["calls a user defined function (see How to create new aliases in <a href='http://apc-aa.sourceforge.net/faq/#aliases'>FAQ</a>)"]
 = "hace un llamado a una función definida por el administrador de este sistema (vea <em>How to create new aliases</em> en el <a href='http://apc-aa.sourceforge.net/faq/#aliases' target=_blank>FAQ</a>)";

// include/constants_param_wizard.php3, row 1100
$_m["function"]
 = "función";

// include/constants_param_wizard.php3, row 1101
$_m["name of the function in the include/usr_aliasfnc.php3 file"]
 = "nombre de la función en el archivo include/usr_aliasfnc.php3";

// include/constants_param_wizard.php3, row 1103
$_m["usr_start_end_date_cz"]
 = "usr_encuentra_fecha";

// include/constants_param_wizard.php3, row 1104
$_m["parameter"]
 = "parámetro";

// include/constants_param_wizard.php3, row 1105
$_m["a parameter passed to the function"]
 = "un parámetro a pasar a la función";

// include/constants_param_wizard.php3, row 1108
$_m["view"]
 = "vista";

// include/constants_param_wizard.php3, row 1109
$_m["allows to manipulate the views. This is a complicated and powerful function, described in <a href=\"../doc/FAQ.html#viewparam\" target=_blank>FAQ</a>, which allows to display any view in place of the alias. It can be used for 'related stories' table or for dislaying content of related slice."]
 = "permite mostrar vistas. Esta función es potente y complicada, y está descrita en el <a href=\"http://apc-aa.sourceforge.net/faq#viewparam\" target=_blank>FAQ</a>. Muestra una vista en el lugar del alias. Es útil, entre otras cosas, para hacer enlaces a items relacionados de otros canales.";

// include/constants_param_wizard.php3, row 1111
$_m["complex parameter"]
 = "parámetro complejo";

// include/constants_param_wizard.php3, row 1112
$_m["this parameter is the same as we use in view.php3 url parameter - see the FAQ"]
 = "este parámetro sigue la sintaxis de los parámetros de view.php3";

// include/constants_param_wizard.php3, row 1114
$_m["vid=4&amp;cmd[23]=v-25"]
 = "";

// include/constants_param_wizard.php3, row 1115
$_m["image width"]
 = "ancho imágen";

// include/constants_param_wizard.php3, row 1116
$_m["An old-style function. Prints <i>the field</i> as image width value (\\<img width=...\\>) or erases the width tag. To be used immediately after \"width=\".The f_c function provides a better way of doing this with parameters \":width=\". "]
 = "Función anterior. Muestra <i>el campo</i> como el valor del ancho de la imagen (\\<img width=...\\>) o borra la etiqueta de ancho de imagen. A ser utilizada inmediatamente después \"width=\". La función f_c provee a una mejor manera de realizar esta función con los parámetros \":width=\".";

// include/constants_param_wizard.php3, row 1121
$_m["Transformation action"]
 = "Acción de transformación";

// include/constants_param_wizard.php3, row 1123
$_m["Store"]
 = "Almacenar";

// include/constants_param_wizard.php3, row 1124
$_m["Simply store a value from the input field"]
 = "Simplemente almacene el valor del campo de entrada";

// include/constants_param_wizard.php3, row 1128
$_m["Remove string"]
 = "Remueva la cadena";

// include/constants_param_wizard.php3, row 1129
$_m["Remove all occurences of a string from the input field."]
 = "Remueva todas las ocurrencias de una cadena desde el campo de entrada";

// include/constants_param_wizard.php3, row 1131, 1149, 1166
$_m["string parameter"]
 = "parámetro de cadena";

// include/constants_param_wizard.php3, row 1132
$_m["Removed string"]
 = "cadena removida";

// include/constants_param_wizard.php3, row 1136
$_m["Format date"]
 = "Formato de fecha";

// include/constants_param_wizard.php3, row 1137
$_m["Parse the date in the input field expected to be in English date format. In case of error, the transformation fails"]
 = "Coloca la fecha en el campo de entrada que se espera sea en formato de fecha Inglés. En caso de error, la transformación falla";

// include/constants_param_wizard.php3, row 1141
$_m["Add http prefix"]
 = "Añade el prefijo http";

// include/constants_param_wizard.php3, row 1142
$_m["Adds 'http://' prefix to the field if not beginning with 'http://' and not empty."]
 = "Añade el prefijo 'http://' al campo que no comienza con 'http://' y no es vacío.";

// include/constants_param_wizard.php3, row 1146
$_m["Store parameter"]
 = "Almacene el parámetro";

// include/constants_param_wizard.php3, row 1147
$_m["Store parameter instead of the input field"]
 = "Almacene el parámetro en lugar del campo de entrada";

// include/constants_param_wizard.php3, row 1153
$_m["Store as long id"]
 = "Almacene tan largo como el id";

// include/constants_param_wizard.php3, row 1154
$_m["Creates long id from the string. The string is combined with the parameter!! or with slice_id (if the parameter is not provided. From the same string (and the same parameter) we create allways the same id."]
 = "Crea un id largo desde la cadena. La cadena es combinada con el parámetro!! o con el identificador de canal slice_id (si el parámatro no se incluye. Desde la misma cadena (y el mismo parámetro) se crea siempre el mismo id.";

// include/constants_param_wizard.php3, row 1156
$_m["string to add"]
 = "cadena a añadir";

// include/constants_param_wizard.php3, row 1157
$_m["this parameter will be added to the string before conversion (the reason is to aviod empty strings and also in order we do not generate allways the same id for common strings (in different imports). If this param is not specified, slice_id is used istead."]
 = "Este parámetro será añadido a la cadena antes de la conversión (la razon es para abolir cadenas vacias y también para no generar siempre el mismo id para cadenas similares (desde diferentes importaciones). Si este parámetro no se especifica, el identificador del canal slice_id será utilizado.";

// include/constants_param_wizard.php3, row 1163
$_m["Split input field by string"]
 = "Dividir el campo de entrada por la cadena";

// include/constants_param_wizard.php3, row 1164
$_m["Split input field by string parameter and store the result as multi-value."]
 = "Dividir el campo de entrada por el parámetro de la cadena y almacena el resultado como un valor múltiple";

// include/constants_param_wizard.php3, row 1167
$_m["string which separates the values of the input field"]
 = "cadena que separa los valores en el campo de entrada";

// include/constants_param_wizard.php3, row 1172
$_m["Store default value"]
 = "Valor almacenado por defecto";

// include/constants_param_wizard.php3, row 1185
$_m["Store these default values for the following output fields. The other output fields will filled form <i>From</i> field (if specified). Else it is filled by <i>Action parameters</i> string.\n"
   ."    <table>\n"
   ."        <tr><td><b>Output field</b></td><td><b>Value</b></td><td><b>Description</b></td></tr></b>\n"
   ."    <tr><td>Status code</td><td>1</td><td>The item will be stored in Active bin (Hint: set it to 2 for Holding bin)</td></tr>\n"
   ."    <tr><td>Display count</td><td>0</td><td></td></tr>\n"
   ."        <tr><td>Publish date</td><td>Current date</td><td></td></tr>\n"
   ."    <tr><td>Post date</td><td>Current date</td><td></td></tr>\n"
   ."    <tr><td>Last edit</td><td>Current date</td><td></td></tr>\n"
   ."    <tr><td>Expiry date</td><td>Current date + 10 years</td><td></td></tr>\n"
   ."    <tr><td>Posted by</td><td>Active user</td><td></td></tr>\n"
   ."    <tr><td>Edited by</td><td>Active user</td><td></td></tr>\n"
   ."      </table>\n"
   ."    "]
 = "Almacena estos valores por defecto para los siguientes campos de salida. Los demás campos de salida serán alimentados desde el campo <i>Desde - From</i> (si se especifica). De lo contrario será alimentado por la cadena de <i>los parámetros de acción</i>.\n"
   ."    <table>\n"
   ."        <tr><td><b>Campo de salida</b></td><td><b>Valor</b></td><td><b>Descripción</b></td></tr></b>\n"
   ."    <tr><td>Código de estado</td><td>1</td><td>El ítem será almacenado en la carpeta Aprobados (Truco: dejelo en 2 para la carpeta Por abrobar)</td></tr>\n"
   ."    <tr><td>Contador de visitas</td><td>0</td><td></td></tr>\n"
   ."        <tr><td>Fecha de documento</td><td>Fecha actual</td><td></td></tr>\n"
   ."    <tr><td>Fecha de publicación</td><td>Fecha actual</td><td></td></tr>\n"
   ."    <tr><td>Ultima edición</td><td>Fecha actual</td><td></td></tr>\n"
   ."    <tr><td>Fecha de expiración</td><td>Fecha actual + 10 años</td><td></td></tr>\n"
   ."    <tr><td>Publicado por</td><td>Usuario actual</td><td></td></tr>\n"
   ."    <tr><td>Editado por</td><td>Usuario actual</td><td></td></tr>\n"
   ."      </table>\n"
   ."    ";

// doc/param_wizard_list.php3, row 36
$_m["Param Wizard Summary"]
 = "Resumen de Asistentes de parámetros";

// doc/param_wizard_list.php3, row 45
$_m["Choose a Parameter Wizard"]
 = "Escoja un Asistente de parámetros";

// doc/param_wizard_list.php3, row 54, 71
$_m["Go"]
 = "Ir";

// doc/param_wizard_list.php3, row 63
$_m["Change to: "]
 = "Cambiar a: ";

// doc/param_wizard_list.php3, row 78
$_m["TOP"]
 = "ARRIBA";

// doc/param_wizard_list.php3, row 92
$_m["Parameters:"]
 = "Parámetros:";

// doc/param_wizard_list.php3, row 95
$_m["name"]
 = "nombre";

// doc/param_wizard_list.php3, row 97
$_m["description"]
 = "descripción";

// doc/param_wizard_list.php3, row 98
$_m["example"]
 = "ejemplo";

// doc/param_wizard_list.php3, row 104
$_m["integer number"]
 = "número entero";

// doc/param_wizard_list.php3, row 105
$_m["any text"]
 = "cualquier texto";

// doc/param_wizard_list.php3, row 106
$_m["field id"]
 = "id campo";

// doc/param_wizard_list.php3, row 107
$_m["boolean: 0=false,1=true"]
 = "booleano: 0=falso,1=verdadero";

?>
