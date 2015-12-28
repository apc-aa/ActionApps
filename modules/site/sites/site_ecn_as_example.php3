<?php

// This file is no longer needed - use AA_Router_Seo - in site moduule settings
// Honza 2015-12-13

// site definition file for Econnect's site (http://ecn.cz)
// This is just an example of site file. For more details on sites see FAQ:
// http://apc-aa.sourceforge.net/faq/

function CheckW( $w ) {
  return ($w && strpos( ' ezn', $w )) ? $w : 'z';
}

function CheckS( $s, $w ) {
  switch( $w ) {
    case 'e': return ($s && strpos( ' 123456789', $s )) ? $s : '1';
    case 'z': return ($s && strpos( ' zmktas', $s )) ? $s : 'z';
    case 'n': return ($s && strpos( ' NFPIJV', $s )) ? $s : 'N';
  }
}  

function CheckF( $f, $w ) {
  switch( $w ) {
    case 'z': return ($f && strpos( ' vzslgrkoi', $f )) ? $f : 'v';
    default:  return ($f && strpos( ' 123456789', $f )) ? $f : '1';
  }
}  

function CheckR( $r, $w ) {
  switch( $w ) {
    case 'z': return ($r && strpos( ' psjekulraviomzrncx', $r )) ? $r : 'x';
    default:  return ($r && strpos( ' -', $r )) ? 'x' : $r;
  }
}  

function CheckP( $p ) {
  return ((integer)$p>0) ? (integer)$p : 1;
}  

function CheckT( $t ) {
  return '-';
}  

function CheckX( $x ) {
  return ((integer)$x>0) ? (integer)$x : '-';
}  

// ------------- functions definition end ------------------------------------

define('L_D_ADD_NEW','Pøidat reakci');
define('L_D_SHOW_SELECTED','Zobrazit vybrané');
define('L_D_SHOW_ALL','Zobrazit vše');
define('L_D_SELECTED_NONE','Není vybrán žádný pøíspìvek');

$regiony_arr = array(  // path, searchstring for zpravodajstvi, searchstrig for tiskove zpravy
  "p" => array( "&nbsp;&gt;&nbsp;Praha",                    "Praha",          "Praha"),
  "s" => array( "&nbsp;&gt;&nbsp;Støedoèeský&nbsp;kraj",    "Støedoèeský",    "Støedoèeský"),
  "j" => array( "&nbsp;&gt;&nbsp;Jihoèeský&nbsp;kraj",      "Jihoèeský",      "Jihoèeský"),
  "e" => array( "&nbsp;&gt;&nbsp;Plzeòský&nbsp;kraj",       "Plzeòský",       "Plzeòský"),
  "k" => array( "&nbsp;&gt;&nbsp;Karlovarský&nbsp;kraj",    "Karlovarský",    "Karlovarský"),
  "u" => array( "&nbsp;&gt;&nbsp;Ústecký&nbsp;kraj",        "Ústecký",        "Ústecký"),
  "l" => array( "&nbsp;&gt;&nbsp;Liberecký&nbsp;kraj",      "Liberecký",      "Královehradecký"),
  "r" => array( "&nbsp;&gt;&nbsp;Královéhradecký&nbsp;kraj","Královehradecký","Královehradecký"),
  "a" => array( "&nbsp;&gt;&nbsp;Pardubický&nbsp;kraj",     "Pardubický",     "Pardubický"),
  "v" => array( "&nbsp;&gt;&nbsp;Vysoèina",                 "Vysoèina",       "Vysoèina"),
  "i" => array( "&nbsp;&gt;&nbsp;Jihomoravský&nbsp;kraj",   "Jihomoravský",   "Jihomoravský"),
  "o" => array( "&nbsp;&gt;&nbsp;Olomoucký&nbsp;kraj",      "Olomoucký",      "Olomoucký"),
  "m" => array( "&nbsp;&gt;&nbsp;Moravskoslezský&nbsp;kraj","Moravskoslezský","Moravskoslezský"),
  "z" => array( "&nbsp;&gt;&nbsp;Zlínský&nbsp;kraj",        "Zlínský",        "Zlínský"),
  "r" => array( "&nbsp;&gt;&nbsp;ÈR",                       "Èeská",          "ÈR"),
  "n" => array( "&nbsp;&gt;&nbsp;Slovensko",                "Slovensko",      "Slovensko"),
  "c" => array( "&nbsp;&gt;&nbsp;Svìt",                     "Zahranièí",      "Maïarsko+or+Nìmecko+or+Polsko+or+Rakousko+or+Ukrajina+or+Evropa+or+Rusko+or+Amerika+or+Asie+or+Afrika+or+Austrálie+or+Antarktida+or+Svìt")
);

