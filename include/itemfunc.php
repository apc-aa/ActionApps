<?php
/**
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;
use AA\Util\Adminlink;
use AA\Util\ChangesMonitor;
use AA\Util\Imgfunc;
use AA\Util\Langs;
use AA\Util\NamedInterface;
use AA\Util\Txt;

require_once __DIR__ . "/varset.php";
require_once __DIR__ . "/notify.php";
require_once __DIR__ . "/imagefunc.php";
require_once __DIR__ . "/date.php";
require_once __DIR__ . "/item_content.php";
require_once __DIR__ . "/event.class.php";


/** Item-context left rail items, shared by the item editor and its Info / History / Statistics
 *  pages. Used as the `function` entry of 'itemcontext_submenu' (see include/menu.php), so it is
 *  rendered by the standard AA submenu renderer and the current page's $activeSubmenu drives the
 *  highlight. Reads the current item from the request (the AA dynamic-menu idiom).
 *
 *  "Edit/Add item" + "Item info" are shown to anyone who may edit the item (the hosting page
 *  enforces that); History and Statistics are superadmin tools.
 *
 * @param mixed $func_param  unused (menu.php function-entry contract)
 * @return array submenu items array for getSubMenuRows()
 */
function GetItemContextMenuItems($func_param = null): array
{
    $id    = (string)($_REQUEST['id'] ?? '');
    $slice = AA_Slice::getModule(AA::$module_id);
    $items = [];   // no slice-name header row - the top bar already shows the current slice

    // a saved item has a real (long) id - not "add", not the slice id of a slice-setting edit
    $item_hex = (is_long_id($id) && $slice && $id !== $slice->getId()) ? $id : '';

    if ($item_hex === '') {
        $items['edit'] = ['exact_href' => get_admin_url('itemedit.php', ['add' => 1]), 'ico' => 'ico-form.png',
                          'show_always' => 1, 'title' => _m("Add item"), 'label' => _m("Add item")];
        return $items;
    }

    $items['edit'] = ['exact_href' => get_admin_url('itemedit.php', ['id' => $item_hex, 'edit' => 1]),
                      'ico' => 'ico-form.png', 'show_always' => 1, 'title' => _m("Edit item"), 'label' => _m("Edit item")];
    $items['info'] = ['exact_href' => get_admin_url('iteminfo.php', ['id' => $item_hex]),
                      'ico' => 'ico-guide.png', 'show_always' => 1, 'title' => _m("Item info"), 'label' => _m("Item info")];

    // History and Statistics are superadmin tools - and only worth a menu row when they have data.
    if (IsSuperadmin()) {
        $item     = AA_Items::getItem(new zids($item_hex));
        $hist_cnt = count(ChangesMonitor::getChangeList($item_hex));
        if ($hist_cnt > 0) {
            $items['history'] = ['exact_href' => get_admin_url('itemhistory.php', ['id' => $item_hex]),
                                 'ico' => 'ico-history.png', 'show_always' => 1, 'title' => _m("History"),
                                 'label' => _m("History"). " <span class='aa-bin-cnt'>".(int)$hist_cnt."</span>"];
        }
        if ($item && (int)$item->getval('display_count...') > 0) {
            $items['stats'] = ['exact_href' => get_admin_url('itemstats.php', ['id' => $item_hex]),
                               'ico' => 'ico-statistics.png', 'show_always' => 1, 'title' => _m("Statistics"), 'label' => _m("Statistics")];
        }
    }

    return $items;
}

/** Item display name via the standard _#HEADLINE idiom. Returns '' (not the literal "_#HEADLINE")
 *  when the slice has no headline alias or it is blank. Mirrors Adminlink::itemLink().
 *
 * @param AA_Item $item
 * @return string
 */
function GetItemDisplayName($item): string
{
    $hdln = current(AA_Items::getFormatted(new zids($item->getItemID(), 'l'), '_#HEADLINE', AA_BIN_ALL));
    return ($hdln && $hdln !== '_#HEADLINE') ? trim((string)$hdln) : '';
}

/** Friendly, non-technical overview of an item - headline + the key facts an editor cares about.
 *  Safe for any user who may edit the item (no internal ids, flags, or raw structures).
 *
 * @param AA_Item $item
 * @return string
 */
function GetItemSummaryHtml($item, bool $full = false): string
{
    $long_id  = $item->getItemID();
    $sid      = $item->getSliceID();
    $slice    = AA_Slice::getModule($sid);
    $headline = GetItemDisplayName($item);
    $short_id = (string)$item->getval('short_id........');

    $status = $item->getStatusCode();
    $pub    = (int)$item->getval('publish_date....');
    $exp    = (int)$item->getval('expiry_date.....');
    $post   = (int)$item->getval('post_date.......');
    $ledit  = (int)$item->getval('last_edit.......');
    $hits   = (int)$item->getval('display_count...');
    $now    = time();

    $dt   = static fn(int $t): string => $t ? safe(date('j.n.Y H:i', $t)) : '-';    // date + time for the Details table
    $int  = static fn($v): string => safe(number_format((int)$v, 0, '.', ' '));
    $user = static fn($u): string => trim((string)$u) !== '' ? ' '._m('by %1', [safe(perm_username($u))]) : '';

    // Slices can switch pending/expired handling off entirely - then publish/expiry dates act as
    // plain data fields and must not drive the status (the item is live despite a "future" publish
    // or "past" expiry date). Mirrors the Item Manager menu gating (menu.php appb/appc).
    $no_pending = $slice && $slice->noPendingBin();
    $no_expired = $slice && $slice->noExpiredBin();

    // --- Status lede: a one-glance "is it live?" answer. The dates themselves live in the
    //     Details table below; the green dot is the only saturated pixel. ---
    $is_live = false;
    $hint    = static fn(string $txt): string => ' <span class="tabhlp">'. safe($txt) .'</span>';
    if ($status === 1 && !$no_pending && $pub > $now) {
        $word = _m('Scheduled');
        $rest = $hint(_m('not yet on the public site'));
    } elseif ($status === 1 && !$no_expired && $exp && $exp < $now) {
        $word = _m('Expired');
        $rest = $hint(_m('not on the public site'));
    } elseif ($status === 1) {
        $is_live = true;
        $word = _m('Published');
        $rest = '';
    } elseif ($status === 2) {
        $word = _m('Holding bin');
        $rest = $hint(_m('not on the public site'));
    } elseif ($status === 3) {
        $word = _m('Trash');
        $rest = $hint(_m('not on the public site'));
    } else {
        $word = (string)$status;
        $rest = '';
    }

    // headline, or (no _#HEADLINE) a slice-name + short-id fallback, never a literal "_#HEADLINE"
    $name = $headline !== '' ? safe($headline)
          : trim(($slice ? safe($slice->getName()) : '').' <span class="aa-id">'. safe($short_id) .'</span>');

    $lede = '<div class="aa-item-lede">'
          . '<h2 class="aa-item-name">'.$name.'</h2>'
          . '<p class="aa-item-status"><span class="aa-status-dot'.($is_live ? ' is-live' : '').'"></span><strong>'. safe($word) .'</strong>'.$rest.'</p>'
          . '</div>';

    // --- Details: the reference facts, demoted below the lede. Short ID stays for editors (handy for
    //     view templates); the long hex Item ID and the slice's internal id are superadmin-only. ---
    // expiry set to the "maximum date" sentinel reads as Never; a disabled pending/expired check
    // greys the date out with a note (the value is kept - it still acts as a plain data field)
    $exp_val = ($exp >= MAX_TIME_NEVER) ? safe(_m('Never')) : $dt($exp);
    $unused  = static fn(string $val): string => '<span class="tabhlp">'. $val .' &ndash; '. safe(_m('not used on this site')) .'</span>';
    $rows = [
        [_m('Published'),   $no_pending ? $unused($dt($pub)) : $dt($pub)],
        [_m('Expires'),     $no_expired ? $unused($exp_val)  : $exp_val],
        [_m('Created'),     $dt($post). $user($item->getval('posted_by.......'))],
        [_m('Last edited'), $dt($ledit).$user($item->getval('edited_by.......'))],
        [_m('Views'),       $int($hits)],
        [_m('Revisions'),   $int(count(ChangesMonitor::getChangeList($long_id)))],
        [_m('Slice'),       ($slice ? safe($slice->getName()) : '').($full ? ' <span class="aa-id">'. safe($sid) .'</span>' : '')],
        [_m('Short ID'),    '<span class="aa-id">'. safe($short_id) .'</span>'],
    ];
    if ($full) {
        $rows[] = [_m('Item ID'), '<span class="aa-id">'. safe($long_id) .'</span>'];
    }

    return $lede. GetHtmlTable($rows, 'td', _m('Details'));
}

/** Technical/raw item data - DB records, content rows, referenced items, log and the raw
 *  object dump. Superadmin-only content (callers must gate). Extracted from the AA finder so
 *  the finder and the Item info page render identical data from one place.
 *
 * @param AA_Item $item
 * @param string  $item_id
 * @return string
 */
