<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\Secret;

/**
 * The statistics slices of the Alerts lists (alerts_collection.stat_slice_id): a hit of a tracked address
 * stores nothing when the slice is gone or lacks a field, and nobody sees it - the reader only gets the
 * picture or the page. The settings check the slice when it is chosen; this check finds what changed since.
 */
class CheckMailbulkStats extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Mailing statistics');
    }

    public static function description(): string
    {
        return _m('Checks that the statistics slice of every Alerts list that measures opens and clicks exists, has its fields, keeps no item history and has a reading password, and that AA_SECRET is set.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'mail';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    /** @return Finding[] */
    public function findings(): array
    {
        $kinds = ['gone' => [], 'fields' => [], 'history' => [], 'readable' => []];
        $lists = $this->lists();
        foreach ($lists as $list) {
            $state = $this->inspect($list['slice']);
            $row   = [
                _m('Alerts')           => a_href(get_aa_url('modules/alerts/modedit.php', ['module_id' => $list['module']], false), safe($list['name'])),
                _m('Statistics slice') => safe($list['slice']),
            ];
            if (!$state['live']) {
                $kinds['gone'][] = $row;
                continue;
            }
            if ($state['missing']) {
                $kinds['fields'][] = $row + [_m('Missing fields') => safe(implode(', ', $state['missing']))];
            }
            if ($state['history']) {
                $kinds['history'][] = $row;
            }
            if ($state['readable']) {
                $kinds['readable'][] = $row;
            }
        }
        $n     = array_map(count(...), $kinds);
        $texts = [
            'gone'     => [Finding::ERROR, _m('%1 Alerts lists measure into a statistics slice that is gone', [$n['gone']]), _m('Nothing is counted. Choose another statistics slice, or none, in the Alerts settings.')],
            'fields'   => [Finding::ERROR, _m('%1 statistics slices lack a field the events need', [$n['fields']]), _m('Nothing is counted. Add the missing fields to the slice, or install the slice anew from the App store and choose it in the Alerts settings.')],
            'history'  => [Finding::WARNING, _m('%1 statistics slices keep the history of their items', [$n['history']]), _m('Every first open and click of a reader then writes a row of the log with the address of the request. Set "Store item history for N days" to 0 in the slice settings.')],
            'readable' => [Finding::WARNING, _m('%1 statistics slices have no reading password', [$n['readable']]), _m('Set a reading password in the slice settings, so its items are not shown on the web.')],
        ];
        $findings = [];
        foreach ($texts as $kind => [$severity, $title, $advice]) {
            if ($n[$kind] > 0) {
                $findings[] = new Finding($severity, $title, count: $n[$kind], evidence: $kinds[$kind], advice: $advice, key: "stat-$kind");
            }
        }
        if ($lists && (strlen($this->secret()) < Secret::MIN_LENGTH)) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('Alerts lists measure opens and clicks while AA_SECRET is not set'),
                advice: _m('The tracked links of the mails are signed by a key derived from the database password, so a new DB_PASSWORD would break the links of all mails sent so far. Set AA_SECRET in include/config.php before the next mailing - see the AA_SECRET check.'),
                key: 'stat-secret',
            );
        }
        return $findings ?: [new Finding(Finding::OK, _m('%1: nothing found', [static::name()]))];
    }

    // ------------------------------------------------------------ what tests replace

    /** the lists that measure - a table of a few rows, read whole
     *  @return list<array{name: string, module: string, slice: string}> long ids */
    protected function lists(): array
    {
        $lists = [];
        // SELECT c.*: the column comes with an update of the database, till then nothing measures
        foreach (DB_AA::select([], 'SELECT c.*, m.name FROM alerts_collection c INNER JOIN module m ON m.id = c.module_id', [['m.deleted', 1, '<']], ['m.name']) as $row) {
            if ((string) ($row['stat_slice_id'] ?? '') !== '') {
                $lists[] = ['name' => (string) $row['name'], 'module' => unpack_id((string) $row['module_id']), 'slice' => unpack_id((string) $row['stat_slice_id'])];
            }
        }
        return $lists;
    }

    /** @return array{live: bool, missing: list<string>, history: bool, readable: bool} */
    protected function inspect(string $sliceId): array
    {
        require_once constant('AA_BASE_PATH') . 'modules/alerts/alerts_sending.php';   // AA_Collection is not autoloaded

        if (!DB_AA::test('module', [['id', $sliceId, 'l'], ['type', 'S'], ['deleted', 0, 'i']])) {
            return ['live' => false, 'missing' => [], 'history' => false, 'readable' => false];
        }
        return [
            'live'     => true,
            'missing'  => \AA_Collection::missingStatFields(DB_AA::select('id', 'SELECT id FROM field', [['slice_id', $sliceId, 'l']])),
            'history'  => \AA_Collection::statSliceKeepsHistory($sliceId),
            'readable' => \AA_Collection::statSliceWarning($sliceId) !== '',
        ];
    }

    protected function secret(): string
    {
        return defined('AA_SECRET') ? (string) constant('AA_SECRET') : '';
    }
}
