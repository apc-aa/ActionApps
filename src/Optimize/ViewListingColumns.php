<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;

/** The columns of `view` behind two settings of the view form: a condition on a field of related items
 *  (the name of such a field - two field ids and, for a relation leading here, the slice between them - is
 *  longer than the 32 bytes of old databases; cut without an error it names no field and the condition is
 *  left out) and the limit of items in a group. Until the columns are there, the form offers neither. */
class ViewListingColumns extends AbstractOptimize
{
    private const TABLE   = 'view';
    private const COLUMNS = ['cond1field', 'cond2field', 'cond3field', 'group_limit'];

    public static function name(): string
    {
        return _m('Columns for the conditions and groups of views');
    }

    public static function description(): string
    {
        return _m('Widens the columns view.cond1field - cond3field, so a view condition can name a field of related items, and adds view.group_limit for the limit of items in a group. Nothing else changes.');
    }

    public static function types(): array
    {
        return ['sql_update', self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'db';
    }

    /** before UpdateDbStructure (50), which would otherwise change them among all the differences */
    public static function priority(): int
    {
        return 45;
    }

    public static function actions(): array
    {
        return ['test', 'repair'];
    }

    /** the view form may offer the two settings */
    public static function ready(): bool
    {
        return self::lacking() === [];
    }

    public function test(): bool
    {
        if ($lacking = self::lacking()) {
            $this->message(_m('The columns of %1 to add or widen: %2', [self::TABLE, implode(', ', array_keys($lacking))]));
            return false;
        }
        $this->message(_m('OK - the columns of %1 are as the view form needs them', [self::TABLE]));
        return true;
    }

    public function findings(): array
    {
        if (!$lacking = self::lacking()) {
            return [new Finding(Finding::OK, _m('Views can keep conditions on related items and the group limit'))];
        }
        $evidence = [];
        foreach ($lacking as $column => $type) {
            $evidence[] = [_m('Column') => self::TABLE . '.' . $column, _m('Type') => $type ?? _m('missing')];
        }
        return [new Finding(
            Finding::WARNING,
            _m('The view form offers no conditions on related items and no group limit'),
            evidence: $evidence,
            advice: _m('The database is older than the code. Repair adds the columns; the view form then offers both settings.'),
            repairable: true,
            key: 'columns',
        )];
    }

    public function repair(): bool
    {
        if (!$lacking = self::lacking()) {
            return true;
        }
        $changes = [];
        foreach ($lacking as $column => $type) {
            // a widened column keeps every value
            $changes[] = (($type === null) ? 'ADD ' : 'MODIFY ') . trim(AA::Metabase()->tables[self::TABLE]->getColumn($column)->getCreateSql());
        }
        return $this->query('ALTER TABLE `' . self::TABLE . '` ' . implode(', ', $changes));   // one rebuild of the table
    }

    /** @return array<string, ?string> the columns to change: null for a missing one, else the type of the too
     *                                 narrow one; [] also without the table - a new install creates it whole */
    private static function lacking(): array
    {
        // one query on every load of the view form
        $live = DB_AA::select(['COLUMN_NAME' => 'COLUMN_TYPE'], 'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS', [['table_schema', (string) constant('DB_NAME')], ['table_name', self::TABLE]]);
        if (!$live) {
            return [];
        }
        $lacking = [];
        foreach (self::COLUMNS as $column) {
            if (!isset($live[$column])) {
                $lacking[$column] = null;
            } elseif (!UpdateDbStructure::holds((string) $live[$column], AA::Metabase()->tables[self::TABLE]->getColumn($column)->c[1])) {
                $lacking[$column] = (string) $live[$column];
            }
        }
        return $lacking;
    }
}