function GetItemTechnicalHtml($item, $item_id): string
{
    ob_start();
    $zid     = new zids($item_id);
    $long_id = $item->getItemID();
    $sid     = $item->getSliceID();
    echo "Item ID: ".Adminlink::itemLink($long_id, $sid);
    if ($arr = WhereFed($item->getItemID())) { echo "<br>Fed to: ".   implode(', ', $arr); }
    if ($arr = FromFed($item->getItemID()))  { echo "<br>Fed from: ". implode(', ', $arr); }

    $long_id = $zid->longids(0);
    if ($long_id) {
        if ($raw = DB_AA::select([], 'SELECT * FROM `item`', [['id',$long_id, 'l']])) {
            $rec = $raw[0];
            $rec['id']       = unpack_id($rec['id']);
            $rec['slice_id'] = unpack_id($rec['slice_id']);
            foreach ($rec as $rk => $rv) { $rec[$rk] = safe((string)$rv); }   // escape every raw column value (incl. legacy text columns)
            $rec['post_date']    .= Txt::additionalInfo([date('j.n.Y H:i:s', (int)$raw[0]['post_date'])]);
            $rec['publish_date'] .= Txt::additionalInfo([date('j.n.Y H:i:s', (int)$raw[0]['publish_date'])]);
            $rec['expiry_date']  .= Txt::additionalInfo([date('j.n.Y H:i:s', (int)$raw[0]['expiry_date'])]);
            $rec['last_edit']    .= Txt::additionalInfo([date('j.n.Y H:i:s', (int)$raw[0]['last_edit'])]);
            $rec['moved2active'] .= Txt::additionalInfo([date('j.n.Y H:i:s', (int)$raw[0]['moved2active'])]);
            $rec['posted_by']    .= Txt::additionalInfo([safe(perm_username($raw[0]['posted_by']))]);
            $rec['edited_by']    .= Txt::additionalInfo([safe(perm_username($raw[0]['edited_by']))]);
            echo GetHtmlTable([$rec], 'key', _m('Item table record for the item'));
        }

        $slice = AA_Slice::getModule($sid);

        if ($res = DB_AA::select([], "SELECT '' as FieldName, `field_id`, `number`,`text`, `flag` FROM `content`", [['item_id',$long_id, 'l']], ['field_id','number'])) {              // empty FieldName - will be filled later
            array_walk($res, static function (&$v, $k) use ($slice) {
                $v['FieldName'] =  ($f=$slice->getField($v['field_id'])) ? safe($f->getName()) : '-- invalid --';
                $raw_text  = (string)$v['text'];
                $v['text'] = safe($raw_text);   // content value may hold author-submitted HTML
                if (is_long_id($raw_text) && ($hdln = StrExp('item', [$raw_text, '_#HEADLINE']))) {
                    $v['text'] .= Txt::additionalInfo([safe($hdln)]);
                }
                $flags = '';
                if ($v['flag'] & FLAG_HTML) { $flags .= '<span  title="">HTML '; }
                if ($v['flag'] & FLAG_FEED) { $flags .= '<span title="item is fed">FEED</span> '; }
                if ($v['flag'] & FLAG_FREEZE) { $flags .= '<span  title="content can\'t be changed">FREEZE</span> '; }
                if ($v['flag'] & 8) { $flags .= '<span  title="offline filled - no more used flag">OFFLINE</span> '; }   // no more used
                if ($v['flag'] & FLAG_UPDATE) { $flags .= '<span  title="content should be updated if source is changed (after feeding)">UPDATE</span> '; }
                if ($v['flag'] & FLAG_TEXT_STORED) { $flags .= '<span  title="value is stored in the text field (and not number field of content table)">TEXT</span> '; }
                if ($v['flag'] & ITEM_FLAG_ANONYMOUS_EDITABLE ) { $flags .= '<span  title="anonymously added and thus anonymously editable (reset on every use of itemedit.php)">ANONYMEDITABLE</span> '; }
                $v['flag'] .= Txt::additionalInfo([$flags]);

            });
            echo GetHtmlTable($res, 'key', _m('Content table records for the item'));
        }

        echo '<h3>'. _m('History') .'</h3>';
        echo 'show '. ChangesMonitor::getHistoryLink($item_id);

        if ($res = DB_AA::select([], "SELECT LOWER(HEX(`item_id`)) as Item, '' as Status, '' as PubDate, '' as ExpDate, '' as LastEdit, '' as FieldName, `field_id`, `number`,`text`, `flag` FROM `content`", [['text',$long_id]])) {
            array_walk($res, static function (&$v, $k) {
                if (is_long_id($iid = $v['Item']) AND ($refitem = AA_Items::getItem(new zids($iid, 'l')))) {
                    $v['Item'] = Adminlink::itemLink($iid, $refitem->getSliceID());
                    $v['Status'] = $refitem->getStatusCode();
                    $v['PubDate']  .= ($tmp = (int)$refitem->getval('publish_date....')). Txt::additionalInfo([date('j.n.Y H:i:s', $tmp)]);
                    $v['ExpDate']  .= ($tmp = (int)$refitem->getval('expiry_date.....')). Txt::additionalInfo([date('j.n.Y H:i:s', $tmp)]);
                    $v['LastEdit'] .= ($tmp = (int)$refitem->getval('last_edit.......')). Txt::additionalInfo([date('j.n.Y H:i:s', $tmp), safe($refitem->getval('edited_by.......').' - '.perm_username($refitem->getval('edited_by.......')))]);

                    $islice = AA_Slice::getModule($refitem->getSliceID());
                    $v['FieldName'] = ($f=$islice?->getField($v['field_id'])) ? safe($f->getName()) : '-- invalid --';
                }
            });
            echo GetHtmlTable($res, 'key', _m('Referenced items'));
        }

        if ($res = DB_AA::select([], 'SELECT `id`, FROM_UNIXTIME(`time`) as Date, `user`, `type`, `selector`, `params` FROM `log`', [['selector',$long_id,'RLIKE']])) {
            echo GetHtmlTable(array_map([AA_Log::class, 'renderLogentryCells'], $res), 'key', _m('Log'));
        }
    }

    // Raw object dump - kept for debugging but collapsed so it doesn't drown the tables above.
    echo '<details class="aa-finder-raw"><summary><h3 style="display:inline">'. _m('AA_Item structure') .'</h3></summary><pre>';
    echo compact_print_r($item->getItemContent(), 0, true);   // compact HTML dump (self-escaping; long values collapsible)
    echo "</pre></details>";

    return (string)ob_get_clean();
}

/** Item info page body: friendly overview always; the technical block (collapsed) only when
 *  $full is true (superadmin). Single source for both the Item info page and the AA finder.
 *
 * @param AA_Item $item
 * @param string  $item_id
 * @param bool    $full   include the technical/raw section (superadmin)
 * @return string
 */
function GetItemInfoHtml($item, $item_id, bool $full): string
{
    $html  = GetItemSummaryHtml($item, $full);

    // Two compact digests side by side under the lede - the headline figures from the
    // History and Statistics pages, each linking on to its full page (for users who may open it).
    $history = GetItemHistoryBox($item, $item_id, $full);
    $stats   = GetItemStatsBox($item, $item_id, $full);
    if ($history !== '' || $stats !== '') {
        $html .= '<div class="aa-item-boxes">'
              .  ($history !== '' ? '<div>'.$history.'</div>' : '')
              .  ($stats   !== '' ? '<div>'.$stats.'</div>'   : '')
              .  '</div>';
    }

    if ($full) {
        $html .= '<details class="aa-item-technical"><summary><h3 style="display:inline">'. _m('Technical details') .'</h3></summary>'
              .  GetItemTechnicalHtml($item, $item_id)
              .  '</details>';
    }
    return '<div class="aa-iteminfo">'. $html .'</div>';
}

/** History digest box: the few most recent recorded changes (when / who / which fields). Each row
 *  links to that change on the full history page when the user may open it ($full). Renders nothing
 *  when the item has no recorded changes (no empty header).
 *
 * @param AA_Item $item
 * @param string  $item_id
 * @param bool    $full   user may open the dedicated history page (link the rows + footer)
 * @return string
 */
