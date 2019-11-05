<?php
// $Id$
// Language: EN-UTF8
// This file was created automatically by the Mini GetText environment
// on 5.9.2008 16:31

// Do not change this file otherwise than by typing translations on the right of =

// Before each message there are links to program code where it was used.

$mgettext_lang = "es-utf8";
define("DB_COLLATION", "utf8mb4_spanish_ci");
setlocale(LC_ALL, 'es_ES.utf8', 'es_ES');  // sort, date, uppercase, ..
setlocale(LC_NUMERIC, 'en_US');            // use numeric with dot - there is problem, when
                                           // used Czech numeric comma for example in AA_Stringexpand_If:
                                           //   $cmp  = create_function('$b', "return ($etalon $operator". ' $b);');
                                           // float!! value $etalon is then with comma which leads to syntax error

// modules/polls/index.php3, row 41
$_m["You do not have permission to manage Polls"]

 = "Usted no tiene permisos para administrar encuestas";

// modules/polls/index.php3, row 75
$_m["ID"]
 = "ID";

// modules/polls/index.php3, row 75
$_m["Poll Question"]
 = "Pregunta para encuesta";

// modules/polls/index.php3, row 75
// modules/polls/polledit.php3, row 188, 195
$_m["Publish Date"]
 = "Fecha de publicación";

// modules/polls/index.php3, row 75
// modules/polls/polledit.php3, row 189, 196
$_m["Expiry Date"]
 = "Fecha de expiración";

// modules/polls/polledit.php3, row 52
$_m["No permission to add/edit poll."]
 = "Sin permisos para añadir o editar encuestas.";

// modules/polls/polledit.php3, row 95
$_m["Can't insert new poll"]
 = "No es posible insertar una nueva encuesta";

// modules/polls/polledit.php3, row 137
$_m["Edit poll"]
 = "Editar encuesta";

// modules/polls/polledit.php3, row 137, 151
$_m["Add poll"]
 = "Añadir encuesta";

// modules/polls/polledit.php3, row 143
$_m["There are too many related items. The number of related items is limited."]
 = "Hay demasiados ítems relacionados. El número de ítems es limitado";

// modules/polls/polledit.php3, row 169
$_m["Insert question and answers"]
 = "Introduzca la pregunta y respuestas";

// modules/polls/polledit.php3, row 186, 193
$_m["Headline"]
 = "Encabezado";

// modules/polls/polledit.php3, row 186, 193
$_m["Question"]
 = "Pregunta";

// modules/polls/polledit.php3, row 187, 194
$_m["Insert new answers and choose their order"]
 = "Inserte nuevas respuestas y seleccione su orden";

// modules/polls/polledit.php3, row 199
$_m["Polls settings"]
 = "Configuración de encuestas";

// modules/polls/polledit.php3, row 201
$_m["Poll is locked"]
 = "Encuesta bloqueada";

// modules/polls/polledit.php3, row 202
// modules/polls/modedit.php3, row 213
$_m["Use logging"]
 = "Utilice log de acceso";

// modules/polls/polledit.php3, row 203
// modules/polls/modedit.php3, row 214
$_m["Use IP locking"]
 = "Utilice bloqueo de IP";

// modules/polls/polledit.php3, row 204
// modules/polls/modedit.php3, row 74, 215
$_m["IP Locking timeout"]
 = "Utilice bloqueo de IP por tiempo";

// modules/polls/polledit.php3, row 204
$_m["time in seconds"]
 = "tiempo en segundos";

// modules/polls/polledit.php3, row 205
// modules/polls/modedit.php3, row 216
$_m["Use cookies"]
 = "Utilice cookies";

// modules/polls/polledit.php3, row 207
// modules/polls/modedit.php3, row 76
$_m["Parameters"]
 = "Parámetros";

// modules/polls/polledit.php3, row 209
$_m["Polls design templates"]
 = "Plantillas de Diseño de encuestas";

// modules/polls/polledit.php3, row 214
$_m["Select design type - before vote"]
 = "Seleccione tipo de diseño - antes de votar";

// modules/polls/polledit.php3, row 215
$_m["Select design type - after vote"]
 = "Seleccione tipo de diseño - después de votar";

// modules/polls/polledit.php3, row 215
$_m["If the design after vote should look differently, then specify it here."]
 = "Si el diseño después de votar debe verse diferente, especifíquelo aquí";

// modules/polls/modedit.php3, row 51
$_m["You have not permissions to add polls"]
 = "Usted no tiene permisos para añadir encuestas";

// modules/polls/modedit.php3, row 56
$_m["You have not permissions to edit this polls"]
 = "Usted no tiene permisos de editar esta encuesta";

// modules/polls/modedit.php3, row 75, 217
$_m["Cookies prefix"]
 = "Prefijo de cookies";

// modules/polls/modedit.php3, row 182
$_m["Polls Admin"]
 = "Administración de encuestas";

// modules/polls/modedit.php3, row 188
$_m["Add Polls"]
 = "Añadir encuestas";

// modules/polls/modedit.php3, row 188
$_m["Edit Polls"]
 = "Editar encuestas";

// modules/polls/modedit.php3, row 195
$_m["Polls Module general data"]
 = "Módulo de datos generales de encuestas";

// modules/polls/modedit.php3, row 196
$_m["Id"]
 = "Id";

// modules/polls/modedit.php3, row 197
$_m["Name"]
 = "Nombre";

// modules/polls/modedit.php3, row 199
$_m["URL"]
 = "URL";

// modules/polls/modedit.php3, row 200
$_m["Owner"] =  "Dueño";

// modules/polls/modedit.php3, row 202
$_m["New Owner"]
 = "Nuevo dueño";

// modules/polls/modedit.php3, row 203
$_m["New Owner's E-mail"]
 = "Correo de nuevo dueño";

// modules/polls/modedit.php3, row 206
$_m["Deleted"]
 = "Borrado";

// modules/polls/modedit.php3, row 208
$_m["Used Language File"]
 = "Archivo de idioma utilizado";

// modules/polls/modedit.php3, row 212
$_m["Defaults for polls in this module"]
 = "Predeterminados para módulo de encuestas";