$formaty_arr = array( 'z' => 'zprávy',
                      'm' => 'kauzy',
                      'k' => 'komentáøe',
                      't' => 'tiskové&nbsp;zprávy',
                      'a' => 'kampanì',
                      's' => 'ze&nbsp;svìta');

if ( !$apc ) {
  if ( $x ) {         // want to view text?
    $SQL = "SELECT content.text, item.slice_id FROM item,content 
             WHERE item.id=content.item_id 
               AND item.short_id='$x' 
               AND content.field_id='switch.........2'";
    $db->query($SQL);
    if ( $db->next_record() ) {
      $apc = $db->f('text');
      // pomocna promena, ktera nam rekne, ze jde o staticky text a ne zpravu  
      $texty = ( unpack_id($db->f('slice_id')) == '21c8416923d4b6c58abc7ed664651802' );  // Ecn - texty
    }   
    $apc .= substr('zzvx--',-( 6-strlen($apc) ));
  } 
  elseif( IsInDomain('zpravodajstvi.ecn.cz') ) $apc = 'zzvx--';
  elseif( IsInDomain('enviro.ecn.cz') ) $apc = 'zzzx--';
  elseif( IsInDomain('soc.ecn.cz') ) $apc = 'zzsx--';
  elseif( IsInDomain('lprava.ecn.cz') ) $apc = 'zzlx--';
  elseif( IsInDomain('kultura.ecn.cz') ) $apc = 'zzkx--';
  elseif( IsInDomain('gender.ecn.cz') ) $apc = 'zzgx--';
  elseif( IsInDomain('regiony.ecn.cz') ) $apc = 'zzrx--';
  elseif( IsInDomain('internet.ecn.cz') ) $apc = 'zzix--';
  elseif( IsInDomain('econnect.ecn.cz') ) $apc = 'e31x1--';
  elseif( IsInDomain('nno.ecn.cz') ) $apc = 'nN1x--';
  elseif( IsInDomain('fundraising.ecn.cz') ) $apc = 'nF1x--';
  elseif( IsInDomain('granty.ecn.cz') ) $apc = 'nF2x--';
  elseif( IsInDomain('prace.ecn.cz') ) $apc = 'nJ1x--';
  elseif( IsInDomain('joblist.ecn.cz') ) $apc = 'nJ1x--';
  elseif( IsInDomain('legislativa.ecn.cz') ) $apc = 'nP1x--';
  elseif( IsInDomain('kauzy.ecn.cz') ) $apc = 'nP2x1--';
  elseif( IsInDomain('podpora.ecn.cz') ) $apc = 'e61x1-73165';
  elseif( IsInDomain('sluzby.ecn.cz') ) $apc = 'e71x1--';
  elseif( IsInDomain('objednavka.ecn.cz') ) $apc = 'e81x1-88866';
  elseif( IsInDomain('cenik.ecn.cz') ) $apc = 'e91x1--';
  else $apc = 'zzvx--';
}  

if ( preg_match( "/^([a-zA-Z0-9_])([a-zA-Z0-9_])([a-zA-Z0-9_-])([a-zA-Z_]+)([-]|[0-9]+)([a-zA-Z_-])([0-9]*)/", $apc, $vars )) 
  list($old_state,$old_w,$old_s,$old_f,$old_r,$old_p,$old_t,$old_x) = $vars;
 else 
  list($old_w,$old_s,$old_f,$old_r,$old_p,$old_t) = array( 'z', 'z', 'v', 'x', '-', '-');

