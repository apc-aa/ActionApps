<?php
/**
 * Shortcut for alerts/uc_settings.php3 to make the URL shorter
 * URL params: $u = user ID, $l = language, $ss = stylesheet URL
 * 
 * @package UserOutput
 * @version $Id$
 * @author Jakub Adamek, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications 
*/
  $self = $HTTP_SERVER_VARS['PHP_SELF'];
  $i = strlen ($self) - 1;
  while ($self[$i] != "/") $i --;
  $self = substr ($self, 0, $i);

  header("Status: 302 Moved Temporarily");
  header("Location: $self/modules/alerts/uc_settings.php3?id=$id&lang=$l&ss=$ss");
?>
  