<?php
//$Id$
/* 
Copyright (C) 1999, 2000 Association for Progressive Communications 
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

# archivo para traducción de mensajes (common language file - comm)

// constantes de configuración
define("L_SETUP_PAGE_BEGIN", 
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/SP">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">');
define("L_SETUP_TITLE", "Instalación de las AA");
define("L_SETUP_H1", "Instalación de las AA");
define("L_SETUP_NO_ACTION", "Este programa no puede ser utilizado en sistemas ya configurados.");
define("L_SETUP_INFO1", "Bienvenido! Utilice este programa para crear " .
                        "la cuenta del super-administrador del sistema.<p>" .
      "Si Usted está instalando una nueva copia de las AA, presione <b>Instalar</b>.<br>");
define("L_SETUP_INFO2", "Si Usted borró la cuenta de super-administrador por error, presione <b>Recuperar</b>.<br>");
define("L_SETUP_INIT", "Instalar");  
define("L_SETUP_RECOVER", "Recuperar");
define("L_SETUP_TRY_RECOVER", "No es posible añadir el objeto de permisos primario.<br>" .
       "Por favor verifique los parámetros de acceso a su sistema de permisos.<br>" .
       "Si Usted simplemente borró la cuenta de super-administrador, utilice <b>Recuperar</b>");
define("L_SETUP_USER", "Cuenta Super-administrador");
define("L_SETUP_LOGIN", "Usuario");
define("L_SETUP_PWD1", "Clave de Acceso");
define("L_SETUP_PWD2", "Confirme la Clave");
define("L_SETUP_FNAME", "Nombre");
define("L_SETUP_LNAME", "Apellido");
define("L_SETUP_EMAIL", "Correo Electrónico");
define("L_SETUP_CREATE", "Crear");
define("L_SETUP_DELPERM", "Permisos inválidos borrados (no existe usuario/grupo): ");
define("L_SETUP_ERR_ADDPERM", "No es posible asignar acceso al super-usuario.");
define("L_SETUP_ERR_DELPERM", "No es posible borrar permisos inválidos.");
define("L_SETUP_OK", "Felicitaciones! La cuenta fue creada con éxito.");
define("L_SETUP_NEXT", "Utilice esta cuenta para entrar al sistema y añadir su primer canal:");
define("L_SETUP_SLICE", "Añadir Canal");

// constantes para entrada al sistema 
define("L_LOGIN", "¡Bienvenido!");
define("L_LOGIN_TXT", "¡Bienvenido! Por favor, identifíquese con su nombre de usuario y clave:");
define("L_LOGINNAME_TIP", "Escriba su nombre de usuario o correo electrónico");
define("L_SEARCH_TIP", "La lista está limitada a 5 usuarios.<br>Si alguno no está en la lista, trate de ser más específico en su consulta");
define("L_USERNAME", "Usuario:");
define("L_PASSWORD", "Clave:");
define("L_LOGINNOW", "Entrar");
define("L_BAD_LOGIN", "La combinación de usuario y clave no es válida.");
define("L_TRY_AGAIN", "¡Intente otra vez!");
define("L_BAD_HINT", "Si Usted está seguro que tecleo bien su clave de acceso, por favor contacte a <a href=mailto:". ERROR_REPORTING_EMAIL . ">" . ERROR_REPORTING_EMAIL . "</a>.");


define("LOGIN_PAGE_BEGIN",
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/SP">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">');

// constantes de navegación
define("L_NEXT", "Siguiente");
define("L_PREV", "Anterior");
define("L_BACK", "Atrás");
define("L_HOME", "Inicio");

// constantes de permisos (perm_ldap.php3, perm_all.php3)
define("L_USER", "Usuario");
define("L_GROUP", "Grupo");

// constantes para configuración de permisos um_uedit
define("L_NEW_USER", "Nuevo Usuario");
define("L_NEW_GROUP", "Nuevo Grupo");
define("L_EDIT_GROUP", "Editar Grupo");

// cadenas no específicas
define("NO_PICTURE_URL", AA_INSTAL_URL ."images/pixel_blank.gif");  // imagen utilizada cuando
  // cuando existe img_source en formato html pero no se encuentra 
  // almacenada en la base de datos img_source  
  // (Usted puede utilizar una imagen en Blanco cuando no existe la imagen)

define("L_ALLCTGS", "Todas las Categorias");
define("L_NO_SUCH_FILE", "No existe el archivo");
define("L_BAD_INC", "Parámetro inc errado - el archivo a incluir debe estar en el mismo directorio que este archivo .shtml y debe contener solamente caracteres alfanuméricos ");
define("L_SELECT_CATEGORY", "Seleccione la Categoria ");
define("L_NO_ITEM", "No se encuentra");
define("L_SLICE_INACCESSIBLE", "Número de canal inválido o el canal fue borrado");
define("L_APP_TYPE", "Tipo de Canal");
define("L_SELECT_APP", "Seleccione Tipo de Canal");
define("L_APP_TYPE_HELP", "<br><br><br><br>");

// textos de grabación de eventos (logs)
define( "LOG_EVENTS_UNDEFINED", "No definido" );

// actualización fuera de línea --------------
define( "L_OFFLINE_ERR_BEGIN",
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/SP">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="./'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
  </HEAD>
  <BODY>');
define( "L_OFFLINE_OK_BEGIN",L_OFFLINE_ERR_BEGIN);
define( "L_OFFLINE_ERR_END","</body></html>");
define( "L_OFFLINE_OK_END",L_OFFLINE_ERR_END);
define( "L_NO_SLICE_ID","El canal no está definido");
define( "L_NO_SUCH_SLICE","Identificación del canal errado");
define( "L_OFFLINE_ADMITED","Usted no tiene permisos para actualizar este canal fuera de línea"); 
define( "L_WDDX_DUPLICATED","Item duplicado envío - saltado");
define( "L_WDDX_BAD_PACKET","Datos errados (paquetes WDDX)");
define( "L_WDDX_OK","Item aprobado - almacenado en la base de datos");
define( "L_CAN_DELETE_WDDX_FILE","Ahora puede borrar el archivo local");
define( "L_DELETE_WDDX","Borrar");
define( "L_NO_SUCH_VIEW", "No such view (bad or missing view id or deleted slice)");

// copyright message for all screens
define( "L_COPYRIGHT", 'Copyright (C) 2001 the 
						<a href="http://www.apc.org">Association for Progressive  Communications (APC)</a> 
						under the 
						<a href="http://www.gnu.org/copyleft/gpl.html">GNU General Public License</a>'); 

define("DEFAULT_CODEPAGE","iso-8859-1");


            
// transformación de estilo de fecha (3/16/1999 o 3/16/99) a formato de mySQL 
function userdate2sec ($dttm, $time="") {
  if( !ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{4}) *$", $dttm, $part))
    if( !ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{2}) *$", $dttm, $part))
      return "";
  if( !ereg("^ *([[:digit:]]{1,2}) *: *([[:digit:]]{1,2}) *: *([[:digit:]]{1,2}) *$", $time, $tpart))
    return mktime(0,0,0,$part[1],$part[2],$part[3]);
   else
    return mktime($tpart[1],$tpart[2],$tpart[3],$part[1],$part[2],$part[3]);
}

function dateExample() {
	return "mm/dd/aaaa";
}
                   
?>
