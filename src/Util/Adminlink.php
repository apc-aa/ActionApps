<?php

namespace AA\Util;

use AA\IO\DB\DB_AA;
use AA_Field;
use AA_Items;
use AA_Module;
use AA_Module_Site;
use AA_Object;
use AA_Slice;
use AA_Views;
use zids;

class Adminlink
{
    public static function fieldLink($field_id, $slice_id, $db_fld = ''): string
    {
        $als_name = '';
        $disabled = false;
        $txt = '';
        if ($fld = AA_Field::factoryFromDb($field_id, $slice_id)) {
            if (str_starts_with($db_fld, 'alias')) {
                $als_name = substr($fld->getProperty(substr($db_fld, 0, 6)), 2);
                if (DB_AA::test('field', [['slice_id', $slice_id, 'l'], ['id', $field_id], [substr($db_fld, 0, 6), 'X', 'BEGIN']])) {
                    $disabled = true;
                }
            }
            // the link text is HTML; $als_name stays raw - it goes to the url, which a_href() escapes
            $txt = $fld->linkToField(safe("$field_id - " . $fld->getName() . ($als_name ? " (_#$als_name)" : '')), $als_name);
            $txt = $disabled ? "<s title=\"" . _m('disabled') . "\">$txt</s>" : $txt;
        }
        return $txt;
    }

    /** url of the settings of a field - where a list of links wants a url, not the HTML of fieldLink();
     *  '' for ids a log row may hold cut or empty */
    public static function fieldUrl(string $slice_id, string $field_id): string
    {
        return (is_long_id($slice_id) && ($field_id !== '')) ? get_admin_url('se_inputform.php', ['change_id' => $slice_id, 'fid' => $field_id]) : '';
    }

    /** url of the item in the item editor */
    public static function itemUrl(string $item_id, string $slice_id): string
    {
        return get_admin_url('itemedit.php', ['id' => $item_id, 'change_id' => $slice_id, 'edit' => 1]);
    }

    public static function linkByTable(string $table, string $fld, array $row): string
    {
        return match ($table) {
            'content', 'discussion', 'item' => self::itemLink($row['iid'], $row['sid'], true),
            'view' => self::viewLink($row['iid']),
            'slice', 'module', 'site', 'polls' => self::moduleLink($row['iid'], $fld),
            'field' => self::fieldLink($row['iid'], $row['sid'], $fld),
            'site_spot' => self::siteLink($row['iid'], $row['sid']),
            'object_text', 'object_integer', 'object_float' => self::objectLink($row['iid']),
            default => '',
        };
    }

    public static function objectLink($object_id): string
    {
        if (($obj = AA_Object::load($object_id))) {
            $alias    = $obj->getProperty('alias');
            $raw_name = $obj->getName();
            $name     = safe($raw_name === $alias ? $raw_name : "$raw_name ($alias)");   // name and alias are set in the admin UI
            $url      = $obj->getAdminUrl();
            return ($url ? a_href($url, $name) : $name) . ' (' . $obj->getObjectType() . ' - ' . AA_Module::getModuleNameSafe($obj->getOwnerId()) . ') &nbsp; ' . ChangesMonitor::getHistoryLink($object_id);
        }
        return '';
    }

    public static function moduleLink($module_id, ?string $fid = null): string
    {
        return match ($fid) {
            'fulltext_format_top', 'fulltext_format', 'fulltext_format_bottom' => AA_Module::getModuleNameSafe($module_id, true, 'admin/se_fulltext.php') . " ($module_id) &nbsp; " . ChangesMonitor::getHistoryLink($module_id),
            'odd_row_format', 'even_row_format', 'compact_top', 'compact_bottom', 'category_top', 'category_format', 'category_bottom' => AA_Module::getModuleNameSafe($module_id, true, 'admin/se_compact.php') . " ($module_id) &nbsp; " . ChangesMonitor::getHistoryLink($module_id),
            'admin_format_top', 'admin_format', 'admin_format_bottom', 'admin_noitem_msg', 'admin_remove' => AA_Module::getModuleNameSafe($module_id, true, 'admin/se_admin.php') . " ($module_id) &nbsp; " . ChangesMonitor::getHistoryLink($module_id),
            'javascript' => AA_Module::getModuleNameSafe($module_id, true, 'admin/se_javascript.php') . " ($module_id) &nbsp; " . ChangesMonitor::getHistoryLink($module_id),
            default => AA_Module::getModuleNameSafe($module_id, true) . " ($module_id) &nbsp; " . ChangesMonitor::getHistoryLink($module_id),
        };
    }