function GetItemHistoryBox($item, $item_id, bool $full): string
{
    $changes = ChangesMonitor::getChangeList($item_id);   // [change_id => ['time','user']], newest first
    if (!$changes) {
        return '';
    }
    $total  = count($changes);
    $recent = array_slice($changes, 0, 6, true);          // a few rows - enough to visually balance the Statistics box

    // selector => field name per recent change, one query for all of them. Audit columns AA stamps
    // on every save are kept apart so "Fields" shows the real edits, and only falls back to them
    // when a change touched nothing else (so the row is never blank).
    $audit_selectors = ['edited_by.......', 'last_edit.......'];
    $by_change       = [];
    if ($cids = array_keys($recent)) {
        $slice = AA_Slice::getModule($item->getSliceID());
        foreach (DB_AA::select([], 'SELECT `change_id`, `selector` FROM `change_record`', [['change_id', $cids]]) as $cr) {
            $sel  = (string)$cr['selector'];
            $name = ($slice && ($f = $slice->getField($sel))) ? $f->getName() : $sel;
            $bag  = in_array($sel, $audit_selectors, true) ? 'audit' : 'content';
            $by_change[$cr['change_id']][$bag][$sel] = $name;   // selector => name, dedup by selector
        }
    }

    $hist_url = get_admin_url('itemhistory.php', ['id' => $item_id]);
    $rows     = [[_m('When'), _m('Changed by'), _m('Fields')]];
    foreach ($recent as $cid => $c) {
        $bags  = $by_change[$cid] ?? [];
        $pairs = $bags['content'] ?? $bags['audit'] ?? [];   // selector=>name; audit only when nothing else
        $sels  = array_keys($pairs);
        $shown = array_slice($sels, 0, 3);
        $more  = count($sels) - count($shown);
        $names = array_map(static fn($s) => $pairs[$s], $shown);
        $more_html = $more > 0 ? ' <span class="aa-stat-item-sid">+'.(int)$more.'</span>' : '';
        if (!$shown) {
            $flds = '<span class="aa-stat-zero">&ndash;</span>';
        } elseif ($full) {
            // superadmins get the raw field ids with names as the hint (same formatting as the
            // Technical details / history pages)
            $flds = safe(implode(', ', $shown)) . $more_html . Txt::additionalInfo([safe(implode(', ', $names))]);
        } else {
            // editors see just the friendly field names
            $flds = safe(implode(', ', $names)) . $more_html;
        }
        $when  = safe(date('j.n.Y H:i', (int)$c['time']));
        // link the row to its entry on the full history page (anchor emitted by ChangesMonitor::display)
        $when  = $full ? a_href($hist_url.'#aa-ch-'.rawurlencode((string)$cid), $when) : $when;
        $rows[] = [$when, safe(perm_username($c['user'])), $flds];
    }

    $html = GetHtmlTable($rows, 'th', _m('History'));
    if ($full) {
        $html .= '<p class="aa-item-followups">'. a_href($hist_url,
                     _m('Full history').' ('.(int)$total.') &raquo;', ['class' => 'btn btn-l btn-secondary']) .'</p>';
    }
    return $html;
}

/** The reach figures a content editor cares about - total views, average per day, recent reach -
 *  plus, when the duration log is on, average response time and cache hit ratio. Returned as
 *  [label, value] pairs so the Item-info "Statistics" digest and the Statistics page KPI strip render
 *  identical numbers and labels (one source of truth). Empty array when the item has no recorded views.
 *
 * @param AA_Item $item
 * @return array<int, array{0:string,1:string}>
 */
function GetItemStatsRows($item): array
{
    $views = (int)$item->getval('display_count...');
    if ($views <= 0) {
        return [];
    }

    $pub = (int)$item->getval('publish_date....');
    $now = time();

    $int  = static fn($v): string => safe(number_format((int)$v, 0, '.', ' '));
    $sum  = \AA\Util\HitcounterDisplay::summaryForItem((int)$item->getval('short_id........'));
    $rows = [[_m('Total views'), $int($views)]];

    // Average / day over the span we actually have hit data for (so an item published years ago
    // isn't averaged down to ~0); falls back to "since published" when there is no measured span.
    $avg = null;
    if ($sum && $sum['first_t'] && $sum['last_t'] >= $sum['first_t']) {
        $span = max(1, (int)round(($sum['last_t'] - $sum['first_t']) / 86400));
        $avg  = $sum['total_hits'] / $span;
    } elseif ($pub > 0 && $pub < $now) {
        $avg = $views / max(1, ($now - $pub) / 86400);
    }
    if ($avg !== null) {
        if ($avg < 1) {
            $dec = 2;
        } elseif ($avg < 10) {
            $dec = 1;
        } else {
            $dec = 0;
        }
        $rows[] = [_m('Average / day'), safe(number_format($avg, $dec, '.', ' '))];
    }

    if ($sum) {
        $rows[] = [_m('Last 7 days'),  $int($sum['hits_7d'])];
        $rows[] = [_m('Last 30 days'), $int($sum['hits_30d'])];
        if ($sum['last_t']) {
            $rows[] = [_m('Last viewed'), safe(date('j.n.Y', $sum['last_t']))];
        }
        if ($sum['avg_ms'] !== null) {
            $ms     = (float)$sum['avg_ms'];
            $rows[] = [_m('Avg response'), safe(number_format($ms, $ms < 10 ? 1 : 0, '.', ' ')).' ms'];
        }
        if ($sum['cache_pct'] !== null) {
            $rows[] = [_m('Served from cache'), safe(number_format((float)$sum['cache_pct'], 0)).'%'];
        }
    }
    return $rows;
}

/** Reach (statistics) digest box for the Item info page. "Full statistics" follow-up for users who may
 *  open the stats page ($full). Renders nothing when the item has no recorded views (no empty header).
 *
 * @param AA_Item $item
 * @param string  $item_id
 * @param bool    $full   show the follow-up link to the dedicated statistics page
 * @return string
 */
function GetItemStatsBox($item, $item_id, bool $full): string
{
    $rows = GetItemStatsRows($item);
    if (!$rows) {
        return '';
    }
    $html = GetHtmlTable($rows, 'td', _m('Statistics'));
    if ($full) {
        $html .= '<p class="aa-item-followups">'. a_href(get_admin_url('itemstats.php', ['id' => $item_id]),
                                                         _m('Full statistics').' &raquo;', ['class' => 'btn btn-l btn-secondary']) .'</p>';
    }
    return $html;
}

/** Item statistics page body: total views plus a per-day hit table (reusing HitcounterDisplay),
 *  and a link to the full statistics dashboard scoped to this item.
 *
 * @param AA_Item $item
 * @param string  $item_id
 * @return string
 */
function GetItemStatsHtml($item, $item_id): string
{
    // KPI summary strip first (data-first): the headline figures, same numbers and labels as the
    // Item-info "Statistics" digest. The page <h1> already says "Statistics".
    $kpis = '';
    foreach (GetItemStatsRows($item) as $r) {
        $kpis .= '<div class="aa-kpi"><span class="aa-kpi-label">'. safe($r[0]) .'</span><span class="aa-kpi-val">'. $r[1] .'</span></div>';
    }
    $html = $kpis !== '' ? '<div class="aa-item-kpis">'. $kpis .'</div>' : '';

    // then the trend + per-day detail table + cache diagnostics (renderTable orders the hits chart
    // above the per-day table for a single item).
    $table = \AA\Util\HitcounterDisplay::renderTable(new zids($item_id), 'days');
    $html .= $table !== '' ? $table : '<p class="aa-empty-note">'. _m('No detailed view statistics have been recorded for this item yet.') .'</p>';

    $html .= '<p>'. a_href(get_admin_url('aa_statistics.php', ['items_code' => $item_id, 'for_module' => $item->getSliceID(), 'show' => 1]),
                           _m('Open full statistics').' &raquo;', ['class' => 'btn btn-l btn-secondary']) .'</p>';
    return $html;
}

/** classes for default values of the fields
 *  derived from  AA_Serializable in order to be able to factory from string
 *
 *  Usage: $aa_value = AA_Generator::factoryByString('dte:5000')->generate();
 *          $string   = AA_Generator::factoryByString('uid')->generate()->getValue();
*/


abstract class AA_Generator extends AA_Serializable implements AA\Util\NamedInterface {

    public static function name(): string        { return _m('');  }      // see NamedInterface
    public static function description(): string { return _m('');  }      // see NamedInterface

    /**
     * @return AA_Value
     */
    abstract function generate(): AA_Value;
}

/** AA_Generator_Now - current timestamp */
class AA_Generator_Now extends AA_Generator {

    /** Name of the component for selection */
    public static function name(): string { return _m("Now, i.e. current date");  }

    /** generate() - main function for generating the value */
    function generate(): AA_Value    { return new AA_Value(now()); }
}

/** AA_Generator_Never - the biggest date possible */
class AA_Generator_Never extends AA_Generator {

    /** Name of the component for selection */
    public static function name(): string { return _m("Never, i.e. maximum date");  }

    /** generate() - main function for generating the value */
    function generate(): AA_Value    { return new AA_Value(MAX_TIME_NEVER); }
}


/** AA_Generator_Uid - User ID */
class AA_Generator_Uid extends AA_Generator {

    /** Name of the component for selection */
    public static function name(): string { return _m("User ID");  }

    /** generate() - main function for generating the value */
    function generate(): AA_Value {
        return new AA_Value(AuthGetUid() ?: '9999999999');  // 9999999999 for anonymous
    }
}

/** AA_Generator_Log - Login name */
class AA_Generator_Log extends AA_Generator {

    /** Name of the component for selection */
    public static function name(): string { return _m("Login name");  }

    /** generate() - main function for generating the value */
    function generate(): AA_Value {
        return new AA_Value(AuthGetUname() ?: 'anonymous'); // "anonymous" for anonymous
    }
}

/** AA_Generator_Dte - Date + 'Parameter' days */
class AA_Generator_Dte extends AA_Generator {

    protected $plusdays;         // see getClassProperties

    /** Name of the component for selection */
    public static function name(): string {  return _m("Date + 'Parameter' days");  }

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    public static function getClassProperties(): array {
        return [             //           id         name                        type    multi  persist validator, required, help, morehelp, example
            'plusdays' => new AA_Property( 'plusdays',  _m("Number of days"), 'int', false, true, 'int', false, '', '', '365'),
        ];
    }

