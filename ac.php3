<?php
/**
 * Shortcut for alerts/index.php3 to make the URL shorter
 * URL params: $id = confirmation ID, $l = language, $ss = stylesheet URL
 * 
 * @package UserOutput
 * @version $Id$
 * @author 
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications 
*/
/** redirect */

  $self = $HTTP_SERVER_VARS['PHP_SELF'];
  $i = strlen ($self) - 1;
  while ($self[$i] != "/") $i --;
  $self = substr ($self, 0, $i);

  header("Status: 302 Moved Temporarily");
  header("Location: $self/misc/alerts/confirm.php3?id=$id&lang=$l&ss=$ss");
?>
 