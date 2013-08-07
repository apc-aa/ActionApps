<?php
/**
 * make_rss.php3 - returns Rich Site Summary RDF file from slice
 * expected at least $slice_id
 *
 * PHP versions 4 and 5
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

//optionaly highlight // when true, shows only highlighted items
//optionaly cat_id    // select only items in category with id cat_id

require_once "../include/config.php3";
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."searchlib.php3";
/** RSS_restrict function
 * @param $txt
 * @param $len
 * @return $len in UTF-8, substringed to $len
 */
function RSS_restrict($txt, $len) {
  return utf8_encode(myspecialchars(substr($txt,0,$len)));
}

$db = new DB_AA;

if ($slice_id==""){
  echo "Error: slice_id not defined";
  exit;
}

$p_slice_id = q_pack_id($slice_id);

// RSS constant header
$rss_begin = '<'.'?xml version="1.0"?'. '>';   // I don't know, how to write it together and not interpret it as php start and end tag
echo $rss_begin;
echo '<!DOCTYPE rss PUBLIC "-//Netscape Communications//DTD RSS 0.91//EN"
            "http://my.netscape.com/publish/formats/rss-0.91.dtd">
<rss version="0.91">';


// RSS chanel (= slice) info
$SQL= "SELECT name, slice_url, owner FROM slice WHERE id='$p_slice_id'";
$db->query($SQL);
if (!$db->next_record()){
  echo "Can't get slice info";
  exit;
}

$title           = RSS_restrict( $db->f('name'), 100);
$link            = RSS_restrict( $db->f('slice_url'), 500);
$description     = RSS_restrict( $db->f('owner').": ".$db->f('name'), 500);
//$language        = RSS_restrict( strtolower($db->f(d_language_code)), 5);
$lastBuildDate   = RSS_restrict( GMDate("D, d M Y H:i:s "). "GMT", 100);

echo "
 <channel>
    <title>$title</title>
    <link>$link</link>
    <description>$description</description>
    <language>$language</language>
    <lastBuildDate>$lastBuildDate</lastBuildDate>";

// RSS items - max 15 items - listed items due to script parameters
$where = MakeWhere($p_slice_id, $cat_id, $highlight);

$SQL   = "SELECT items.id, items.headline, items.abstract, fulltexts.full_text
          FROM items, fulltexts WHERE $where AND fulltexts.ft_id=items.master_id
          ORDER BY publish_date";

$item_count = 1;
$db->query($SQL);
while ($db->next_record()){
  $title       = RSS_restrict( $db->f('headline'), 100);
  $link_item   = RSS_restrict( con_url($link, "sh_itm=".unpack_id($db->f(id))), 500);
  $description = RSS_restrict( ($db->f('abstract')=="" ? $db->f('full_text') : strip_tags($db->f('abstract'))), 256);   // should be 500 but whole RSS file must be less than 8 kB
  echo "
    <item>
      <title>$title</title>
      <link>$link_item</link>
      <description>$description</description>
    </item>";
    if ( ++$item_count > 15 ) {
        break;
    }
}

// RSS end
echo "
  </channel>
</rss>";
?>