    /** generate() value of currrent timestamp */    // checks maximum date bounds
    function generate(): AA_Value    {  return new AA_Value(min(MAX_TIME_NEVER, mktime(0,0,0,date("m"),date("d")+(int)$this->plusdays,date("Y"))));  }
}

/** AA_Generator_Txt - Text from 'Parameter' */
class AA_Generator_Txt extends AA_Generator {

    protected $text;         // see getClassProperties

    /** Name of the component for selection */
    public static function name(): string {  return _m("Text from 'Parameter'");  }

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    public static function getClassProperties(): array {
        return [      //           id         name     type    multi  persist validator, required, help, morehelp, example
            'text' => new AA_Property( 'text',  _m("Text"), 'text' ),
        ];
    }

    /** generate() - main function for generating the value */
    function generate(): AA_Value {
        return strlen($this->text) ? new AA_Value($this->text) : new AA_Value();
    }
}

/** AA_Generator_Qte - only for backward complatibility. The same as Txt */
class AA_Generator_Qte extends AA_Generator_Txt {
    /** Name of the component for selection */
    public static function name(): string {  return _m('');  }
}




/** AA_Generator_Rnd - Random string */
class AA_Generator_Rnd extends AA_Generator {

    protected $length;         // see getClassProperties
    protected $checkfield;     // see getClassProperties
    protected $wheretocheck;   // see getClassProperties

    /** Name of the component for selection */
    public static function name(): string        {  return _m("Random string");  }
    /** Decription  of the component for selection */
    public static function description(): string {  return _m("Random alphanumeric [A-Z0-9] string.");  }

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    public static function getClassProperties(): array {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'length'       => new AA_Property( 'length      ',  _m("String length"),  'int',    false, true, 'int', false, '', '', '5'),
            'checkfield'   => new AA_Property( 'checkfield  ',  _m("Field to check"), 'string', false, true, 'field', false, _m("If you need a unique code, you must send the field ID, the function will then look into this field to ensure uniqueness."), '', 'unspecified.....'),
            'wheretocheck' => new AA_Property( 'wheretocheck',  _m("Slice only"),     'bool',   false, true, 'bool', false, _m("Do you want to check for uniqueness this slice only or all slices?")),
        ];
    }

    /** generate() - main function for generating the value */
    function generate(): AA_Value {
        global $slice_id;

        $len        = $this->length ?: 5;   // default is 5
        $field_id   = $this->checkfield;
        $slice_only = is_numeric($this->wheretocheck) ? $this->wheretocheck : true;

        if (strlen($field_id) != 16) {
            return new AA_Value(gensalt($len));
        }

        $rec = false;
        do {
            $randstring = gensalt($len);
            if ($slice_only) {
                $rec = DB_AA::select1('', 'SELECT item_id FROM content INNER JOIN item ON content.item_id = item.id', [
                    ['item.slice_id', $slice_id, 'l'],
                    ['field_id', $field_id],
                    ['text', $randstring],
                ]);
            } else {
                $rec = DB_AA::select1('', 'SELECT item_id FROM content', [
                    ['field_id', $field_id],
                    ['text', $randstring],
                ]);
            }
        } while ($rec);
        return new AA_Value($randstring);
    }
}

/** AA_Generator_Variable - AA Expression */
class AA_Generator_Variable extends AA_Generator {

    protected $text;         // see getClassProperties

    /** Name of the component for selection */
    public static function name(): string        {  return _m("AA Expression");  }
    /** Decription  of the component for selection */
    public static function description(): string {  return _m("any text with possible {AA expressions} like: {date:Y}");  }

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    public static function getClassProperties(): array {
        return [      //           id         name     type    multi  persist validator, required, help, morehelp, example
            'text' => new AA_Property( 'text',  _m("Text"), 'text', false, true, 'text', false, '', '', '{date:Y}'),
        ];
    }

    /** getClassProperties function of AA_Serializable  */
    function generate(): AA_Value {
        return new AA_Value(AA::Un($this->text));
    }
}

/** AA_Generator_Mul - Multivalues */
class AA_Generator_Mul extends AA_Generator {

    protected $text;               // see getClassProperties
    protected $delimiter;          // see getClassProperties

    /** Name of the component for selection */
    public static function name(): string        {  return _m("Multivalue");  }

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    public static function getClassProperties(): array {
        return [      //           id         name     type                  multi  persist validator, required, help, morehelp, example
            'text'      => new AA_Property( 'text',       _m("Text"),      'text',   false, true, 'text', false, '', '', 'red|green|blue'),
            'delimiter' => new AA_Property( 'delimiter',  _m("Delimiter"), 'string', false, true, 'text', false, '', '', '|'),
        ];
    }

    /** getClassProperties function of AA_Serializable  */
    function generate(): AA_Value {
        return new AA_Value(array_filter(explode(($this->delimiter ?: '|'), $this->text),'strlen'));
    }
}

// ----------------------- insert functions ------------------------------------
/** classes for insert data to the database field
 *  derived from  AA_Serializable in order to be able to factory from string
 *
 *  Usage: $aa_value = AA_Generator::factoryByString('dte:5000')->generate();
 *          $string   = AA_Generator::factoryByString('uid')->generate()->getValue();
 * @method  static AA_Inserter factoryByString($string)
 */
abstract class AA_Inserter extends AA_Serializable implements NamedInterface {

    /** @var AA_Field */
    protected $field;         // additional variable common to all AA_Inserters - not filled by standard factoryByString

    /** @var AA_Value[] stores array of the fields which should be also changed  */
    private array $change_fields = [];

    public static function name(): string        { return _m('');  }      // see NamedInterface
    public static function description(): string { return _m('');  }      // see NamedInterface

    // __construct()  // we use AA_Serializable::factoryByString + post initiation for $field

    /** preferred way of construction
     * @param AA_Field $field
     * @return AA_Inserter
     */
    public static function factoryByField($field) : AA_Inserter {
        $definition = $field->getProperty('input_insert_func');
        $inserter = AA_Inserter::factoryByString($definition);
        if (!$inserter) {
            warn("Can't create AA_Inserter from <b>$definition</b>");
            $inserter = AA_Inserter::factoryByString('nul');
        }
        $inserter->setField($field);
        return $inserter;
    }

    /**
     * @param AA_Field $field
     * @return AA_Inserter
     */
    public function setField($field): static
    {
        $this->field = $field;
        return $this;
    }