if ( isset($w) ) {                          // w stands for WEB (like zpravodajstvi - z, econnect - e, nno - n
  switch($w) {
    case 'z':   list($old_w,$old_s,$old_f,$old_r,$old_p,$old_t,$old_x) = array( 'z', 'z', 'v', 'x', '-', '-', ''); break;
    case 'n':   list($old_w,$old_s,$old_f,$old_r,$old_p,$old_t,$old_x) = array( 'n', 'N', '1', '-', '-', '-', ''); break;
    case 'e':   list($old_w,$old_s,$old_f,$old_r,$old_p,$old_t,$old_x) = array( 'e', '1', '1', '-', '-', '-', '73161'); break;
  }
}    
if ( isset($s) ) {                          // s stands for SUBWEB (like zpravy - z, komentare - k, o Econnectu - 1) We use disjunct sets of subweb chracters, so zpravodajstvi uses [a-z], econnect uses [1-9], nno [A-Z]
  $old_s=$s; 
  $old_x='';
  $old_p='1';
  if ( $old_w != 'z' ) // the format in zpravodajstvi stays the same
    $old_f='';        //CheckS fills the right $f
}

if ( isset($f) ) {$old_f=$f; $old_x='';$old_p='1';}    // f stands for FILTER (like zivotni protredi - z, kultura - k) - used only on zpravodajsvi page yet
if ( isset($r) ) {
  if ( isset($r) and is_array($r)) {
    reset( $r );
    while ( list($k,) = each($r) )
      $max_index = max($max_index, $k);
    $max_index = max( $max_index, strlen($old_r)-1 );
    for ( $i=0; $i<=$max_index; $i++)
      $new_r .= ($r[$i] ? $r[$i] : ( $old_r[$i] ? $old_r[$i] : 'x' ));
    $old_r = $new_r;   
  } else
    $old_r = ($r ? $r : 'x');
  $old_x='';
  $old_p='1';
}                                          // r stands for REGION (like Praha - a ...) or any other second filter - used on zpravodajsvi for region selection or in joblist for switching between grant categories and types
if ( isset($p) ) {$old_p=$p; $old_x='';}    // page
if ( isset($t) ) {$old_t=$t; $old_x='';}    // switch to special mode (text only, print, ...)
if ( isset($x) ) {$old_x=$x;}               // item id to display

if ( isset($scrl) ) {      // page scroller

  $pagevar = "scr_".$scrl."_Go";
  $old_p = $$pagevar;
  $old_x='';
} 
if ( ($old_p <= 0) OR ($old_p=='-') )
  $old_p = 1;

// zaverecna kontrola a pripadna uprava promennych:
$old_w = CheckW( $old_w );
$old_s = CheckS( $old_s, $old_w );
$old_f = CheckF( $old_f, $old_w );
$old_r = CheckR( $old_r, $old_w );
$old_p = CheckP( $old_p );
$old_t = CheckT( $old_t );
$old_x = CheckX( $old_x );
  
// pomocne promenne pouzite pro vyhledavani a pro zobrazeni cesty
list( $region_cesta, $region_z, $region_k ) = $regiony_arr[$old_r];
$format_cesta = '&nbsp;&gt;&nbsp;'.$formaty_arr[$old_s];

$apc_state = array ('state' => "$old_w$old_s$old_f$old_r$old_p$old_t$old_x",
                    'w' => $old_w,
                    's' => $old_s,
                    'f' => $old_f,
                    'r' => $old_r,
                    't' => $old_t,
                    'p' => $old_p,
                    'x' => $old_x,
  // pomocne promenne pouzite pro vyhledavani a pro zobrazeni cesty
                    'texty' =>        ($texty ? $texty : ''),
                    'region_cesta' => ($region_cesta ? $region_cesta : ''),
                    'region_z' =>     ($region_z ? $region_z : ''),
                    'region_k' =>     ($region_k ?$region_k : ''),
                    'format_cesta' => ($format_cesta ? $format_cesta : '')
                    );
                    
// $r variable is could be array, so create variables like r0, r1, .. to be used in site
for ( $i=0; $i< strlen($old_r); $i++)
  $apc_state['r'.$i] = $old_r[$i];

if ( $dbg )
  print_r($apc_state);
?>