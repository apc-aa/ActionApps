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


# identificador del arhivo de configuración
# debe corresponder con el nombre de este archivo.
define("LANG_FILE", "es_news_lang.php3");

define("EDIT_ITEM_COUNT", 20);                  // número de ítems en la ventana del editor.

define("DEFAULT_FULLTEXT_HTML", '<BR><FONT SIZE=+2 COLOR=blue>_#HEADLINE</FONT> <BR><B>_#PUB_DATE</B> <BR>_#FULLTEXT');
define("DEFAULT_ODD_HTML", '<font face=Arial color=#808080 size=-2>_#PUB_DATE - </font><font color=#FF0000><strong><a href=_#HDLN_URL>_#HEADLINE</a></strong></font><font color=#808080 size=-1><br>_#PLACE###(<a href="_#SRC_URL#">_#SOURCE##</a>) - </font><font color=black size=-1>_#ABSTRACT<br></font><br>');
define("DEFAULT_EVEN_HTML", "");
define("DEFAULT_TOP_HTML", "<br>");
define("DEFAULT_BOTTOM_HTML", "<br>");
define("DEFAULT_CATEGORY_HTML", "<p>_#CATEGORY</p>");
define("DEFAULT_EVEN_ODD_DIFFER", false);
define("DEFAULT_CATEGORY_SORT", true);
define("DEFAULT_COMPACT_REMOVE", "()");
define("DEFAULT_FULLTEXT_REMOVE", "()");