    /** deletes content for field in database
     * @param $item_id
     * @param $field_id
     */
    protected function _clear_field($item_id, $field_id): void
    {
        // delete content just for displayed fields
        DB_AA::delete('content', [['item_id', $item_id, 'l'], ['field_id', $field_id]]);
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated(): bool
    {
        return false;
    }

    /** used for additional changes in other fields during item storage */
    protected function addToChangedFields(string $field_id, $value, int $order, int $flag): void
    {
        if ( array_key_exists($field_id, $this->change_fields) ) {
            $this->change_fields[$field_id][$order] = $value;   // using array access to AA_Value
        } else {
            $this->change_fields[$field_id] = new AA_Value( [$order => $value], $flag );
        }
    }

    public function executeMulti(AA_Field_Writer $field_writer, string $item_id, AA_Value $cont): void
    {
        global $err;

        // this check is maybe no necessary
        $slice = $field_writer->getSlice();
        if (!$slice || !$slice->isValid()) {
            $err['field_writer'] = _m("Slice with id '%1' is not valid.", [$field_writer->getSliceId()]);
            return;
        }

        // update content table or fill item_varset
        $flag = $cont->getFlag();
        $this->change_fields = [];
        foreach ($cont as $numkey => $v) {
            $this->execute($field_writer, $item_id, $v, (int)$numkey, $flag);
            // do not store multiple values if field is not marked as multiple
            // if ( !$f["multiple"]!=1 ) continue;   // ERRORNOUS
        }
        // for password backup, thumbnail...
        if ($this->change_fields) {
            foreach ($this->change_fields as $fid => $value) {
                if ($fld = $field_writer->getSlice()?->getField($fid)) {
                    $this->_clear_field($item_id, $fid);
                    AA_Inserter::factoryByField($fld)->executeMulti($field_writer, $item_id, $value);
                }
            }
        }
        $this->change_fields = []; // just to clean it - it was executed
    }

    /**
     * @param  AA_Field_Writer  $fieldwriter
     * @param  string           $item_id
     * @param  string|int       $value
     * @param  int              $order
     * @param  int              $flag
     */
    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {

        $varset = new Cvarset();
        // if input function is 'selectbox with presets' and add2connstant flag is set,
        // store filled value to constants
        $fnc = ParseFnc($this->field->getProperty("input_show_func"));   // input show function
        if ($fnc && ($fnc['fnc']==='pre')) {
            // get add2constant and constgroup (other parameters are irrelevant in here)
            [$constgroup, $maxlength, $fieldsize,$slice_field, $usevalue, $adding, $secondfield, $add2constant] = explode(':', $fnc['param']);
            // add2constant is used in $this->_store - adds new value to constant table
            if ($add2constant && $constgroup && (!str_starts_with($constgroup, "#sLiCe-")) && trim($value) !== '') {
                $db = getDB();
                // does this constant already exist?
                $constgroup = quote($constgroup);
                $constvalue = quote($value);
                $SQL = "SELECT * FROM constant WHERE group_id='$constgroup' AND value='$constvalue'";
                $db->query($SQL);
                if (!$db->next_record()) {
                    // constant is not in database yet => add it

                    // first we have to get max priority in order we can add new constant
                    // with bigger number
                    $SQL = "SELECT max(pri) as max_pri FROM constant WHERE group_id='$constgroup'";
                    $db->query($SQL);
                    $new_pri = ($db->next_record() ? $db->f('max_pri') + 10 : 1000);

                    // we have priority - we can add
                    $varset->set("name",  $constvalue, 'quoted');
                    $varset->set("value", $constvalue, 'quoted');
                    $varset->set("pri",   $new_pri, "number");
                    $varset->set("id", new_id(), "unpacked" );
                    $varset->set("group_id", $constgroup, 'quoted' );
                    $varset->doInsert('constant');
                }
                freeDB($db);
            }
        }

        if ($fid = $this->field->getProperty("in_item_tbl")) {
            // Mitra thinks that this might want to be 'expiry_date.....' ...
            // ... which is not correct because in 'in_item_tbl' database field
            // we store REAL database field names from aadb.item table (honzam)

            if (($fid === 'expiry_date') && (date("Hi",($v = (int)$value)) == "0000")) {
                // $value += 86399;
                // if time is not specified, take end of day 23:59:59
                // !!it is not working for daylight saving change days !!!
                $value = mktime(23,59,59,date("m",$v),date("d",$v),date("Y",$v));
            }
            // field in item table  - check, if numeric...
            if (in_array($fid, ['short_id','status_code','post_date','publish_date','expiry_date','highlight','last_edit','display_count','disc_count','disc_app','moved2active'])) {
                $fieldwriter->addItemField($fid, "number", (int)$value);
            } else {
                $fieldwriter->addItemField($fid, "text", $value);
            }
            return;
        }

        // all the fields are already deleted. Now the isSafeStored field is really updated, so we can finally delete it
        $delete = $this->field->isSafeStored();  // safe stored fields are not erased, yet

        // field in content table (function defined in util.php since we need it for display count
        StoreToContent($item_id, $this->field->getId(), $this->field->getProperty("text_stored"), $value, $order, $flag, $delete);
    }
}

/** AA_Inserter_Qte - Just insert string (so quote it before storing to the DB) */
class AA_Inserter_Qte extends AA_Inserter {
    public static function name(): string        { return _m("Text = don't modify");  }             // see NamedInterface
    public static function description(): string { return _m("Does not modify the value.");  }      // see NamedInterface
}

/** AA_Inserter_Dte - Just insert string (so quote it before storing to the DB) */
class AA_Inserter_Dte extends AA_Inserter {
    public static function name(): string        { return _m("Date = don't modify");  }             // see NamedInterface
    public static function description(): string { return _m("Does not modify the value (just like Text), but it is better to separate it for future usage.");  }      // see NamedInterface
}

// not used. eventualy the same as Qte
// class AA_Inserter_Cns extends AA_Inserter {}

/** AA_Inserter_Num - Just insert string (so quote it before storing to the DB) */
class AA_Inserter_Num extends AA_Inserter {
    public static function name(): string        { return _m("Number = don't modify");  }             // see NamedInterface
    public static function description(): string { return _m("Does not modify the value (just like Text), but it is better to separate it for future usage.");  }      // see NamedInterface
}

/** AA_Inserter_Boo - 0 | 1 */
class AA_Inserter_Boo extends AA_Inserter {
    public static function name(): string        { return _m("Boolean = store 0 or 1");  }             // see NamedInterface
    public static function description(): string { return _m('');  }      // see NamedInterface

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {
        parent::execute($fieldwriter, $item_id, ($value ? 1 : 0), $order, $flag);
    }
}

/** Float - including conversion of 123456789012345678901234567890 to 1.2345678901235E+29 */
class AA_Inserter_Flo extends AA_Inserter {
    public static function name(): string        { return _m("Float");  }             // see NamedInterface
    public static function description(): string { return _m('Real float - could convert big numbers to scientific notation (1.23E+29)');  }      // see NamedInterface

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {
        parent::execute($fieldwriter, $item_id, PhpFloat($value), $order, $flag);   // converts also 12345678901234567890 to 1.2345678901235E+29
    }
}

/** AA_Inserter_Dec */
class AA_Inserter_Dec extends AA_Inserter {
    public static function name(): string        { return _m("Decimal");  }             // see NamedInterface
    public static function description(): string { return _m("The same as Float, but does not convert big numbers to  scientific notation (1.23E+29)");  }      // see NamedInterface

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {
        parent::execute($fieldwriter, $item_id, str_replace(',', '.', $value), $order, $flag); // Phpfloat does not work for long integers like 12345678901234567890
    }
}

/** AA_Inserter_Trm - trim and normalize */
class AA_Inserter_Trm extends AA_Inserter {
    public static function name(): string        { return _m("Trim");  }             // see NamedInterface
    public static function description(): string { return _m("Remove whitespaces from beginning and end and also normalize UTF (NFC)");  }      // see NamedInterface

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {
        $value = Langs::isUTF() ? aa_trim(Normalizer::normalize($value)) : aa_trim($value);
        parent::execute($fieldwriter, $item_id, $value, $order, $flag);
    }
}

/** AA_Inserter_Ids - Just insert string (so quote it before storing to the DB) */
class AA_Inserter_Ids extends AA_Inserter {
    public static function name(): string        { return _m("Item IDs");  }             // see NamedInterface
    public static function description(): string { return _m('');  }      // see NamedInterface

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {

        $fid         = $this->field->getId();
        $text_stored = $this->field->getProperty("text_stored");

        $add_mode = substr($value,0,1);          // x=add, y=add mutual, z=add backward
        if (str_contains('txyz', $add_mode)) {
            $value = substr($value,1);  // remove x, y or z
        }
        switch( $add_mode ) {
            case 't':  // t for tags - it could be normal item ID or new Tag
                $v = $value;
                if ( !is_long_id($v)) {
                    $fnc = ParseFnc($this->field->getProperty("input_show_func"));   // input show function
                    if ($fnc && ($fnc['fnc']==='tag')) {
                        // get add2constant and constgroup (other parameters are irrelevant in here)
                        [$constgroup, $others] = explode(':', $fnc['param']);
                        // add2constant is used in $this->_store - adds new value to constant table
                        if ((str_starts_with($constgroup, "#sLiCe-")) && trim($v) !== '') {
                            $sid = substr($constgroup,7);
                            $content4id = new ItemContent();
                            $content4id->setItemID($new_id=new_id());
                            $content4id->setSliceID($sid);
                            $content4id->setAaValue('headline........', new AA_Value($v));
                            $content4id->complete4Insert();
                            $content4id->storeItem('insert');
                            $value = $new_id;
                        }
                    }
                }
                parent::execute($fieldwriter, $item_id, $value, $order, $flag);
                break;
            case 'y':   // y means 2way related item id - we have to store it for both
                parent::execute($fieldwriter, $item_id, $value, $order, $flag);
            // !!!!! there is no break or return - CONTINUE with 'z' case !!!!!

            case 'z':   // z means backward related item id - store it only backward
                // add reverse related
                $reverse_id = $value;
                $value      = $item_id;

                // mimo added
                // get rid of empty dummy relations (text='')
                // this is only a problem for text content
                if ($text_stored) {
                    DB_AA::delete('content', [['item_id', $reverse_id, 'l'], ['field_id', $fid], ['text', '']]);
                }
                // is reverse relation already set?
                if (!DB_AA::test('content', [['item_id',$reverse_id, 'l'], ['field_id', $fid], [($text_stored ? "text" : "number"), $value]])) { // not found
                    parent::execute($fieldwriter, $reverse_id, $value, $order, $flag);
                }
                break;

            case 'x':   // just filling character - remove it
            default:
                parent::execute($fieldwriter, $item_id, $value, $order, $flag);
        }
    }
}


/** AA_Inserter_Uid - User ID = always store current user ID */
class AA_Inserter_Uid extends AA_Inserter {
    public static function name(): string        { return _m("User ID = always store current user ID");  }             // see NamedInterface
    public static function description(): string { return _m('Inserts the identity of the current user, no matter what the user sets.');  }      // see NamedInterface

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {
        $val = ($value and IsSuperadmin()) ? $value
                                           : ((AuthGetUid() ?: (strlen($value) ? $value : '9999999999')));
        parent::execute($fieldwriter, $item_id, $val, $order, $flag);
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated(): bool
    {
        return true;
    }
}

/** AA_Inserter_Log - Login name */
class AA_Inserter_Log extends AA_Inserter {
    public static function name(): string        { return _m("Login name");  }             // see NamedInterface
    public static function description(): string { return _m('');  }      // see NamedInterface

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {
        // if not $auth, it is from anonymous posting
        $val = (AuthGetUname() ?: (strlen($value) ? $value : 'anonymous'));
        parent::execute($fieldwriter, $item_id, $val, $order, $flag);
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated(): bool
    {
        return true;
    }
}

/** AA_Inserter_Now - Now = always store current time */
class AA_Inserter_Now extends AA_Inserter {
    public static function name(): string        { return _m("Now = always store current time");  }             // see NamedInterface
    public static function description(): string { return _m('Inserts the current time, no matter what the user sets.');  }      // see NamedInterface

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {
        parent::execute($fieldwriter, $item_id, time(), $order, $flag);
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated(): bool
    {
        return true;
    }
}


/** AA_Inserter_Co2 - Computed field for INSERT/UPDATE */
class AA_Inserter_Co2 extends AA_Inserter {
    // we store it to the database at this time, even if it is probably
    // not final value for this field - we probably recompute this value later
    // in storeItem method, but we should compute with this new value there,
    // so we need to store it, right now
    // (this is the only case for computed field SHOWN IN INPUTFORM)