    public static function siteLink($spot_id, $module_id): string
    {
        // a slice id is not a site, and has no tree
        $spot_safe = safe($spot_id);   // the label keeps its <s>/<b> markup, so the value is escaped instead
        if (($module = AA_Module_Site::getOwnModule($module_id)) && ($tree = $module->getTree())) {
            $spot_code = ($tree->get('flag', $spot_id) & AA_Module_Site::FLAG_DISABLE) ? "<s>$spot_safe</s>" : "<b>$spot_safe</b>";
        } else {
            $spot_code = "??? $spot_safe";
        }
        return a_href(get_aa_url("modules/site/index.php", ['slice_id' => $module_id, 'module_id' => $module_id, 'spot_id' => $spot_id, 'go_sid' => $spot_id]), $spot_code) . ' &nbsp; '
            . ChangesMonitor::getHistoryLink("S.$spot_id");
    }

    public static function viewLink($view_id): string
    {
        $view = AA_Views::getViewNumeric($view_id);
        return $view->jumpLink(true) . ' &nbsp; ' . ChangesMonitor::getHistoryLink("V.$view_id");
    }

    /** Prints link to Item with many details for Superuser in AA Admin interface */
    public static function itemLink($item_id, $slice_id = '', $no_module_link = false): string
    {
        $item = AA_Items::getItem(new zids($item_id, 'l'));
        if (!$slice_id) {
            if ($item) {
                $slice_id = $item->getSliceID();
            } else {
                return '';
            }
        }
        $seo  = safe($item->getValue('seo.............') ?: 'Item');   // raw editor input - AA_Inserter_Seo slugifies only an empty field
        $hdln = current(AA_Items::getFormatted(new zids($item_id, 'l'), '_#HEADLINE', AA_BIN_ALL));  // we want also headline of items in trash
        $hdln = ($hdln && $hdln !== '_#HEADLINE') ? ' (' . self::headlineText($hdln) . ')' : '';

        if (!$item->isActive()) {
            $seo = "<s>$seo</s>";
        }

        $info   = [];
        $info[] = self::searchLink($item_id);
        $info[] = a_href(self::itemUrl($item_id, $slice_id), "$seo$hdln");
        if (!$no_module_link) {
            $info[] = AA_Slice::getModuleNameSafe($slice_id, true);
        }
        $info[] = ChangesMonitor::getHistoryLink($item_id);
        $info[] = a_href(get_admin_url('se_statistics.php', [
            'change_id'  => $slice_id,
            'items_code' => $item_id,
            'item_set'   => 'exact',
            'stats'      => 'days',
            'date_from'  => date('Y-m-d H:i', strtotime('-50 days')),
            'show'       => 1,
        ]), _m('Statistics'));
        return $item_id . Txt::additionalInfo($info);
    }

    /** _#HEADLINE output as safe link text: an editor may put HTML in the headline, and the link
     *  is shown to the superadmin, so only the text of it is kept */
    public static function headlineText(string $html): string
    {
        return Txt::safe(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function searchLink(string $searchtext, string $label = "🔍"): string
    {
        return a_href(self::searchUrl($searchtext), $label);
    }

    /** url of the AA Finder searching every template (views, slices, fields, site spots, objects, code items) */
    public static function searchUrl(string $searchtext): string
    {
        return get_admin_url(
            'aafinder.php',
            ['search' => $searchtext, 'searchinview' => 1, 'searchinslice' => 1, 'searchinfield' => 1, 'searchinspot' => 1, 'searchinobject' => 1, 'searchincodeitems' => 1, 'go_search' => 1],
        );
    }


}