# HTML al comienzo de la págzo dde aML aistració 
# Usted debe definir el lenguaje de las páginas de administración y posiblemente algunos meta-tags
define("HTML_PAGE_BEGIN",
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/SP">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="'.AA_INSTAL_URL.ADMIN_CSS.'" type="text/css"  title="CPAdminCSS">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">');
    
# etiquetas específicas de las aa 
define("L_VIEW_SLICE", "Ver Canal");
define( "L_SLICE_HINT", '<br>Para incluir el contenido publicado aquí 
                             escriba en su página shtml: ');
define("L_ITEM_ID_ALIAS",'alias para el Id del ítem');
define("L_EDITITEM_ALIAS",'alias usado en la página de administración index.php3 para el url de edición del ítem'); 
define("L_LANG_FILE","Archivo de idioma utilizado");
define("L_PARAMETERS","Parámetros");
define("L_SELECT_APP","Seleccione aplicación");
define("L_SELECT_OWNER","Seleccione propietario");

define("L_CANT_UPLOAD","No se pudó copiar la imagen"); 
define("L_MSG_PAGE", "Mensaje de las aa");   // título de la página de mensajes
define("L_EDITOR_TITLE", "Ventana de edición - administración de ítems");
define("L_FULLTEXT_FORMAT_TOP", "Código HTML para encabezado");
define("L_FULLTEXT_FORMAT", "Código HTML para texto");
define("L_FULLTEXT_FORMAT_BOTTOM", "Código HTML para pie");
define("L_A_FULLTEXT_TIT", "Administración - diseño para texto completo");
define("L_FULLTEXT_HDR", "Código HTML para vista de texto completo del ítem");
define("L_COMPACT_HDR", "Código HTML para el índice de los ítems");
define("L_ITEM_HDR", "Item");
define("L_A_ITEM_ADD", "Añadir un ítem");
define("L_A_ITEM_EDT", "Editar un ítem");
define("L_IMP_EXPORT", "Permitir exportar al canal:");
define("L_ADD_NEW_ITEM", "Añadir un ítem");
define("L_DELETE_TRASH", "Vaciar papelera");
define("L_VIEW_FULLTEXT", "Vista preliminar");
define("L_FULLTEXT", "Texto completo");
define("L_HIGHLIGHTED", "Resaltado");
define("L_A_FIELDS_EDT", "Administración - campos");
define("L_FIELDS_HDR", "Campos");
define("L_NO_PS_EDIT_ITEMS", "Usted no tiene permisos para editar ítems en este canal");
define("L_NO_DELETE_ITEMS", "Usted no tiene permisos para remover ítems");
define("L_NO_PS_MOVE_ITEMS", "Usted no tiene permisos para mover ítems");
define("L_FULLTEXT_OK", "Formato de texto completo actualizado con éxito");

# etiquetas comunes de las aa 
# pueden ser las mismas para todas las aplicaciones 
define("L_ACTIVE_BIN", "Aprobados");
define("L_HOLDING_BIN", "Por Aprobar");
define("L_TRASH_BIN", "Papelera");

define("L_CATEGORY","Categoría");
define("L_SLICE_NAME", "Título");          // canal
define("L_DELETED", "Borrar");           // canal
define("L_D_LISTLEN", "Longitud listado");  // canal
define("L_ERR_CANT_CHANGE", "No se puede cambiar configuración del canal");
define("L_ODD_ROW_FORMAT", "Filas impares");
define("L_EVEN_ROW_FORMAT", "Filas pares");
define("L_EVEN_ODD_DIFFER", "Utilice otro código HTML para filas pares");
define("L_CATEGORY_TOP", "Encabezado categoría HTML");
define("L_CATEGORY_FORMAT", "Título categoría");
define("L_CATEGORY_BOTTOM", "Pie categoría HTML");
define("L_CATEGORY_SORT", "Ordene ítems por categoría");
define("L_COMPACT_TOP", "Encabezado HTML");
define("L_COMPACT_BOTTOM", "Pie HTML");
define("L_A_COMPACT_TIT", "Administración - formato del listado");
define("L_A_FILTERS_TIT", "Administración - contenido compartido - filtros");
define("L_FLT_SETTING", "Contenido compartido - configuración filtros");
define("L_FLT_FROM_SL", "Filtro para el canal importado");
define("L_FLT_FROM", "De");
define("L_FLT_TO", "A");
define("L_FLT_APPROVED", "Aprobado");
define("L_FLT_CATEGORIES", "Categorías");
define("L_ALL_CATEGORIES", "Todas categorías");
define("L_FLT_NONE", "No se ha seleccionado categoría!");
define("L_THE_SAME", "-- Igual --");
define("L_EXPORT_TO_ALL", "Habilitar exportación a cualquier canal");

define("L_IMP_EXPORT_Y", "Habilitar exportación");
define("L_IMP_EXPORT_N", "Deshabilitar exportación");
define("L_IMP_IMPORT", "Importar desde canal:");
define("L_IMP_IMPORT_Y", "Importar");
define("L_IMP_IMPORT_N", "No importe");
define("L_CONSTANTS_HLP", "Utilice estos alias para los campos de la base de datos");

define("L_ERR_IN", "Error en");
define("L_ERR_NEED", "debe ser llenado");
define("L_ERR_LOG", "Utilice los caracteres a-z, A-Z o 0-9");
define("L_ERR_LOGLEN", "debe ser de 5 a 32 caracteres de longitud");
define("L_ERR_NO_SRCHFLDS", "No se especificó campo de búsqueda!");

define("L_FIELDS", "Campos");
define("L_EDIT", "Editar");
define("L_DELETE", "Borrar");
define("L_REVOKE", "Revocar");
define("L_UPDATE", "Actualizar");
define("L_RESET", "Deshacer cambios");
define("L_CANCEL", "Cancelar");
define("L_ACTION", "Acción");
define("L_INSERT", "Insertar");
define("L_NEW", "Nuevo");
define("L_GO", "Ir");
define("L_ADD", "Añadir");
define("L_USERS", "Usuarios");
define("L_GROUPS", "Grupos");
define("L_SEARCH", "Buscar");
define("L_DEFAULTS", "Predeterminado");
define("L_SLICE", "Canal");
define("L_DELETED_SLICE", "No se encontro el canal");
define("L_A_NEWUSER", "Nuevo usuario en el sistema de permisos");
define("L_NEWUSER_HDR", "Nuevo usuario");
define("L_USER_LOGIN", "Nombre");
define("L_USER_PASSWORD1", "Clave");
define("L_USER_PASSWORD2", "Teclee la clave nuevamente");
define("L_USER_FIRSTNAME", "Nombre");
define("L_USER_SURNAME", "Apellido");
define("L_USER_MAIL", "Correo electrónico");
define("L_USER_SUPER", "Cuenta de superadministrador");
define("L_A_USERS_TIT", "Administración - gestión de usuarios");
define("L_A_PERMISSIONS", "Administración - permisos");
define("L_A_ADMIN", "Admininistración - vista de gestión de diseño");
define("L_A_ADMIN_TIT", "Administración - vista de gestión de diseño");
define("L_ADMIN_FORMAT", "Formato ítem");
define("L_ADMIN_FORMAT_BOTTOM", "Pie HTML");
define("L_ADMIN_FORMAT_TOP", "Encabezado HTML");
define("L_ADMIN_HDR", "Listado de ítems en la interface de administración");
define("L_ADMIN_OK", "Campos de administración actualizados con éxito");
define("L_ADMIN_REMOVE", "Borrar cadena");

define("L_ROLE_AUTHOR", "Autor");
define("L_ROLE_EDITOR", "Editor");
define("L_ROLE_ADMINISTRATOR", "Administrador");
define("L_ROLE_SUPER", "Super");

define("L_MAIN_SET", "Propiedades generales");
define("L_PERMISSIONS", "Permisos");
define("L_PERM_CHANGE", "Cambiar");
define("L_PERM_ASSIGN", "Asignar");
define("L_PERM_NEW", "Buscar usuario o grupo");
define("L_PERM_SEARCH", "Asignar nuevos permisos");
define("L_PERM_CURRENT", "Cambiar permisos actuales");
define("L_USER_NEW", "Nuevo usuario");
define("L_DESIGN", "Diseño");
define("L_COMPACT", "Indice");
define("L_COMPACT_REMOVE", "Remover cadena");
define("L_FEEDING", "Compartir contenido");
define("L_IMPORT", "Importar & Exportar");
define("L_FILTERS", "Filtros");

define("L_A_SLICE_ADD", "Añadir canal");
define("L_A_SLICE_EDT", "Administración - Propiedades canal");
define("L_A_SLICE_CAT", "Administración - configurar categorías");
define("L_A_SLICE_IMP", "Administración - configurar compartir contenido");
define("L_FIELD", "Campo");
define("L_FIELD_IN_EDIT", "Mostrar");
define("L_NEEDED_FIELD", "Requerido");
define("L_A_SEARCH_TIT", "Administración - diseño página búsqueda");
define("L_SEARCH_HDR", "Criterio de búsqueda");
define("L_SEARCH_HDR2", "Buscar en campos");
define("L_SEARCH_SHOW", "Mostrar");
define("L_SEARCH_DEFAULT", "Configuración predeterminada");
define("L_SEARCH_SET", "Buscar");
define("L_AND", " Y ");
define("L_OR", " O ");
define("L_SRCH_KW", "Buscar");
define("L_SRCH_FROM", "Desde");
define("L_SRCH_TO", "Hasta");
define("L_SRCH_SUBMIT", "Buscar");
define("L_NO_PS_EDIT", "Usted no tiene permisos para editar este canal");
define("L_NO_PS_ADD", "Usted no tiene permisos para añadir un canal");
define("L_NO_PS_COPMPACT", "Usted no tiene permisos para para cambiar el formato de presentación compacto");
define("L_NO_PS_FULLTEXT", "Usted no tiene permisos para para cambiar el formato de texto completo");
define("L_NO_PS_CATEGORY", "Usted no tiene permisos para cambiar la configuración de categoría");
define("L_NO_PS_FEEDING", "Usted no tiene permisos para cambiar la configuración de alimentación");
define("L_NO_PS_USERS", "Usted no tiene permisos para administrar usuarios");
define("L_NO_PS_FIELDS", "Usted no tiene permisos para cambiar la configuración de campos");
define("L_NO_PS_SEARCH", "Usted no tiene permisos para cambiar la configuración de búsqueda");

define("L_BAD_RETYPED_PWD", "Las claves no coinciden");
define("L_ERR_USER_ADD", "Imposible añadir el usuario al sistema de permisos");
define("L_NEWUSER_OK", "Usuario añadido al sistema de permisos con éxito");
define("L_COMPACT_OK", "Diseño compacto actualizado con éxito");
define("L_BAD_ITEM_ID", "Identificación de ítem inválido");
define("L_ALL", " - todos - ");
define("L_CAT_LIST", "Categorías de canales");
define("L_CAT_SELECT", "Categorías para este canal");
define("L_NEW_SLICE", "Añadir canal");
define("L_ASSIGN", "Asignar");
define("L_CATBINDS_OK", "Categoría actualizada con éxito");
define("L_IMPORT_OK", "Contenido compartido actualizado con éxito");
define("L_FIELDS_OK", "Campos actualizados con éxito");
define("L_SEARCH_OK", "Campos de búsqueda actualizados con éxito");
define("L_NO_CATEGORY", "Categoría no definida");
define("L_NO_IMPORTED_SLICE", "No hay canales importados");
define("L_NO_USERS", "No se encuentra grupo de usuarios");

define("L_TOO_MUCH_USERS", "Demasiados grupos de usuarios encontrados");
define("L_MORE_SPECIFIC", "Trate de ser más específico");
define("L_REMOVE", "Remover");
define("L_ID", "Id");
define("L_SETTINGS", "Administración");
define("L_LOGO", "APC Aplicaciones para la Acción");
define("L_USER_MANAGEMENT", "Usuarios");
define("L_ITEMS", "Mantenimiento de ítems");
define("L_NEW_SLICE_HEAD", "Nuevo canal");
define("L_ERR_USER_CHANGE", "No se puede cambiar de usuario");
define("L_PUBLISHED", "Publicado");
define("L_EXPIRED", "Expirado");
define("L_NOT_PUBLISHED", "No publicne(" aun");
define("L_EDIT_USER", "Editar Usuario");
define("L_EDITUSER_HDR", L_EDIT_USER);

define("NO_SOURCE_URL", "javascript: window.alert('URL fuente no especificada')"); 
define("NO_OUTER_LINK_URL", "javascript: window.alert('URL externa no especificada')");

# contantes de edición de la interface
define("L_PUBLISHED_HEAD", "Pub");
define("L_HIGHLIGHTED_HEAD", "&nbsp;!&nbsp;");
define("L_FEEDED_HEAD", "Importado");
define("L_MORE_DETAILS", "Más detalles");
define("L_LESS_DETAILS", "Menos detalles");
define("L_UNSELECT_ALL", "Desmarcar todos");
define("L_SELECT_VISIBLE", "Marcar visibles");
define("L_UNSELECT_VISIBLE", "Desmarcar visibles");

define("L_SLICE_ADM", "Canal administración ");
define("L_A_FILTERS_FLT", L_A_FILTERS_TIT);
define("L_A_COMPACT", L_A_COMPACT_TIT);
define("L_A_FULLTEXT", L_A_FULLTEXT_TIT);
define("L_SRCH_ALL", L_ALL);
define("L_SRCH_SLICE", L_SLICE);
define("L_SRCH_CATEGORY", L_CATEGORY);
define("L_SRCH_AUTHOR", L_CREATED_BY);
define("L_SRCH_LANGUAGE", L_LANGUAGE_CODE);
define("L_SRCH_HEADLINE", L_HEADLINE);
define("L_SRCH_ABSTRACT", L_ABSTRACT);
define("L_SRCH_FULL_TEXT", L_FULL_TEXT);
define("L_SRCH_EDIT_NOTE", L_EDIT_NOTE);
define("L_SLICES_HDR", L_SLICE);
define("L_A_SEARCH_EDT", L_A_SEARCH_TIT);
define("L_A_SLICE_TIT", L_SLICE_ADM);
define("L_A_FIELDS_TIT", L_A_FIELDS_EDT);
define("L_SLICE_SET", L_SLICE);
define("L_FULLTEXT_REMOVE", L_COMPACT_REMOVE);

define("L_FEED", "Exportar");
define("L_FEEDTO_TITLE", "Exportar ítem al canal seleccionado");
define("L_FEED_TO", "Exportar ítems seleccionados al canal seleccionado");
define("L_NO_PERMISSION_TO_FEED", "Sin permisos");
define("L_NO_PS_CONFIG", "Usted no tiene permisos para configurar los parámetros de este canal");
define("L_SLICE_CONFIG", "Administrador de ítems");
define("L_CHBOX_HEAD", "&nbsp;");   // título de caja de selección (checkbox) en interface de administración
define("L_CHBOX", "Checkbox"); 
define("L_CATNAME", "Nombre categoría");
define("L_CATEGORY_ID", "Id categoría");
define("L_EDITED_BY","Editado por");
define("L_MASTER_ID", "Id maestro");
define("L_CHANGE_MARKED", "Items seleccionados");
define("L_MOVE_TO_ACTIVE_BIN", "Mover a activos");
define("L_MOVE_TO_HOLDING_BIN", "Mover a por aprobar");
define("L_MOVE_TO_TRASH_BIN", "Mover a papelera");
define("L_OTHER_ARTICLES", "Carpetas");
define("L_MISC", "Otras opciones");
define("L_HEADLINE_EDIT", "Encabezado (editar)");
define("L_HEADLINE_PREVIEW", "Encabezado (vista preliminar)");
define("L_EDIT_LINK", "&nbsp;");
define("L_PREVIEW_LINK", "&nbsp;");
define("L_ARTICLE_MANAGER", "Administrador de ítems");
define("L_SWITCH_TO", "Cambiar a canal");
define("L_ADMIN", "Administración");

define("L_NO_PS_NEW_USER", "No tiene permisos para crear un nuevo usuario");
define("L_ALL_GROUPS", "Todos los grupos");
define("L_USERS_GROUPS", "Grupos de usuarios");
define("L_REALY_DELETE_USER", "Está seguro de borrar el usuario seleccionado del sistema de permisos?");
define("L_REALY_DELETE_GROUP", "Está seguro de borrar el grupo seleccionado del sistema de permisos?");
define("L_TOO_MUCH_GROUPS", "Muchos grupos encontrados");
define("L_NO_GROUPS", "No se encontraron grupos");
define("L_GROUP_NAME", "Nombre");
define("L_GROUP_DESCRIPTION", "Descripción");
define("L_GROUP_SUPER", "Grupo super-administración");
define("L_ERR_GROUP_ADD", "Imposible añadir el grupo al sistema de permisos");
define("L_NEWGROUP_OK", "Grupo añadido al sistema de permisos con éxito");
define("L_ERR_GROUP_CHANGE", "No se puede cambiar el grupo");
define("L_A_UM_USERS_TIT", "Gestión usuarios - usuarios");
define("L_A_UM_GROUPS_TIT", "Gestión usuarios - grupos");
define("L_EDITGROUP_HDR", "Editar grupo");
define("L_NEWGROUP_HDR", "Nuevo grupo");
define("L_GROUP_ID", "Id grupo");
define("L_ALL_USERS", "Todos los usuarios");
define("L_GROUPS_USERS", "Usuarios del grupo");
define("L_POST", "Publicar");
define("L_POST_PREV", "Publicar y vista preliminar");
define("L_INSERT_PREV", "Publicar y vista preliminar");
define("L_OK", "Aceptar");
define("L_ACTIVE_BIN_EXPIRED", "Caducados");
define("L_ACTIVE_BIN_PENDING", "Pendientes");
define("L_ACTIVE_BIN_EXPIRED_MENU", "Caducados");
define("L_ACTIVE_BIN_PENDING_MENU", "Pendientes");


define("L_FIELD_PRIORITY", "Prioridad");
define("L_FIELD_TYPE", "Tipo");
define("L_CONSTANTS", "Constantes");
define("L_DEFAULT", "Predeterminadas");
define("L_DELETE_FIELD", "Está seguro de borrar este campo del canal?");
define("L_FEEDED", "Importado");
define("L_HTML_DEFAULT", "HTML codificado como predeterminado");
define("L_HTML_SHOW", "Mostrar 'HTML' / 'texto plano'");
define("L_NEW_OWNER", "Nuevo dueño");
define("L_NEW_OWNER_EMAIL", "Correo nuevo dueño");
define("L_NO_FIELDS", "No hay campos definidos para este canal");
define("L_NO_FIELD", "");
define("L_NO_PERM_TO_FEED", "Sin permisos para configurar opciones de alimentación para ningún canal");
define("L_NO_SLICES", "Sin canales");
define("L_NO_TEMPLATES", "Sin plantillas");
define("L_OWNER", "Dueño");
define("L_SLICES", "Canaless");
define("L_TEMPLATE", "Plantilla");
define("L_VALIDATE", "Validar");

define("L_FIELD_DELETE_OK", "Campo borrado con éxito");

define("L_WARNING_NOT_CHANGE","<p>ATENCION: No cambie este parámetro a menos que esté seguro!</p>");
define("L_INPUT_SHOW_FUNC_F_HLP","Function used for displaying in inputform. Some of them use the Constants,some of them use the Parameters. To get some more info, use the Wizard with Help.");
define("L_INPUT_SHOW_FUNC_C_HLP","Choose a Constant Group or a Slice.");
define("L_INPUT_SHOW_FUNC_HLP","Parameters are divided by double dot (:) or (in some special cases) by apostrophy (').");
define("L_INPUT_DEFAULT_F_HLP","Qué función debe utilizarse por defecto:<BR>Ahora - la fecha es la predeterminada<BR>Id del usuario - Id usuario actual<BR>Texto - en parámetros del campo texto es el predeterminado<br>Fecha - por defecto se utiliza la fecha actual más <parametro> número de días");
define("L_INPUT_DEFAULT_HLP","Si el tipo por defecto es texto, este especifica el texto por defecto<BR>Si el tipo por defecto es fecha, se establece la fecha por defecto como la fecha actual más el número de días que se especifique aquí.");

define("L_INPUT_DEFAULT_TXT", "Texto");
define("L_INPUT_DEFAULT_DTE", "Fecha");
define("L_INPUT_DEFAULT_UID", "Id Usuario");
define("L_INPUT_DEFAULT_NOW", "Ahora");
define("L_INPUT_DEFAULT_VAR", "Variable"); # Added by Ram on 5th March 2002

define("L_INPUT_SHOW_TXT","Area texto");
define("L_INPUT_SHOW_EDT","Rich Edit Text Area");
define("L_INPUT_SHOW_FLD","Campo texto");
define("L_INPUT_SHOW_SEL","Caja Selección");
define("L_INPUT_SHOW_RIO","Botón selección");
define("L_INPUT_SHOW_DTE","Fecha");
define("L_INPUT_SHOW_CHB","Selección");
define("L_INPUT_SHOW_MCH", "Selección múltiple");
define("L_INPUT_SHOW_MSE", "Caja selección múltiple");
define("L_INPUT_SHOW_FIL","Archivo");
define("L_INPUT_SHOW_ISI","Related Item Select Box");   # added 08/22/01
define("L_INPUT_SHOW_ISO","Related Item Window");       # added 08/22/01
define("L_INPUT_SHOW_WI2","Two Boxes");                 # added 08/22/01
define("L_INPUT_SHOW_PRE","Select Box with Presets");   # added 08/22/01
define("L_INPUT_SHOW_NUL","No muestre");
                              
define("L_INPUT_VALIDATE_TEXT","Texto");
define("L_INPUT_VALIDATE_URL","URL");
define("L_INPUT_VALIDATE_EMAIL","Correo");
define("L_INPUT_VALIDATE_NUMBER","Número");
define("L_INPUT_VALIDATE_ID","Id");
define("L_INPUT_VALIDATE_DATE","Fecha");
define("L_INPUT_VALIDATE_BOOL","Boleano");

define("L_INPUT_INSERT_QTE","Texto");
define("L_INPUT_INSERT_DTE","Fecha");
define("L_INPUT_INSERT_CNS","Constante");
define("L_INPUT_INSERT_NUM","Número");
define("L_INPUT_INSERT_IDS","ID");
define("L_INPUT_INSERT_BOO","Boleano");
define("L_INPUT_INSERT_UID","Id usuario");
define("L_INPUT_INSERT_NOW","Ahora");
define("L_INPUT_INSERT_FIL","Archivo");
define("L_INPUT_INSERT_NUL","Ninguno");

define("L_INPUT_DEFAULT","Predeterminado");
define("L_INPUT_BEFORE","Antes de código HTML");
define("L_INPUT_BEFORE_HLP","Código mostrado en formato de entrada antes de este campo");
define("L_INPUT_FUNC","Tipo de entrada");
define("L_INPUT_HELP","Ayuda para este campo");
define("L_INPUT_HELP_HLP","Ayuda mostrada para este campo");
define("L_INPUT_MOREHLP","Más ayuda");
define("L_INPUT_MOREHLP_HLP","Texto mostrado después que el usuario selecciona '?' en el formato de entrada");
define("L_INPUT_INSERT_HLP","Esto define como el valor es almacenado en la base de datos. Generalmente utilice 'Texto'.<BR>El campo almacenará un archivo importado.<BR>Ahora se insertará la hora actual, no importa la configuración del usuario. Se insertará la identificación del usuario actual, no importa la confiuración del usuario. Boleanos almacenaran 1 o 0. ");
define("L_INPUT_VALIDATE_HLP","Validar función");

define("L_CONSTANT_NAME", "Nombre");
define("L_CONSTANT_VALUE", "Valor");
define("L_CONSTANT_PRIORITY", "Prioridad");
define("L_CONSTANT_PRI", "Prioridad");
define("L_CONSTANT_GROUP", "Grupo constantes");
define("L_CONSTANT_GROUP_EXIST", "Este grupo de constantes ya existe");
define("L_CONSTANTS_OK", "Constantes actualizadas con éxito");
define("L_A_CONSTANTS_TIT", "Administración - Configuración de constantes");
define("L_A_CONSTANTS_EDT", "Administración - Configuración de constantes");
define("L_CONSTANTS_HDR", "Constantes");
define("L_CONSTANT_NAME_HLP", "mostrado&nbsp;en&nbsp;formato de entrada");
define("L_CONSTANT_VALUE_HLP", "almacenado&nbsp;en&nbsp;la base de datos");
define("L_CONSTANT_PRI_HLP", "orden&nbsp;constante");
define("L_CONSTANT_CLASS", "Padre");
define("L_CONSTANT_CLASS_HLP", "solamente&nbsp;categorias");
define("L_CONSTANT_DEL_HLP", "Remover el nombre de la constante para su borrado");

$L_MONTH = array( 1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 
		'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre');

define("L_NO_CATEGORY_FIELD","No se ha definido campo de categoría para este canal.<br>Añada un campo de categoria para el canal primero (vea página de campos)..");
define("L_PERMIT_ANONYMOUS_POST","Permitir publicación anónima de ítems");
define("L_PERMIT_OFFLINE_FILL","Permitir alimentación de ítems fuera de línea");
define("L_SOME_CATEGORY", "<misma categoría>");

define("L_ALIASES", "Cuando Usted accede a la administración de diseño, Usted utiliza el alias mostrado en este campo");
define("L_ALIAS1", "Alias 1"); 
define("L_ALIAS_HLP", "Debe comenzar con _#.<br>El alias debe ser de exactamente diez caracteres de longitud incluyendo  \"_#\" y en mayúsculas.<br>"); 
define("L_ALIAS_FUNC", "Función"); 
define("L_ALIAS_FUNC_F_HLP", "Esta función maneja los campos de la base de datos y los muestra en la página<BR>usualmente se utiliza 'imprimir'.<BR>"); 
define("L_ALIAS_FUNC_HLP", "Parametro enviando a la función de manejo del alias. Para detalles vea el archivo include/item.php3"); 
define("L_ALIAS_HELP", "Texto de ayuda"); 
define("L_ALIAS_HELP_HLP", "Texto de ayuda para el alias"); 
define("L_ALIAS2", "Alias 2"); 
define("L_ALIAS3", "Alias 3"); 

define("L_TOP_HLP", "Código HTML que aparece en el área de encabezado del canal");
define("L_FORMAT_HLP", "Coloque aquí el código HTML combinado con el formato de aliases al final de esta página. 
                        El alias será sustituido por valores reales provenientes de la base de datos cuando se publiquen en la página");
define("L_BOTTOM_HLP", "Código HTML que aparece en el área de pie de página del canal");
define("L_EVEN_ROW_HLP", "Usted puede definir diferentes códigos para filas pares e impares
                          Por ejemplo, primero rojo, segundo negro");

define("L_SLICE_URL", "URL para la página .shtml (normalmente deje en blanco)");
define("L_A_SLICE_ADD_HELP", "Para crear una nuevo canal, seleccione la plantilla.
        El nuevo canal contendrá los campos de la plantilla seleccionada.
        Usted puede también seleccionar un canal existente como plantilla para su nuevo canal."); 
define("L_REMOVE_HLP", "Remueve brackets vacios etc. Utilice ## como delimitador.");

define("L_COMPACT_HELP", "Utilice estas casillas ( y los tags listados abajo ) para controlar lo que aparece en la página de sumario");
define("L_A_FULLTEXT_HELP", "Utilice estas casillas ( con los tags listados abajo ) para controlar lo que aparece en la vista de texto completo para cada ítem");
define("L_PROHIBITED", "No permitido");
define("L_HTML", "HTML");
define("L_PLAIN_TEXT", "Texto plano");
define("L_A_DELSLICE", "Administración - Borrar Canal");
define("L_DELSLICE_TIT", L_A_DELSLICE);
define("L_DELSLICE_HDR", "Seleccione el canal a borrar");
define("L_DEL_SLICE_HLP","<p>Usted solamente puede borrar canales que estén marcados como &quot;<b>borrados</b>&quot; en la página &quot;<b>". L_SLICE_SET ."</b>&quot;.</p>");
define("L_A_DELSLICE", L_DELSLICE_TIT);
define("L_DELETE_SLICE", "Está seguro que desea borrar este canal con todos sus campos y todos sus ítems?");
define("L_NO_SLICE_TO_DELETE", "No hay canales marcados para borrado");
define("L_NO_SUCH_SLICE", "Identificación del canal errada");
define("L_NO_DELETED_SLICE", "El canal no está marcado para borrado");
define("L_DELSLICE_OK", "Canal borrado con éxito y las tablas han sido optimizadas");
define("L_DEL_SLICE", "Borrar canal");
define("L_FEED_STATE", "Modo de alimentación");
define("L_STATE_FEEDABLE", "Alimentar" );
define("L_STATE_UNFEEDABLE", "No alimentar" );
define("L_STATE_FEEDNOCHANGE", "Alimentación bloqueada" );
define("L_INPUT_FEED_MODES_HLP", "Debe el contenido de este campo ser copiado a otro canal si es alimentado?");
define("L_CANT_CREATE_IMG_DIR","No se puede crear el directorio para copiado de imágenes");

// ------------------------- New - not translated ----------------------------

  # constants for View setting 
define('L_VIEWS','Views');
define('L_ASCENDING','Ascending');
define('L_DESCENDING','Descending');
define('L_NO_PS_VIEWS','You do not have permission to change views');
define('L_VIEW_OK','View successfully changed');
define('L_A_VIEW_TIT','Admin - design View');
define('L_A_VIEWS','Admin - design View');
define('L_VIEWS_HDR','Defined Views');
define('L_VIEW_DELETE_OK','View successfully deleted');
define('L_DELETE_VIEW','Are you sure you want to delete selected view?');
define('L_V_BEFORE',L_COMPACT_TOP);
define('L_V_ODD',L_ODD_ROW_FORMAT);
define('L_V_EVENODDDIF',L_EVEN_ODD_DIFFER);
define('L_V_EVEN',L_EVEN_ROW_FORMAT);
define('L_V_AFTER',L_COMPACT_BOTTOM);
define('L_V_GROUP_BY1','Group by');
define('L_V_GROUP1DIR',' ');
define('L_V_GROUP_BY2',L_V_GROUP_BY1);
define('L_V_GROUP2DIR',' ');
define('L_V_GROUP','Group title format');
define('L_V_REMOVE_STRING',L_COMPACT_REMOVE);
define('L_V_MODIFICATION','Type');
define('L_V_PARAMETER','Parameter');
define('L_V_IMG1','View image 1');
define('L_V_IMG2','View image 2');
define('L_V_IMG3','View image 3');
define('L_V_IMG4','View image 4');
define('L_V_ORDER1','Sort primary');
define('L_V_ORDER1DIR',' ');
define('L_V_ORDER2','Sort secondary');
define('L_V_ORDER2DIR',' ');
define('L_V_SELECTED','HTML for Selected');
define('L_V_COND1FLD','Condition 1');
define('L_V_COND1OP',' ');
define('L_V_COND1COND',' ');
define('L_V_COND2FLD','Condition 2');
define('L_V_COND2OP',' ');
define('L_V_COND2COND',' ');
define('L_V_COND3FLD','Condition 3');
define('L_V_COND3OP',' ');
define('L_V_COND3COND',' ');
define('L_V_LISTLEN',L_D_LISTLEN);
define('L_V_FLAG','Flag');
define('L_V_SCROLLER','Display page scroller');
define('L_V_ADITIONAL','Additional');
define('L_COMPACT_VIEW','Item listing');
define('L_FULLTEXT_VIEW','Fulltext view');
define('L_DIGEST_VIEW','Item digest');
define('L_DISCUSSION_VIEW','Discussion');
define('L_RELATED_VIEW','Related item');
define('L_CONSTANT_VIEW','View of Constants');
define('L_RSS_VIEW','RSS exchange');
define('L_STATIC_VIEW','Static page');
define('L_SCRIPT_VIEW','Javascript item exchange');

define("L_MAP","Mapping");
define("L_MAP_TIT","Admin - Content Pooling - Fields' Mapping");
define("L_MAP_FIELDS","Fields' mapping");
define("L_MAP_TABTIT","Content Pooling - Fields' mapping");
define("L_MAP_FROM_SLICE","Mapping from slice");
define("L_MAP_FROM","From");
define("L_MAP_TO","To");
define("L_MAP_DUP","Cannot map to same field");
define("L_MAP_NOTMAP","-- Not map --");
define("L_MAP_OK","Fields' mapping update succesful");

define("L_STATE_FEEDABLE_UPDATE", "Feed & update" );
define("L_STATE_FEEDABLE_UPDATE_LOCKED", "Feed & update & lock" );

define("L_SITEM_ID_ALIAS",'alias para el "short" Id del ítem');
define("L_MAP_VALUE","-- Value --");
define("L_MAP_VALUE2","Value");
define("L_ORDER", "Order");
define("L_INSERT_AS_NEW","Insert as new");

// Constant view constants
define("L_CONST_NAME_ALIAS", "Constant name");
define("L_CONST_VALUE_ALIAS", "Constant value");
define("L_CONST_PRIORITY_ALIAS", "Constant priority");
define("L_CONST_GROUP_ALIAS", "Constant group id");
define("L_CONST_CLASS_ALIAS", "Category class (for categories only)");
define("L_CONST_COUNTER_ALIAS", "Constant number");
define("L_CONST_ID_ALIAS", "Constant unique id");

define('L_V_CONSTANT_GROUP','Constant Group');
define("L_NO_CONSTANT", "Constant not found");

// Discussion constants.
define("L_DISCUS_SEL","Show discussion");
define("L_DISCUS_EMPTY"," -- Empty -- ");
define("L_DISCUS_HTML_FORMAT","Use HTML tags");
define("L_EDITDISC_ALIAS",'Alias used on admin page index.php3 for edit discussion url');

define("L_D_SUBJECT_ALIAS","Alias for subject of the discussion comment");
define("L_D_BODY_ALIAS"," Alias for text of the discussion comment");
define("L_D_AUTHOR_ALIAS"," Alias for written by");
define("L_D_EMAIL_ALIAS","Alias for author's e-mail");
define("L_D_WWWURL_ALIAS","Alias for url address of author's www site");
define("L_D_WWWDES_ALIAS","Alias for description of author's www site");
define("L_D_DATE_ALIAS","Alias for publish date");
define("L_D_REMOTE_ADDR_ALIAS","Alias pro IP address of author's computer");
define("L_D_URLBODY_ALIAS","Alias for link to text of the discussion comment<br>
                             <i>Usage: </i>in HTML code for index view of the comment<br>
                             <i>Example: </i>&lt;a href=_#URL_BODY>_#SUBJECT#&lt;/a>");
define("L_D_CHECKBOX_ALIAS","Alias for checkbox used for choosing discussion comment");
define("L_D_TREEIMGS_ALIAS","Alias for images");
define("L_D_ALL_COUNT_ALIAS","Alias for the number of all comments to the item");
define("L_D_APPROVED_COUNT_ALIAS","Alias for the number of approve comments to the item");
define("L_D_URLREPLY_ALIAS","Alias for link to a form<br>
                             <i>Usage: </i>in HTML code for fulltext view of the comment<br>
                             <i>Example: </i>&lt;a href=_#URLREPLY&gt;Reply&lt;/a&gt;");
define("L_D_URL","Alias for link to discussion<br>
                             <i>Usage: </i>in form code<br>
                             <i>Example: </i>&lt;input type=hidden name=url value=\"_#DISC_URL\">");
define("L_D_ID_ALIAS"," Alias for item ID<br>
                             <i>Usage: </i>in form code<br>
                             <i>Example: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">");
define("L_D_ITEM_ID_ALIAS"," Alias for comment ID<br>
                             <i>Usage: </i>in form code<br>
                             <i>Example: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">");

define("L_D_BUTTONS","Alias for buttons Show all, Show selected, Add new<br>
                             <i>Usage: </i> in the Bottom HTML code");

define("L_D_COMPACT" , "HTML code for index view of the comment");
define("L_D_SHOWIMGS" , "Show images");
define("L_D_ORDER" , "Order by");
define("L_D_FULLTEXT" ,"HTML code for fulltext view of the comment");

define("L_D_ADMIN","Discussion comments management");
define("L_D_NODISCUS","No discussion comments");
define("L_D_TOPIC","Title");
define("L_D_AUTHOR","Author");
define("L_D_DATE","Date");
define("L_D_ACTIONS","Actions");
define("L_D_DELETE","Delete");
define("L_D_EDIT","Edit");
define("L_D_HIDE","Hide");
define("L_D_APPROVE","Approve");

define("L_D_EDITDISC","Items managment - Discussion comments managment - Edit comment");
define("L_D_EDITDISC_TABTIT","Edit comment");
define("L_D_SUBJECT","Subject");
define("L_D_AUTHOR","Author");
define("L_D_EMAIL","E-mail");
define("L_D_BODY","Text of discussion comment");
define("L_D_URL_ADDRESS","Authors's WWW  - URL");
define("L_D_URL_DES","Authors's WWW - description");
define("L_D_HOSTNAME","IP address of authors's computer");

define("L_D_SELECTED_NONE","No comment was selected");
define("L_D_DELETE_COMMENT","Are you sure you want to delete selected comment?");

define("L_D_FORM","HTML code of the form for posting comment");
define("L_D_ITEM","Item: ");

define("L_D_SHOW_SELECTED","Show selected");
define("L_D_SHOW_ALL","Show all");
define("L_D_ADD_NEW","Add new");

define("L_TOO_MUCH_RELATED","There are too much related items. The number of related items is limitted.");
define("L_SELECT_RELATED","Select related items");
define("L_SELECT_RELATED_1WAY","Add");
define("L_SELECT_RELATED_2WAY","Add&nbsp;mutual");

define("L_D_BACK","Back");
define("L_D_ADMIN2","Discussion comments managment");

define("L_INNER_IMPORT","Inner Node Feeding");
define("L_INTER_IMPORT","Inter Node Import");
define("L_INTER_EXPORT","Inter Node Export");

define("L_NODES_MANAGER","Nodes");
define("L_NO_PS_NODES_MANAGER","You have not permissions to manage nodes");
define("L_NODES_ADMIN_TIT","Remote node administration");
define("L_NODES_LIST","Known remote nodes");
define("L_NODES_ADD_NEW","Add new node");
define("L_NODES_EDIT","Edit node data");
define("L_NODES_NODE_NAME","Node name");
define("L_NODES_YOUR_NODE","Your node name");
define("L_NODES_SERVER_URL","URL of the getxml.php3");
define("L_NODES_YOUR_GETXML","Your getxml is");
define("L_NODES_PASWORD","Password");
define("L_SUBMIT","Submit");
define("L_NODES_SEL_NONE","No selected node");
define("L_NODES_CONFIRM_DELETE","Are you sure you want to delete the node?");
define("L_NODES_NODE_EMPTY","Node name must be filled");

define("L_IMPORT_TIT","Inter node import settings");
define("L_IMPORT_LIST","Existing remote imports into the slice ");
define("L_IMPORT_CONFIRM_DELETE","Are you sure you want to delete the import?");
define("L_IMPORT_SEL_NONE","No selected import");
define("L_IMPORT_NODES_LIST","All remote nodes");
define("L_IMPORT_CREATE","Create new feed from node");
define("L_IMPORT_NODE_SEL","No selected node");
define("L_IMPORT_SLICES","List of remote slices");
define("L_IMPORT_SLICES2","List of available slices from the node ");
define("L_IMPORT_SUBMIT","Choose slice");
define("L_IMPORT2_OK","The import was successfully created");
define("L_IMPORT2_ERR","The import was already created");

define("L_RSS_ERROR","Unable to connect and/or retrieve data from the remote node. Contact the administrator of the local node.");
define("L_RSS_ERROR2","Invalid password for the node name:");
define("L_RSS_ERROR3","Contact the administrator of the local node.");
define("L_RSS_ERROR4","No slices available. You have not permissions to import any data of that node. Contact ".
          "the administrator of the remote slice and check, that he obtained your correct username.");


define("L_EXPORT_TIT","Inter node export settings");
define("L_EXPORT_CONFIRM_DELETE","Are you sure you want to delete the export?");
define("L_EXPORT_SEL_NONE","No selected export");
define("L_EXPORT_LIST","Existing exports of the slice ");
define("L_EXPORT_ADD","Insert new item");
define("L_EXPORT_NAME","User name");
define("L_EXPORT_NODES","Remote Nodes");

define("L_RSS_TITL", "Title of Slice for RSS");
define("L_RSS_LINK", "Link to the Slice for RSS");
define("L_RSS_DESC", "Short description (owner and name) of slice for RSS");
define("L_RSS_DATE", "Date RSS information is generated, in RSS date format");

define("L_NO_PS_EXPORT_IMPORT", "You are not allowed to export / import slices");
define("L_EXPORT_SLICE", "Export");
define("L_IMPORT_SLICE", "Import");
define("L_EXPIMP_SET", "Slice structure");

define("L_E_EXPORT_TITLE", "Export slice structure");
define("L_E_EXPORT_MEMO", "Choose one of two export kinds:");
define("L_E_EXPORT_DESC", "When exporting \"to another ActionApps\" only the current slice will be exported "
		."and you choose her new identificator.");
define("L_E_EXPORT_DESC_BACKUP", "When exporting \"to Backup\" you may choose more slices at once.");
define("L_E_EXPORT_MEMO_ID","Choose a new slice identificator exactly 16 characters long: ");
define("L_E_EXPORT_SWITCH", "Export to Backup");
define("L_E_EXPORT_SWITCH_BACKUP", "Export to another ActionApps");
define("L_E_EXPORT_IDLENGTH", "The identificator should be 16 characters long, not ");
define("L_E_EXPORT_TEXT_LABEL", "Save this text. You may use it to import the slices into any ActionApps:");
define("L_E_EXPORT_LIST", "Select slices which you WANT to export:");

define("L_PARAM_WIZARD_LINK", "Wizard with help");
define("L_SHOW_RICH", "Show this field as a rich text editor (use only after having installed the necessary components!)");
define("L_MAP_JOIN","-- Joined fields --");

// aliases used in se_notify.php3 
define("L_NOTIFY_SUBJECT", "Subject of the Email message"); 
define("L_NOTIFY_BODY", "Body of the Email message"); 
define("L_NOTIFY_EMAILS", "Email addresses, one per line");
define("L_NOTIFY_HOLDING", "<h4>New Item in Holding Bin</h4> People can be notified by email when an item is created and put into the Holding Bin.  If you want to make use of this feature, enter the recipients email address below.  In the following fields, you can customize the format of the email they will receive."); 
define("L_NOTIFY_HOLDING_EDIT", "<h4>Item Changed in Holding Bin</h4>  People can be notified by email when an item in the Holding Bin is modified.  If you want to make use of this feature, enter the recipients email address below.  In the following fields, you can customize the format of the email they will receive."); 
define("L_NOTIFY_APPROVED", "<h4>New Item in Approved Bin</h4>  People can be notified by email when an item is created and put into the Approved Bin.  If you want to make use of this feature, enter the recipients email address below.  In the following fields, you can customize the format of the email they will receive."); 
define("L_NOTIFY_APPROVED_EDIT", "<h4>Item Changed in Approved Bin</h4>  People can be notified by email when an item in the Approved Bin is modified.  If you want to make use of this feature, enter the recipients email address below.  In the following fields, you can customize the format of the email they will receive.");
define("L_NOTIFY", "Email Notification"); 
define("L_A_NOTIFY_TIT", "Email Notifications of Events");

define("L_NOITEM_MSG", "'No item found' message");
define("L_NOITEM_MSG_HLP", "message to show in place of slice.php3, if no item matches the query");

# ---------------- Users profiles -----------------------------------------
define('L_PROFILE','Profile');
define('L_DEFAULT_USER_PROFILE','Default user profile');
define('L_PROFILE_DELETE_OK','Rule deleted');
define('L_PROFILE_ADD_OK','Rule added');
define('L_PROFILE_ADD_ERR',"Error: Can't add rule");
define('L_PROFILE_LISTLEN','Item number');
define('L_PROFILE_ADMIN_SEARCH','Item filter');
define('L_PROFILE_ADMIN_ORDER','Item order');
define('L_PROFILE_HIDE','Hide field');
define('L_PROFILE_HIDEFILL','Hide and Fill');
define('L_PROFILE_FILL','Fill field');
define('L_PROFILE_PREDEFINE','Predefine field');
define('L_A_PROFILE_TIT','Admin - user Profiles');
define('L_PROFILE_HDR','Rules');
define('L_NO_RULE_SET','No rule is set');
define('L_PROFILE_ADD_HDR','Add Rule');
define('L_PROFILE_LISTLEN_DESC','number of item displayed in Item Manager');
define('L_PROFILE_ADMIN_SEARCH_DESC','preset "Search" in Itme Manager');
define('L_PROFILE_ADMIN_ORDER_DESC','preset "Order" in Itme Manager');
define('L_PROFILE_HIDE_DESC','hide the field in inputform');
define('L_PROFILE_HIDEFILL_DESC','hide the field in inputform and fill it by the value');
define('L_PROFILE_FILL_DESC','fill the field in inputform by the value');
define('L_PROFILE_PREDEFINE_DESC','predefine value of the field in inputform');
define('L_VALUE',L_MAP_VALUE2);
define('L_FUNCTION',L_ALIAS_FUNC);
define('L_RULE','Rule');

define('L_ID_COUNT_ALIAS','number of found items');
define('L_V_NO_ITEM','HTML code for "No item found" message');
define('L_INPUT_SHOW_HCO','Hierachical constants');
define("L_NO_ITEM_FOUND", "No item found");

// constants used in param wizard only:
require  $GLOBALS[AA_INC_PATH]."en_param_wizard_lang.php3";
// new constants to be translated are here. Leave this "require" always at the 
// end of this file
// If You want to translate the new texts (which is in new_news_lang.php3 file),
// just copy them from the new_news_lang.php3 file here and translate.
// IMPORTANT: Leave the require new_news_lang.php3 as the last line of this
// file - it will not redefine the constant You translated and helps You when 
// we add new texts !!!
require  $GLOBALS[AA_INC_PATH]."new_news_lang.php3"; ?>