    protected $code;                   // see getClassProperties
    protected $code_update;            // see getClassProperties
    protected $delimiter;              // see getClassProperties
    protected $recompute;              // see getClassProperties

    public static function name(): string        { return _m("Computed field for INSERT/UPDATE");  }             // see NamedInterface
    public static function description(): string { return _m("The field is the result of expression written in \"Code for unaliasing\". It is a good solution for all values, which could be precomputed, since its computation on item-show-time would be slow. Yes, you can use {view...}, {include...}, {switch...} here");  }      // see NamedInterface

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    public static function getClassProperties():array {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'code'       => new AA_Property( 'code',        _m("Code for unaliasing (INSERT)"),'text', false, true, 'text', false, _m("There you can write any string. The string will be unaliased on item store, so you can use any {...} construct as well as field aliases here"), '', '({publish_date....}) {headline........}'),
            'code_update'=> new AA_Property( 'code_update', _m("Code for unaliasing (UPDATE)"),'text', false, true, 'text', false, _m("The same as above, but just for UPDATE operation. If unfilled, the value of the field stays unchanged"), '', ''),
            'delimiter'  => new AA_Property( 'delimiter',   _m("Multivalue delimiter"),        'text', false, true, 'text', false, _m("Character or string, which will split the computed string into multiple values (the same field)"), '', '|'),
            'recompute'  => new AA_Property( 'recompute',   _m("Recompute every"),             'text', false, true, 'text', false, "minute|hour|day|week|month "._m("The field will be recomputed every minute, hour, ... (using UPDATE code above). The times are not exact - the minute means something 1-10 minutes. If not filled, the field will be recomputed only on insert/update of the item."), '', ''),
        ];
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated(): bool
    {
        return true;  // @todo - maybe better check - it could be generated, but who knows
    }
}

/** AA_Inserter_Com - Computed field*/
class AA_Inserter_Com extends AA_Inserter {

    protected $code;                   // see getClassProperties

    public static function name(): string        { return _m("Computed field");  }             // see NamedInterface
    public static function description(): string { return _m('The field is the result of expression written in \"Code for unaliasing\". It is good solution for all values, which could be precomputed, since its computation on item-show-time would be slow. Yes, you can use {view...}, {include...}, {switch...} here');  }      // see NamedInterface

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    public static function getClassProperties():array {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'code'   => new AA_Property( 'code',        _m("Code for unaliasing (INSERT+UPDATE)"),    'text', false, true, 'text', false, _m("There you can write any string. The string will be unaliased on item store, so you can use any {...} construct as well as field aliases here"), '', '({publish_date....}) {headline........}'),
        ];
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated(): bool
    {
        return true;  // @todo - maybe better check - it could be generated, but who knows
    }
}

/** AA_Inserter_Seo - SEO Name -  */
class AA_Inserter_Seo extends AA_Inserter {

    // the value computed in updateComputedFields

    protected $code;                   // see getClassProperties

    public static function name(): string        { return _m("SEO Name");  }             // see NamedInterface
    public static function description(): string { return _m("If the field is not filled, compute seoname from _#HEADLINE alias (or another if specified) unique to all slices in sitemodules, where the slice is mentioned in \"Uses slices\" settings. Basically the SEO Name alias is shortcut for computed field with {ifset:{seo.............}:_#1:{seoname:{_#HEADLINE}:all:CHARSET}}");  }      // see NamedInterface

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    public static function getClassProperties():array {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'code'   => new AA_Property( 'code', _m("Alias to compute from"), 'text', false, true, 'text', false, _m("if not specified, the _#HEADLINE is used"), '', '_#HEADLINE'),
        ];
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated(): bool
    {
        return true;
    }
}

/** AA_Inserter_Seq */
class AA_Inserter_Seq extends AA_Inserter {
    public static function name(): string        { return _m("Unique number for this field (from 1,..)");  }             // see NamedInterface
    public static function description(): string { return _m('Unique number in sequence 1,2,3,...');  }      // see NamedInterface
}

/** AA_Inserter_Fil  - File = uploaded file
 */
// There are three cases here
// 1: uploaded - overwrites any existing value, does resampling etc
// 2: file name left over from existing record, just stores the value
// 3: newly entered URL, this is not distinguishable from case //2 so
//    its just stored, and no thumbnails etc generated, this could be
//    fixed later (mtira)
// in $additional are fields
class AA_Inserter_Fil extends AA_Inserter {

    protected $type;                   // see getClassProperties
    protected $width;                  // see getClassProperties
    protected $height;                 // see getClassProperties
    protected $otherfield;             // see getClassProperties
    protected $replacemethod;          // see getClassProperties
    protected $exact;                  // see getClassProperties
    protected $nametemplate;           // see getClassProperties

    public static function name(): string        { return _m("File = uploaded file");  }             // see NamedInterface
    public static function description(): string { return _m('Stores the uploaded file and a link to it, parameters only apply if type is image/something.');  }      // see NamedInterface

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    public static function getClassProperties():array {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'type'          => new AA_Property( 'type',         _m("Mime types accepted"), 'text', false, true, 'text', false, _m('Only files of matching mime types will be accepted'), '', 'image/*'),
            'width'         => new AA_Property( 'width',        _m("Maximum image width"), 'int',  false, true, 'int',  false, '', '', '800'),
            'height'        => new AA_Property( 'height',       _m("Maximum image height"),'int',  false, true, 'int',  false, _m("The image will be resampled to be within these limits, while retaining aspect ratio."), '', '600'),
            'otherfield'    => new AA_Property( 'otherfield',   _m("Other fields"),        'text', false, true, 'text', false, _m("List of other fields to receive this image, separated by ##. Does not work well for multi uploads."), '', 'image..........2'),
            'replacemethod' => new AA_Property( 'replacemethod',_m("Upload policy"),       'text', false, true, 'text', false, _m("new | overwrite | backup<br>This parameter controls what to do if uploaded file alredy exists:<br>new - AA creates new filename (by adding _x postfix) and store it with this new name (default)<br>overwrite - the old file of the same name is overwritten<br>backup - the old file is copied to new (non-existing) file and current file is stored with current name.<br>In all cases the filename is escaped, so any non-word characters will be replaced by an underscore."), '', 'new'),
            'exact'         => new AA_Property( 'exact',        _m("Exact dimensions"),    'bool', false, true, 'bool', false, _m("If set to 1 the image will be downsized exactly to the specified dimensions (and cropped if needed). Default is 0 or empty: Maintain aspect ratio while resizing the image."), '', '1'),
            'nametemplate'  => new AA_Property( 'nametemplate', _m("Filename template"),   'text', false, true, 'text', false, _m("Renames file according to template. You can use AA expressions as well as special alias _#name and _#ext or _#full (file name, extension and fullname). The item aliases in AA expressions do not work on INSERT (item does not exist at time of file write). They will work on UPDATE."), '', 'file-_#SITEM_ID-_#name._#ext'),
        ];
    }

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {
        global $err;

        $fid = $this->field->getId();

        $mode = 'oldval';
        if (str_starts_with($value, 'AA_UPLOAD:')) {
            // newer - widget approach - the uploaded file is encoded into the value
            // and prefixed with "AA_UPLOAD:" constant  - AA_UPLOAD:aalogo.svg:aalogo.svg:image/svg+xml:/tmp/php4e7YxF:0:5266
            //$up_file = array_combine(['aa_const', 'name', 'type', 'tmp_name', 'error', 'size'], ParamExplode($value));  // there is new full_path member in php 8.1
            $up_file = unserialize(substr($value, 10), ['allowed_classes' => false]);
            if ($up_file['name']=='') {
                $value = '';
            }
            $mode = 'upload';
        } elseif (str_starts_with($value, 'AA_UPTHUMB:')) {
            // thumb - the value is prefixed with "AA_UPTHUMB:" constant - nothing to do right now
            $mode = 'thumb';
        } else {
            // old version of input form
            $up_file = $_FILES["v".unpack_id($fid)."x"];
            $mode = 'upload';
        }

        if (($mode==='upload' && (!$up_file['name'] || ($up_file['name']==='none'))) || ($fieldwriter->getContext() === 'feed')) { // no upload - store old value?
            // store specified file URL if nothing was uploaded
            parent::execute($fieldwriter, $item_id, $value, $order, $flag);
            return;
        }

        if (!($slice = $fieldwriter->getSlice())) {
            return;
        }

        if ($mode === 'upload') {
            if (($dest_file = $this->uploadFile($up_file, $item_id, $slice, $fid)) === false) {   // error
                $err[$fid] = Files::lastErrMsg();
                return;
            }

            $resampled_name = $dest_file;
        } else { // thumb
            $dest_file = ParamExplode($value)[1];
            $resampled_name = Files::generateUnusedFilename($dest_file);
        }

        // ---------------------------------------------------------------------
        // Create thumbnails (image miniature) into fields identified in this
        // field's parameters if file type is supported by GD library.

        // This has been considerable simplified, by making ResampleImage
        // return true for unsupported types IF they are already small enough
        // and also making ResampleImage copy the files if small enough

        if ($e = Imgfunc::resampleImage($dest_file, $resampled_name, $this->width, $this->height, $this->exact)) {
            $err[$fid] = $e;
            return;
        }

        if ($other_fields = $this->getOtherFields($slice)) {
            foreach ($other_fields as $fid) {
                $this->addToChangedFields($fid, ParamImplode(['AA_UPTHUMB',$dest_file]), $order, $flag);
            }
        }

        $value = $slice->getUrlFromPath($resampled_name);

        // store link to uploaded file
        parent::execute($fieldwriter, $item_id, $value, $order, $flag);
    }

    /**
     * @return false|string
     */
    protected function uploadFile(array $up_file, $item_id, ?AA_Module $slice, $fid): false|string
    {
        // determine filename ----
        $filename = null;
        if (strlen($this->nametemplate)) {
            $template = str_replace(['_#name', '_#ext', '_#full'], [
                pathinfo($up_file['name'], PATHINFO_FILENAME),
                pathinfo($up_file['name'], PATHINFO_EXTENSION),
                pathinfo($up_file['name'], PATHINFO_BASENAME),
            ], $this->nametemplate);
            if ($item_id and ($item = AA_Items::getItem(new zids($item_id)))) {
                $filename = AA::Un($template, '', $item);
            } else {
                $filename = AA::Un($template);
            }
        }

        return Files::uploadFile($up_file, Files::destinationDir($slice), $this->type, $this->replacemethod, $filename);
    }


//    public function execute_thumb($fieldwriter, $item_id, $value, $order, $flag) {
//        global $err;
//
//        $fid         = $this->field->getId();
//        $dest_file = $value;
//        $resampled_name = Files::generateUnusedFilename($dest_file);
//        $slice = AA_Slice::getModule($fieldwriter->getSliceId());
//
//        // ---------------------------------------------------------------------
//        // Create thumbnails (image miniature) into fields identified in this
//        // field's parameters if file type is supported by GD library.
//
//        // This has been considerable simplified, by making ResampleImage
//        // return true for unsupported types IF they are already small enough
//        // and also making ResampleImage copy the files if small enough
//
//        if ($e = Imgfunc::resampleImage($dest_file, $resampled_name, $this->width, $this->height, $this->exact)) {
//            $err[$fid] = $e;
//            return [];
//        }
//
//        if ($thumb_inserters = $this->get_thumb_inserters($slice)) {
//            $value = $resampled_name;
//            foreach ($thumb_inserters as $thumb_ins) {
//                // the content of the field is already deleted in clean_updated_fields();
//                // $this->_clear_field($item_id, $fid);
//                $thumb_ins->execute_thumb($fieldwriter, $item_id, $value, $order, $flag);
//            }
//        }
//        // store link to uploaded file
//        $value = $slice->getUrlFromPath($resampled_name);
//        parent::execute($fieldwriter, $item_id, $value, $order, $flag);
//    }

    private function getOtherFields(AA_Slice $slice): array {
        $ret = [];
        $potentional_fields = $this->field->getInsertOtherFields();

        foreach ($potentional_fields as $fid) {
            if (($fnc = $slice->getField($fid)?->getProperty('input_insert_func')) && str_starts_with($fnc, 'fil')) {
                $ret[] = $fid;
            } else {
                warn("Insert function for field '$fid' for thumbnail of '{$this->field->getId()}' is not a File Inserter.");
            }
        }
        return $ret;
    }
}



/** AA_Inserter_Pwd - Password and Change Password */
class AA_Inserter_Pwd extends AA_Inserter {

    protected $bck_field;                   // see getClassProperties
    protected $crypt_phrase;            // see getClassProperties

    public static function name(): string        { return _m("Password and Change Password");  }             // see NamedInterface
    public static function description(): string { return _m('Stores value from a "Password and Change Password" field type. First proves the new password matches the retyped new password, and if so, encrypts the new password and stores it.');  }      // see NamedInterface

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    public static function getClassProperties():array {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'bck_field'    => new AA_Property( 'bck_field',    _m("Copy of password"), 'text', false, true, 'field', false, _m("Field_id, where you want to store the copy of the password. Sometimes useful if you want to allow people to get his/her old password. It is strongly recommended to not store it in plaintext and encrypt it. See second parameter."), '', 'password.......1'),
            'crypt_phrase' => new AA_Property( 'crypt_phrase', _m("Encrypt phrase"),   'text',  false, true, 'text', false, _m("If you specify the \"Copy of password\" field, it is recommended to encrypt the password in the database. Fill there any string. If you then need the password, you can decrypt it from the field by using the same phrase - {decrypt:{password.......1}:Some Phrase}"), '', 'Some Phrase'),
        ];
    }

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {
        [$aa_const, $password] = ParamExplode($value);

        if ($aa_const === 'AA_PASSWD') {
            if ($this->bck_field && $fieldwriter->getSlice()?->isField($this->bck_field)) {
                // $password is_a decrypted here
                $backup = $this->crypt_phrase ? StrExp('encrypt', [$password, $this->crypt_phrase]) : $password;
                // store backup value to specified field
                $this->addToChangedFields($this->bck_field, $backup, $order, $flag);
            }
            $value = AA_Perm::cryptPwd($password);
        } elseif ($aa_const === 'AA_PASSWD_CRYPTED') {
            // this is the only case if you are updating the item and you want to left the password the same
            $value = $password;
        } else {
            $value = AA_Perm::cryptPwd($value);
        }
        parent::execute($fieldwriter, $item_id, $value, $order, $flag);
    }
}

/** AA_Inserter_2fa - 2FA Secret */
class AA_Inserter_2fa extends AA_Inserter {

    protected $crypt_phrase;            // see getClassProperties

    public static function name(): string        { return _m("2FA Secret");  }             // see NamedInterface
    public static function description(): string { return _m('Generates and stores secret for Two-factor authentication, possibly encrypted by password');  }      // see NamedInterface

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    public static function getClassProperties():array  {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
                             'crypt_phrase' => new AA_Property( 'crypt_phrase',  _m("Encrypt phrase"), 'text', false, true, 'text', false, _m('Password to be applied for encrypting this field value. Could contain AA expressions or aliases (for user item in Reader Management slice)'), '', 'SomeSecret784!*'),
        ];
    }

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {
        [$aa_const, $username, $password, $code2fa] = ParamExplode($value);

        if ($aa_const === 'AA_2FASECRET') {
            if ($username === '' || !($uid = AA::$perm->authenticateUsername($username, $password, $code2fa)) || ($uid != AuthGetUid())) {
                return;
            }
            $ga = new PHPGangsta_GoogleAuthenticator();
            $secret = $ga->createSecret();

            $this->crypt_phrase = trim($this->crypt_phrase);

            if ($this->crypt_phrase !== '') {
                if (is_long_id($uid) && ($item = AA_Items::getItem(new zids($uid)))) {
                    $phrase = AA::Un($this->crypt_phrase,'',$item);
                } else {
                    $phrase = AA::Un($this->crypt_phrase);
                }
                if ($phrase = trim($phrase)) {
                    $secret = StrExp('encrypt', [$secret, $phrase]);
                }
            }

            // $this->_clear_field($item_id, $this->field->getId());  // I think it is not necessary - safeFields will be deleted in execute, others are already deleted
            $value = $secret;
            parent::execute($fieldwriter, $item_id, $value, $order, $flag);
        }
        // nothing stored. The field should be safefield, so not cleared
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated(): bool
    {
        return true;
    }
}

/** AA_Inserter_Unq - not tested, not used, yet*/
class AA_Inserter_Unq extends AA_Inserter {
    public static function name(): string        { return _m("");  }             // see NamedInterface
    public static function description(): string { return _m('');  }      // see NamedInterface

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void
    {
        parent::execute($fieldwriter, $item_id, StrExp('finduniq', [$value, $this->field->getId(), empty($unique_slices) ? $fieldwriter->getSliceId() : $unique_slices, $item_id]), $order, $flag);
    }
}

/** AA_Inserter_Null - insert nothing - not used */
class AA_Inserter_Nul extends AA_Inserter {
    public static function name(): string        { return _m("");  }             // see NamedInterface
    public static function description(): string { return _m('');  }      // see NamedInterface

    public function execute(AA_Field_Writer $fieldwriter, $item_id, $value, int $order = 0, int $flag = 0): void {}

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated(): bool
    {
        return true;
    }
}

// -----------------------------------------------------------------------------


class AA_Field_Writer {

    /** @var Cvarset  */
    protected Cvarset $item_varset;
    protected string $slice_id;
    protected string $context;

    /**
     * AA_Field_Writer constructor.
     * @param string $slice_id - long slice id - it is optional for backward compatibility, but should be provided
     *                           in order file-upload and uniques works...
     */
    function __construct(string $slice_id, string $context='') {
        $this->slice_id    = $slice_id;
        $this->context     = $context;
        $this->item_varset = new Cvarset;
    }

    /** The content fields are already written, but the fields in item table still probably waits - get them for update
      * @return Cvarset
      */
    public function getItemVarset(): Cvarset
    {
        return $this->item_varset;
    }

    /** Adds item field */
    public function addItemField($varname, $type, $value): void
    {
        $this->item_varset->add($varname, $type, $value);
    }

    /**
     * @return null|string
     */
    public function getSliceId(): ?string
    {
        return $this->slice_id;
    }

    /**
     */
    public function getSlice(): ?AA_Slice {
        static $slice = '';
        if ($slice === '') {
            $slice = AA_Slice::getModule($this->slice_id);
        }
        return $slice;
    }



    public function getContext(): string {
        return $this->context;
    }
}

// ----------------------- show functions --------------------------------------
// moved to formutil into AA_Inputfield class (formutil.php)
// -----------------------------------------------------------------------------
/**
 * @param  int         $fieldflag
 * @param  AA_Field    $field
 * @param  AA_Profile  $profile
 *
 * @return bool
 */
function IsEditable(int $fieldflag, AA_Field $field, AA_Profile $profile): bool {
    return (!($fieldflag & FLAG_FREEZE)
        && $field->getProperty("input_show")
        && !$profile->getProperty('hide',$field->getId())
        && !$profile->getProperty('hide&fill',$field->getId())
        && !$profile->getProperty('fill',$field->getId()));
}

// -----------------------------------------------------------------------------
/** StoreItem - deprecated function - $content4id->storeItem() instead
 *
 *   Basic function for changing contents of items.
 *   Use always this function, not direct SQL queries.
 *   Updates the tables @c item and @c content.
 *   $GLOBALS[err][field_id] should be set on error in function
 *   It looks like it will return true even if inset_fnc_xxx fails
 *
 * @param        $id
 * @param        $slice_id
 * @param array  $content4id    array (field_id => array of values
 *                              (usually just a single value, but still an array))
 * @param        $insert
 * @param bool   $invalidatecache
 * @param bool   $feed
 * @param string $context       special parameter used for thumbnails - ''|feed
 * @return false|string item id or false on error
 */
function StoreItem( $id, $slice_id, $content4id, $insert, $invalidatecache=true, $feed=true, $context='' ): false|string
{
    $content4id = new ItemContent($content4id);
    $content4id->setItemID($id);
    $content4id->setSliceID($slice_id);
    return $content4id->storeItem( $insert ? 'insert' : 'update', [$invalidatecache, $feed], (string)$context);     // invalidatecache, feed
} // end of StoreItem

// ----------------------------------------------------------------------------

/** Validates new content, sets defaults, reads dates from the 3-selectbox-AA-format,
 *   sets global variables:
 *       $special input variables
 *
 *   @author Jakub Adamek, Econnect, January 2003
 *           Most of the code is taken from itemedit.php, created by Honza.
 *
 * @param $err
 * @param $slice AA_Slice
 * @param string $action should be one of:
 *                       "add" .... a "new item" page (not form-called)
 *                       "edit" ... an "edit item" page (not form-called)
 *                       "insert" . call for inserting an item
 *                       "update" . call for updating an item
 * @param string $id is useful only for "update"
 * @param bool $do_validate Should validate the fields?
 * @param array $notshown is an optional array ("field_id"=>1,...) of fields
 *                          not shown in the anonymous form
 */

function ValidateContent4Id(&$err, $slice, $action, $id=0, $do_validate=true, $notshown=[]): ?AA_Content {

    $profile = AA_Profile::getProfile(AuthGetUid(), $slice->getId()); // current user settings

    // error array (Init - just for initializing variable
    if (!is_array($err)) {
        $err = [];
    }

    // Are we editing dynamic slice setting fields?
    $slice_fields = ($id == $slice->getId());

    // get slice fields and its priorities in inputform
    $fields = $slice->getFields($slice_fields);

    // it is needed to call IsEditable() function and GetContentFromForm()
    $oldcontent4id = null;
    if ( $action === "update" ) {
        // if we are editing dynamic slice setting fields (stored in content
        // table), we need to get values from slice's fields
        if ($slice_fields) {
            $oldcontent4id = $slice->get_dynamic_setting_content(true);
        } else {
            $oldcontent4id = new ItemContent($id);
        }
    }

    foreach ($fields as $pri_field_id => $field) {

        // $f = $field->getRecord();  // now we use ArrayAccess

        //  'status_code.....' is not in condition - could be set from defaults
        if (($pri_field_id==='edited_by.......') || ($pri_field_id==='posted_by.......')) {
            continue;   // filed by AA - it could not be filled here
        }
        $varname = 'v'. unpack_id($pri_field_id);  // "v" prefix - database field var
        $htmlvarname = $varname."html";

        global $$varname, $$htmlvarname;

        $setdefault = $action === "add"
                || !$field["input_show"]
                || $profile->getProperty('hide',$pri_field_id)
                || ($action === "insert" && $notshown[$varname]);

        [$validate_type] = explode(":", $field["input_validate"], 2);
        [$insert_type]   = explode(":", $field["input_insert_func"], 2);

        if ($setdefault) {
            $default = $field->getDefault();

            // modify the value to be compatible with $_GET[] array - we use
            // slashed variables (this will be changed in future) - TODO
            $$varname     = ($default->count() > 1) ? array_map('addslashes',$default->getValues()) : addslashes($default->getValue());
            $$htmlvarname = $default->getFlag();

        } elseif ($validate_type==='date') {
            $default = $field->getDefault();
            // we do not know at this moment, if we have to use default
            $default_val  = addslashes($default->getValue());
        }

        $editable = IsEditable($oldcontent4id ? $oldcontent4id->getFlag($pri_field_id) : 0, $field, $profile) && !$notshown[$varname];

        // Run the "validation" which changes field values
        if ($editable && ($action === "insert" || $action === "update")) {
            if ( $validate_type === 'date' ) {
                $foo_datectrl_name = new datectrl($varname);

                if ($$varname != "") {                  // loaded from defaults
                    $foo_datectrl_name->setdate_int($$varname);
                }
                $foo_datectrl_name->ValidateDate($field["name"], $err, $field["required"], $default_val);
                $$varname = $foo_datectrl_name->get_date();  // write to var
            } elseif ($insert_type === 'boo') {
                $$varname = ($$varname ? 1 : 0);
            } elseif ($insert_type === 'pwd') {
                $change_varname   = $varname.'a';
                $retype_varname   = $varname.'b';
                $delete_varname   = $varname.'d';

                global $$change_varname, $$retype_varname, $$delete_varname;

                if ($$change_varname && ($$change_varname == $$retype_varname)) {
                    $$varname = ParamImplode(['AA_PASSWD',$$change_varname]);
                } elseif ($$delete_varname) {
                    $$varname = '';
                } elseif ( ($action === "update") && $oldcontent4id) {
                    // store the original password to use it in
                    // insert_fnc_pwd when it is not changed
                    // $$varname = $oldcontent4id[$pri_field_id][0]['value'];
                    $$varname = ParamImplode(['AA_PASSWD_CRYPTED',$oldcontent4id->getValue($pri_field_id)]);
                }
            }
        }

        // Run the validation which really only validates
        if ($do_validate && ($action === "insert" || $action === "update")) {
            // special setting for file upload - there we solve the problem
            // of required fileupload field, but which is empty at this moment
            if ( $field["required"] && (str_starts_with($field["input_show_func"], 'fil'))) {
                ValidateInput($varname, $field["name"], $$varname. $GLOBALS[$varname.'x'] , $err, $field["required"] ? 1 : 0, AA_Validate::factoryByString($field["input_validate"]));
                continue;
            }

            switch( $validate_type ) {
                case 'e-unique':
                case 'unique':

                    // fill field with current field, if not filled and
                    // add $id, so we do not find the currently edited item when
                    // we are looking for uniqueness

                    [$v_func,$v_field,$v_scope] = ParamExplode($field["input_validate"]);
                    if (!$v_field) {
                        $v_field = $pri_field_id;
                    }
                    $v_type = ParamImplode([$v_func,$v_field,$v_scope,$id]);
                    ValidateInput($varname, $field["name"], $$varname, $err, $field["required"] ? 1 : 0, AA_Validate::factoryByString($v_type));

                    break;
                case 'user':
                    // this is under development.... setu, 2002-0301
                    // value can be modified by $$varname = "new value";
                    if (function_exists('usr_validate')) {
                        $$varname = usr_validate($varname, $field["name"], $$varname, $err, $field, $fields);
                    }
                    break;
                //case 'text':
                //case 'url':
                //case 'email':
                //case 'number':
                //case 'id':
                default:
                    // status code is never required
                    ValidateInput($varname, $field["name"], $$varname, $err, ($field["required"] && ($pri_field_id!=='status_code.....')) ? 1 : 0, AA_Validate::factoryByString($field["input_validate"]));
                    break;
            }
        }
    }
    return $oldcontent4id;
}
